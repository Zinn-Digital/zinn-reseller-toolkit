<?php
/**
 * Self-hosted plugin updates from the Zinn control plane.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** The source of truth is
 * `wp/updater/class-zinn-updater.php.tpl`; `wp/bin/build-updater.php` renders it into every
 * Zinn plugin that does not carry a namespaced updater of its own, and `--check` fails the
 * build if a checked-in copy has drifted from a fresh render (§2.32 — generated code is an
 * output). Each copy differs only in its class name, text domain, transient key and error code.
 *
 * ⛔⛔ **WHY EVERY PLUGIN CARRIES ONE (D19745).** Until 2026-09-05 only three of the seven Zinn
 * plugins had an updater. The other four were published to the platform update channel at
 * 1.1.0, mirrored to their public repositories, and reached ZERO installed sites — nothing on a
 * customer's WordPress ever asked the channel about them, and the engine has no push path. A
 * release that no consumer polls is §2.38's producer with no consumer; the only symptom was
 * a Plugins screen that never showed an update.
 *
 * ⭐ Generated rather than shared, for the reason `wp/promo/` gives: a single file `require`d
 * from several plugins cannot carry several text domains, and a variable domain is refused by
 * the i18n sniff and invisible to `wp i18n make-pot`. Substituting at build time keeps every
 * `__()` argument a literal.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Delivers updates for this plugin from the Zinn control plane rather than
 * WordPress.org. Because it force-enables auto-updates, the update path is
 * defended in depth: the endpoint (`ZINN_UPDATE_URL`) must be HTTPS and is
 * fetched with `sslverify`; the response is authenticated by an HMAC over its
 * body (`X-Zinn-Signature`, keyed with `ZINN_UPDATE_SECRET`); the `package` URL
 * must be HTTPS on an allow-listed Zinn host; and the downloaded zip is verified
 * against the authenticated `package_sha256` before install. Absent config = inert.
 */
final class Zinn_Reseller_Updater {

	/**
	 * Transient key caching the last remote update payload.
	 */
	private const CACHE_KEY = 'zinn_reseller_update_payload';

	/**
	 * How long to cache a remote update check, in seconds (6 hours). A literal,
	 * not `6 * HOUR_IN_SECONDS`, so the class loads without WordPress present.
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Plugin basename, e.g. `zinn-reseller/zinn-reseller.php`.
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Plugin slug, e.g. `zinn-reseller`.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param string $file    Main plugin file (the plugin's main file).
	 * @param string $version Installed version (the plugin's `Version:` header value).
	 */
	public function __construct( string $file, string $version ) {
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'force_auto_update' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
	}

	/**
	 * Whether a remote version is newer than the installed one.
	 *
	 * @param string $remote  Remote version string.
	 * @param string $current Installed version string.
	 * @return bool
	 */
	public static function is_newer( string $remote, string $current ): bool {
		return '' !== $remote && version_compare( $remote, $current, '>' );
	}

	/**
	 * Whether a package host is acceptable: HTTPS on an allow-listed Zinn host.
	 *
	 * Pure and unit-tested; the runtime caller resolves scheme/host first.
	 *
	 * @param string $scheme        URL scheme.
	 * @param string $host          URL host.
	 * @param string $endpoint_host Host of the configured update endpoint.
	 * @return bool
	 */
	public static function is_package_host_allowed( string $scheme, string $host, string $endpoint_host ): bool {
		if ( 'https' !== strtolower( $scheme ) ) {
			return false;
		}

		$host = strtolower( $host );
		if ( '' === $host ) {
			return false;
		}

		return strtolower( $endpoint_host ) === $host
			|| 'zinndigital.com' === $host
			|| str_ends_with( $host, '.zinndigital.com' );
	}

	/**
	 * Shape a decoded update payload into a normalised release descriptor.
	 *
	 * Pure: no I/O. Returns null when the payload has no usable version/package.
	 *
	 * @param mixed $payload Decoded JSON from the update endpoint.
	 * @return array{version:string,package:string,package_sha256:string,tested:string,requires:string,requires_php:string,changelog:string,homepage:string}|null
	 */
	public static function parse_response( $payload ): ?array {
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$version = isset( $payload['version'] ) && is_scalar( $payload['version'] ) ? (string) $payload['version'] : '';
		$package = isset( $payload['package'] ) && is_scalar( $payload['package'] ) ? (string) $payload['package'] : '';

		if ( '' === $version || '' === $package ) {
			return null;
		}

		return array(
			'version'        => $version,
			'package'        => $package,
			'package_sha256' => isset( $payload['package_sha256'] ) && is_scalar( $payload['package_sha256'] ) ? (string) $payload['package_sha256'] : '',
			'tested'         => isset( $payload['tested'] ) && is_scalar( $payload['tested'] ) ? (string) $payload['tested'] : '',
			'requires'       => isset( $payload['requires'] ) && is_scalar( $payload['requires'] ) ? (string) $payload['requires'] : '',
			'requires_php'   => isset( $payload['requires_php'] ) && is_scalar( $payload['requires_php'] ) ? (string) $payload['requires_php'] : '',
			'changelog'      => isset( $payload['changelog'] ) && is_scalar( $payload['changelog'] ) ? (string) $payload['changelog'] : '',
			'homepage'       => isset( $payload['homepage'] ) && is_scalar( $payload['homepage'] ) ? (string) $payload['homepage'] : '',
		);
	}

	/**
	 * Add our plugin to WordPress's list of available updates when one exists.
	 *
	 * @param mixed $transient The `update_plugins` site transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( null === $release || ! self::is_newer( $release['version'], $this->version ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'package'      => $release['package'],
			'url'          => '' !== $release['homepage'] ? $release['homepage'] : 'https://zinndigital.com',
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);

		return $transient;
	}

	/**
	 * Provide the "View details" information for our plugin.
	 *
	 * @param mixed  $result The result object or false.
	 * @param string $action The requested action.
	 * @param object $args   Arguments (expects a `slug` property).
	 * @return mixed
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Zinn® Reseller Toolkit',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://zinndigital.com">Neil Lock — CEO, Zinn Digital® Ltd</a>',
			'homepage'      => '' !== $release['homepage'] ? $release['homepage'] : 'https://zinndigital.com',
			'download_link' => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'sections'      => array(
				'changelog' => '' !== $release['changelog'] ? $release['changelog'] : esc_html__( 'See the Zinn Digital® release notes.', 'zinn-reseller' ),
			),
		);
	}

	/**
	 * Force WordPress to auto-update this plugin.
	 *
	 * @param mixed  $update Whether to update (bool) or null.
	 * @param object $item   The update offer (expects a `plugin` property).
	 * @return mixed
	 */
	public function force_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === $this->basename ) {
			return true;
		}

		return $update;
	}

	/**
	 * Verify the downloaded package against the authenticated `package_sha256`
	 * before WordPress installs it. Only intervenes for THIS plugin's package.
	 *
	 * @param mixed               $reply      Short-circuit value (false to let WP download).
	 * @param string              $package    Package URL WP is about to download.
	 * @param object|null         $upgrader   The upgrader instance (unused).
	 * @param array<string,mixed> $hook_extra Contextual data (may contain `plugin`).
	 * @return mixed A verified local file path, a WP_Error, or the original reply.
	 */
	public function verify_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );

		$release = $this->get_release();
		if ( null === $release || '' === $release['package_sha256'] || (string) $package !== $release['package'] ) {
			return $reply;
		}
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] !== $this->basename ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( (string) $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( strtolower( $release['package_sha256'] ), strtolower( $actual ) ) ) {
			wp_delete_file( $file );
			return new WP_Error(
				'zinn_reseller_update_checksum',
				__( 'The downloaded update failed its integrity check and was not installed.', 'zinn-reseller' )
			);
		}

		return $file;
	}

	/**
	 * Drop the cached remote payload (after an install/update completes).
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Whether the self-hosted update source is configured (and HTTPS).
	 *
	 * @return bool
	 */
	private function is_configured(): bool {
		if ( ! defined( 'ZINN_UPDATE_URL' ) || ! defined( 'ZINN_UPDATE_SECRET' ) ) {
			return false;
		}

		$url    = (string) constant( 'ZINN_UPDATE_URL' );
		$secret = (string) constant( 'ZINN_UPDATE_SECRET' );

		return '' !== $secret && str_starts_with( strtolower( $url ), 'https://' );
	}

	/**
	 * Fetch (and cache) the latest release descriptor from the control plane.
	 *
	 * @return array{version:string,package:string,package_sha256:string,tested:string,requires:string,requires_php:string,changelog:string,homepage:string}|null
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $this->accept( self::parse_response( $cached ) );
		}

		$url       = (string) constant( 'ZINN_UPDATE_URL' );
		$secret    = (string) constant( 'ZINN_UPDATE_SECRET' );
		$timestamp = (string) time();
		$body      = (string) wp_json_encode(
			array(
				'slug'    => $this->slug,
				'version' => $this->version,
				'site'    => home_url( '/' ),
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'           => 'application/json',
					'Accept'                 => 'application/json',
					'X-Zinn-Cache-Timestamp' => $timestamp,
					'X-Zinn-Cache-Signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret ),
				),
				'body'      => $body,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$raw = (string) wp_remote_retrieve_body( $response );
		if ( ! $this->response_is_authentic( $raw, (string) wp_remote_retrieve_header( $response, 'x-zinn-signature' ), $secret ) ) {
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$decoded = json_decode( $raw, true );
		$release = $this->accept( self::parse_response( $decoded ) );

		set_transient( self::CACHE_KEY, null === $release ? array() : $decoded, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Reject a release whose package URL is not HTTPS on an allow-listed host.
	 *
	 * @param array{version:string,package:string,package_sha256:string,tested:string,requires:string,requires_php:string,changelog:string,homepage:string}|null $release Parsed release.
	 * @return array{version:string,package:string,package_sha256:string,tested:string,requires:string,requires_php:string,changelog:string,homepage:string}|null
	 */
	private function accept( ?array $release ): ?array {
		if ( null === $release ) {
			return null;
		}

		$parts  = wp_parse_url( $release['package'] );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';

		$endpoint      = wp_parse_url( (string) constant( 'ZINN_UPDATE_URL' ) );
		$endpoint_host = is_array( $endpoint ) && isset( $endpoint['host'] ) ? (string) $endpoint['host'] : '';

		return self::is_package_host_allowed( $scheme, $host, $endpoint_host ) ? $release : null;
	}

	/**
	 * Verify the endpoint authenticated its response by HMAC over the raw body.
	 *
	 * @param string $raw       Raw response body.
	 * @param string $signature Provided `X-Zinn-Signature` header.
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	private function response_is_authentic( string $raw, string $signature, string $secret ): bool {
		if ( '' === $signature ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );

		return hash_equals( $expected, $signature );
	}
}
