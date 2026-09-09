<?php
/**
 * Everything support needs to answer "why is it not working?", shown to the customer first.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** Source of truth:
 * `wp/admin-ui/class-zinn-diagnostics.php.tpl`, rendered by `wp/bin/build-admin-ui.php`.
 *
 * ⚖️ **Owner ruling, 2026-09-08**, asked directly with the alternatives in front of him:
 * *"Yes, with an explicit preview first"* — the customer sees exactly what will be sent,
 * redacted, before pressing send.
 *
 * ⛔⛔ **THE PREVIEW IS THE FEATURE, NOT THE POLITENESS.** A one-click "send diagnostics"
 * that shows nothing is a plugin asking somebody to transmit the contents of their server to
 * a third party on trust. Rendering the exact payload first is what makes it defensible — and
 * it is also the only way the customer can tell us we redacted something wrongly, which is
 * the failure nobody would otherwise ever discover.
 *
 * ⛔⛔ **REDACTION IS BY DECLARATION, NOT BY PATTERN MATCH.** A field is excluded because its
 * declaration says `secret` — not because its key happens to contain "token". A regex
 * deny-list of secret-looking names is the enumeration §2.24 keeps recording: correct on the
 * day it is typed, and the entry that goes missing is never the one you are looking at. The
 * failure direction here is a live credential in a support ticket.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Collects, redacts, previews and sends a support report.
 */
final class Zinn_Reseller_Diagnostics {

	/**
	 * What a redacted value renders as. Deliberately says WHY, not just that it is gone.
	 */
	private const REDACTED = '[not sent — credential]';

	/**
	 * Build the report.
	 *
	 * ⭐ Plugins add their own facts through the `zinn_diagnostics_report` filter rather than
	 * by editing this file, which is generated. A plugin that knows something support would
	 * want — a last error, a queue depth, whether a drop-in is installed — contributes it in
	 * its own tree.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		global $wp_version;

		$report = array(
			'generated_at' => gmdate( 'c' ),
			'plugin'       => array(
				'slug'    => 'zinn-reseller',
				'version' => defined( 'ZINN_RESELLER_VERSION' ) ? (string) constant( 'ZINN_RESELLER_VERSION' ) : '',
			),
			'site'         => array(
				'url'        => home_url(),
				'wp_version' => (string) $wp_version,
				'php'        => PHP_VERSION,
				'multisite'  => is_multisite(),
				'locale'     => get_locale(),
				'https'      => is_ssl(),
				'permalinks' => (string) get_option( 'permalink_structure', '' ),
				'theme'      => self::theme(),
				'debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
			),
			'server'       => array(
				'memory_limit'     => (string) ini_get( 'memory_limit' ),
				'max_execution'    => (string) ini_get( 'max_execution_time' ),
				'upload_max'       => (string) ini_get( 'upload_max_filesize' ),
				'curl'             => function_exists( 'curl_version' ),
				'openssl'          => extension_loaded( 'openssl' ),
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			'plugins'      => self::active_plugins(),
			'settings'     => self::redacted_settings(),
			'connection'   => self::connection(),
			'log'          => Zinn_Reseller_Admin_UI::log_entries(),
		);

		/**
		 * Filters the support report before it is shown to the customer.
		 *
		 * ⛔ Fires BEFORE the preview, never between the preview and the send. Anything added
		 * afterwards would be data the customer was not shown, which is exactly the property
		 * the owner's ruling was about.
		 *
		 * @param array<string, mixed> $report The report so far.
		 * @param string               $slug   The plugin building it.
		 */
		$report = (array) apply_filters( 'zinn_diagnostics_report', $report, 'zinn-reseller' );

		return $report;
	}

	/**
	 * The active theme, as name and version.
	 *
	 * @return array<string, string>
	 */
	private static function theme(): array {
		$theme = wp_get_theme();
		return array(
			'name'     => (string) $theme->get( 'Name' ),
			'version'  => (string) $theme->get( 'Version' ),
			'template' => (string) $theme->get_template(),
		);
	}

	/**
	 * Active plugins, as name and version.
	 *
	 * ⭐ Names and versions only. A plugin conflict is the commonest cause of a support
	 * ticket about any of ours, and this is the single most useful thing in the report —
	 * but paths can carry a customer's own directory names and belong nowhere.
	 *
	 * @return array<int, string>
	 */
	private static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$out[] = trim( (string) $all[ $file ]['Name'] . ' ' . (string) $all[ $file ]['Version'] );
		}
		sort( $out );
		return $out;
	}

	/**
	 * This plugin's settings, with declared secrets removed.
	 *
	 * @return array<string, mixed>
	 */
	private static function redacted_settings(): array {
		$values = Zinn_Reseller_Admin_UI::all();
		$secret = Zinn_Reseller_Admin_UI::secret_keys();
		$out    = array();
		foreach ( $values as $key => $value ) {
			if ( in_array( (string) $key, $secret, true ) ) {
				// ⛔ A boolean beside the redaction, because "is a key set at all?" is the
				// single question support asks most, and a blank line cannot answer it.
				$out[ (string) $key ] = ( '' === $value || array() === $value ) ? '[not set]' : self::REDACTED;
				continue;
			}
			$out[ (string) $key ] = $value;
		}
		return $out;
	}

	/**
	 * The connection status, as the plugin reports it.
	 *
	 * @return array<string, mixed>
	 */
	private static function connection(): array {
		$status = Zinn_Reseller_Admin_UI::status_for_overview();
		if ( array() === $status ) {
			return array( 'state' => 'not-applicable' );
		}
		$status = Zinn_Reseller_Connection::normalise( $status );
		unset( $status['actions'] );
		return $status;
	}

	/**
	 * The report as the text the customer sees and we receive.
	 *
	 * ⛔ Pretty-printed JSON rather than prose. It is the same bytes in the preview, in the
	 * clipboard and on the wire — so "what did it send?" has one answer, and the customer can
	 * diff it themselves if they want to.
	 *
	 * @return string
	 */
	public static function as_text(): string {
		$json = wp_json_encode( self::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * The support tab: the preview, a copy button, and the send button.
	 *
	 * @return void
	 */
	public static function render_screen(): void {
		$text = self::as_text();
		?>
		<h2><?php esc_html_e( 'Send this to Zinn® support', 'zinn-reseller' ); ?></h2>
		<p>
			<?php esc_html_e( 'This is exactly what will be sent — nothing else, and nothing hidden. Your passwords, tokens and API keys are not in it.', 'zinn-reseller' ); ?>
		</p>

		<pre class="zinn-diagnostics" tabindex="0" aria-label="<?php esc_attr_e( 'The diagnostics report that will be sent', 'zinn-reseller' ); ?>"><?php echo esc_html( $text ); ?></pre>

		<p class="zinn-tools__row">
			<button type="button" class="button" data-zinn-copy><?php esc_html_e( 'Copy to clipboard', 'zinn-reseller' ); ?></button>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Zinn_Reseller_Admin_UI::act_action() ); ?>" />
			<input type="hidden" name="zinn_action" value="zinn_diagnostics_send" />
			<?php wp_nonce_field( Zinn_Reseller_Admin_UI::act_action() ); ?>
			<?php submit_button( __( 'Send this report to Zinn® support', 'zinn-reseller' ), 'primary', 'submit', false ); ?>
			<p class="description">
				<?php esc_html_e( 'We open a support ticket for you and reply by email. If you would rather not send it, copy it above and paste it into a ticket yourself.', 'zinn-reseller' ); ?>
			</p>
		</form>

		<script>
		( function () {
			var button = document.querySelector( '[data-zinn-copy]' );
			var block = document.querySelector( '.zinn-diagnostics' );
			if ( ! button || ! block || ! navigator.clipboard ) { return; }
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( block.textContent ).then( function () {
					var was = button.textContent;
					button.textContent = <?php echo wp_json_encode( __( 'Copied', 'zinn-reseller' ) ); ?>;
					window.setTimeout( function () { button.textContent = was; }, 2000 );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Post the report to Zinn Digital.
	 *
	 * ⛔⛤ **THE FAILURE IS CLASSIFIED, NOT GUESSED (§2.57).** A plugin that answers "we could
	 * not reach Zinn" to a `401` has blamed the network for an authorisation problem the
	 * customer can actually fix, and a plugin that answers "your account has a problem" to a
	 * `503` has blamed the customer for our outage. Each class gets the sentence that names
	 * the thing the reader can do about it, and an unclassifiable failure says so rather than
	 * picking the convenient reading (§2.44).
	 *
	 * @return array<string, string> `kind` and `message`, for the settings notice.
	 */
	public static function send(): array {
		$body = wp_json_encode(
			array(
				'plugin' => 'zinn-reseller',
				'report' => self::collect(),
			)
		);

		$response = wp_remote_post(
			self::api_base() . '/v1/connector/diagnostics',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => false === $body ? '{}' : $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'kind'    => 'error',
				'message' => sprintf(
					/* translators: %s: the transport error reported by WordPress. */
					__( 'We could not reach Zinn Digital® from this server (%s). Copy the report above and open a ticket instead — nothing about your settings is wrong.', 'zinn-reseller' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			$decoded   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$reference = is_array( $decoded ) ? (string) ( $decoded['reference'] ?? '' ) : '';
			return array(
				'kind'    => 'success',
				'message' => '' === $reference
					? __( 'Sent. Zinn® support has your report and will reply by email.', 'zinn-reseller' )
					: sprintf(
						/* translators: %s: the support reference for the report just sent. */
						__( 'Sent. Zinn® support has your report — reference %s — and will reply by email.', 'zinn-reseller' ),
						$reference
					),
			);
		}

		if ( 429 === $code ) {
			return array(
				'kind'    => 'warning',
				'message' => __( 'You have sent several reports in a short time. Wait a few minutes and try again — the earlier ones did arrive.', 'zinn-reseller' ),
			);
		}

		if ( $code >= 500 ) {
			return array(
				'kind'    => 'error',
				'message' => __( 'Zinn Digital® could not accept the report just now — that is our end, not yours. Try again shortly, or copy the report above into a ticket.', 'zinn-reseller' ),
			);
		}

		return array(
			'kind'    => 'error',
			'message' => sprintf(
				/* translators: %d: the HTTP status code the server returned. */
				__( 'The report was refused (HTTP %d). Copy it above and open a ticket, quoting that number.', 'zinn-reseller' ),
				$code
			),
		);
	}

	/**
	 * Where reports are posted.
	 *
	 * ⛔ Filterable so a staging site can point elsewhere, but it defaults to production and
	 * is NEVER read from the database — a compromised option must not be able to redirect a
	 * report about a customer's server to somebody else's.
	 *
	 * @return string
	 */
	private static function api_base(): string {
		/**
		 * Filters the Zinn Digital API base used for support diagnostics.
		 *
		 * @param string $base Absolute URL with no trailing slash.
		 */
		return (string) apply_filters( 'zinn_diagnostics_api_base', 'https://api.zinndigital.com' );
	}
}
