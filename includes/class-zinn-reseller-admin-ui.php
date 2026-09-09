<?php
/**
 * One settings shell for every Zinn® plugin: one menu, one save path, one set of guards.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** The source of truth is
 * `wp/admin-ui/class-zinn-admin-ui.php.tpl`; `wp/bin/build-admin-ui.php` renders it into every
 * plugin and `--check` fails the build if a checked-in copy has drifted (§2.32).
 *
 * ⚖️ **Owner, 2026-09-08, verbatim:** *"All our pluiigns realluy need to have cusotmisable
 * options and styling optiins etc also where needed so they can properly contorl it and setit
 * up etc incduing the bridge one between wp and our panel etc. And it needs to show status of
 * ocnnetion and all those things properly. This needs to be for all our pluigins too and
 * relaly well thoguht out with deep feature packs in them etc and top uui and ux."*
 *
 * ⛔⛔ **WHY ONE FRAMEWORK AND NOT SIX SCREENS.** Six plugins each growing their own settings
 * page is six hand-rolled `sanitize()` methods, six places to forget `check_admin_referer`,
 * and six different answers to "am I connected?". The one that forgets fails **silently and in
 * the expensive direction** — it saves, it looks right, and nothing is red (§2.44). Capability
 * checks, nonces, sanitisation and escaping are declared once here and applied to every field
 * of every plugin, so a plugin author cannot forget one by omission.
 *
 * ⚖️ **Owner ruling, 2026-09-08: ONE top-level `Zinn` menu.** Asked directly, given that six
 * plugins each adding themselves under Settings → is six scattered entries. So no plugin calls
 * `add_menu_page` or `add_options_page` for itself: each REGISTERS, and whichever copy loads
 * first builds the menu for all of them. That coordination is the only reason the shared
 * `zinn_admin_ui` global prefix exists — the same exception, for the same reason, that
 * `zinn_promo` already carries in `wp/phpcs.xml.dist`.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The settings shell: register a page, get a screen; read values anywhere.
 */
final class Zinn_Reseller_Admin_UI {

	/**
	 * This plugin's slug, which is also its option prefix and its text domain.
	 */
	private const SLUG = 'zinn-reseller';

	/**
	 * The shared top-level menu slug. ⛔ Identical in every plugin, on purpose.
	 */
	private const PARENT = 'zinn-admin-ui';

	/**
	 * Option holding the schema version, which is what makes the legacy fold run once.
	 *
	 * ⛔ An OPTION, not a transient. A transient can be evicted by an object cache under
	 * memory pressure, and a migration that re-runs is a migration that can overwrite a value
	 * the customer has since changed — the legacy fold is guarded by "the array does not
	 * already hold this key", but the guard should not be the only thing standing between a
	 * customer's edit and a stale scalar from two releases ago.
	 */
	private const SCHEMA_OPTION = 'zinn_reseller_settings_schema';

	/**
	 * The schema version this build of the framework writes.
	 */
	private const SCHEMA_VERSION = 1;

	/**
	 * The registered configuration for this plugin, or an empty array before `register()`.
	 *
	 * @var array<string, mixed>
	 */
	private static array $config = array();

	/**
	 * Memoised, fully-defaulted settings, so a front-end request reads the option once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Lazily-resolved field lists, keyed by tab id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $resolved = array();

	// ─────────────────────────────────────────────────────────────────────────────────────
	// Registration
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Declare this plugin's settings page.
	 *
	 * ⛔ Call this UNCONDITIONALLY from `plugins_loaded` — never inside an `is_admin()` guard.
	 * `get()` and `all()` must work on a front-end request, in WP-Cron and in WP-CLI, and a
	 * class registered only in wp-admin is a plugin whose settings silently become their
	 * defaults everywhere a visitor can see (§2.38). Only the RENDERING is admin-gated, and
	 * that gating lives inside this class where it cannot be forgotten.
	 *
	 * @param array<string, mixed> $config {
	 *     The page declaration.
	 *
	 *     @type string   $title      Submenu label.
	 *     @type string   $option     The single array option this plugin stores.
	 *     @type string   $capability Capability required to view and to save. Default `manage_options`.
	 *     @type int      $position   Submenu ordering hint. Default 50.
	 *     @type array    $tabs       Tab id => `array{title:string, fields:array}`.
	 *     @type callable $connection Returns a connection-status array. Optional.
	 *     @type array    $actions    Extra buttons: `array{id,label,callback,confirm?,tab?}`.
	 *     @type array    $screens    Custom tabs rendered by a callback: `array{id,title,render}`.
	 *     @type array    $legacy     New key => legacy standalone option name.
	 *     @type bool     $diagnostics Offer the support-diagnostics action. Default true.
	 * }
	 * @return void
	 */
	public static function register( array $config ): void {
		self::$config = array_merge(
			array(
				'title'       => self::SLUG,
				'option'      => str_replace( '-', '_', self::SLUG ) . '_settings',
				'capability'  => 'manage_options',
				'position'    => 50,
				'tabs'        => array(),
				'connection'  => null,
				'actions'     => array(),
				'screens'     => array(),
				'legacy'      => array(),
				'diagnostics' => true,
			),
			$config
		);

		// ⛔ The registry is a GLOBAL rather than a static, because the seven copies of this
		// class are seven different classes. A static cannot be shared between them, and the
		// menu has to be built from all of them at once.
		if ( ! isset( $GLOBALS['zinn_admin_ui_pages'] ) || ! is_array( $GLOBALS['zinn_admin_ui_pages'] ) ) {
			$GLOBALS['zinn_admin_ui_pages'] = array();
		}
		$GLOBALS['zinn_admin_ui_pages'][ self::SLUG ] = array(
			'title'    => (string) self::$config['title'],
			'position' => (int) self::$config['position'],
			'cap'      => (string) self::$config['capability'],
			'class'    => __CLASS__,
		);

		// ⛔⛔ Priority 1 on `init`, on BOTH admin and front-end requests. A site whose
		// wp-admin is never opened — and a great many are, once set up — must still fold its
		// legacy options before anything reads them, or the first front-end read returns
		// defaults for a site that is fully configured.
		//
		// ⛔⛤ **AND IT RUNS IMMEDIATELY IF `init` HAS ALREADY FIRED.** WordPress 6.7 asks
		// that translations be loaded no earlier than `init`, so a plugin declaring a page
		// with translated labels must call `register()` on `init` — at which point adding an
		// action to `init` at priority 1 queues a callback that will never run, and the
		// legacy fold silently never happens. Nothing would be red; the site would simply
		// stop backing itself up (§2.44).
		if ( did_action( 'init' ) > 0 ) {
			self::migrate_legacy();
		} else {
			add_action( 'init', array( __CLASS__, 'migrate_legacy' ), 1 );
		}

		if ( ! is_admin() ) {
			return;
		}

		// ⛔ The FIRST copy to register owns the menu. Ownership is recorded in a global so
		// the other six do not each add a parent — which would give a site running three Zinn
		// plugins three identical top-level menus.
		if ( empty( $GLOBALS['zinn_admin_ui_menu_owner'] ) ) {
			$GLOBALS['zinn_admin_ui_menu_owner'] = __CLASS__;
			add_action( 'admin_menu', array( __CLASS__, 'build_menu' ), 9 );
		}

		add_action( 'admin_post_' . self::action_name( 'save' ), array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::action_name( 'act' ), array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * This plugin's `admin-post.php` action name for a given verb.
	 *
	 * @param string $verb `save` or `act`.
	 * @return string
	 */
	private static function action_name( string $verb ): string {
		return str_replace( '-', '_', self::SLUG ) . '_' . $verb;
	}

	/**
	 * This plugin's `admin-post.php` action name for tool and connection buttons.
	 *
	 * ⛔ Public because the connection card renders buttons that post back here, and it is
	 * a separate class. Exposing the NAME is not exposing the capability: `handle_action()`
	 * still checks the capability and the nonce, and dispatches only to declared actions.
	 *
	 * @return string
	 */
	public static function act_action(): string {
		return self::action_name( 'act' );
	}

	/**
	 * This plugin's admin page slug.
	 *
	 * @return string
	 */
	public static function page_slug(): string {
		return self::PARENT . '-' . self::SLUG;
	}

	/**
	 * The URL of this plugin's settings page.
	 *
	 * @param string $tab Optional tab to open.
	 * @return string
	 */
	public static function page_url( string $tab = '' ): string {
		$args = array( 'page' => self::page_slug() );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// The menu
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Build the one `Zinn` menu from every registered plugin.
	 *
	 * ⛔ Runs in whichever copy claimed ownership, and reads the shared registry — so the
	 * menu contains every Zinn plugin on the site, not only the one that happened to win.
	 *
	 * @return void
	 */
	public static function build_menu(): void {
		$pages = isset( $GLOBALS['zinn_admin_ui_pages'] ) && is_array( $GLOBALS['zinn_admin_ui_pages'] )
			? $GLOBALS['zinn_admin_ui_pages']
			: array();
		if ( array() === $pages ) {
			return;
		}

		uasort(
			$pages,
			static function ( array $a, array $b ): int {
				return array( (int) $a['position'], (string) $a['title'] ) <=> array( (int) $b['position'], (string) $b['title'] );
			}
		);

		// The parent takes the LEAST demanding capability of its children, so a role that can
		// see one plugin's screen can reach the menu that holds it. A parent gated harder
		// than its children renders the children unreachable while every one of them looks
		// correctly registered.
		$caps        = array_column( $pages, 'cap' );
		$parent_cap  = in_array( 'manage_options', $caps, true ) && 1 === count( array_unique( $caps ) )
			? 'manage_options'
			: (string) reset( $caps );
		$parent_page = self::PARENT;

		add_menu_page(
			__( 'Zinn Digital®', 'zinn-reseller' ),
			__( 'Zinn Digital®', 'zinn-reseller' ),
			$parent_cap,
			$parent_page,
			array( __CLASS__, 'render_overview' ),
			self::menu_icon(),
			58
		);

		// ⛔ WordPress renders the parent as its own first submenu with the parent's title.
		// Overriding it here is what makes that entry read "Overview" rather than "Zinn
		// Digital® / Zinn Digital®", which looks like a bug to anyone who sees it.
		add_submenu_page(
			$parent_page,
			__( 'Zinn Digital® — overview', 'zinn-reseller' ),
			__( 'Overview', 'zinn-reseller' ),
			$parent_cap,
			$parent_page,
			array( __CLASS__, 'render_overview' )
		);

		foreach ( $pages as $slug => $page ) {
			$owner = (string) $page['class'];
			add_submenu_page(
				$parent_page,
				(string) $page['title'],
				(string) $page['title'],
				(string) $page['cap'],
				self::PARENT . '-' . $slug,
				static function () use ( $owner ): void {
					// ⛔ A string-ish dynamic call rather than a captured object: each page is
					// rendered by ITS OWN plugin's copy of this class, which is the only copy
					// that holds that plugin's configuration.
					if ( is_callable( array( $owner, 'render_page' ) ) ) {
						call_user_func( array( $owner, 'render_page' ) );
					}
				}
			);
		}
	}

	/**
	 * The menu icon, as an inline SVG data URI.
	 *
	 * ⛔ Inline, not a file and never a remote URL. `wp/promo/class-zinn-promo.php.tpl`
	 * refuses a remote image for the same reason: a third-party request on every admin page
	 * load of a site we do not host is slow for them and a liability for us the first time
	 * our CDN is down — and it is the first thing a WordPress.org reviewer looks for.
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">'
			. '<path d="M3.5 3h13v2.4L7.9 14.6h8.6V17h-13v-2.4L12.1 5.4H3.5V3z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI is the documented form for a menu icon.
	}

	/**
	 * The `Zinn Digital® → Overview` screen: every Zinn plugin on this site, and its status.
	 *
	 * ⚖️ The owner's *"it needs to show status of ocnnetion and all those things properly"*.
	 * One screen that answers "is anything wrong?" without opening six others.
	 *
	 * @return void
	 */
	public static function render_overview(): void {
		$pages = isset( $GLOBALS['zinn_admin_ui_pages'] ) && is_array( $GLOBALS['zinn_admin_ui_pages'] )
			? $GLOBALS['zinn_admin_ui_pages']
			: array();

		echo '<div class="wrap zinn-admin">';
		echo '<h1>' . esc_html__( 'Zinn Digital®', 'zinn-reseller' ) . '</h1>';
		echo '<p class="zinn-lede">' . esc_html__( 'Every Zinn® plugin on this site, and whether it is working.', 'zinn-reseller' ) . '</p>';
		self::print_styles();

		echo '<div class="zinn-cards">';
		foreach ( $pages as $slug => $page ) {
			$owner  = (string) $page['class'];
			$status = is_callable( array( $owner, 'status_for_overview' ) )
				? (array) call_user_func( array( $owner, 'status_for_overview' ) )
				: array();
			$url    = add_query_arg( array( 'page' => self::PARENT . '-' . $slug ), admin_url( 'admin.php' ) );
			$state  = (string) ( $status['state'] ?? 'unknown' );

			echo '<div class="zinn-card">';
			echo '<h2 class="zinn-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( (string) $page['title'] ) . '</a></h2>';
			echo '<p class="zinn-status zinn-status--' . esc_attr( sanitize_html_class( $state ) ) . '">';
			echo '<span class="zinn-status__dot" aria-hidden="true"></span>';
			echo '<span class="zinn-status__text">' . esc_html( (string) ( $status['summary'] ?? __( 'No connection is needed for this plugin.', 'zinn-reseller' ) ) ) . '</span>';
			echo '</p>';
			if ( ! empty( $status['reason'] ) ) {
				echo '<p class="zinn-card__reason">' . esc_html( (string) $status['reason'] ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open settings', 'zinn-reseller' ) . '</a></p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * This plugin's status, as the overview card wants it.
	 *
	 * @return array<string, mixed>
	 */
	public static function status_for_overview(): array {
		$callable = self::$config['connection'] ?? null;
		if ( ! is_callable( $callable ) ) {
			return array();
		}
		$status = call_user_func( $callable );
		return is_array( $status ) ? $status : array();
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// Reading values
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Every setting, defaults merged in, sanitised.
	 *
	 * ⛔ Safe on the front end, in cron and in WP-CLI. It does NOT check a capability: the
	 * capability guards the screen and the save, not a read the plugin's own renderer needs
	 * on an anonymous request.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		// ⛔⛤ **LOADED IS NOT REGISTERED, AND `class_exists()` ONLY ANSWERS THE FIRST**
		// (§2.54 — the instrument that answers the adjacent question). Every caller of
		// `get()` in our plugins guards on `class_exists()`, which is true from the moment
		// the file is required and stays true for the whole request — including the window
		// between `plugins_loaded` and the `init` on which pages are declared. Returning an
		// empty array here rather than reading `$config['option']` is what turns that window
		// into "you get your declared fallback" instead of a PHP warning on every page load.
		if ( array() === self::$config ) {
			return array();
		}
		$stored = get_option( (string) self::$config['option'], array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( self::fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || in_array( (string) ( $field['type'] ?? '' ), Zinn_Reseller_Admin_Fields::presentational(), true ) ) {
				continue;
			}
			$out[ $key ] = array_key_exists( $key, $stored )
				? $stored[ $key ]
				: Zinn_Reseller_Admin_Fields::default_for( $field );
		}

		// Keys the plugin stores but does not declare as fields — counters, last-error
		// strings, timestamps. They are preserved verbatim; the framework does not own them
		// and must not silently drop them on a save.
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}

		self::$cache = $out;
		return $out;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Field key.
	 * @param mixed  $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Has this plugin declared its page yet?
	 *
	 * ⭐ For a caller that must distinguish "the customer has not set this" from "it is too
	 * early to ask" — a distinction `get()` alone cannot make, because both return the
	 * fallback (§2.44).
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return array() !== self::$config;
	}

	/**
	 * Write settings back, merging over what is stored.
	 *
	 * ⛔ For a plugin's own bookkeeping — a counter, a last-error, a timestamp. It does NOT
	 * sanitise through the field declarations, because its callers are our own code rather
	 * than a form post: `handle_save()` is the path that takes untrusted input, and it is the
	 * only one that should.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 * @return void
	 */
	public static function put( array $values ): void {
		$stored = get_option( (string) self::$config['option'], array() );
		$stored = is_array( $stored ) ? $stored : array();
		update_option( (string) self::$config['option'], array_merge( $stored, $values ) );
		self::$cache = null;
	}

	/**
	 * Every declared field across every tab, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields(): array {
		$out = array();
		foreach ( array_keys( (array) ( self::$config['tabs'] ?? array() ) ) as $id ) {
			foreach ( self::tab_field_list( (string) $id ) as $field ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * One tab's fields, resolving a lazy declaration exactly once.
	 *
	 * ⛔⛔ **A TAB MAY DECLARE ITS FIELDS AS A CALLABLE, AND FOR ANYTHING THAT TOUCHES THE
	 * DATABASE IT MUST.** `register()` runs on `plugins_loaded`, which is BEFORE roles are
	 * set up and before `init` — so a field list built eagerly with `get_users()` fatals the
	 * whole site with `Call to a member function for_site() on null`, and one built with
	 * `__()` trips WordPress 6.7's *"translation loading … triggered too early"* notice on
	 * every request. Both were measured on a real WordPress 7.1 before this existed; neither
	 * is visible to `php -l`, to a unit test with stubs, or to reading the code back (§2.24).
	 *
	 * ⭐ It is also the difference between a settings screen costing nothing on a front-end
	 * request and costing two user/term queries on every page view (§2.16).
	 *
	 * @param string $id Tab id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tab_field_list( string $id ): array {
		if ( isset( self::$resolved[ $id ] ) ) {
			return self::$resolved[ $id ];
		}
		$declared = self::$config['tabs'][ $id ]['fields'] ?? array();
		if ( is_callable( $declared ) ) {
			$declared = call_user_func( $declared );
		}
		$out = array();
		foreach ( (array) $declared as $field ) {
			if ( is_array( $field ) ) {
				$out[] = $field;
			}
		}
		self::$resolved[ $id ] = $out;
		return $out;
	}

	/**
	 * Record something support will want, if the customer has asked us to.
	 *
	 * ⛔⛔ **CAPPED, AND CAPPED IN THE OPTION RATHER THAN AT READ TIME.** An unbounded log in
	 * an autoloaded option is a site that gets slower every day and a `wp_options` row nobody
	 * associates with the plugin that wrote it. Fifty entries is enough to see a pattern and
	 * small enough that it can never be the problem.
	 *
	 * ⛔ **Off by default and it must stay off by default.** A plugin that logs everything on
	 * every site, for ever, in case somebody one day asks, is a plugin writing to the database
	 * on behalf of a support case that will never happen.
	 *
	 * ⛔ Never log a credential. The caller decides what goes in, and every call site in our
	 * own plugins passes a sentence, not a value.
	 *
	 * @param string $message What happened, in English — this is read by us, not by a customer.
	 * @return void
	 */
	public static function log( string $message ): void {
		if ( empty( self::get( 'verbose_log', false ) ) ) {
			return;
		}
		$key       = 'zinn_reseller_log';
		$entries   = get_option( $key, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'at'      => gmdate( 'c' ),
			'message' => substr( $message, 0, 500 ),
		);
		if ( count( $entries ) > 50 ) {
			$entries = array_slice( $entries, -50 );
		}
		// ⛔ `false` — NOT autoloaded. A log read only by a support screen has no business
		// being loaded into memory on every request the site serves.
		update_option( $key, $entries, false );
	}

	/**
	 * The recorded entries, newest last.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function log_entries(): array {
		$entries = get_option( 'zinn_reseller_log', array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * The keys of every field declared `secret`, or of type `password`.
	 *
	 * ⛔ Read by the diagnostics collector so redaction is driven by the DECLARATION rather
	 * than by a pattern match on the key name. A regex deny-list of secret-looking names is
	 * an enumeration, and the entry that goes missing is never the one you are reading — with
	 * a live credential in a support ticket as the failure (§2.24).
	 *
	 * @return array<int, string>
	 */
	public static function secret_keys(): array {
		$out = array();
		foreach ( self::fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			if ( ! empty( $field['secret'] ) || 'password' === (string) ( $field['type'] ?? '' ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * The field with a given key, or null.
	 *
	 * @param string $key Field key.
	 * @return array<string, mixed>|null
	 */
	private static function field( string $key ): ?array {
		foreach ( self::fields() as $field ) {
			if ( (string) ( $field['key'] ?? '' ) === $key ) {
				return $field;
			}
		}
		return null;
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// The legacy fold
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Fold standalone legacy options into the settings array, once.
	 *
	 * ⛔⛔ **THIS EXISTS BECAUSE THE ALTERNATIVE FAILS SILENTLY ON LIVE SITES.** Two plugins
	 * shipped before this framework stored their values as separate scalar options —
	 * `zinn_translate_site_id`, `zinn_connector_backup_token` and their siblings. A framework
	 * that simply started reading its own array would find it empty on every existing
	 * install, and the site would stop working while every screen looked correct: the
	 * expensive direction of §2.44, on the two plugins that hold a live credential.
	 *
	 * ⭐ The original options are LEFT IN PLACE for one release. A fold that deletes as it
	 * copies has no way back if it is wrong, and a customer downgrading a plugin would find
	 * their credential gone.
	 *
	 * @return int How many values were folded, for the diagnostics report.
	 */
	public static function migrate_legacy(): int {
		$legacy = (array) ( self::$config['legacy'] ?? array() );
		if ( array() === $legacy ) {
			return 0;
		}
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return 0;
		}

		$stored = get_option( (string) self::$config['option'], array() );
		$stored = is_array( $stored ) ? $stored : array();
		$folded = 0;

		foreach ( $legacy as $key => $old_option ) {
			$key = (string) $key;
			// ⛔ A value already in the array WINS. It was either saved through the new
			// screen or folded by an earlier run; either way it is newer than the scalar.
			if ( array_key_exists( $key, $stored ) ) {
				continue;
			}
			$sentinel = '__zinn_absent__';
			$value    = get_option( (string) $old_option, $sentinel );
			if ( $sentinel === $value ) {
				continue;
			}
			$field          = self::field( $key );
			$stored[ $key ] = null === $field ? $value : Zinn_Reseller_Admin_Fields::sanitize( $field, $value, null );
			++$folded;
		}

		if ( $folded > 0 ) {
			update_option( (string) self::$config['option'], $stored );
			self::$cache = null;
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );

		return $folded;
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// The page
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Render this plugin's settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// ⛔ Checked again HERE and not only at `add_submenu_page`. A page slug is reachable
		// directly; relying on the menu registration to keep somebody out is relying on the
		// navigation to be the access control.
		if ( ! current_user_can( (string) self::$config['capability'] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change these settings.', 'zinn-reseller' ),
				'',
				array( 'response' => 403 )
			);
		}

		$tabs    = self::all_tabs();
		$current = self::current_tab( $tabs );
		$values  = self::all();
		$notice  = self::take_notice();

		self::print_styles();
		?>
		<div class="wrap zinn-admin">
			<h1><?php echo esc_html( (string) self::$config['title'] ); ?></h1>

			<?php if ( array() !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( (string) $notice['kind'] ); ?> is-dismissible">
					<p><?php echo esc_html( (string) $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			$connection = self::$config['connection'] ?? null;
			if ( is_callable( $connection ) ) {
				Zinn_Reseller_Connection::render_card( (array) call_user_func( $connection ) );
			}
			?>

			<?php if ( count( $tabs ) > 1 ) : ?>
				<nav class="nav-tab-wrapper zinn-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'zinn-reseller' ); ?>">
					<?php foreach ( $tabs as $id => $tab ) : ?>
						<a
							class="nav-tab <?php echo $id === $current ? 'nav-tab-active' : ''; ?>"
							href="<?php echo esc_url( self::page_url( $id ) ); ?>"
							<?php echo $id === $current ? 'aria-current="page"' : ''; ?>
						><?php echo esc_html( (string) $tab['title'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php
			$tab = $tabs[ $current ] ?? array();
			if ( isset( $tab['render'] ) && is_callable( $tab['render'] ) ) {
				// A custom screen: the plugin owns the body, the framework owns the chrome,
				// the capability check and the nonce for anything it posts.
				call_user_func( $tab['render'] );
			} else {
				self::render_form( $current, self::tab_field_list( $current ), $values );
			}
			?>

			<?php self::render_tools( $current ); ?>

			<?php
			// ⛔⛔ AT THE BOTTOM, BELOW THE CONTROLS — never above them. Somebody who opened a
			// settings screen came to change a setting; a promotion that pushes it below the
			// fold is the disruptive upselling a WordPress.org reviewer rejects.
			// ⛔ A DIRECT static call, not `call_user_func( array( '…', 'render_panel' ) )`.
			// This generated file is global (it carries no namespace), so the class resolves
			// correctly either way — but `scripts/wp-promo-check.py`'s P003 looks for the
			// literal `::render_panel`, and a string callable is invisible to it. A gate that
			// cannot see the consumer reports the panel as unrendered, which is a false alarm
			// in the direction somebody acts on (§2.44's twin).
			if ( class_exists( 'Zinn_Reseller_Promo' ) ) {
				Zinn_Reseller_Promo::render_panel();
			}
			?>
		</div>
		<?php
		self::print_script();
	}

	/**
	 * Declared tabs plus any custom screens, in one ordered map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all_tabs(): array {
		$tabs = array();
		foreach ( (array) ( self::$config['tabs'] ?? array() ) as $id => $tab ) {
			// ⛔ Title only. Carrying the `fields` entry forward would make every caller of
			// this method a place a lazy declaration could be resolved by accident, which is
			// the whole defect this laziness exists to prevent.
			$tabs[ (string) $id ] = array( 'title' => (string) ( $tab['title'] ?? $id ) );
		}
		foreach ( (array) ( self::$config['screens'] ?? array() ) as $screen ) {
			$id = (string) ( $screen['id'] ?? '' );
			if ( '' === $id || ! isset( $screen['render'] ) || ! is_callable( $screen['render'] ) ) {
				continue;
			}
			$tabs[ $id ] = array(
				'title'  => (string) ( $screen['title'] ?? $id ),
				'render' => $screen['render'],
			);
		}

		// ⛔ The diagnostics screen is a TAB rather than a modal or a separate page, so the
		// customer can see what will be sent in the same place they were already looking.
		// §2.57's "explicit preview first" is only meaningful if the preview is where the
		// person is: a preview behind a second navigation is a preview nobody reads.
		if ( ! empty( self::$config['diagnostics'] ) ) {
			$tabs['zinn-diagnostics'] = array(
				'title'  => __( 'Support', 'zinn-reseller' ),
				'render' => array( 'Zinn_Reseller_Diagnostics', 'render_screen' ),
			);
		}

		return $tabs;
	}

	/**
	 * Which tab the request asked for, falling back to the first.
	 *
	 * ⛔ Validated against the declared tabs, never used raw. `?tab=` is attacker-controlled
	 * on any link an administrator can be handed, and it is used to build markup below.
	 *
	 * @param array<string, array<string, mixed>> $tabs Declared tabs.
	 * @return string
	 */
	private static function current_tab( array $tabs ): string {
		$first = (string) array_key_first( $tabs );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to draw is not a state change, and the value is validated against the declared set on the next line.
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( $tabs[ $asked ] ) ? $asked : $first;
	}

	/**
	 * The settings form for one tab.
	 *
	 * @param string                           $tab    Tab id.
	 * @param array<int, array<string, mixed>> $fields Its fields.
	 * @param array<string, mixed>             $values Current values.
	 * @return void
	 */
	private static function render_form( string $tab, array $fields, array $values ): void {
		if ( array() === $fields ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zinn-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::action_name( 'save' ) ); ?>" />
			<input type="hidden" name="zinn_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php wp_nonce_field( self::action_name( 'save' ) ); ?>
			<table class="form-table zinn-form-table" role="presentation">
				<tbody>
				<?php
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$key = (string) ( $field['key'] ?? '' );
					Zinn_Reseller_Admin_Fields::render_row(
						$field,
						'' === $key ? null : ( $values[ $key ] ?? Zinn_Reseller_Admin_Fields::default_for( $field ) ),
						'zinn_settings'
					);
				}
				?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save changes', 'zinn-reseller' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The tools row: plugin actions, export, import, reset, diagnostics.
	 *
	 * @param string $tab The tab currently open, so an action can be scoped to one.
	 * @return void
	 */
	private static function render_tools( string $tab ): void {
		$actions = array();
		foreach ( (array) ( self::$config['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || '' === (string) ( $action['id'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $action['tab'] ) && (string) $action['tab'] !== $tab ) {
				continue;
			}
			$actions[] = $action;
		}

		$has_fields = array() !== self::fields();
		if ( array() === $actions && ! $has_fields ) {
			return;
		}
		?>
		<div class="zinn-tools">
			<h2><?php esc_html_e( 'Tools', 'zinn-reseller' ); ?></h2>
			<div class="zinn-tools__row">
				<?php foreach ( $actions as $action ) : ?>
					<?php
					self::action_button(
						(string) $action['id'],
						(string) ( $action['label'] ?? $action['id'] ),
						(string) ( $action['confirm'] ?? '' ),
						(string) ( $action['style'] ?? '' )
					);
					?>
				<?php endforeach; ?>

				<?php if ( $has_fields ) : ?>
					<?php
					self::action_button( 'zinn_export', __( 'Export settings', 'zinn-reseller' ), '', '' );
					self::action_button(
						'zinn_reset',
						__( 'Reset to defaults', 'zinn-reseller' ),
						__( 'This puts every setting on this plugin back to its default. Continue?', 'zinn-reseller' ),
						'delete'
					);
					?>
				<?php endif; ?>

				<?php if ( ! empty( self::$config['diagnostics'] ) ) : ?>
					<?php self::action_button( 'zinn_diagnostics', __( 'Diagnostics for support', 'zinn-reseller' ), '', '' ); ?>
				<?php endif; ?>
			</div>

			<?php if ( $has_fields ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="zinn-tools__import">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::action_name( 'act' ) ); ?>" />
					<input type="hidden" name="zinn_action" value="zinn_import" />
					<?php wp_nonce_field( self::action_name( 'act' ) ); ?>
					<label class="zinn-tools__import-label" for="zinn-import-file"><?php esc_html_e( 'Import a settings file', 'zinn-reseller' ); ?></label>
					<input type="file" id="zinn-import-file" name="zinn_import_file" accept="application/json,.json" required />
					<button type="submit" class="button"><?php esc_html_e( 'Import', 'zinn-reseller' ); ?></button>
					<p class="description">
						<?php esc_html_e( 'Exported files never contain passwords, tokens or API keys — you will need to enter those again on the new site.', 'zinn-reseller' ); ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One tool button, as its own nonce-bearing form.
	 *
	 * ⛔ A FORM, not a link with a nonce in the query string. A `GET` that changes state is
	 * followed by a prefetcher, a scanner and the browser's own address-bar completion — and
	 * "reset to defaults" is not something to hand to a link prefetcher.
	 *
	 * @param string $id      Action id.
	 * @param string $label   Button label.
	 * @param string $confirm Confirmation prompt, or empty for none.
	 * @param string $style   `delete` renders it as a destructive button.
	 * @return void
	 */
	private static function action_button( string $id, string $label, string $confirm, string $style ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zinn-tools__form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::action_name( 'act' ) ); ?>" />
			<input type="hidden" name="zinn_action" value="<?php echo esc_attr( $id ); ?>" />
			<?php wp_nonce_field( self::action_name( 'act' ) ); ?>
			<button
				type="submit"
				class="button <?php echo 'delete' === $style ? 'zinn-button--danger' : ''; ?>"
				<?php echo '' === $confirm ? '' : 'data-zinn-confirm="' . esc_attr( $confirm ) . '"'; ?>
			><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// Writes
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Save the settings form.
	 *
	 * ⛔ Capability check **and** nonce, in that order and both mandatory. The nonce stops a
	 * cross-site request riding an administrator's session; the capability check stops a
	 * subscriber who has a valid one of their own. Neither substitutes for the other, and a
	 * settings screen is exactly where that mistake is usually made.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		self::guard( self::action_name( 'save' ) );

		$stored = get_option( (string) self::$config['option'], array() );
		$stored = is_array( $stored ) ? $stored : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::guard() on the line above.
		$posted = isset( $_POST['zinn_settings'] ) && is_array( $_POST['zinn_settings'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- unslashed here and sanitised per field, by declared type, in the loop below; a blanket sanitiser would corrupt array and secret fields.
			? wp_unslash( $_POST['zinn_settings'] )
			: array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		$tab    = isset( $_POST['zinn_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['zinn_tab'] ) ) : '';
		$fields = self::tab_fields( $tab );

		foreach ( $fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || in_array( (string) ( $field['type'] ?? '' ), Zinn_Reseller_Admin_Fields::presentational(), true ) ) {
				continue;
			}
			// ⛔ An unchecked checkbox posts NOTHING, so a missing key must mean "off" rather
			// than "leave it alone" — otherwise a toggle can be turned on and never off.
			$raw = array_key_exists( $key, $posted ) ? $posted[ $key ] : null;
			if ( null === $raw && in_array( (string) $field['type'], array( 'checkbox', 'toggle' ), true ) ) {
				$raw = false;
			}
			if ( null === $raw && 'multiselect' === (string) $field['type'] ) {
				$raw = array();
			}
			if ( null === $raw && 'repeater' === (string) $field['type'] ) {
				$raw = array();
			}
			if ( null === $raw ) {
				continue;
			}
			$stored[ $key ] = Zinn_Reseller_Admin_Fields::sanitize( $field, $raw, $stored[ $key ] ?? null );
		}

		update_option( (string) self::$config['option'], $stored );
		self::$cache = null;

		/**
		 * Fires after a Zinn settings page has saved.
		 *
		 * @param array<string, mixed> $stored The full stored settings array.
		 * @param string               $tab    The tab that was saved.
		 */
		do_action( 'zinn_admin_ui_saved_zinn_reseller', $stored, $tab );

		self::notice( 'success', __( 'Settings saved.', 'zinn-reseller' ) );
		self::redirect( $tab );
	}

	/**
	 * The fields on one tab, or every field when the tab is unknown.
	 *
	 * ⛔⛤ **SCOPED TO THE POSTED TAB, and that is a correctness requirement rather than an
	 * optimisation.** Saving every field on every submit would run each unposted field
	 * through its sanitiser with a `null` — so a tab the customer was not looking at would
	 * have its text fields blanked and its toggles switched off, every time they saved a
	 * different tab.
	 *
	 * @param string $tab Tab id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tab_fields( string $tab ): array {
		$tabs = (array) ( self::$config['tabs'] ?? array() );
		if ( '' !== $tab && isset( $tabs[ $tab ] ) ) {
			return self::tab_field_list( $tab );
		}
		return self::fields();
	}

	/**
	 * Run one of the tool or plugin actions.
	 *
	 * @return void
	 */
	public static function handle_action(): void {
		self::guard( self::action_name( 'act' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::guard().
		$id = isset( $_POST['zinn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['zinn_action'] ) ) : '';

		switch ( $id ) {
			case 'zinn_export':
				self::export();
				return;

			case 'zinn_import':
				self::import();
				break;

			case 'zinn_reset':
				delete_option( (string) self::$config['option'] );
				self::$cache = null;
				self::notice( 'success', __( 'Every setting on this plugin is back to its default.', 'zinn-reseller' ) );
				break;

			case 'zinn_diagnostics':
				self::redirect( 'zinn-diagnostics' );
				return;

			case 'zinn_diagnostics_send':
				$sent = Zinn_Reseller_Diagnostics::send();
				self::notice( (string) $sent['kind'], (string) $sent['message'] );
				self::redirect( 'zinn-diagnostics' );
				return;

			default:
				self::run_plugin_action( $id );
				break;
		}

		self::redirect( '' );
	}

	/**
	 * Dispatch to a plugin-declared action.
	 *
	 * ⛔ Looked up in the DECLARED list. A callable named by the request would be arbitrary
	 * code execution from a form field, which is the single worst thing a settings screen
	 * can offer — and it is an easy shape to reach for when adding "just one more button".
	 *
	 * @param string $id Action id.
	 * @return void
	 */
	private static function run_plugin_action( string $id ): void {
		foreach ( (array) ( self::$config['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || (string) ( $action['id'] ?? '' ) !== $id ) {
				continue;
			}
			if ( ! isset( $action['callback'] ) || ! is_callable( $action['callback'] ) ) {
				continue;
			}
			$result = call_user_func( $action['callback'] );
			$result = is_array( $result ) ? $result : array();
			self::notice(
				(string) ( $result['kind'] ?? 'success' ),
				(string) ( $result['message'] ?? __( 'Done.', 'zinn-reseller' ) )
			);
			return;
		}
		self::notice( 'error', __( 'That action is not available.', 'zinn-reseller' ) );
	}

	/**
	 * Send the settings as a JSON download.
	 *
	 * ⛔⛔ **SECRETS ARE NEVER EXPORTED.** An export is a file a customer emails to their
	 * developer, drops in a shared folder, or attaches to a support ticket. A token in it is
	 * a credential leak with a friendly filename — so a field declared `secret` (or of type
	 * `password`) is omitted entirely rather than blanked, and the screen says so before you
	 * press the button.
	 *
	 * @return void
	 */
	private static function export(): void {
		$values = self::all();
		$safe   = array();

		foreach ( self::fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( ! empty( $field['secret'] ) || 'password' === (string) ( $field['type'] ?? '' ) ) {
				continue;
			}
			$safe[ $key ] = $values[ $key ];
		}

		$payload = wp_json_encode(
			array(
				'plugin'      => self::SLUG,
				'version'     => self::SCHEMA_VERSION,
				'exported_at' => gmdate( 'c' ),
				'settings'    => $safe,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::SLUG . '-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo false === $payload ? '{}' : $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not markup; escaping it would corrupt the file.
		exit;
	}

	/**
	 * Read an uploaded settings file back in.
	 *
	 * ⛔ Every value goes through the SAME sanitiser the form uses. An import is untrusted
	 * input that happens to arrive as a file, and a settings importer that trusts its own
	 * export format is trusting a file anybody can write.
	 *
	 * @return void
	 */
	private static function import(): void {
		// ⛔ `$_FILES` cannot be "sanitised" as a whole — it is a structure, not a value, and
		// every member this function uses is read out and sanitised individually on the next
		// three lines. `tmp_name` is additionally checked with `is_uploaded_file()`, which is
		// the control that actually matters: it is what stops a crafted `tmp_name` pointing
		// this function at an arbitrary path on the server.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by self::guard() in the caller; each member is sanitised below.
		$file = isset( $_FILES['zinn_import_file'] ) && is_array( $_FILES['zinn_import_file'] ) ? $_FILES['zinn_import_file'] : array();
		$tmp  = isset( $file['tmp_name'] ) ? sanitize_text_field( (string) $file['tmp_name'] ) : '';
		$err  = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $err || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::notice( 'error', __( 'That file could not be read. Choose a settings file exported from a Zinn® plugin.', 'zinn-reseller' ) );
			return;
		}

		// ⛔ A settings export is small. A cap stops a multi-gigabyte "JSON" file being read
		// into memory by an administrator who was handed one.
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size > 1048576 ) {
			self::notice( 'error', __( 'That file is too large to be a settings export.', 'zinn-reseller' ) );
			return;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$raw     = $wp_filesystem ? (string) $wp_filesystem->get_contents( $tmp ) : '';
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['settings'] ) || ! is_array( $decoded['settings'] ) ) {
			self::notice( 'error', __( 'That file is not a Zinn® settings export.', 'zinn-reseller' ) );
			return;
		}
		if ( (string) ( $decoded['plugin'] ?? '' ) !== self::SLUG ) {
			self::notice(
				'error',
				sprintf(
					/* translators: %s: the plugin slug the file was exported from. */
					__( 'That file was exported from a different plugin (%s), so importing it here would store settings this plugin does not understand.', 'zinn-reseller' ),
					esc_html( (string) ( $decoded['plugin'] ?? '?' ) )
				)
			);
			return;
		}

		$stored   = get_option( (string) self::$config['option'], array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$imported = 0;

		foreach ( self::fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || ! array_key_exists( $key, $decoded['settings'] ) ) {
				continue;
			}
			if ( ! empty( $field['secret'] ) || 'password' === (string) ( $field['type'] ?? '' ) ) {
				continue;
			}
			$stored[ $key ] = Zinn_Reseller_Admin_Fields::sanitize( $field, $decoded['settings'][ $key ], $stored[ $key ] ?? null );
			++$imported;
		}

		update_option( (string) self::$config['option'], $stored );
		self::$cache = null;

		self::notice(
			'success',
			sprintf(
				/* translators: %d: how many settings were imported. */
				_n( '%d setting imported. Passwords and API keys are never in an export, so enter those again.', '%d settings imported. Passwords and API keys are never in an export, so enter those again.', $imported, 'zinn-reseller' ),
				$imported
			)
		);
	}

	/**
	 * Capability, then nonce. Both, always.
	 *
	 * @param string $nonce_action The nonce action to verify.
	 * @return void
	 */
	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( (string) self::$config['capability'] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change these settings.', 'zinn-reseller' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $nonce_action );
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// Notices, redirects, assets
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Remember an outcome for the redirect that follows.
	 *
	 * ⛔ A per-user TRANSIENT, not a query argument. A message rendered out of the URL is a
	 * surface anyone can put text into by handing an administrator a link; escaping makes
	 * that harmless rather than absent. A transient cannot be set by whoever crafted it.
	 *
	 * @param string $kind    `success`, `error`, `warning` or `info`.
	 * @param string $message The message.
	 * @return void
	 */
	public static function notice( string $kind, string $message ): void {
		set_transient(
			'zinn_reseller_notice_' . get_current_user_id(),
			array(
				'kind'    => in_array( $kind, array( 'success', 'error', 'warning', 'info' ), true ) ? $kind : 'info',
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Read and clear the pending outcome.
	 *
	 * @return array<string, string>
	 */
	private static function take_notice(): array {
		$key    = 'zinn_reseller_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		return is_array( $notice ) ? $notice : array();
	}

	/**
	 * Back to the settings page.
	 *
	 * @param string $tab Tab to open.
	 * @return void
	 */
	private static function redirect( string $tab ): void {
		wp_safe_redirect( self::page_url( $tab ) );
		exit;
	}

	/**
	 * The framework's admin CSS, printed once per request.
	 *
	 * ⛔⛔ **LOGICAL PROPERTIES ONLY** (§2.7) — `margin-inline-start`, never `margin-left`.
	 * WordPress ships an RTL admin and a hard-coded `left` is a layout that silently breaks
	 * for Arabic, Hebrew, Persian and Urdu administrators, which is the half of RTL nobody
	 * tests because the English screen looks perfect.
	 *
	 * ⭐ Inline against a registered handle rather than a stylesheet file: it is a few hundred
	 * bytes, it only ever loads on our own screens, and it means the plugin ships no asset a
	 * WordPress.org reviewer has to check the licence of.
	 *
	 * @return void
	 */
	private static function print_styles(): void {
		if ( ! empty( $GLOBALS['zinn_admin_ui_styles_done'] ) ) {
			return;
		}
		$GLOBALS['zinn_admin_ui_styles_done'] = true;
		?>
		<style id="zinn-admin-ui">
			.zinn-admin .zinn-lede { max-inline-size: 60ch; color: #50575e; }
			.zinn-admin .zinn-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1rem; margin-block-start: 1rem; }
			.zinn-admin .zinn-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 1rem 1.25rem; }
			.zinn-admin .zinn-card__title { margin-block: 0 .5rem; font-size: 1.05rem; }
			.zinn-admin .zinn-card__reason { color: #50575e; margin-block: .25rem 0; }
			.zinn-admin .zinn-status { display: flex; align-items: center; gap: .5rem; margin-block: .25rem; }
			.zinn-admin .zinn-status__dot { inline-size: .65rem; block-size: .65rem; border-radius: 50%; background: #8c8f94; flex: 0 0 auto; }
			.zinn-admin .zinn-status--connected .zinn-status__dot { background: #00a32a; }
			.zinn-admin .zinn-status--connecting .zinn-status__dot { background: #dba617; }
			.zinn-admin .zinn-status--degraded .zinn-status__dot { background: #dba617; }
			.zinn-admin .zinn-status--disconnected .zinn-status__dot { background: #d63638; }
			.zinn-admin .zinn-status--standalone .zinn-status__dot { background: #2271b1; }
			.zinn-admin .zinn-connection { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; justify-content: space-between; background: #fff; border: 1px solid #c3c4c7; border-inline-start-width: 4px; border-radius: 4px; padding: 1rem 1.25rem; margin-block: 1rem; }
			.zinn-admin .zinn-connection--connected { border-inline-start-color: #00a32a; }
			.zinn-admin .zinn-connection--connecting, .zinn-admin .zinn-connection--degraded { border-inline-start-color: #dba617; }
			.zinn-admin .zinn-connection--disconnected { border-inline-start-color: #d63638; }
			.zinn-admin .zinn-connection--standalone { border-inline-start-color: #2271b1; }
			.zinn-admin .zinn-connection--unknown { border-inline-start-color: #8c8f94; }
			.zinn-admin .zinn-connection__body { flex: 1 1 22rem; }
			.zinn-admin .zinn-connection__summary { font-size: 1.05rem; font-weight: 600; margin-block: 0 .25rem; }
			.zinn-admin .zinn-connection__reason { margin-block: 0 .5rem; color: #50575e; }
			.zinn-admin .zinn-connection__details { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: .15rem .75rem; }
			.zinn-admin .zinn-connection__details dt { color: #50575e; }
			.zinn-admin .zinn-connection__details dd { margin-inline-start: 0; font-family: Menlo, Consolas, monospace; overflow-wrap: anywhere; }
			.zinn-admin .zinn-connection__actions { display: flex; gap: .5rem; flex-wrap: wrap; }
			.zinn-admin .zinn-connection__checked { color: #646970; font-size: .9em; margin-block: .5rem 0; }
			.zinn-admin .zinn-fieldset-title { margin-block: 1.5rem .25rem; }
			.zinn-admin .zinn-field-row--full > td { padding-inline-start: 0; }
			.zinn-admin .zinn-multiselect { border: 1px solid #c3c4c7; border-radius: 4px; padding: .5rem; max-inline-size: 32rem; }
			.zinn-admin .zinn-multiselect__search { inline-size: 100%; margin-block-end: .5rem; }
			.zinn-admin .zinn-multiselect__list { max-block-size: 15rem; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: .1rem .75rem; }
			.zinn-admin .zinn-multiselect__item { display: flex; align-items: center; gap: .35rem; }
			.zinn-admin .zinn-repeater__row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .5rem; padding: .5rem; border: 1px solid #dcdcde; border-radius: 4px; margin-block-end: .5rem; }
			.zinn-admin .zinn-repeater__cell { display: flex; flex-direction: column; gap: .15rem; }
			.zinn-admin .zinn-repeater__label { font-size: .85em; color: #50575e; }
			.zinn-admin .zinn-repeater__remove { color: #d63638; }
			.zinn-admin .zinn-tools { margin-block-start: 2rem; padding-block-start: 1rem; border-block-start: 1px solid #dcdcde; }
			.zinn-admin .zinn-tools__row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
			.zinn-admin .zinn-tools__form { display: inline; }
			.zinn-admin .zinn-tools__import { margin-block-start: 1rem; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
			.zinn-admin .zinn-tools__import .description { flex-basis: 100%; margin-block: 0; }
			.zinn-admin .zinn-button--danger { color: #d63638; border-color: #d63638; }
			.zinn-admin .zinn-secret-hint { color: #50575e; margin-inline-start: .5rem; }
			.zinn-admin .zinn-toggle, .zinn-admin .zinn-radio { display: flex; align-items: center; gap: .35rem; margin-block-end: .25rem; }
			.zinn-admin .zinn-color-value { margin-inline-start: .5rem; }
			.zinn-admin .zinn-diagnostics { background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 1rem; max-block-size: 26rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
			.zinn-admin .zinn-preset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: .75rem; }
			.zinn-admin .zinn-preset { border: 2px solid #c3c4c7; border-radius: 4px; padding: .75rem; cursor: pointer; display: block; }
			.zinn-admin .zinn-preset:has(input:checked) { border-color: #2271b1; }
			.zinn-admin .zinn-preset__name { font-weight: 600; display: block; }
		</style>
		<?php
	}

	/**
	 * The framework's admin JavaScript: conditional fields, confirms, filters, repeaters.
	 *
	 * ⛔⛔ **EVERY FEATURE HERE IS AN ENHANCEMENT, NOT A REQUIREMENT.** With JavaScript off,
	 * conditional fields are all visible, the multiselect filter hides itself, the repeater
	 * still edits its existing rows and every destructive button still asks the server. A
	 * settings screen that stops working when a script fails to load is a screen that stops
	 * working on the exact site whose plugin conflict brought the customer to it.
	 *
	 * @return void
	 */
	private static function print_script(): void {
		if ( ! empty( $GLOBALS['zinn_admin_ui_script_done'] ) ) {
			return;
		}
		$GLOBALS['zinn_admin_ui_script_done'] = true;
		?>
		<script>
		( function () {
			var root = document.querySelector( '.zinn-admin' );
			if ( ! root ) { return; }

			function values() {
				var map = {};
				root.querySelectorAll( '[data-zinn-key]' ).forEach( function ( el ) {
					if ( el.type === 'checkbox' ) { map[ el.dataset.zinnKey ] = el.checked ? '1' : ''; }
					else if ( el.type === 'radio' ) { if ( el.checked ) { map[ el.dataset.zinnKey ] = el.value; } }
					else { map[ el.dataset.zinnKey ] = el.value; }
				} );
				return map;
			}

			function applyConditions() {
				var map = values();
				root.querySelectorAll( '[data-zinn-show-if]' ).forEach( function ( row ) {
					var want;
					try { want = JSON.parse( row.getAttribute( 'data-zinn-show-if' ) ); } catch ( e ) { return; }
					var show = Object.keys( want ).every( function ( key ) {
						var expected = want[ key ];
						var actual = map[ key ];
						if ( Array.isArray( expected ) ) { return expected.map( String ).indexOf( String( actual ) ) !== -1; }
						if ( typeof expected === 'boolean' ) { return expected === ( actual === '1' ); }
						return String( expected ) === String( actual );
					} );
					row.hidden = ! show;
				} );
			}
			root.addEventListener( 'change', applyConditions );
			applyConditions();

			root.querySelectorAll( '[data-zinn-confirm]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					if ( ! window.confirm( button.getAttribute( 'data-zinn-confirm' ) ) ) { event.preventDefault(); }
				} );
			} );

			root.querySelectorAll( '.zinn-multiselect' ).forEach( function ( box ) {
				var search = box.querySelector( '.zinn-multiselect__search' );
				var empty = box.querySelector( '.zinn-multiselect__empty' );
				if ( ! search ) { return; }
				search.hidden = false;
				search.addEventListener( 'input', function () {
					var needle = search.value.trim().toLowerCase();
					var shown = 0;
					box.querySelectorAll( '.zinn-multiselect__item' ).forEach( function ( item ) {
						var hit = needle === '' || ( item.dataset.zinnLabel || '' ).indexOf( needle ) !== -1;
						item.hidden = ! hit;
						if ( hit ) { shown++; }
					} );
					if ( empty ) { empty.hidden = shown !== 0; }
				} );
			} );

			root.querySelectorAll( '[data-zinn-repeater]' ).forEach( function ( box ) {
				var rows = box.querySelector( '.zinn-repeater__rows' );
				var tpl = box.querySelector( '.zinn-repeater__template' );
				var add = box.querySelector( '.zinn-repeater__add' );
				if ( ! rows || ! tpl || ! add ) { return; }
				add.addEventListener( 'click', function () {
					var next = rows.children.length;
					var html = tpl.innerHTML.split( '__INDEX__' ).join( String( next ) );
					var host = document.createElement( 'div' );
					host.innerHTML = html;
					while ( host.firstElementChild ) { rows.appendChild( host.firstElementChild ); }
				} );
				box.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '.zinn-repeater__remove' );
					if ( ! button ) { return; }
					var row = button.closest( '.zinn-repeater__row' );
					if ( row ) { row.remove(); }
				} );
			} );
		}() );
		</script>
		<?php
	}
}
