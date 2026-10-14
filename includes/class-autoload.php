<?php
/**
 * Reads a Zinn® Reseller Toolkit class from disk the first time something uses it (stage PERF3, Q206; this copy CONSENT1, Q226).
 *
 * @package Zinn\Reseller
 */

declare( strict_types = 1 );

namespace Zinn\Reseller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Measured 2026-10-13 (PERF3): the start `require_once`d every class file whether the request
 * used it or not. A class the start no longer requires is in the generated map
 * (includes/classmap.php, wp/bin/build-plugin-classmap.php) and is read when first used.
 *
 * Only a file that declares and does nothing else, in this plugin's own namespace, free code and
 * not a rendered kit, is mapped: whatever still requires a file keeps its require, so nothing is
 * loaded later than its first use or earlier than it was loadable before.
 */
final class Autoload {

	/**
	 * Class => path relative to the plugin directory.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $map = null;

	/**
	 * Register once.
	 *
	 * @return void
	 */
	public static function register(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load one class of ours, if the map knows it.
	 *
	 * @param string $name Fully-qualified class name.
	 * @return void
	 */
	public static function load( string $name ): void {
		if ( 0 !== strncmp( $name, __NAMESPACE__ . '\\', strlen( __NAMESPACE__ ) + 1 ) ) {
			return;
		}
		$map = self::map();
		if ( isset( $map[ $name ] ) ) {
			require_once dirname( __DIR__ ) . '/' . $map[ $name ];
		}
	}

	/**
	 * The map.
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		if ( null === self::$map ) {
			$map       = require __DIR__ . '/classmap.php';
			self::$map = is_array( $map ) ? $map : array();
		}

		return self::$map;
	}
}
