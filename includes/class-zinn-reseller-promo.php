<?php
/**
 * What Zinn Digital sells, shown where the site's own administrator can see it.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** Every Zinn plugin ships an identical copy of
 * this class, differing only in its class name and text domain. The source of truth is
 * `wp/promo/class-zinn-promo.php.tpl`; `wp/bin/build-promo.php` renders it into each plugin
 * and `scripts/wp-promo-check.py` fails the build if a checked-in copy has drifted from a
 * fresh render (§2.32 — generated code is an output).
 *
 * ⭐ Generated rather than shared because the alternative does not work. A single file
 * `require`d from seven plugins cannot carry seven text domains, and a **variable** text
 * domain is refused by the WordPress i18n sniff and invisible to `wp i18n make-pot` — so the
 * strings would ship untranslatable into 58 locales (§2.19). Substituting the domain at build
 * time keeps every `__()` call a literal, which is what both tools require.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The Zinn® panel: one dashboard widget, one settings-screen block, no remote calls.
 *
 * ⚖️ **Required by the owner, 2026-09-01:** *"each plugin should promote our hosting and
 * marketplace as well as Zinn Hub global marketplace inside people's site in the admin
 * dashboard … so it can be promoted properly inside people's wp dashboard"*, and *"as do all
 * our plugins for seo reach and understanding too as well as user guides for them … and
 * linked to in the plugins dashboard"*. Four things are named there — hosting, the
 * marketplace, Zinn Hub, and a user guide per plugin — and all four are here.
 *
 * ⭐ The pattern is `zinn-connector`'s dashboard widget (owner ruling 2026-08-22), which
 * promoted the same three destinations for one plugin. This generalises it to all of them and
 * adds the guide link, which is the half that makes the panel useful rather than only
 * promotional.
 *
 * ⛔⛔ **THIS IS OUR SURFACE INSIDE THEIR ADMIN, WHICH IS NOT OUR CONTENT ON THEIR SITE.**
 * `docs/203` §8: our brand stops at the publish boundary. A logged-in administrator looking at
 * wp-admin is looking at a plugin they installed from us, so our name belongs here. Anything
 * rendered on the public site would be a **footprint event before it is a branding one**
 * (§2.14) — the signature a footprint audit hunts for across a network. Every entry point
 * below is therefore an `admin_*` hook: no shortcode, no widget, no filter on `the_content`.
 *
 * ⛔ **No remote call, no tracking pixel, no image loaded from us.** A panel that fetched
 * anything would put a third-party request on the critical path of every admin page load on a
 * site we do not host — slow for them, and a liability for us the first time our API is down.
 * It is also what a WordPress.org reviewer looks for first.
 */
final class Zinn_Reseller_Promo {

	/**
	 * This plugin's slug, which is also its catalogue identity and its guide's key.
	 */
	/**
	 * This plugin's own name, isolated so an RTL locale cannot detach its trade-mark symbol.
	 *
	 * ⛔⛤ **MEASURED IN A BROWSER, NOT REASONED FROM THE SPEC** (W37-AO's hypothesis, rendered
	 * here 2026-09-02). Every use below substitutes this into a translatable string, so in an
	 * Arabic, Hebrew, Persian or Urdu catalogue the run becomes
	 * `L("Zinn Digital") + ON(®) + WS + ON(—) + WS + AL(…)`. A neutral run BETWEEN OPPOSITE
	 * STRONG TYPES resolves to the paragraph direction, so `® — ` lays out RTL and the symbol
	 * detaches from the name it belongs to. Chrome renders the bare form as:
	 *
	 *     ®Zinn Digital — أدلة وخدمات      ⛔ the ® has jumped to the far side
	 *     Zinn Digital® — أدلة وخدمات      ✅ with U+2068 FSI … U+2069 PDI
	 *
	 * ⭐ FSI/PDI rather than LRM: both render correctly, but an isolate says *"this span has its
	 * own direction, do not let it interact"*, which is exactly the claim being made about an
	 * interpolated proper noun. LRM only happens to fix this instance.
	 *
	 * ⚠️ **Latent, not live, on the day it was added** — the promo msgids are not yet in the
	 * shipped `.po` catalogues, so today every locale renders the English and the boundary does
	 * not exist (verified: the English control renders correctly). It becomes real the moment
	 * the catalogues include these strings, which is why it is fixed BEFORE they do.
	 *
	 * ⛔ The isolates are around the INTERPOLATED value only. A mark written INSIDE a
	 * translatable string moves with the translation and the translator controls its placement;
	 * that is a wider estate question and not this file's to answer.
	 */
	private const NAME = "\u{2068}Zinn® Reseller Toolkit\u{2069}";

	/**
	 * The company mark, isolated for the same reason as `NAME` above.
	 *
	 * ⛔ This is the one W37-AO measured — `sprintf( __( '%s — guides and services' ), … )` at
	 * the widget title and the settings heading, sixteen places across the generated set. It is
	 * a SEPARATE constant from `NAME` because they are different marks with different owners in
	 * §2.15's list, and collapsing them would make a future rename of one silently rename both.
	 */
	// ⛔ The ® is the LITERAL character, not `\u{00ae}`. `brand-gate` reads extracted strings
	// and does not decode PHP escapes, so an escaped symbol reads as NO symbol and the gate
	// refuses the line — fail-closed, and correct. The isolates stay escaped because they
	// have no visual form: a literal U+2068 in the source is an invisible character nobody
	// reviewing this file could see.
	private const BRAND = "\u{2068}Zinn Digital®\u{2069}";

	private const SLUG = 'zinn-reseller';

	/**
	 * User-meta key holding a per-user dismissal of the settings-screen block.
	 *
	 * ⛔ **Per USER, not per site.** A site with three administrators has three people with
	 * three different opinions about whether they want to see this, and an option would let
	 * the first one decide for the other two.
	 */
	private const DISMISS_META = 'zinn_promo_dismissed';

	/**
	 * Query argument and nonce action for the dismiss link.
	 */
	private const DISMISS_ARG = 'zinn-promo-dismiss';

	/**
	 * Register the panel's hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		self::announce();
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss' ) );

		// ⛔⛔ ONE WIDGET, HOWEVER MANY ZINN PLUGINS ARE ACTIVE. A site can reasonably run
		// Zinn® Cache, the Connector and Offload together; three identical widgets stacked on
		// the dashboard is the "aggressive advertising" a WordPress.org reviewer rejects, and
		// it would be our own fault rather than theirs. The first Zinn plugin to load claims
		// the widget and the rest stand down — and because the guide list is built from the
		// shared registry below, the one widget still names every Zinn plugin on the site.
		if ( ! defined( 'ZINN_PROMO_WIDGET_OWNER' ) ) {
			define( 'ZINN_PROMO_WIDGET_OWNER', self::SLUG );
		}
		if ( ZINN_PROMO_WIDGET_OWNER === self::SLUG ) {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		}
	}

	/**
	 * Put this plugin into the shared registry the widget reads.
	 *
	 * ⭐ A global array rather than a filter, because the plugins load in an order WordPress
	 * chooses and a filter registered after the widget rendered would be too late. The label
	 * and the guide title are translated HERE, in this plugin's own text domain, so the
	 * claiming plugin never has to translate another plugin's name.
	 *
	 * @return void
	 */
	private static function announce(): void {
		if ( ! isset( $GLOBALS['zinn_promo_plugins'] ) || ! is_array( $GLOBALS['zinn_promo_plugins'] ) ) {
			$GLOBALS['zinn_promo_plugins'] = array();
		}
		$GLOBALS['zinn_promo_plugins'][ self::SLUG ] = array(
			// ⛔ The product name is INTERPOLATED, not translated. §2.15 fixes the presentation
			// of the mark (® immediately after `Zinn Digital`, or after `Zinn` for a product),
			// and a translatable brand string puts that presentation in the hands of 58
			// translations — which is exactly how the brand gate has caught it before.
			'name'  => self::NAME,
			'guide' => self::guide_url(),
		);
	}

	/**
	 * The public URL of this plugin's user guide.
	 *
	 * @return string
	 */
	public static function guide_url(): string {
		return 'https://zinndigital.com/kb/zinn-reseller-plugin';
	}

	/**
	 * Constants that exist on a Zinn-hosted site and on no other kind of site.
	 *
	 * ⛔⛔ **A UNION, AND EVERY MEMBER IS LOAD-BEARING. THE OBVIOUS ONE ALONE IS WRONG.**
	 * The first draft of this gated on `ZINN_SITE_ID` alone, which reads like the definitive
	 * answer and is not one. In `engine/engine/hosting/site_constants.py` that constant is
	 * assigned **only in the `else` branch** of the SSO-key derivation, so a genuinely
	 * Zinn-hosted site gets no `ZINN_SITE_ID` whenever `ENGINE_WP_SSO_KEY` is unset or too
	 * weak — a state the engine warns about loudly and which was live on 2026-09-01, the day
	 * this was written. `ZINN_SITE_ID` answers *"is one-click login configured for this
	 * site"*; the question here is *"is this site hosted with us"*. Adjacent question,
	 * confident wrong answer, in the one direction that costs a customer something (§2.54).
	 *
	 * ⭐ The other three are assigned **unconditionally** in the same function's opening
	 * `constants` literal, so they survive the SSO misconfiguration that removes the first.
	 * Found by W37-AO reading the engine rather than the plugin.
	 *
	 * @var string[]
	 */
	private const HOSTED_MARKERS = array(
		'ZINN_UPDATE_URL',
		'ZINN_SITE_EVENTS_URL',
		'ZINN_CACHE_PANEL_SECRET',
		'ZINN_SITE_ID',
	);

	/**
	 * Is this site hosted by us?
	 *
	 * ⛔⛔ **THIS IS WHAT STOPS THE PANEL SELLING SOMEBODY WHAT THEY ALREADY HAVE.** The
	 * markers above are written into `wp-config.php` by the platform and exist on a
	 * Zinn-hosted site only. Without this check the panel would offer *"move this site to
	 * Zinn hosting"* to a customer already paying us for hosting — which reads as a plugin
	 * that does not know who its user is, and is the fastest way to make a promotion feel
	 * like spam.
	 *
	 * ⭐ It also decides which cache product it is honest to name. Zinn® Cache is a cache
	 * *controller* for sites hosted with us; Zinn® Cache Engine is a cache *engine* for sites
	 * hosted elsewhere. A customer installs one or the other, never both (W37-AO,
	 * 2026-09-01), so offering the engine to a hosted site re-creates in a new surface the
	 * confusion the rename exists to remove.
	 *
	 * @return bool True when at least one platform marker is present.
	 */
	public static function is_zinn_hosted(): bool {
		foreach ( self::HOSTED_MARKERS as $marker ) {
			if ( defined( $marker ) && '' !== (string) constant( $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register the shared dashboard widget, for administrators only.
	 *
	 * ⭐ Capability-gated. A dashboard widget shown to every contributor is advertising to
	 * people who did not install anything and cannot act on it. WordPress's own Screen Options
	 * then lets an administrator who does not want it hide it permanently — which is why the
	 * widget carries no separate dismiss link and the settings block does.
	 *
	 * @return void
	 */
	public static function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'zinn_promo_overview',
			/* translators: %s: the Zinn Digital trade mark, which is not translated. */
			sprintf( __( '%s — guides and services', 'zinn-reseller' ), self::BRAND ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * Render the dashboard widget: every installed Zinn plugin's guide, then what we sell.
	 *
	 * @return void
	 */
	public static function render_widget(): void {
		$installed = self::installed_plugins();
		?>
		<div class="zinn-promo">
			<?php if ( array() !== $installed ) : ?>
				<p><strong><?php echo esc_html__( 'User guides', 'zinn-reseller' ); ?></strong></p>
				<ul>
					<?php foreach ( $installed as $plugin ) : ?>
						<li>
							<?php echo self::link( (string) $plugin['guide'], (string) $plugin['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::link() escapes both arguments. ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php self::render_offers(); ?>
		</div>
		<?php
	}

	/**
	 * Render the settings-screen block, unless this user has dismissed it.
	 *
	 * Called from the plugin's own settings page, at the bottom, below its controls — never
	 * above them. Somebody who opened a settings screen came to change a setting.
	 *
	 * @return void
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) || self::is_dismissed() ) {
			return;
		}
		?>
		<div class="zinn-promo card" style="max-width:52em;padding:0 1.5em 1em;margin-top:2em;">
			<?php
			// ⛔⛤ THE SAME STRING AS THE WIDGET TITLE, AND THE REUSE IS STRUCTURAL RATHER THAN
			// LAZY. This heading was `More from %s`, which renders `More from Zinn Digital®` —
			// a mark at the END of the string — and in an RTL locale the browser's bidi
			// algorithm then puts the symbol on the WRONG SIDE: `®Zinn Digital`. The bytes are
			// correct; the rendering is not, and no gate reads it because nothing is wrong with
			// the source (W37-BG, measured in Hebrew, 2026-09-01).
			//
			// ⭐ Keeping the mark MID-PHRASE by construction is the fix — the ® then sits inside
			// the Latin run and bidi leaves it alone. It cannot be guaranteed from the English
			// alone, because a translator moves `%s` wherever their language wants it; what CAN
			// be guaranteed is that the English does not invite it, and that both surfaces say
			// the same thing so there is one string to get right in 57 locales instead of two.
			$heading = sprintf(
				/* translators: %s: the Zinn Digital trade mark, which is not translated. */
				__( '%s — guides and services', 'zinn-reseller' ),
				self::BRAND
			);
			?>
			<h2><?php echo esc_html( $heading ); ?></h2>
			<?php
			/* translators: %s: this plugin's name, e.g. "Zinn® Cache". */
			$guide_label = sprintf( __( 'Read the %s user guide', 'zinn-reseller' ), self::NAME );
			?>
			<p>
				<?php echo self::link( self::guide_url(), $guide_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::link() escapes both arguments. ?>
			</p>
			<?php self::render_offers(); ?>
			<p>
				<a href="<?php echo esc_url( self::dismiss_url() ); ?>">
					<?php echo esc_html__( 'Hide this', 'zinn-reseller' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the panel in the footer of a plugin's own admin screens.
	 *
	 * ⛔⛔ **THIS EXISTS FOR `zinn-cache-pro` AND THE REASON IS THAT ITS TREE IS GENERATED.**
	 * Every file under `wp/plugins/zinn-cache-pro/` is produced by
	 * `wp/rebrand/apply-rebrand.php` from a SHA-pinned LiteSpeed Cache, so a
	 * `{CLASS}::render_panel()` added to one of its templates would have to be a
	 * `file_patches` entry whose `find` string matches upstream **verbatim** — and would
	 * therefore go stale, silently, on the next upstream bump, in a file nobody rereads
	 * (W37-AO, 2026-09-01).
	 *
	 * ⭐ A screen-scoped `admin_footer` hook needs no patch at all and cannot rot: it is
	 * keyed on the plugin's own menu slug, which is ours and is asserted by the manifest's
	 * `invariants`. The panel lands at the bottom of the plugin's own pages and nowhere else.
	 *
	 * @param string $screen_prefix The plugin's menu slug, e.g. `zinn-cache-pro`.
	 * @return void
	 */
	public static function attach_footer_panel( string $screen_prefix ): void {
		add_action(
			'admin_footer',
			static function () use ( $screen_prefix ): void {
				if ( ! function_exists( 'get_current_screen' ) ) {
					return;
				}
				$screen = get_current_screen();
				// ⛔ The check is on OUR OWN menu slug appearing in the screen id, so the
				// panel cannot leak onto another plugin's page or onto a core screen. A bare
				// `admin_footer` with no screen test is an advert on every page of wp-admin,
				// which is the single clearest WordPress.org rejection there is.
				if ( null === $screen || false === strpos( (string) $screen->id, $screen_prefix ) ) {
					return;
				}
				echo '<div class="wrap">';
				self::render_panel();
				echo '</div>';
			}
		);
	}

	/**
	 * The three things we sell, as the owner named them.
	 *
	 * ⛔ Hosting is omitted on a site we already host — see `is_zinn_hosted()`.
	 *
	 * @return void
	 */
	private static function render_offers(): void {
		$offers = array();

		if ( ! self::is_zinn_hosted() ) {
			$offers[] = array(
				'url'   => 'https://zinndigital.com/hosting',
				'title' => __( 'Zinn® hosting for WordPress', 'zinn-reseller' ),
				'body'  => __( 'LiteSpeed, daily backups, free migration and a control panel built for people who run more than one site.', 'zinn-reseller' ),
			);
		}

		$offers[] = array(
			'url'   => 'https://zinndigital.com/marketplace',
			'title' => __( 'The Zinn® marketplace', 'zinn-reseller' ),
			'body'  => __( 'Buy and sell established sites, domains and link placements, with the money held safely until both sides are happy.', 'zinn-reseller' ),
		);

		// ⛔⛔ `/zinns/` AND NOT THE HOMEPAGE, AND THE WORDS ARE THE SITE'S OWN. Measured by
		// W37-BI on 2026-09-01 and re-checked here: Zinn Hub® is a live WordPress/WooCommerce
		// site with 10,457 published services, and its own vocabulary is **Zinns**, **Micro
		// Zinns** and **Projects** — not "gigs" and not "micros". A panel that uses different
		// words from the page it lands on reads as written by someone who has not seen it.
		//
		// ⛔⛤ `Zinn Hub®` IS CORRECT, AND A GATE TOLD US OTHERWISE WITH TOTAL CONFIDENCE.
		// This line briefly carried the symbol on the wrong word — split INSIDE the two-word
		// mark rather than after it — because `brand-gate` refused the correct form: its
		// `MARKS` tuple held `Zinn Digital`, `Zinnector`, `Zinner` and `Zinn`, so the longest
		// match was `Zinn` and rule 3 (*the symbol attaches to the MARK, never the product
		// phrase*) said the ® belonged there. The rule was applied correctly. The premise was
		// wrong: **`Zinn Hub` is registered — UK00004361714, Class 35, Zinn Digital LTD** — and
		// the owner confirmed it directly (2026-09-01).
		//
		// ⭐⭐ A hardcoded list of marks is a claim about OUR KNOWLEDGE, and it reads at the point
		// of use as a claim about the WORLD. Registration ownership is a fact held at the IPO;
		// a tuple that looks total says "all the marks that exist" while only ever meaning "the
		// ones we know about", and nothing at the call site distinguishes them.
		//
		// ⛔⛔ AND IT WAS CONFIDENTLY WRONG RATHER THAN SILENT, WHICH IS THE EXPENSIVE KIND. A
		// blind zero costs an investigation nobody runs; an authoritative REFUSAL recruits
		// everyone downstream. One missing row propagated the wrong form into seven plugins,
		// seven POTs, three documents, a register entry and a message to another lane telling
		// them to expect it in 399 translation catalogues. Two lanes reasoned independently and
		// both reached the same wrong answer, because both read the same list as exhaustive.
		//
		// ⛔⛤ AND THIS PARAGRAPH REPRODUCED THE WRONG FORM TWICE WHILE EXPLAINING IT —
		// once when it was written and once in the correction — so the audit kept finding
		// nine hits that were all this comment. §2.41: a control that searches for a phrase
		// finds the DOCUMENTATION of the phrase, and the documentation is the hardest copy
		// to notice because it is the one you are proud of. Name the error, never spell it.
		//
		// ⭐ The gate now knows the mark (W37-BI). If you are ever refused on a mark's
		// presentation, ask whether the mark is REGISTERED before you change the text — that
		// question is one sentence and it is the whole answer.
		// ⛔ `/become-a-seller/` is a 404 and `/sell/` is a 301; deep `page/3/` archive URLs
		// answer 404 while still rendering content. Link to archive ROOTS only.
		//
		// ⛔ NO PAYMENT-COMPLETION PROMISE. Checkout has not been walked end to end and the
		// estate has taken zero transactions ever, so *"buy in one click"* would be a claim
		// with nobody behind it (§2.41). "Browse" is what has actually been verified.
		$offers[] = array(
			'url'   => 'https://zinnhub.com/zinns/',
			'title' => __( 'Zinn Hub® — hire people who do this for a living', 'zinn-reseller' ),
			'body'  => __( 'Our global freelance marketplace: browse over 10,000 ready-made services from writers, designers, developers and SEOs, or post a project and let them bid.', 'zinn-reseller' ),
		);

		?>
		<ul>
			<?php foreach ( $offers as $offer ) : ?>
				<li style="margin-bottom:1em;">
					<?php echo self::link( (string) $offer['url'], (string) $offer['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::link() escapes both arguments. ?>
					<br />
					<span><?php echo esc_html( (string) $offer['body'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Every Zinn plugin active on this site, in a stable order.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function installed_plugins(): array {
		$registry = isset( $GLOBALS['zinn_promo_plugins'] ) && is_array( $GLOBALS['zinn_promo_plugins'] )
			? $GLOBALS['zinn_promo_plugins']
			: array();
		ksort( $registry );
		return $registry;
	}

	/**
	 * An external link that cannot reach back through `window.opener`.
	 *
	 * @param string $url   Destination.
	 * @param string $label Link text.
	 * @return string Escaped HTML.
	 */
	private static function link( string $url, string $label ): string {
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Has the current user hidden the settings-screen block?
	 *
	 * @return bool
	 */
	private static function is_dismissed(): bool {
		return '' !== (string) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
	}

	/**
	 * The nonce-protected URL that hides the block for the current user.
	 *
	 * @return string
	 */
	private static function dismiss_url(): string {
		return wp_nonce_url(
			add_query_arg( self::DISMISS_ARG, '1' ),
			self::DISMISS_ARG,
			self::DISMISS_ARG . '-nonce'
		);
	}

	/**
	 * Record a dismissal.
	 *
	 * ⛔ Nonce-checked and capability-checked, like every other state change in the plugin. A
	 * bare `?zinn-promo-dismiss=1` would let any page on the internet flip a setting for a
	 * logged-in administrator who merely visited it.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET[ self::DISMISS_ARG . '-nonce' ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET[ self::DISMISS_ARG . '-nonce' ] ) );
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ARG ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
	}
}
