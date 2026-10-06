<?php
/**
 * Sign in with OAuth 2.1 from Claude, ChatGPT and other AI apps — this site is its own
 * authorization server for its MCP endpoints.
 *
 * Generated from wp/packages/zinn-mcp-kit/src/class-oauth.php by wp/bin/build-mcp-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package Zinn\Reseller\McpKit
 */

declare( strict_types = 1 );

namespace Zinn\Reseller\McpKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.1 (PKCE S256, RFC 7591 dynamic registration, Client ID Metadata Documents, RFC 8414 /
 * RFC 9728 discovery, rotating refresh tokens, RFC 7009 revocation) for the site's Zinn MCP
 * servers. Application passwords keep working exactly as before; this is a second way in.
 *
 * - The person approving is the WordPress user signed in to wp-admin, on a consent screen of this
 *   site. A token acts as that user and can do only what their capabilities allow — the same rule
 *   as an application password — and only on the MCP endpoint it was issued for.
 * - Nothing secret is stored: client secrets and tokens are kept as SHA-256 hashes.
 * - Each approval is a "connected app" on the user (the MCP panel lists them, with Disconnect).
 *   Disconnecting, or replaying a used refresh token, ends that app's tokens at once.
 *
 * Several Zinn plugins on one site each carry this kit, so exactly ONE copy runs the server
 * (the first to reach `plugins_loaded`); every copy adds its own MCP endpoint to the shared
 * `zinn_mcp_oauth_resources` filter.
 */
final class OAuth {

	public const NS            = 'zinn-mcp/v1';
	private const ACCESS       = 'zwpat';
	private const REFRESH      = 'zwprt';
	private const ACCESS_TTL   = HOUR_IN_SECONDS;
	private const REFRESH_TTL  = 90 * DAY_IN_SECONDS;
	private const CODE_TTL     = 120;
	private const REQUEST_TTL  = 600;
	private const GRANTS       = 'zinn_mcp_oauth_grants';
	private const PAGE         = 'zinn-mcp-connect';
	private const MAX_REG_HOUR = 600;
	private const CLIENT_KEY   = 'zinn_mcp_oauth_client_';
	// A registered client nobody finishes signing in with lapses after a day; one in use lives as
	// long as its refresh token (every token issued renews it), so registration cannot grow the
	// options table without bound.
	private const CLIENT_UNUSED_TTL = DAY_IN_SECONDS;

	/**
	 * Offer this plugin's MCP endpoint to the site's OAuth server.
	 *
	 * @param string $route      REST route of the MCP endpoint, e.g. `tranzly/v1/mcp`.
	 * @param string $name       Human name of the server (the plugin).
	 * @param string $capability Capability a user needs to connect it.
	 * @return void
	 */
	public static function offer( string $route, string $name, string $capability ): void {
		add_filter(
			'zinn_mcp_oauth_resources',
			static function ( $resources ) use ( $route, $name, $capability ) {
				$resources           = is_array( $resources ) ? $resources : array();
				$resources[ $route ] = array(
					'name'       => $name,
					'capability' => $capability,
				);

				return $resources;
			}
		);
		// ⛔ A plugin that boots its MCP server ON `plugins_loaded` (Zinn® Cache and the Reseller
		// Toolkit do, at priority 10) arrives after priority 6 has run: the hook never fired, so a
		// site with only that plugin had no OAuth sign-in at all (found by the access gate's
		// stale OAuth rows, 2026-10-04). Start now in that case; the first copy still wins.
		if ( did_action( 'plugins_loaded' ) ) {
			self::load();
		} else {
			add_action( 'plugins_loaded', array( self::class, 'load' ), 6 );
		}
	}

	/**
	 * Start the server (the first copy of the kit does; the rest only offered their endpoint).
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( defined( 'ZINN_MCP_OAUTH' ) || Adapter::killed() ) {
			return;
		}
		define( 'ZINN_MCP_OAUTH', __NAMESPACE__ );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 30 );
		add_filter( 'rest_post_dispatch', array( self::class, 'challenge' ), 10, 3 );
		add_action( 'parse_request', array( self::class, 'well_known' ), 0 );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_zinn_mcp_oauth_answer', array( self::class, 'answer' ) );
		add_action( 'admin_post_zinn_mcp_oauth_disconnect', array( self::class, 'disconnect' ) );
	}

	/**
	 * The endpoints that may be connected: route => name + capability.
	 *
	 * @return array<string, array{name: string, capability: string}>
	 */
	public static function resources(): array {
		$out = apply_filters( 'zinn_mcp_oauth_resources', array() );

		return is_array( $out ) ? $out : array();
	}

	/**
	 * The issuer URL, or '' when the site has no pretty permalinks (an OAuth issuer cannot carry a
	 * query string, so OAuth is unavailable there and application passwords still work).
	 *
	 * @return string
	 */
	public static function issuer(): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return '';
		}

		return untrailingslashit( rest_url( self::NS . '/oauth' ) );
	}

	/**
	 * Is OAuth sign-in available on this site?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return '' !== self::issuer() && ! empty( self::resources() );
	}

	// ── discovery ──────────────────────────────────────────────────────────────────────────

	/**
	 * RFC 8414 metadata.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata(): array {
		$issuer = self::issuer();

		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'                => $issuer . '/authorize',
			'token_endpoint'                        => $issuer . '/token',
			'registration_endpoint'                 => $issuer . '/register',
			'revocation_endpoint'                   => $issuer . '/revoke',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
			'scopes_supported'                      => array( 'mcp' ),
		);
	}

	/**
	 * RFC 9728 metadata for one MCP endpoint.
	 *
	 * @param string $route REST route.
	 * @return array<string, mixed>
	 */
	public static function resource_metadata( string $route ): array {
		$resources = self::resources();

		return array(
			'resource'                 => rest_url( $route ),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => array( 'mcp' ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => (string) ( $resources[ $route ]['name'] ?? get_bloginfo( 'name' ) ),
		);
	}

	/**
	 * The PRM URL the 401 challenge points at for a route.
	 *
	 * @param string $route REST route.
	 * @return string
	 */
	private static function resource_metadata_url( string $route ): string {
		return rest_url( self::NS . '/protected-resource/' . rawurlencode( str_replace( '/', '~', $route ) ) );
	}

	/**
	 * Answer the root `/.well-known/` paths too, for clients that only look there.
	 *
	 * @return void
	 */
	public static function well_known(): void {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '' === self::issuer() || ! str_starts_with( $path, $home . '/.well-known/oauth-' ) ) {
			return;
		}
		$rest = substr( $path, strlen( $home . '/.well-known/' ) );
		$body = null;
		if ( str_starts_with( $rest, 'oauth-authorization-server' ) ) {
			$body = self::metadata();
		} elseif ( str_starts_with( $rest, 'oauth-protected-resource' ) ) {
			$prefix = untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . '/';
			$asked  = substr( $rest, strlen( 'oauth-protected-resource' ) );
			foreach ( array_keys( self::resources() ) as $route ) {
				if ( in_array( $asked, array( '', $prefix . $route ), true ) ) {
					$body = self::resource_metadata( $route );
					break;
				}
			}
		}
		if ( null === $body ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
		exit;
	}

	// ── REST routes ────────────────────────────────────────────────────────────────────────

	/**
	 * Register the protocol endpoints. All are public by design: they ARE the sign-in.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$open = '__return_true';
		register_rest_route(
			self::NS,
			'/oauth/\.well-known/(?:openid-configuration|oauth-authorization-server)',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => self::json( self::metadata() ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			'/protected-resource/(?P<route>[A-Za-z0-9_~%.-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_resource_metadata' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'register' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/authorize',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'authorize' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'token' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'revoke' ),
				'permission_callback' => $open,
			)
		);
	}

	/**
	 * A JSON response that is never cached.
	 *
	 * @param array<string, mixed> $body   Body.
	 * @param int                  $status Status.
	 * @return \WP_REST_Response
	 */
	private static function json( array $body, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * An RFC 6749 §5.2 error.
	 *
	 * @param string $error       Code.
	 * @param string $description Sentence.
	 * @param int    $status      Status.
	 * @return \WP_REST_Response
	 */
	private static function error( string $error, string $description, int $status = 400 ): \WP_REST_Response {
		return self::json(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	/**
	 * GET protected-resource/{route}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_resource_metadata( \WP_REST_Request $request ): \WP_REST_Response {
		$route = str_replace( '~', '/', rawurldecode( (string) $request['route'] ) );
		if ( ! isset( self::resources()[ $route ] ) ) {
			return self::error( 'invalid_target', 'Unknown MCP endpoint.', 404 );
		}

		return self::json( self::resource_metadata( $route ) );
	}

	// ── clients ────────────────────────────────────────────────────────────────────────────

	/**
	 * Is this a usable redirect URI? https anywhere, http only to loopback, or an app scheme.
	 *
	 * @param mixed $uri URI.
	 * @return bool
	 */
	public static function valid_redirect( $uri ): bool {
		if ( ! is_string( $uri ) || strlen( $uri ) > 500 ) {
			return false;
		}
		$parts  = wp_parse_url( $uri );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( false === $parts || isset( $parts['fragment'] ) || isset( $parts['user'] ) || in_array( $scheme, array( 'javascript', 'data', 'file', 'vbscript', 'about', 'blob' ), true ) ) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return '' !== (string) ( $parts['host'] ?? '' );
		}
		if ( 'http' === $scheme ) {
			return in_array( (string) ( $parts['host'] ?? '' ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
		}

		return 1 === preg_match( '/^[a-z][a-z0-9+.-]{1,40}$/', $scheme );
	}

	/**
	 * Does `$uri` match one of the client's redirect URIs? Exact, or a loopback on any port.
	 *
	 * @param array<int, string> $registered Registered URIs.
	 * @param string             $uri        Asked URI.
	 * @return bool
	 */
	public static function redirect_matches( array $registered, string $uri ): bool {
		if ( in_array( $uri, $registered, true ) ) {
			return true;
		}
		$asked = wp_parse_url( $uri );
		if ( ! is_array( $asked ) || 'http' !== ( $asked['scheme'] ?? '' ) || ! in_array( (string) ( $asked['host'] ?? '' ), array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) {
			return false;
		}
		foreach ( $registered as $candidate ) {
			$reg  = wp_parse_url( $candidate );
			$same = is_array( $reg ) && 'http' === ( $reg['scheme'] ?? '' )
				&& (string) ( $reg['host'] ?? '' ) === (string) $asked['host']
				&& (string) ( $reg['path'] ?? '' ) === (string) ( $asked['path'] ?? '' );
			if ( $same ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * POST oauth/register (RFC 7591).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function register( \WP_REST_Request $request ): \WP_REST_Response {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$count = (int) get_transient( 'zinn_mcp_dcr_' . md5( $ip ) );
		if ( $count >= self::MAX_REG_HOUR ) {
			return self::error( 'invalid_request', 'Too many registrations from this address; try again in an hour.', 429 );
		}
		set_transient( 'zinn_mcp_dcr_' . md5( $ip ), $count + 1, HOUR_IN_SECONDS );
		$data = (array) $request->get_json_params();
		$uris = $data['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > 10 || count( array_filter( $uris, array( self::class, 'valid_redirect' ) ) ) !== count( $uris ) ) {
			return self::error( 'invalid_redirect_uri', 'redirect_uris must list https, loopback http or app-scheme URIs (at most 10).' );
		}
		$method = (string) ( $data['token_endpoint_auth_method'] ?? 'none' );
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			return self::error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}
		$id     = 'zwpc_' . bin2hex( random_bytes( 12 ) );
		$secret = 'none' === $method ? '' : bin2hex( random_bytes( 24 ) );
		$client = array(
			'client_id'   => $id,
			'client_name' => substr( sanitize_text_field( (string) ( $data['client_name'] ?? '' ) ), 0, 200 ),
			'redirect'    => array_values( array_map( 'strval', $uris ) ),
			'method'      => $method,
			'secret_hash' => '' === $secret ? '' : hash( 'sha256', $secret ),
			'created'     => time(),
		);
		if ( '' === $client['client_name'] ) {
			$client['client_name'] = 'AI app';
		}
		set_transient( self::CLIENT_KEY . $id, $client, self::CLIENT_UNUSED_TTL );
		$body = array(
			'client_id'                  => $id,
			'client_id_issued_at'        => $client['created'],
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);
		if ( '' !== $secret ) {
			$body['client_secret']            = $secret;
			$body['client_secret_expires_at'] = 0;
		}

		return self::json( $body, 201 );
	}

	/**
	 * Is `$id` a Client ID Metadata Document URL?
	 *
	 * @param string $id Client id.
	 * @return bool
	 */
	public static function is_cimd( string $id ): bool {
		$parts = wp_parse_url( $id );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}
		if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['port'] ) || in_array( (string) ( $parts['path'] ?? '' ), array( '', '/' ), true ) ) {
			return false;
		}
		if ( str_contains( (string) $parts['path'], '/../' ) || str_contains( (string) $parts['path'], '/./' ) ) {
			return false;
		}
		// SSRF: a public DNS name only. No IP literal; no single-label or local-only name (an
		// intranet host, localhost); a bounded length. wp_safe_remote_get() then refuses a name
		// that RESOLVES to a private or loopback address (reject_unsafe_urls).
		$host = strtolower( (string) $parts['host'] );
		if ( strlen( $id ) > 255 || ! str_contains( $host, '.' ) || 1 === preg_match( '/(^|\.)(localhost|local|internal|localdomain|home\.arpa)$/', $host ) ) {
			return false;
		}

		return false === filter_var( $host, FILTER_VALIDATE_IP ) && false === filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
	}

	/**
	 * Is this request from a connector-directory app — Claude or ChatGPT — by its metadata
	 * document, or a registered client whose every redirect is that app's? The same rule as the
	 * platform's `engine/mcp/directory.py`; such a connection is not offered tools that generate
	 * images, video or audio (docs/925 §1a).
	 *
	 * @return bool
	 */
	public static function directory_client(): bool {
		$id = (string) ( $GLOBALS['zinn_mcp_oauth_client'] ?? '' );
		if ( '' === $id ) {
			return false;
		}
		if ( in_array( $id, array( 'https://claude.ai/oauth/mcp-oauth-client-metadata', 'https://chatgpt.com/oauth/client.json' ), true ) ) {
			return true;
		}
		$client = self::client( $id );
		$uris   = is_array( $client ) ? (array) ( $client['redirect'] ?? $client['redirect_uris'] ?? array() ) : array();
		if ( array() === $uris ) {
			return false;
		}
		foreach ( $uris as $uri ) {
			$host = strtolower( (string) wp_parse_url( (string) $uri, PHP_URL_HOST ) );
			if ( 'https' !== wp_parse_url( (string) $uri, PHP_URL_SCHEME ) || ! in_array( $host, array( 'claude.ai', 'claude.com', 'chatgpt.com', 'chat.openai.com' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Test seam: set the signed-in client without a token.
	 *
	 * @param string $id Client id.
	 * @return void
	 */
	public static function set_client_for_tests( string $id ): void {
		$GLOBALS['zinn_mcp_oauth_client'] = $id;
	}

	/**
	 * The client for an id: a registered one, or a metadata document fetched (cached a day).
	 *
	 * @param string $id Client id.
	 * @return array<string, mixed>|null
	 */
	public static function client( string $id ): ?array {
		if ( str_starts_with( $id, 'zwpc_' ) && 1 === preg_match( '/^zwpc_[a-f0-9]{24}$/', $id ) ) {
			$row = get_transient( self::CLIENT_KEY . $id );

			return is_array( $row ) ? $row : null;
		}
		if ( ! self::is_cimd( $id ) ) {
			return null;
		}
		$key    = 'zinn_mcp_cimd_' . md5( $id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'refused' === $cached || false === wp_http_validate_url( $id ) ) {
			return null; // Refused recently, or WordPress's own check says it resolves somewhere unsafe.
		}
		// An unauthenticated caller decides which URL is fetched: bound how often, per address.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$count = (int) get_transient( 'zinn_mcp_cimd_n_' . md5( $ip ) );
		if ( $count >= self::MAX_REG_HOUR ) {
			return null;
		}
		set_transient( 'zinn_mcp_cimd_n_' . md5( $ip ), $count + 1, HOUR_IN_SECONDS );
		// wp_safe_remote_get refuses private and loopback addresses (reject_unsafe_urls).
		$response = wp_safe_remote_get(
			$id,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 16384,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $key, 'refused', 10 * MINUTE_IN_SECONDS ); // A dead or hostile URL is not fetched again for a while.
			return null;
		}
		$doc  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$uris = is_array( $doc ) ? ( $doc['redirect_uris'] ?? null ) : null;
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? '' ) !== $id || ! is_array( $uris ) || array() === $uris || count( array_filter( $uris, array( self::class, 'valid_redirect' ) ) ) !== count( $uris ) ) {
			return null;
		}
		if ( 'none' !== (string) ( $doc['token_endpoint_auth_method'] ?? 'none' ) ) {
			return null;
		}
		$client = array(
			'client_id'   => $id,
			'client_name' => substr( sanitize_text_field( (string) ( $doc['client_name'] ?? (string) wp_parse_url( $id, PHP_URL_HOST ) ) ), 0, 200 ),
			'redirect'    => array_values( array_map( 'strval', $uris ) ),
			'method'      => 'none',
			'secret_hash' => '',
			'verified'    => (string) wp_parse_url( $id, PHP_URL_HOST ),
		);
		set_transient( $key, $client, DAY_IN_SECONDS );

		return $client;
	}

	/**
	 * The client a token/revoke request authenticates as, or an error response.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private static function calling_client( \WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'client_id' );
		$secret = (string) $request->get_param( 'client_secret' );
		$basic  = (string) $request->get_header( 'authorization' );
		if ( str_starts_with( $basic, 'Basic ' ) ) {
			$pair             = (string) base64_decode( substr( $basic, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 6749 §2.3.1 client credentials.
			list( $id, $sec ) = array_pad( explode( ':', $pair, 2 ), 2, '' );
			$id               = rawurldecode( $id );
			$secret           = rawurldecode( $sec );
		}
		$client = self::client( $id );
		if ( null === $client ) {
			return self::error( 'invalid_client', 'Unknown client_id.', 401 );
		}
		if ( 'none' !== $client['method'] && ( '' === $secret || ! hash_equals( (string) $client['secret_hash'], hash( 'sha256', $secret ) ) ) ) {
			return self::error( 'invalid_client', 'Client authentication failed.', 401 );
		}

		return $client;
	}

	// ── authorize + consent ────────────────────────────────────────────────────────────────

	/**
	 * GET oauth/authorize — validate, park the request, send the browser to the consent screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$client   = self::client( (string) $request->get_param( 'client_id' ) );
		$redirect = (string) $request->get_param( 'redirect_uri' );
		if ( null === $client || ! self::redirect_matches( $client['redirect'], $redirect ) ) {
			// Never redirect to an unvalidated URI: that would be an open redirector.
			return self::error( 'invalid_request', 'Unknown client or unregistered redirect_uri.' );
		}
		$state     = substr( (string) $request->get_param( 'state' ), 0, 500 );
		$challenge = (string) $request->get_param( 'code_challenge' );
		$resource  = untrailingslashit( (string) $request->get_param( 'resource' ) );
		$route     = self::route_for( $resource );
		$fail      = '';
		if ( 'code' !== $request->get_param( 'response_type' ) ) {
			$fail = 'unsupported_response_type';
		} elseif ( 'S256' !== $request->get_param( 'code_challenge_method' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) {
			$fail = 'invalid_request';
		} elseif ( null === $route ) {
			$fail = 'invalid_target';
		}
		if ( '' !== $fail ) {
			return self::redirect(
				add_query_arg(
					rawurlencode_deep(
						array(
							'error' => $fail,
							'state' => $state,
							'iss'   => self::issuer(),
						)
					),
					$redirect
				)
			);
		}
		$id = bin2hex( random_bytes( 16 ) );
		set_transient(
			'zinn_mcp_req_' . $id,
			array(
				'client_id' => $client['client_id'],
				'redirect'  => $redirect,
				'state'     => $state,
				'challenge' => $challenge,
				'route'     => $route,
			),
			self::REQUEST_TTL
		);

		return self::redirect( add_query_arg( array( 'request' => $id ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
	}

	/**
	 * The MCP route a `resource` names. A client that sends none gets the only one, when the site
	 * has exactly one; otherwise it must say which.
	 *
	 * @param string $target The RFC 8707 resource.
	 * @return string|null
	 */
	private static function route_for( string $target ): ?string {
		$routes = array_keys( self::resources() );
		if ( '' === $target ) {
			return 1 === count( $routes ) ? $routes[0] : null;
		}
		foreach ( $routes as $route ) {
			if ( untrailingslashit( rest_url( $route ) ) === $target ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * A 302 as a REST response.
	 *
	 * @param string $url Where to.
	 * @return \WP_REST_Response
	 */
	private static function redirect( string $url ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The hidden consent screen (reachable only from an authorize redirect).
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( '', __( 'Connect an AI app', 'zinn-reseller' ), '', 'read', self::PAGE, array( self::class, 'consent' ) );
	}

	/**
	 * Render the consent screen.
	 *
	 * @return void
	 */
	public static function consent(): void {
		$id      = isset( $_GET['request'] ) ? sanitize_key( wp_unslash( $_GET['request'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the request id is an unguessable capability; the answer form carries a nonce.
		$pending = get_transient( 'zinn_mcp_req_' . $id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Connect an AI app', 'zinn-reseller' ) . '</h1>';
		if ( ! is_array( $pending ) ) {
			echo '<p>' . esc_html__( 'This connection request has expired. Go back to the app and start connecting again.', 'zinn-reseller' ) . '</p></div>';
			return;
		}
		$client   = self::client( (string) $pending['client_id'] );
		$resource = self::resources()[ $pending['route'] ] ?? null;
		if ( null === $client || null === $resource ) {
			echo '<p>' . esc_html__( 'This connection request has expired. Go back to the app and start connecting again.', 'zinn-reseller' ) . '</p></div>';
			return;
		}
		$app  = (string) $client['client_name'];
		$host = (string) wp_parse_url( (string) $pending['redirect'], PHP_URL_HOST );
		$user = wp_get_current_user();
		echo '<div class="card" style="max-inline-size:40rem">';
		/* translators: 1: the AI app's name, 2: the plugin's MCP server name, 3: the WordPress user's display name. */
		echo '<p>' . esc_html( sprintf( __( '%1$s is asking to use %2$s on this site as %3$s. It can do only what your WordPress account is allowed to do, and you can disconnect it at any time from the AI agents (MCP) settings.', 'zinn-reseller' ), $app, (string) $resource['name'], $user->display_name ) ) . '</p>';
		if ( ! empty( $client['verified'] ) ) {
			/* translators: 1: the AI app's name, 2: a domain name. */
			echo '<p>' . esc_html( sprintf( __( '%1$s identified itself from %2$s.', 'zinn-reseller' ), $app, (string) $client['verified'] ) ) . '</p>';
		} else {
			/* translators: 1: the AI app's name, 2: a domain name. */
			echo '<p>' . esc_html( sprintf( __( '%1$s registered itself automatically. Only continue if you started this from %2$s.', 'zinn-reseller' ), $app, $host ) ) . '</p>';
		}
		$allowed = current_user_can( (string) $resource['capability'] ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- the host plugin names a core capability.
		if ( ! $allowed ) {
			echo '<p><strong>' . esc_html__( 'Your account cannot use these tools on this site. Ask an administrator.', 'zinn-reseller' ) . '</strong></p>';
		}
		/* translators: %s: a domain name. */
		echo '<p class="description">' . esc_html( sprintf( __( 'After you answer you will go back to %s.', 'zinn-reseller' ), $host ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'zinn_mcp_oauth_' . $id );
		echo '<input type="hidden" name="action" value="zinn_mcp_oauth_answer" /><input type="hidden" name="request" value="' . esc_attr( $id ) . '" />';
		/* translators: %s: the AI app's name. */
		echo '<p><button type="submit" name="answer" value="allow" class="button button-primary"' . disabled( ! $allowed, true, false ) . '>' . esc_html( sprintf( __( 'Allow %s', 'zinn-reseller' ), $app ) ) . '</button> '; // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- every part is escaped (esc_html) or fixed markup (disabled() returns a constant attribute); re-check if a value is ever echoed unescaped here.
		echo '<button type="submit" name="answer" value="deny" class="button">' . esc_html__( 'Do not allow', 'zinn-reseller' ) . '</button></p>';
		echo '</form></div></div>';
	}

	/**
	 * Handle the person's answer (admin-post).
	 *
	 * @return void
	 */
	public static function answer(): void {
		$id = isset( $_POST['request'] ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '';
		check_admin_referer( 'zinn_mcp_oauth_' . $id );
		$pending = get_transient( 'zinn_mcp_req_' . $id );
		if ( ! is_array( $pending ) || ! delete_transient( 'zinn_mcp_req_' . $id ) ) {
			wp_die( esc_html__( 'This connection request has expired. Go back to the app and start connecting again.', 'zinn-reseller' ) );
		}
		$resource = self::resources()[ $pending['route'] ] ?? null;
		$params   = array(
			'state' => $pending['state'],
			'iss'   => self::issuer(),
		);
		$allow    = isset( $_POST['answer'] ) && 'allow' === sanitize_key( wp_unslash( $_POST['answer'] ) );
		if ( $allow && null !== $resource && current_user_can( (string) $resource['capability'] ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- the host plugin names a core capability.
			$code = bin2hex( random_bytes( 24 ) );
			set_transient(
				'zinn_mcp_code_' . hash( 'sha256', $code ),
				array(
					'client_id' => $pending['client_id'],
					'redirect'  => $pending['redirect'],
					'challenge' => $pending['challenge'],
					'route'     => $pending['route'],
					'user'      => get_current_user_id(),
				),
				self::CODE_TTL
			);
			$params['code'] = $code;
		} else {
			$params['error'] = 'access_denied';
		}
		$target = add_query_arg( rawurlencode_deep( $params ), (string) $pending['redirect'] );
		$host   = (string) wp_parse_url( $target, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', static fn( $hosts ) => array_merge( (array) $hosts, array( $host ) ) );
		wp_safe_redirect( $target );
		exit;
	}

	// ── tokens ─────────────────────────────────────────────────────────────────────────────

	/**
	 * POST oauth/token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		$client = self::calling_client( $request );
		if ( $client instanceof \WP_REST_Response ) {
			return $client;
		}
		$grant = (string) $request->get_param( 'grant_type' );
		if ( 'authorization_code' === $grant ) {
			$key  = 'zinn_mcp_code_' . hash( 'sha256', (string) $request->get_param( 'code' ) );
			$code = get_transient( $key );
			// Single use: whoever deletes it first owns it.
			if ( ! is_array( $code ) || ! delete_transient( $key ) ) {
				return self::error( 'invalid_grant', 'The authorization code is invalid or has expired.' );
			}
			if ( $code['client_id'] !== $client['client_id'] || $code['redirect'] !== (string) $request->get_param( 'redirect_uri' ) ) {
				return self::error( 'invalid_grant', 'The code was issued to a different client or redirect_uri.' );
			}
			$verifier = (string) $request->get_param( 'code_verifier' );
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256 is base64url of a SHA-256.
			if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || ! hash_equals( (string) $code['challenge'], $expected ) ) {
				return self::error( 'invalid_grant', 'PKCE verification failed.' );
			}
			$family            = bin2hex( random_bytes( 12 ) );
			$grants            = self::grants( (int) $code['user'] );
			$grants[ $family ] = array(
				'client_id'   => $client['client_id'],
				'client_name' => $client['client_name'],
				'route'       => $code['route'],
				'created'     => time(),
				'last_used'   => 0,
			);
			update_user_meta( (int) $code['user'], self::GRANTS, $grants );

			return self::json( self::issue( (int) $code['user'], $family, (string) $code['route'], (string) $client['client_id'] ) );
		}
		if ( 'refresh_token' === $grant ) {
			$parts = self::split( (string) $request->get_param( 'refresh_token' ), self::REFRESH );
			$found = null === $parts ? array() : get_users(
				array(
					'meta_key' => 'zinn_mcp_rt_' . $parts[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key is indexed; one row.
					'number'   => 1,
					'fields'   => 'ID',
				)
			);
			$user  = (int) ( $found[0] ?? 0 );
			$row   = $user ? get_user_meta( $user, 'zinn_mcp_rt_' . $parts[0], true ) : null;
			if ( ! is_array( $row ) || ! hash_equals( (string) $row['hash'], hash( 'sha256', (string) $parts[1] ) ) || $row['client_id'] !== $client['client_id'] ) {
				return self::error( 'invalid_grant', 'The refresh token is invalid.' );
			}
			$grants = self::grants( $user );
			if ( ! empty( $row['used'] ) ) {
				// A replayed refresh token: somebody holds a copy. End the whole connection.
				unset( $grants[ $row['family'] ] );
				update_user_meta( $user, self::GRANTS, $grants );
				self::forget_refresh_rows( $user, (string) $row['family'] );
				return self::error( 'invalid_grant', 'The refresh token was already used; the connection is revoked.' );
			}
			if ( ! isset( $grants[ $row['family'] ] ) || (int) $row['exp'] < time() ) {
				delete_user_meta( $user, 'zinn_mcp_rt_' . $parts[0] );
				return self::error( 'invalid_grant', 'The refresh token has expired or the app was disconnected.' );
			}
			// Keep exactly one spent row per connection (this one, for replay detection); older
			// spent rows go, or an hourly refresh would add a row an hour for as long as it lives.
			self::forget_refresh_rows( $user, (string) $row['family'], $parts[0] );
			$row['used'] = true;
			update_user_meta( $user, 'zinn_mcp_rt_' . $parts[0], $row );

			return self::json( self::issue( $user, (string) $row['family'], (string) $row['route'], (string) $client['client_id'] ) );
		}

		return self::error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	/**
	 * Mint an access + refresh pair for a user's connected app.
	 *
	 * @param int    $user      User.
	 * @param string $family    Connected-app id.
	 * @param string $route     MCP route the tokens are bound to.
	 * @param string $client_id Client.
	 * @return array<string, mixed>
	 */
	private static function issue( int $user, string $family, string $route, string $client_id ): array {
		if ( str_starts_with( $client_id, 'zwpc_' ) ) {
			$registered = get_transient( self::CLIENT_KEY . $client_id );
			if ( is_array( $registered ) ) {
				set_transient( self::CLIENT_KEY . $client_id, $registered, self::REFRESH_TTL );
			}
		}
		$a_lookup = bin2hex( random_bytes( 8 ) );
		$a_secret = bin2hex( random_bytes( 24 ) );
		$r_lookup = bin2hex( random_bytes( 8 ) );
		$r_secret = bin2hex( random_bytes( 24 ) );
		set_transient(
			'zinn_mcp_at_' . $a_lookup,
			array(
				'hash'   => hash( 'sha256', $a_secret ),
				'user'   => $user,
				'family' => $family,
				'route'  => $route,
				'exp'    => time() + self::ACCESS_TTL,
			),
			self::ACCESS_TTL
		);
		update_user_meta(
			$user,
			'zinn_mcp_rt_' . $r_lookup,
			array(
				'hash'      => hash( 'sha256', $r_secret ),
				'client_id' => $client_id,
				'family'    => $family,
				'route'     => $route,
				'exp'       => time() + self::REFRESH_TTL,
				'used'      => false,
			)
		);

		return array(
			'access_token'  => self::ACCESS . '_' . $a_lookup . '_' . $a_secret,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => self::REFRESH . '_' . $r_lookup . '_' . $r_secret,
			'scope'         => 'mcp',
		);
	}

	/**
	 * Split `scheme_prefix_secret` into [prefix, secret].
	 *
	 * @param string $token  Token.
	 * @param string $scheme Scheme.
	 * @return array{0: string, 1: string}|null
	 */
	private static function split( string $token, string $scheme ): ?array {
		if ( 1 !== preg_match( '/^' . $scheme . '_([a-f0-9]{16})_([a-f0-9]{48})$/', $token, $m ) ) {
			return null;
		}

		return array( $m[1], $m[2] );
	}

	/**
	 * A user's connected apps.
	 *
	 * @param int $user User.
	 * @return array<string, array<string, mixed>>
	 */
	public static function grants( int $user ): array {
		$grants = get_user_meta( $user, self::GRANTS, true );

		return is_array( $grants ) ? $grants : array();
	}

	/**
	 * Delete a connection's refresh-token rows: all of them, or (with `$keep`) every SPENT row but
	 * that one. Unspent rows are kept unless the whole connection goes.
	 *
	 * @param int    $user   User.
	 * @param string $family Connected-app id.
	 * @param string $keep   A row prefix to keep, or '' to drop the connection's rows entirely.
	 * @return int How many rows were deleted.
	 */
	public static function forget_refresh_rows( int $user, string $family, string $keep = '' ): int {
		$gone = 0;
		foreach ( array_keys( (array) get_user_meta( $user ) ) as $key ) {
			$key = (string) $key;
			if ( ! str_starts_with( $key, 'zinn_mcp_rt_' ) || 'zinn_mcp_rt_' . $keep === $key ) {
				continue;
			}
			$row = get_user_meta( $user, $key, true ); // WordPress unserializes its own meta; we never do.
			if ( ! is_array( $row ) || (string) ( $row['family'] ?? '' ) !== $family ) {
				continue;
			}
			if ( '' !== $keep && empty( $row['used'] ) ) {
				continue;
			}
			delete_user_meta( $user, $key );
			++$gone;
		}

		return $gone;
	}

	/**
	 * POST oauth/revoke (RFC 7009): either token disconnects the app. Unknown tokens are fine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$client = self::calling_client( $request );
		if ( $client instanceof \WP_REST_Response ) {
			return $client;
		}
		$token = (string) $request->get_param( 'token' );
		$user  = 0;
		$fam   = '';
		$a     = self::split( $token, self::ACCESS );
		$r     = self::split( $token, self::REFRESH );
		if ( null !== $a ) {
			$row = get_transient( 'zinn_mcp_at_' . $a[0] );
			if ( is_array( $row ) && hash_equals( (string) $row['hash'], hash( 'sha256', $a[1] ) ) ) {
				$user = (int) $row['user'];
				$fam  = (string) $row['family'];
			}
		} elseif ( null !== $r ) {
			$found = get_users(
				array(
					'meta_key' => 'zinn_mcp_rt_' . $r[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key is indexed; one row.
					'number'   => 1,
					'fields'   => 'ID',
				)
			);
			$user  = (int) ( $found[0] ?? 0 );
			$row   = $user ? get_user_meta( $user, 'zinn_mcp_rt_' . $r[0], true ) : null;
			$fam   = is_array( $row ) && hash_equals( (string) $row['hash'], hash( 'sha256', $r[1] ) ) ? (string) $row['family'] : '';
		}
		if ( $user && '' !== $fam ) {
			$grants = self::grants( $user );
			if ( isset( $grants[ $fam ] ) && $grants[ $fam ]['client_id'] === $client['client_id'] ) {
				unset( $grants[ $fam ] );
				update_user_meta( $user, self::GRANTS, $grants );
				self::forget_refresh_rows( $user, $fam );
			}
		}

		return self::json( array() );
	}

	// ── using a token ──────────────────────────────────────────────────────────────────────

	/**
	 * Filter `determine_current_user`: a valid access token on the MCP endpoint it was issued for
	 * signs the request in as its user. Anywhere else it is ignored.
	 *
	 * @param int|false $user_id What earlier filters decided.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$header = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}
		if ( ! str_starts_with( $header, 'Bearer ' . self::ACCESS . '_' ) ) {
			return $user_id;
		}
		$parts = self::split( substr( $header, 7 ), self::ACCESS );
		$row   = null === $parts ? null : get_transient( 'zinn_mcp_at_' . $parts[0] );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['hash'], hash( 'sha256', (string) $parts[1] ) ) || (int) $row['exp'] < time() ) {
			return $user_id;
		}
		if ( ! self::requesting( (string) $row['route'] ) ) {
			return $user_id; // Bound to its MCP endpoint: not a key to the rest of the REST API.
		}
		$grants = self::grants( (int) $row['user'] );
		if ( ! isset( $grants[ $row['family'] ] ) ) {
			return $user_id; // The app was disconnected.
		}
		// A GLOBAL, not a static: each plugin carries its own copy of this class, and only the first
		// copy's filter signs the request in — the other copies must still see which app it is.
		$GLOBALS['zinn_mcp_oauth_client'] = (string) ( $grants[ $row['family'] ]['client_id'] ?? '' );
		if ( time() - (int) $grants[ $row['family'] ]['last_used'] > 300 ) {
			$grants[ $row['family'] ]['last_used'] = time();
			update_user_meta( (int) $row['user'], self::GRANTS, $grants );
		}

		return (int) $row['user'];
	}

	/**
	 * Is this request for that REST route?
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	private static function requesting( string $route ): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$want = untrailingslashit( (string) wp_parse_url( rest_url( $route ), PHP_URL_PATH ) );
		$qr   = isset( $_GET['rest_route'] ) ? untrailingslashit( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing, not a form.

		return $path === $want || '/' . $route === $qr;
	}

	/**
	 * A 401 from an MCP endpoint points the client at its OAuth metadata (RFC 9728 §5.1).
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public static function challenge( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || 401 !== $response->get_status() || '' === self::issuer() ) {
			return $response;
		}
		$route = ltrim( (string) $request->get_route(), '/' );
		if ( isset( self::resources()[ $route ] ) ) {
			$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . self::resource_metadata_url( $route ) . '"' );
		}

		return $response;
	}

	// ── the panel ──────────────────────────────────────────────────────────────────────────

	/**
	 * The current user's connected apps, for the MCP panel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function connected(): array {
		$out = array();
		foreach ( self::grants( get_current_user_id() ) as $family => $grant ) {
			$out[] = array( 'id' => (string) $family ) + $grant;
		}

		return $out;
	}

	/**
	 * Disconnect one of my apps (admin-post).
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		$family = isset( $_POST['app'] ) ? sanitize_key( wp_unslash( $_POST['app'] ) ) : '';
		check_admin_referer( 'zinn_mcp_disconnect_' . $family );
		$grants = self::grants( get_current_user_id() );
		unset( $grants[ $family ] );
		update_user_meta( get_current_user_id(), self::GRANTS, $grants );
		self::forget_refresh_rows( get_current_user_id(), $family );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
