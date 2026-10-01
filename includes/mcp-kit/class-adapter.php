<?php
/**
 * Loads ONE copy of the WordPress MCP adapter for the whole site.
 *
 * Generated from wp/packages/zinn-mcp-kit/src/class-adapter.php by wp/bin/build-mcp-kit.php.
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
 * Several plugins can bundle the adapter (ours, WooCommerce, the MCP Adapter plugin itself), and
 * its classes live in ONE global namespace (`WP\MCP`), so exactly one copy may load per request.
 *
 * - A copy another plugin has ALREADY made loadable (its own autoloader registered while plugins
 *   were loading) always wins: we never replace someone else's adapter.
 * - Otherwise every Zinn copy offers itself on the shared `zinn_mcp_adapter_copies` filter while
 *   its plugin file loads, and the first kit to reach `plugins_loaded` registers the NEWEST copy
 *   offered (the same "latest wins" rule as the Jetpack autoloader and Action Scheduler).
 * - Only when a Zinn copy is the site's adapter do we switch the adapter's generic default server
 *   off: installing a page builder must not open a site-wide "run any ability" endpoint. A site
 *   that wants it installs the MCP Adapter plugin, whose copy then wins and keeps its default.
 *
 * The hooks and the constant are spelled `zinn_mcp_…` on purpose: every copy of the kit shares them.
 */
final class Adapter {

	/** The adapter's entry class. */
	public const ENTRY = '\\WP\\MCP\\Core\\McpAdapter';

	/**
	 * Offer this plugin's bundled copy. Call while the plugin file loads.
	 *
	 * @param string $vendor_dir The plugin's `vendor/wordpress` directory.
	 * @return void
	 */
	public static function offer( string $vendor_dir ): void {
		$vendor_dir = untrailingslashit( $vendor_dir );
		add_filter(
			'zinn_mcp_adapter_copies',
			static function ( $copies ) use ( $vendor_dir ) {
				$copies                = is_array( $copies ) ? $copies : array();
				$copies[ $vendor_dir ] = self::version_of( $vendor_dir );

				return $copies;
			}
		);
		add_action( 'plugins_loaded', array( self::class, 'load' ), 5 );
	}

	/**
	 * Make the adapter available and start it. Runs once per copy of the kit; the first one does
	 * the work.
	 *
	 * @return bool True when an adapter is available.
	 */
	public static function load(): bool {
		if ( self::killed() ) {
			return false;
		}
		if ( defined( 'ZINN_MCP_ADAPTER' ) ) {
			return class_exists( self::ENTRY );
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			// WordPress before 6.9 has no Abilities API, so there is nothing to serve.
			define( 'ZINN_MCP_ADAPTER', '' );
			return false;
		}
		if ( class_exists( self::ENTRY ) ) {
			// Another plugin's copy (or the MCP Adapter plugin). Use it as it is — but see
			// start_external(): we never START someone else's adapter with its default server on.
			define( 'ZINN_MCP_ADAPTER', 'external' );
			add_action( 'plugins_loaded', array( self::class, 'start_external' ), 100 );
			return true;
		} else {
			// One hook SHARED by every Zinn plugin's kit copy (that is how the newest copy is found once), so it
			// cannot carry one plugin's prefix.
			$dir = self::newest( (array) apply_filters( 'zinn_mcp_adapter_copies', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared across plugins on purpose.
			if ( '' === $dir ) {
				define( 'ZINN_MCP_ADAPTER', '' );
				return false;
			}
			self::autoload( $dir );
			define( 'ZINN_MCP_ADAPTER', $dir );
			add_filter( 'mcp_adapter_create_default_server', '__return_false', 5 );
		}
		if ( ! class_exists( self::ENTRY ) ) {
			return false;
		}
		if ( self::cli_without_mcp() ) {
			return true; // Loadable, deliberately not started: see cli_without_mcp().
		}
		$entry = self::ENTRY;
		$entry::instance();

		return true;
	}

	/**
	 * The site-wide kill switch: `define( 'ZINN_MCP_DISABLED', true );` in wp-config.php (or
	 * `wp --exec=…`) turns every Zinn plugin's MCP server off — no adapter is loaded or started,
	 * no ability is registered. For hosting operations and for an incident like PF-450.
	 *
	 * @return bool
	 */
	public static function killed(): bool {
		return defined( 'ZINN_MCP_DISABLED' ) && (bool) constant( 'ZINN_MCP_DISABLED' );
	}

	/**
	 * Is this a WP-CLI command other than `wp mcp-adapter …`?
	 *
	 * ⛔⛔ INCIDENT 2026-10-01 (found by L10, PF-450): under WP-CLI the adapter initialises on `init`
	 * for EVERY command, which fires `mcp_adapter_init`, which made the kit list the abilities, which
	 * built EVERY plugin's REST routes (`rest_get_server()`) — with WooCommerce active a plain
	 * `wp option get siteurl` peaked at 152 MB against an 83 MB baseline and died at a 128M limit.
	 * Our platform drives hosted sites through WP-CLI. A command that is not the adapter's own has
	 * no use for an MCP server, so none is started; `wp mcp-adapter serve` (the platform's MCP
	 * path) still is.
	 *
	 * @return bool
	 */
	public static function cli_without_mcp(): bool {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) || ! class_exists( '\\WP_CLI' ) || ! method_exists( '\\WP_CLI', 'get_runner' ) ) {
			return false;
		}
		$runner = \WP_CLI::get_runner();
		// ⛔ `Runner::$arguments` is PRIVATE behind `__get()` with no `__isset()`: `isset()` and `??`
		// read it as unset, every command looked like "not mcp-adapter" and `wp mcp-adapter serve`
		// answered 0 tools (caught by the harness's STDIO check). Read it through `__get()`.
		$args = is_object( $runner ) ? $runner->arguments : array();
		$args = is_array( $args ) ? $args : array();

		return 'mcp-adapter' !== (string) ( $args[0] ?? '' );
	}

	/**
	 * Start another plugin's adapter copy only when its owner has not.
	 *
	 * ⛔⛔ Measured 2026-10-01 (test-wp-compat.sh with WooCommerce, which bundles the adapter and
	 * leaves it dormant): `instance()` on Woo's copy started it with its generic default server,
	 * and `/mcp/mcp-adapter-default-server` + `mcp-adapter/discover-abilities` let a SUBSCRIBER in.
	 * The owner that STARTED its copy (the MCP Adapter plugin, Woo with its MCP feature on) has
	 * chosen its default server and is left alone; a copy nobody started is started by us, with
	 * the default server off — exactly as for our own copy.
	 *
	 * @return void
	 */
	public static function start_external(): void {
		if ( self::killed() ) {
			return;
		}
		$entry = ltrim( self::ENTRY, '\\' );
		if ( ! class_exists( $entry ) ) {
			return;
		}
		$started = true;
		try {
			$property = new \ReflectionProperty( $entry, 'instance' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true ); // Needed before PHP 8.1; deprecated in 8.5.
			}
			$started = $property->isInitialized();
		} catch ( \ReflectionException $e ) {
			$started = true; // A future adapter without the property: never second-guess its owner.
		}
		if ( ! $started ) {
			if ( self::cli_without_mcp() ) {
				return; // Never start a dormant copy for a WP-CLI command that is not the adapter's own.
			}
			add_filter( 'mcp_adapter_create_default_server', '__return_false', 5 );
		}
		$entry::instance();
	}

	/**
	 * The newest copy offered, '' when none is readable.
	 *
	 * @param array<string, string> $copies Directory => version.
	 * @return string
	 */
	public static function newest( array $copies ): string {
		$best    = '';
		$version = '';
		foreach ( $copies as $dir => $candidate ) {
			$dir = (string) $dir;
			if ( '' === (string) $candidate || ! is_readable( $dir . '/mcp-adapter/includes/Core/McpAdapter.php' ) ) {
				continue;
			}
			if ( '' === $best || version_compare( (string) $candidate, $version, '>' ) ) {
				$best    = $dir;
				$version = (string) $candidate;
			}
		}

		return $best;
	}

	/**
	 * The version a bundled copy declares (`McpAdapter::VERSION`), '' when unreadable.
	 *
	 * @param string $vendor_dir The `vendor/wordpress` directory.
	 * @return string
	 */
	public static function version_of( string $vendor_dir ): string {
		$file = $vendor_dir . '/mcp-adapter/includes/Core/McpAdapter.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of our own plugin.

		return 1 === preg_match( "/const VERSION = '([0-9][0-9A-Za-z.\-]*)'/", $head, $m ) ? $m[1] : '';
	}

	/**
	 * Register the two PSR-4 roots of a copy.
	 *
	 * @param string $dir The `vendor/wordpress` directory.
	 * @return void
	 */
	private static function autoload( string $dir ): void {
		$roots = array(
			'WP\\MCP\\'       => $dir . '/mcp-adapter/includes/',
			'WP\\McpSchema\\' => $dir . '/php-mcp-schema/src/',
		);
		spl_autoload_register(
			static function ( string $class_name ) use ( $roots ): void {
				foreach ( $roots as $prefix => $base ) {
					if ( str_starts_with( $class_name, $prefix ) ) {
						$file = $base . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
						if ( is_readable( $file ) ) {
							require_once $file;
						}
						return;
					}
				}
			}
		);
	}
}
