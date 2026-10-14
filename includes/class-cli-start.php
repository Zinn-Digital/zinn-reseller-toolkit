<?php
/**
 * When Zinn® Reseller Toolkit starts: at once, or — in a WP-CLI command — right after WordPress
 * has loaded (stage PERF3, Q206; this copy CONSENT1, Q226).
 *
 * @package Zinn\Reseller
 */

declare( strict_types = 1 );

namespace Zinn\Reseller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ Measured 2026-10-13 (P13 and PERF3, WordPress 7.1.3 / PHP 8.4, WooCommerce + the six hosted
 * plugins): WP-CLI loads WordPress under PHP's own `memory_limit` (128 MB on our hosting) and lifts
 * it only AFTER `wp-settings.php` has finished (WP_CLI\Runner::load_wordpress). `wp eval` needed
 * 126 MB, so one more plugin or one WooCommerce update would break every WP-CLI command and the
 * stdio MCP server — how the platform drives every hosted site. This plugin's full start was about 6 MB
 * of that window (20 files) and WP-CLI never needs it while WordPress loads.
 *
 * So under WP-CLI the start runs on WP-CLI's `after_wp_load` — after the limit is lifted, before
 * the command is looked up — and then REPLAYS, for the callbacks the start added, every WordPress
 * action that already fired while it loaded (`plugins_loaded`, `init`, `wp_loaded` …, see REPLAY),
 * in WordPress's order and by priority. The command itself (an eval, a cron event, an Action
 * Scheduler action, an MCP tool) runs after that, so every hook is in place. The front end,
 * wp-admin, REST and wp-cron.php start exactly as before.
 *
 * ⭐ Every Zinn plugin that starts this way (Page Builder Sandwich, Tranzly, Zinn® Chat, Zinn® Cache,
 * Zinn® Reseller Toolkit) puts its
 * start on ONE shared queue (`zinn_cli_deferred_starts`) and the first copy runs them all, in the
 * order the plugins were read, and replays them together: callbacks of different plugins on the
 * same action run interleaved by priority, exactly as when they start at once. The copies are
 * kept identical apart from the namespace and the plugin's name (PerfCliStartTest).
 */
final class Cli_Start {

	/**
	 * The queue every copy shares (spelled `zinn_…` on purpose: it is not one plugin's hook).
	 */
	public const QUEUE = 'zinn_cli_deferred_starts';

	/**
	 * Actions WordPress fires while WP-CLI loads it that a start hooks into. A fired action that is
	 * NOT here and that a start hooked is reported by missed() (the real-site harness asserts it
	 * is empty), never silently skipped.
	 */
	public const REPLAY = array(
		'plugins_loaded',
		'setup_theme',
		'after_setup_theme',
		'set_current_user',
		'init',
		'widgets_init',
		'cli_init',
		'wp_loaded',
		'rest_api_init',
		'action_scheduler_pre_init',
		'action_scheduler_init',
		'woocommerce_loaded',
		'woocommerce_init',
		'before_woocommerce_init',
		'wp_abilities_api_categories_init',
		'wp_abilities_api_init',
	);

	/**
	 * Fired actions whose callbacks a deferred start added but were not replayed (diagnostics).
	 *
	 * @var array<int, string>
	 */
	private static array $missed = array();

	/**
	 * Was this plugin's start deferred in this request?
	 *
	 * @var bool
	 */
	private static bool $deferred = false;

	/**
	 * Has the deferred start run?
	 *
	 * @var bool
	 */
	private static bool $started = false;

	/**
	 * Start now, or put the start on the shared queue for WP-CLI's `after_wp_load`.
	 *
	 * @param callable $start Starts the plugin (bootstrap.php's start).
	 * @return void
	 */
	public static function start( callable $start ): void {
		if ( ! self::defer() ) {
			call_user_func( $start );
			return;
		}
		self::$deferred = true;
		$first          = false === has_filter( self::QUEUE );
		add_filter(
			'zinn_cli_deferred_starts', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- self::QUEUE, shared by every Zinn plugin's copy on purpose (see the class comment).
			static function ( $starts ) use ( $start ): array {
				$starts   = is_array( $starts ) ? $starts : array();
				$starts[] = static function () use ( $start ): void {
					call_user_func( $start );
					self::$started = true;
				};

				return $starts;
			}
		);
		if ( $first ) {
			\WP_CLI::add_hook( 'after_wp_load', array( self::class, 'run' ) );
		}
	}

	/**
	 * Should this request start the plugin after WordPress has loaded?
	 *
	 * Only under WP-CLI, only while WordPress is still loading (a plugin file read later —
	 * `wp plugin activate` — starts at once, as before), only with a WP-CLI that has the hook,
	 * and not when ZINN_CLI_START_AT_ONCE is set.
	 *
	 * @return bool
	 */
	public static function defer(): bool {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) || ! class_exists( '\WP_CLI' ) || ! method_exists( '\WP_CLI', 'add_hook' ) ) {
			return false;
		}
		if ( did_action( 'wp_loaded' ) ) {
			return false;
		}

		// `define( 'ZINN_CLI_START_AT_ONCE', true );` (wp-config.php, or `wp --exec=…`) starts every
		// Zinn plugin that defers at once under WP-CLI too, as before — for hosting operations, and
		// for the harness that compares a deferred start with an immediate one.
		return ! ( defined( 'ZINN_CLI_START_AT_ONCE' ) && constant( 'ZINN_CLI_START_AT_ONCE' ) );
	}

	/**
	 * Run every queued start (WP-CLI's `after_wp_load`), then replay what they hooked.
	 *
	 * @return void
	 */
	public static function run(): void {
		$starts = (array) apply_filters( 'zinn_cli_deferred_starts', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- self::QUEUE, shared, see start().
		remove_all_filters( self::QUEUE );
		self::resume(
			static function () use ( $starts ): void {
				foreach ( $starts as $start ) {
					if ( is_callable( $start ) ) {
						call_user_func( $start );
					}
				}
			}
		);
	}

	/**
	 * Start, then run what the start hooked into actions that already fired.
	 *
	 * Replayed until nothing is left: an action WordPress fires INSIDE another one (WooCommerce
	 * fires `woocommerce_init` during `init`) can add a callback to the outer action after the outer
	 * one was replayed, so every pass runs what the last one left. Then the late registrations are
	 * caught up (catch_up()).
	 *
	 * @param callable $start Starts the plugin(s).
	 * @return void
	 */
	public static function resume( callable $start ): void {
		global $wp_filter;
		$fired  = self::fired();
		$before = array();
		foreach ( $fired as $hook ) {
			$before[ $hook ] = self::ids( $hook );
		}
		$block_args = self::ids( 'register_block_type_args' );
		$blocks     = class_exists( '\WP_Block_Type_Registry' ) ? array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) : array();
		call_user_func( $start );
		$called = $before;
		$ran    = array();
		do {
			$runs = 0;
			foreach ( $fired as $hook ) {
				if ( isset( $wp_filter[ $hook ] ) && in_array( $hook, self::REPLAY, true ) ) {
					$n            = self::replay( $hook, $called[ $hook ] );
					$ran[ $hook ] = ( $ran[ $hook ] ?? 0 ) + $n;
					$runs        += $n;
				}
			}
		} while ( $runs > 0 );
		foreach ( $fired as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) && ! in_array( $hook, self::REPLAY, true ) && array() !== array_diff_key( self::ids( $hook ), $before[ $hook ] ) ) {
				self::$missed[] = $hook;
			}
		}
		self::catch_up( $block_args, $blocks, $ran );
	}

	/**
	 * Call, in priority order, every callback on a fired action that was not there before the
	 * start — including those a replayed callback adds.
	 *
	 * @param string             $hook   Action.
	 * @param array<string, int> $called Callback ids not to call (there before, or called already);
	 *                                   the ones called here are added.
	 * @return int How many were called.
	 */
	public static function replay( string $hook, array &$called ): int {
		global $wp_filter, $wp_current_filter;
		$args = self::args( $hook );
		$runs = 0;
		// While a replayed callback runs, `doing_action( $hook )` and `current_action()` answer as
		// they did when WordPress fired it.
		$wp_current_filter   = is_array( $wp_current_filter ) ? $wp_current_filter : array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the replay stands in for do_action().
		$wp_current_filter[] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the replay stands in for do_action().
		try {
			while ( isset( $wp_filter[ $hook ] ) ) {
				$next = null;
				foreach ( self::by_priority( $hook ) as $id => $callback ) {
					if ( ! isset( $called[ $id ] ) ) {
						$next = array( $id, $callback );
						break;
					}
				}
				if ( null === $next ) {
					break;
				}
				$called[ $next[0] ] = 1;
				call_user_func_array( $next[1]['function'], array_slice( $args, 0, (int) $next[1]['accepted_args'] ) );
				++$runs;
			}
		} finally {
			array_pop( $wp_current_filter );
		}

		return $runs;
	}

	/**
	 * What WordPress registered while it loaded, from things the start added too late to see it.
	 *
	 * - `register_block_type_args`: the blocks WordPress registered on `init` (every core block)
	 *   get the attributes and supports the start's callbacks add, as a block registered later
	 *   does — only the start's own callbacks run, in priority order.
	 * - Core's own idempotent consumers of what `init` and `widgets_init` register run once more
	 *   when the replay added to those actions: block-supports attributes, the footnotes meta of
	 *   post types registered by the start, and the widgets registered by the start.
	 *
	 * @param array<string, int> $block_args Callback ids on `register_block_type_args` before the start.
	 * @param array<int, string> $blocks     Block types registered before the start (only these
	 *                                       missed the start's callbacks).
	 * @param array<string, int> $ran        How many callbacks the replay ran, per action.
	 * @return void
	 */
	private static function catch_up( array $block_args, array $blocks, array $ran ): void {
		$late = array_diff_key( self::by_priority( 'register_block_type_args' ), $block_args );
		if ( array() !== $late && class_exists( '\WP_Block_Type_Registry' ) ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			foreach ( $blocks as $name ) {
				$block_type = $registry->get_registered( $name );
				if ( ! is_object( $block_type ) ) {
					continue;
				}
				$args = get_object_vars( $block_type );
				$was  = $args;
				foreach ( $late as $callback ) {
					$args = call_user_func_array( $callback['function'], array_slice( array( $args, (string) $name ), 0, max( 1, (int) $callback['accepted_args'] ) ) );
					$args = is_array( $args ) ? $args : $was;
				}
				foreach ( $args as $property => $value ) {
					if ( ! array_key_exists( $property, $was ) || $was[ $property ] !== $value ) {
						$block_type->$property = $value;
					}
				}
			}
		}
		if ( ( $ran['init'] ?? 0 ) > 0 || array() !== $late ) {
			if ( function_exists( 'has_action' ) && false !== has_action( 'init', array( 'WP_Block_Supports', 'init' ) ) ) {
				\WP_Block_Supports::init();
			}
		}
		if ( ( $ran['init'] ?? 0 ) > 0 && function_exists( 'has_action' ) && false !== has_action( 'init', 'register_block_core_footnotes_post_meta' ) ) {
			register_block_core_footnotes_post_meta();
		}
		if ( ( $ran['widgets_init'] ?? 0 ) > 0 && isset( $GLOBALS['wp_widget_factory'] ) && is_object( $GLOBALS['wp_widget_factory'] ) && method_exists( $GLOBALS['wp_widget_factory'], '_register_widgets' ) ) {
			$GLOBALS['wp_widget_factory']->_register_widgets();
		}
	}

	/**
	 * What WordPress passes to a replayed action.
	 *
	 * @param string $hook Action.
	 * @return array<int, mixed>
	 */
	private static function args( string $hook ): array {
		if ( 'rest_api_init' === $hook && function_exists( 'rest_get_server' ) ) {
			return array( rest_get_server() );
		}

		return array();
	}

	/**
	 * Actions that have fired, in the order they first fired.
	 *
	 * @return array<int, string>
	 */
	private static function fired(): array {
		global $wp_actions;

		return is_array( $wp_actions ) ? array_map( 'strval', array_keys( $wp_actions ) ) : array();
	}

	/**
	 * Callback ids on an action (id => 1).
	 *
	 * @param string $hook Action.
	 * @return array<string, int>
	 */
	private static function ids( string $hook ): array {
		return array_fill_keys( array_keys( self::by_priority( $hook ) ), 1 );
	}

	/**
	 * An action's callbacks in the order WordPress runs them, keyed `priority|id`.
	 *
	 * @param string $hook Action.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_priority( string $hook ): array {
		global $wp_filter;
		$callbacks = isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) ? (array) $wp_filter[ $hook ]->callbacks : array();
		ksort( $callbacks, SORT_NUMERIC );
		$out = array();
		foreach ( $callbacks as $priority => $list ) {
			foreach ( (array) $list as $id => $callback ) {
				$out[ $priority . '|' . $id ] = $callback;
			}
		}

		return $out;
	}

	/**
	 * Was this plugin's start deferred in this request?
	 *
	 * @return bool
	 */
	public static function deferred(): bool {
		return self::$deferred;
	}

	/**
	 * Is this plugin's start deferred and not yet run (WordPress is still loading under WP-CLI)?
	 *
	 * @return bool
	 */
	public static function waiting(): bool {
		return self::$deferred && ! self::$started;
	}

	/**
	 * Fired actions a deferred start hooked into but did not replay (should be none).
	 *
	 * @return array<int, string>
	 */
	public static function missed(): array {
		return self::$missed;
	}
}
