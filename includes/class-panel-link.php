<?php
/**
 * Panel link — the `[zinn_panel_link]` shortcode.
 *
 * A logged-in client of the reseller clicks one link on the reseller's own site and lands
 * in their hosting panel, already signed in. No second password, no second account.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the sign-in link and redeems it.
 */
class Panel_Link {

	/**
	 * User meta key holding the client's Zinn® service id.
	 *
	 * ⛔ Stored per WordPress user rather than looked up by email. Matching a client by
	 * email would mean this plugin could sign anybody into any account whose email it could
	 * guess — the WooCommerce module writes this key when it provisions, and an admin can
	 * set it by hand for a client who bought before the plugin was installed.
	 */
	public const SERVICE_META = '_zinn_reseller_service_id';

	/**
	 * Register the shortcode and the redirect endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'zinn_panel_link', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_zinn_reseller_panel', array( __CLASS__, 'redirect' ) );
	}

	/**
	 * Render the link.
	 *
	 * ⛔⛔ The link points at `admin-post.php`, NOT at a sign-in URL. The sign-in URL is
	 * single-use and short-lived, so minting one while rendering a page would burn it on a
	 * page view, put it in every HTML cache the reseller's site has, and hand it to any
	 * crawler that reads the page. It is minted when the link is *clicked*, server-side,
	 * for the user who clicked it.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$service = self::service_id_for( get_current_user_id() );
		if ( '' === $service ) {
			return '';
		}
		$atts = shortcode_atts(
			array( 'label' => __( 'Manage my hosting', 'zinn-reseller' ) ),
			is_array( $atts ) ? $atts : array(),
			'zinn_panel_link'
		);
		$url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=zinn_reseller_panel' ),
			'zinn_reseller_panel'
		);
		return sprintf(
			'<a class="zinn-panel-link" href="%1$s" rel="nofollow noopener">%2$s</a>',
			esc_url( $url ),
			esc_html( (string) $atts['label'] )
		);
	}

	/**
	 * Mint a sign-in URL and send the user to it.
	 *
	 * @return void
	 */
	public static function redirect(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please sign in first.', 'zinn-reseller' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zinn_reseller_panel' );

		$service = self::service_id_for( get_current_user_id() );
		if ( '' === $service ) {
			wp_die(
				esc_html__( 'This account is not linked to a hosting service yet.', 'zinn-reseller' ),
				'',
				array( 'response' => 404 )
			);
		}

		$response = Client::post( '/v1/reseller/services/' . rawurlencode( $service ) . '/sso', array() );
		if ( is_wp_error( $response ) || empty( $response['url'] ) ) {
			$message = is_wp_error( $response )
				? $response->get_error_message()
				: __( 'The hosting platform did not return a sign-in link.', 'zinn-reseller' );
			wp_die( esc_html( $message ), '', array( 'response' => 502 ) );
		}

		// ⛔ `wp_redirect`, not `wp_safe_redirect`: the target is the hosting panel, which
		// is by design a different host from the reseller's site, and the safe variant
		// would refuse it. The URL is not user input — it came from our own API over TLS
		// with our own credential — so the allow-list the safe variant enforces is
		// answering a question that does not arise here.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( (string) $response['url'] );
		exit;
	}

	/**
	 * The Zinn® service id linked to a WordPress user, or an empty string.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function service_id_for( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::SERVICE_META, true );
	}

	/**
	 * Link a WordPress user to a Zinn® service.
	 *
	 * @param int    $user_id    WordPress user id.
	 * @param string $service_id Zinn® site id.
	 * @return void
	 */
	public static function link( int $user_id, string $service_id ): void {
		update_user_meta( $user_id, self::SERVICE_META, $service_id );
	}
}
