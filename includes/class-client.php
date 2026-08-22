<?php
/**
 * The API client — every call this plugin makes to the Zinn® platform.
 *
 * ⛔ ONE class, and every module goes through it. Three modules each rolling their own
 * `wp_remote_get` is three places the API key can leak into a log, three timeout policies
 * and three ideas of what an error looks like — and only one of them would ever be fixed.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

/**
 * A thin, cached HTTP client for the Zinn® public API.
 */
class Client {

	/**
	 * How long a successful catalogue-style GET is cached, in seconds.
	 *
	 * Domain availability and plan lists change slowly and a shop page can be hit hard.
	 * Five minutes keeps a burst of visitors off the API without ever showing a price
	 * that is a working day old.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Seconds to wait for the API. Deliberately short: this runs inside a page render,
	 * and a shop that hangs for 30 seconds because our API is slow is a worse outcome
	 * for the reseller than a search box that says it could not reach us.
	 */
	private const TIMEOUT = 8;

	/**
	 * Perform a GET and return the decoded body.
	 *
	 * @param string               $path  API path beginning with `/v1/`.
	 * @param array<string,scalar> $query Query parameters.
	 * @param bool                 $cache Whether a successful response may be cached.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get( string $path, array $query = array(), bool $cache = true ) {
		$url = self::url( $path, $query );

		if ( $cache ) {
			$key    = 'zinn_res_' . md5( $url );
			$cached = get_transient( $key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get( $url, self::args() );
		$decoded  = self::decode( $response );

		if ( $cache && ! is_wp_error( $decoded ) ) {
			set_transient( 'zinn_res_' . md5( $url ), $decoded, self::CACHE_TTL );
		}
		return $decoded;
	}

	/**
	 * Perform a POST.
	 *
	 * ⛔ Never cached, and it carries an `Idempotency-Key` the caller supplies. The one
	 * POST this plugin makes in anger is "provision hosting for this paid order", and
	 * WooCommerce fires its payment hooks more than once in ordinary operation — a gateway
	 * retry, an admin re-sending an order, a webhook arriving twice. Without the key the
	 * client is billed for the second site.
	 *
	 * @param string              $path            API path beginning with `/v1/`.
	 * @param array<string,mixed> $body            JSON body.
	 * @param string              $idempotency_key Stable key for this logical operation.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function post( string $path, array $body, string $idempotency_key = '' ) {
		$args                            = self::args();
		$args['method']                  = 'POST';
		$args['body']                    = wp_json_encode( $body );
		$args['headers']['Content-Type'] = 'application/json';
		if ( '' !== $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}
		return self::decode( wp_remote_post( self::url( $path ), $args ) );
	}

	/**
	 * Whether the plugin has an API key at all.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== Settings::api_key();
	}

	/**
	 * Build the absolute URL for an API path.
	 *
	 * @param string               $path  API path beginning with `/v1/`.
	 * @param array<string,scalar> $query Query parameters.
	 * @return string
	 */
	private static function url( string $path, array $query = array() ): string {
		$url = untrailingslashit( Settings::api_base() ) . $path;
		return empty( $query ) ? $url : add_query_arg( array_map( 'rawurlencode', $query ), $url );
	}

	/**
	 * Request arguments, including the bearer credential.
	 *
	 * @return array<string,mixed>
	 */
	private static function args(): array {
		return array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . Settings::api_key(),
				'Accept'        => 'application/json',
				// So we can tell a reseller's WordPress site apart from their WHMCS box in
				// our own logs when they ask us why something did not provision.
				'User-Agent'    => 'ZinnResellerToolkit/' . ZINN_RESELLER_VERSION . '; ' . home_url( '/' ),
			),
		);
	}

	/**
	 * Turn a `wp_remote_*` result into decoded data or a `WP_Error`.
	 *
	 * ⛔⛔ A non-2xx response becomes a `WP_Error` carrying the API's OWN message. The
	 * platform's errors are written to be shown to a person ("This service cannot be
	 * suspended from its current state"), and replacing them with "Something went wrong"
	 * would throw away the only useful half of the response. ⛔ The status code travels
	 * with it, because a 401 (your key is wrong) and a 409 (the site is not in that state)
	 * need completely different actions from the reseller.
	 *
	 * @param array<string,mixed>|\WP_Error $response Raw `wp_remote_*` result.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}
		$message = '';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$message = (string) $body['error']['message'];
		}
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code returned by the hosting API. */
				__( 'The hosting platform answered with status %d.', 'zinn-reseller' ),
				$code
			);
		}
		return new \WP_Error( 'zinn_reseller_api', $message, array( 'status' => $code ) );
	}
}
