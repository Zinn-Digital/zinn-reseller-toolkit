<?php
/**
 * Domain search — the `[zinn_domain_search]` shortcode.
 *
 * A visitor types a name, the site asks the Zinn® platform whether it is available and at
 * what price, and the results render with the reseller's own theme classes so they can be
 * styled without touching this plugin.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the search form and the results.
 */
class Domain_Search {

	/**
	 * Register the shortcode and its assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'zinn_domain_search', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the stylesheet ONLY on a page whose content contains the shortcode.
	 *
	 * ⛔⛔ This is the whole reason there is a `has_shortcode` call here rather than a plain
	 * `wp_enqueue_style`. A stylesheet loaded on every page of a reseller's site is a cost
	 * their blog readers pay for a search box on one page, and it compounds — every "just
	 * one more enqueue" is permanent until somebody audits the theme, and nobody ever does.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! has_shortcode( (string) $post->post_content, 'zinn_domain_search' ) ) {
			return;
		}
		wp_enqueue_style(
			'zinn-reseller-domain-search',
			plugins_url( 'assets/domain-search.css', dirname( __DIR__ ) . '/zinn-reseller.php' ),
			array(),
			ZINN_RESELLER_VERSION
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * ⛔ The form is a plain GET to the current page. No JavaScript at all: a search box
	 * that needs a bundle to work is a search box that does not work for a crawler, does
	 * not work with JavaScript off, and costs every visitor bytes before they type. The
	 * result page is server-rendered and shareable as a URL.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'placeholder' => __( 'Find your domain name', 'zinn-reseller' ),
				'button'      => __( 'Search', 'zinn-reseller' ),
				'tlds'        => '',
			),
			is_array( $atts ) ? $atts : array(),
			'zinn_domain_search'
		);

		// Read-only search on a public page: no nonce, deliberately. A nonce on a GET
		// search form breaks the shareable result URL and expires for a cached page, and
		// this request changes nothing — WordPress's own search form does the same.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = isset( $_GET['zinn_domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['zinn_domain'] ) ) : '';

		ob_start();
		self::form( $atts, $query );
		if ( '' !== $query ) {
			self::results( $query, (string) $atts['tlds'] );
		}
		return (string) ob_get_clean();
	}

	/**
	 * The search form.
	 *
	 * @param array<string,string> $atts  Resolved shortcode attributes.
	 * @param string               $query The current query, echoed back into the field.
	 * @return void
	 */
	private static function form( array $atts, string $query ): void {
		?>
		<form class="zinn-domain-search" method="get" action="<?php echo esc_url( get_permalink() ); ?>" role="search">
			<label class="screen-reader-text" for="zinn-domain">
				<?php echo esc_html( $atts['placeholder'] ); ?>
			</label>
			<input
				type="search"
				id="zinn-domain"
				name="zinn_domain"
				value="<?php echo esc_attr( $query ); ?>"
				placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
				autocomplete="off"
				required
			/>
			<button type="submit"><?php echo esc_html( $atts['button'] ); ?></button>
		</form>
		<?php
	}

	/**
	 * Look the name up and render the answer.
	 *
	 * @param string $query The visitor's search term.
	 * @param string $tlds  Comma-separated extensions from the shortcode, or empty.
	 * @return void
	 */
	private static function results( string $query, string $tlds ): void {
		$body = array( 'query' => $query );
		$list = array_values(
			array_filter( array_map( 'trim', explode( ',', $tlds ) ) )
		);
		if ( ! empty( $list ) ) {
			// ⛔ The PUBLIC endpoint refuses more than five with a 422 rather than truncating,
			// because each miss is a paid registrar lookup made for an anonymous visitor. The
			// authenticated one allows more. Either way the list is passed through unchanged
			// and a refusal is shown — sending six and being refused is a clearer failure for
			// the reseller than silently dropping their sixth choice.
			$body['tlds'] = $list;
		}

		// ⛔⛔ **AUTHENTICATED WHEN A KEY IS CONFIGURED, and that is the owner's phrase made
		// real**: *"domain search intergration or plugin for wp that will link in to their
		// domain account"*. `/v1/domains/search` runs the search AS THE RESELLER — their
		// entitlements, their account — and checks the catalogue's top **25** extensions for
		// a bare name against the public endpoint's top **10**. The response shape is
		// identical, so nothing below changes.
		//
		// ⭐ The public endpoint stays as the fallback rather than being replaced: the
		// shortcode must work the moment somebody drops it on a page, before they have pasted
		// a key. A search box that renders an error until an unrelated setting is filled in is
		// a worse first impression than one that quietly checks ten extensions instead of 25.
		$path     = Client::is_configured() ? '/v1/domains/search' : '/v1/public/domains/search';
		$response = Client::post( $path, $body );
		if ( is_wp_error( $response ) ) {
			self::render_error( $response );
			return;
		}

		$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
		if ( empty( $results ) ) {
			printf(
				'<p class="zinn-domain-empty">%s</p>',
				esc_html__( 'No extensions were checked for that name.', 'zinn-reseller' )
			);
			return;
		}

		echo '<ul class="zinn-domain-results">';
		foreach ( $results as $result ) {
			self::result_row( is_array( $result ) ? $result : array() );
		}
		echo '</ul>';
	}

	/**
	 * Show a failed lookup: one line for the visitor, the real cause for the administrator.
	 *
	 * ⛔⛔ The visitor NEVER sees the API's own message. It is written for the reseller
	 * ("this key does not carry the sites.view permission"), and on a public page it would
	 * leak how the site is wired to anyone who can load the shortcode.
	 *
	 * ⛔⛔ AND "please try again in a moment" IS A LIE FOR MOST OF THESE. A 401 or 403
	 * is a settings mistake that will still be there tomorrow, so the visitor is told the
	 * search is unavailable rather than invited to retry forever. Only a genuine transport
	 * failure or a 5xx earns the retry wording.
	 *
	 * ⭐ The administrator gets the actionable half in the same place they are standing,
	 * because the alternative is a reseller who can see the box is broken and has nothing
	 * to act on. It is gated on `manage_options`, so a logged-out visitor and a subscriber
	 * both see only the neutral line.
	 *
	 * @param \WP_Error $error The failure from the API client.
	 */
	private static function render_error( $error ) {
		$status    = (int) ( $error->get_error_data()['status'] ?? 0 );
		$permanent = in_array( $status, array( 400, 401, 403, 404, 422 ), true );

		printf(
			'<p class="zinn-domain-error">%s</p>',
			esc_html(
				$permanent
					? __( 'Domain search is unavailable on this site at the moment.', 'zinn-reseller' )
					: __( 'We could not reach the domain registry just now. Please try again in a moment.', 'zinn-reseller' )
			)
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hint = '';
		if ( 401 === $status ) {
			$hint = __( 'The API key is missing or no longer valid. Check it under Settings → Zinn® Reseller.', 'zinn-reseller' );
		} elseif ( 403 === $status ) {
			$hint = __( 'This API key does not carry the sites.view permission, which the domain search needs. Add it to the key in your reseller panel, or clear the key here to fall back to the public search.', 'zinn-reseller' );
		}

		printf(
			'<p class="zinn-domain-error zinn-domain-error--admin"><strong>%s</strong> %s%s</p>',
			esc_html__( 'Only administrators see this:', 'zinn-reseller' ),
			esc_html( $error->get_error_message() ),
			'' === $hint ? '' : ' ' . esc_html( $hint )
		);
	}

	/**
	 * Which of the THREE answers this result is: `available`, `taken` or `unknown`.
	 *
	 * ⛔⛔ Pure, and separated out so it can be tested without WordPress — because the
	 * middle case is the one that costs a reseller money and it is invisible to any test
	 * that only checks "available or not". `available: false` with
	 * `reason: availability_unknown` means NO SOURCE COULD SAY: no registrar reachable, no
	 * RDAP for that extension, a vendor timeout. Rendering that as "taken" makes a visitor
	 * abandon a name that may well be free. The API's own schema says a client MUST
	 * distinguish it; this is where that is decided, once.
	 *
	 * @param array<string,mixed> $result One `DomainSearchResult`.
	 * @return string
	 */
	public static function classify( array $result ): string {
		$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
		if ( 'availability_unknown' === $reason ) {
			return 'unknown';
		}
		return empty( $result['available'] ) ? 'taken' : 'available';
	}

	/**
	 * One result.
	 *
	 * ⛔⛔ THREE states, not two, and the third is the one that matters. `available: false`
	 * with `reason: availability_unknown` means NO SOURCE COULD SAY — no registrar
	 * reachable, no RDAP for that extension, a vendor timeout. Rendering that as "taken"
	 * makes a visitor abandon a name that may well be free, so it gets its own wording and
	 * its own class. The API's own schema says a client MUST do this; it is not our
	 * embellishment.
	 *
	 * @param array<string,mixed> $result One `DomainSearchResult`.
	 * @return void
	 */
	private static function result_row( array $result ): void {
		$fqdn = isset( $result['fqdn'] ) ? (string) $result['fqdn'] : '';
		if ( '' === $fqdn ) {
			return;
		}

		$state = self::classify( $result );
		if ( 'unknown' === $state ) {
			$label = __( 'We could not check this one — try again', 'zinn-reseller' );
		} elseif ( 'available' === $state ) {
			$label = self::price_label( $result );
		} else {
			$label = __( 'Already registered', 'zinn-reseller' );
		}

		// `buy_link()` returns a fully escaped anchor (`esc_url` + `esc_html` inside), so
		// it is markup by the time it arrives here and must not be escaped a second time —
		// that would print the tag to the visitor instead of rendering it.
		$buy = 'available' === $state ? self::buy_link( $fqdn ) : '';
		printf(
			'<li class="zinn-domain-result zinn-domain-result--%1$s"><span class="zinn-domain-name">%2$s</span> <span class="zinn-domain-state">%3$s</span>%4$s</li>',
			esc_attr( $state ),
			esc_html( $fqdn ),
			esc_html( $label ),
			wp_kses(
				$buy,
				array(
					'a' => array(
						'class' => array(),
						'href'  => array(),
						'rel'   => array(),
					),
				)
			)
		);
	}

	/**
	 * The price, or an honest sentence when there is not one.
	 *
	 * ⛔ Never `0` and never a blank: a name that is available but unpriced (`price` null
	 * with a `reason`) must not read as free. §2.44 in a shop window — the reassuring
	 * value is the wrong one.
	 *
	 * @param array<string,mixed> $result One `DomainSearchResult`.
	 * @return string
	 */
	private static function price_label( array $result ): string {
		$price = isset( $result['price'] ) && is_array( $result['price'] ) ? $result['price'] : null;
		if ( null === $price || ! isset( $price['amount_minor'], $price['currency'] ) ) {
			return __( 'Available — ask us for a price', 'zinn-reseller' );
		}
		$amount = number_format_i18n( ( (int) $price['amount_minor'] ) / 100, 2 );
		return sprintf(
			/* translators: 1: price, 2: ISO currency code. */
			__( 'Available — %1$s %2$s for the first year', 'zinn-reseller' ),
			$amount,
			(string) $price['currency']
		);
	}

	/**
	 * A link that hands the name to the reseller's own panel to buy.
	 *
	 * @param string $fqdn The domain name.
	 * @return string
	 */
	private static function buy_link( string $fqdn ): string {
		$url = add_query_arg(
			array(
				'tab' => 'search',
				'q'   => rawurlencode( $fqdn ),
			),
			untrailingslashit( Settings::panel_base() ) . '/domains'
		);
		return sprintf(
			' <a class="zinn-domain-buy" href="%1$s" rel="noopener">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Register it', 'zinn-reseller' )
		);
	}
}
