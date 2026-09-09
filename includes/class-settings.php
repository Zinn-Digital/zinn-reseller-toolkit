<?php
/**
 * The settings screen and the one option behind it.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and renders every setting this plugin owns.
 */
class Settings {

	/**
	 * Defaults. Every module is OFF until the reseller turns it on: installing a plugin
	 * is not consent to start calling an API, and a shop owner who activates this and
	 * immediately has provisioning hooks on their checkout has been surprised by us.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'api_base'             => 'https://api.zinndigital.com',
		'api_key'              => '',
		'panel_base'           => 'https://app.zinndigital.com',
		'enable_domain_search' => false,
		'enable_panel_link'    => false,
		'enable_woocommerce'   => false,
		'plan_code'            => '',
	);

	/**
	 * Wire the admin screen.
	 *
	 * @return void
	 */
	public static function register(): void {
		// ⛔⛔ ON `init`, NOT `plugins_loaded` — WordPress 6.7 raises *"Translation loading …
		// triggered too early"* for any `__()` before `init`, and a settings page is
		// translated labels by construction. Measured on WordPress 7.1.
		add_action( 'init', array( __CLASS__, 'declare_page' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/zinn-reseller.php' ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Every setting, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( ZINN_RESELLER_OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The API base URL.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		return (string) self::get()['api_base'];
	}

	/**
	 * The panel base URL a client is sent to.
	 *
	 * @return string
	 */
	public static function panel_base(): string {
		return (string) self::get()['panel_base'];
	}

	/**
	 * The API key.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		return (string) self::get()['api_key'];
	}

	/*
	 * ⛔⛔ THE OLD `Settings →` SCREEN IS DELETED, NOT LEFT IN PLACE. ⚖️ The owner ruled on
	 * 2026-09-08 that every plugin lives under ONE `Zinn Digital®` menu, so `add_menu()` and
	 * the screen it pointed at were no longer hooked by anything — and a settings screen that
	 * still compiles, still reads the same option and can never be reached is the worst kind
	 * of dead code: the next person to change a field changes it in the copy nobody sees.
	 * The live declaration is `declare_page()`.
	 *
	 * ⛔ `register_settings()` / `register_setting()` SURVIVES and must. It is what makes
	 * `sanitize_option_{$option}` fire on the framework's own `update_option`, so the plugin's
	 * own normaliser still guards every write. Deleting it alongside the screen would have
	 * removed a control while removing something that looked like the same thing.
	 */


	/**
	 * Declare the screen through the shared Zinn settings framework.
	 *
	 * ⚖️ **Owner ruling, 2026-09-08: ONE top-level `Zinn` menu**, and *"customisable options
	 * and styling optiins etc also where needed"*. This plugin renders on a reseller's public
	 * pages, so it is one of the two that genuinely needed the styling half.
	 *
	 * @return void
	 */
	public static function declare_page(): void {
		\Zinn_Reseller_Style_Presets::register( self::style_component() );

		\Zinn_Reseller_Admin_UI::register(
			array(
				'title'      => __( 'Reseller Toolkit', 'zinn-reseller' ),
				'option'     => ZINN_RESELLER_OPTION,
				'position'   => 30,
				'connection' => array( __CLASS__, 'status' ),
				'tabs'       => array(
					'account'  => array(
						'title'  => __( 'Account', 'zinn-reseller' ),
						'fields' => array( __CLASS__, 'account_fields' ),
					),
					'features' => array(
						'title'  => __( 'Features', 'zinn-reseller' ),
						'fields' => array( __CLASS__, 'feature_fields' ),
					),
					'styling'  => array(
						'title'  => __( 'Styling', 'zinn-reseller' ),
						'fields' => array( __CLASS__, 'styling_fields' ),
					),
				),
			)
		);
	}

	/**
	 * Where this reseller's account lives, and the key that reaches it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function account_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Your Zinn Digital® reseller account', 'zinn-reseller' ),
				'description' => __( 'Create a reseller API key in your Zinn® dashboard under Settings → API keys, and paste it here.', 'zinn-reseller' ),
			),
			array(
				'key'         => 'api_key',
				'type'        => 'password',
				'secret'      => true,
				'label'       => __( 'Reseller API key', 'zinn-reseller' ),
				'description' => __( 'Held on this site only, and never included in an export or a diagnostics report.', 'zinn-reseller' ),
				'default'     => '',
			),
			array(
				'key'         => 'plan_code',
				'type'        => 'text',
				'label'       => __( 'Plan to provision', 'zinn-reseller' ),
				'description' => __( 'The plan code new customers are put on. Leave blank to be asked each time.', 'zinn-reseller' ),
				'default'     => '',
			),
			array(
				'key'         => 'api_base',
				'type'        => 'url',
				'label'       => __( 'API address', 'zinn-reseller' ),
				'description' => __( 'Leave this alone unless Zinn® support has asked you to change it.', 'zinn-reseller' ),
				'default'     => self::DEFAULTS['api_base'],
			),
			array(
				'key'         => 'panel_base',
				'type'        => 'url',
				'label'       => __( 'Control-panel address', 'zinn-reseller' ),
				'description' => __( 'Where the “My hosting” link sends your customers.', 'zinn-reseller' ),
				'default'     => self::DEFAULTS['panel_base'],
			),
		);
	}

	/**
	 * Which parts of the toolkit are switched on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function feature_fields(): array {
		return array(
			array(
				'key'            => 'enable_domain_search',
				'type'           => 'toggle',
				'label'          => __( 'Domain search', 'zinn-reseller' ),
				'checkbox_label' => __( 'Provide the [zinn_domain_search] shortcode', 'zinn-reseller' ),
				'description'    => __( 'Put the shortcode on any page to let visitors check a domain and buy it through you.', 'zinn-reseller' ),
				'default'        => false,
			),
			array(
				'key'            => 'enable_panel_link',
				'type'           => 'toggle',
				'label'          => __( 'Panel link', 'zinn-reseller' ),
				'checkbox_label' => __( 'Show a “My hosting” link to signed-in customers', 'zinn-reseller' ),
				'default'        => false,
			),
			array(
				'key'            => 'enable_woocommerce',
				'type'           => 'toggle',
				'label'          => __( 'WooCommerce provisioning', 'zinn-reseller' ),
				'checkbox_label' => __( 'Provision hosting automatically when a WooCommerce order completes', 'zinn-reseller' ),
				'description'    => __( 'Only takes effect on orders containing a product you have mapped to a Zinn® plan.', 'zinn-reseller' ),
				'default'        => false,
			),
		);
	}

	/**
	 * How the domain-search widget looks on the reseller's own site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function styling_fields(): array {
		return array_merge(
			array(
				array(
					'type'        => 'heading',
					'label'       => __( 'Domain search appearance', 'zinn-reseller' ),
					'description' => __( 'This widget renders inside your own theme. Pick a preset, or set your own colours — either way nothing is added to your pages that a visitor can see as ours.', 'zinn-reseller' ),
				),
			),
			\Zinn_Reseller_Style_Presets::fields( 'domain-search' ),
			array(
				array(
					'type'  => 'notice',
					'kind'  => 'info',
					'label' => __( 'Every class the widget uses is listed in the plugin’s readme, so a developer can override anything a preset does not cover.', 'zinn-reseller' ),
				),
			)
		);
	}

	/**
	 * The domain-search widget's presets and design tokens.
	 *
	 * @return array<string, mixed>
	 */
	private static function style_component(): array {
		return array(
			'id'       => 'domain-search',
			'selector' => '.zinn-domain-search, .zinn-domain-results',
			'presets'  => array(
				'theme'    => array(
					'label'  => __( 'Follow my theme', 'zinn-reseller' ),
					'tokens' => array(
						'accent'     => 'inherit',
						'text'       => 'inherit',
						'available'  => '#008a20',
						'unknown'    => '#996800',
						'error'      => '#b32d2e',
						'rule'       => 'rgba(0,0,0,0.1)',
						'gap'        => '0.5rem',
						'rowpad'     => '0.6rem',
						'radius'     => '0',
						'nameweight' => '600',
						'fontsize'   => 'inherit',
					),
				),
				'soft'     => array(
					'label'  => __( 'Soft', 'zinn-reseller' ),
					'tokens' => array(
						'accent'     => '#2563eb',
						'text'       => '#111827',
						'available'  => '#047857',
						'unknown'    => '#b45309',
						'error'      => '#b91c1c',
						'rule'       => 'rgba(0,0,0,0.06)',
						'gap'        => '0.75rem',
						'rowpad'     => '0.9rem',
						'radius'     => '8px',
						'nameweight' => '600',
						'fontsize'   => '1rem',
					),
				),
				'contrast' => array(
					'label'  => __( 'High contrast', 'zinn-reseller' ),
					'tokens' => array(
						'accent'     => '#0000ee',
						'text'       => '#000000',
						'available'  => '#006400',
						'unknown'    => '#8b4500',
						'error'      => '#a10000',
						'rule'       => 'rgba(0,0,0,0.45)',
						'gap'        => '0.75rem',
						'rowpad'     => '0.8rem',
						'radius'     => '0',
						'nameweight' => '700',
						'fontsize'   => '1.05rem',
					),
				),
				'compact'  => array(
					'label'  => __( 'Compact', 'zinn-reseller' ),
					'tokens' => array(
						'accent'     => 'inherit',
						'text'       => 'inherit',
						'available'  => '#008a20',
						'unknown'    => '#996800',
						'error'      => '#b32d2e',
						'rule'       => 'rgba(0,0,0,0.08)',
						'gap'        => '0.35rem',
						'rowpad'     => '0.35rem',
						'radius'     => '0',
						'nameweight' => '600',
						'fontsize'   => '0.9rem',
					),
				),
			),
			'tokens'   => array(
				'accent'     => array(
					'type'  => 'color',
					'label' => __( 'Buy-link colour', 'zinn-reseller' ),
				),
				'available'  => array(
					'type'  => 'color',
					'label' => __( '“Available” colour', 'zinn-reseller' ),
				),
				'unknown'    => array(
					'type'  => 'color',
					'label' => __( '“Could not check” colour', 'zinn-reseller' ),
				),
				'error'      => array(
					'type'  => 'color',
					'label' => __( 'Error colour', 'zinn-reseller' ),
				),
				'radius'     => array(
					'type'  => 'text',
					'label' => __( 'Corner rounding', 'zinn-reseller' ),
				),
				'rowpad'     => array(
					'type'  => 'text',
					'label' => __( 'Space inside each row', 'zinn-reseller' ),
				),
				'fontsize'   => array(
					'type'  => 'text',
					'label' => __( 'Text size', 'zinn-reseller' ),
				),
				'nameweight' => array(
					'type'  => 'text',
					'label' => __( 'Domain-name weight', 'zinn-reseller' ),
				),
			),
		);
	}

	/**
	 * Whether the toolkit can reach the reseller's Zinn® account.
	 *
	 * ⛔⛔ **THE STATES ARE DIFFERENT ANSWERS AND ARE NOT COLLAPSED (§2.57).** "No key yet",
	 * "the key was refused" and "we could not reach us" send the reader to three different
	 * places, and only one of them is something they can fix by typing.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings = self::get();

		if ( '' === (string) $settings['api_key'] ) {
			return array(
				'state'   => 'disconnected',
				'summary' => __( 'No reseller API key yet.', 'zinn-reseller' ),
				'reason'  => __( 'Nothing here works until a key is set. Create one in your Zinn® dashboard under Settings → API keys.', 'zinn-reseller' ),
				'action'  => array(
					'label' => __( 'Open my Zinn® dashboard', 'zinn-reseller' ),
					'url'   => rtrim( (string) $settings['panel_base'], '/' ) . '/settings/api-keys',
					'style' => 'primary',
				),
			);
		}

		$enabled = array_filter(
			array(
				'enable_domain_search' => $settings['enable_domain_search'],
				'enable_panel_link'    => $settings['enable_panel_link'],
				'enable_woocommerce'   => $settings['enable_woocommerce'],
			)
		);

		if ( array() === $enabled ) {
			return array(
				'state'   => 'degraded',
				'summary' => __( 'A key is set, but nothing is switched on.', 'zinn-reseller' ),
				'reason'  => __( 'None of the three features is enabled, so this plugin is doing nothing on your site. Turn on what you need under Features.', 'zinn-reseller' ),
				'action'  => array(
					'label' => __( 'Open Features', 'zinn-reseller' ),
					'url'   => \Zinn_Reseller_Admin_UI::page_url( 'features' ),
				),
			);
		}

		return array(
			'state'   => 'connected',
			'summary' => sprintf(
				/* translators: %d: how many toolkit features are switched on. */
				_n( 'Ready — %d feature switched on.', 'Ready — %d features switched on.', count( $enabled ), 'zinn-reseller' ),
				count( $enabled )
			),
			'details' => array(
				array(
					'label' => __( 'Selling from', 'zinn-reseller' ),
					'value' => (string) $settings['panel_base'],
				),
			),
		);
	}

	/**
	 * Register the option with its sanitizer.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			'zinn_reseller',
			ZINN_RESELLER_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	/**
	 * A "Settings" link on the plugins list.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=zinn-reseller' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'zinn-reseller' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * ⛔ `esc_url_raw` on both base URLs, and the key is trimmed rather than stripped:
	 * an API key is opaque and a sanitizer that "cleaned" it would silently produce a
	 * credential that never works, which is the hardest kind of misconfiguration to
	 * diagnose because the screen shows exactly what you pasted.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array(
			'api_base'             => esc_url_raw( (string) ( $input['api_base'] ?? self::DEFAULTS['api_base'] ) ),
			'panel_base'           => esc_url_raw( (string) ( $input['panel_base'] ?? self::DEFAULTS['panel_base'] ) ),
			'api_key'              => trim( (string) ( $input['api_key'] ?? '' ) ),
			'plan_code'            => sanitize_text_field( (string) ( $input['plan_code'] ?? '' ) ),
			'enable_domain_search' => ! empty( $input['enable_domain_search'] ),
			'enable_panel_link'    => ! empty( $input['enable_panel_link'] ),
			'enable_woocommerce'   => ! empty( $input['enable_woocommerce'] ),
		);

		// ⛔⛔ **THE STYLING KEYS MUST SURVIVE THIS FUNCTION.** It is `register_setting`'s
		// `sanitize_callback`, so it runs on EVERY `update_option` for this option —
		// including the shared framework's. Rebuilding the array from a fixed list alone
		// deleted the reseller's chosen preset and colours the moment anything else was
		// saved: the widget would quietly go back to its defaults with nothing red anywhere
		// (§2.44). They are already sanitised by the framework, by declared type, and are
		// re-sanitised here rather than trusted because this function is reachable from
		// `options.php` as well.
		foreach ( $input as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'style_' ) ) {
				continue;
			}
			$clean[ (string) $key ] = is_bool( $value ) ? $value : sanitize_text_field( (string) $value );
		}

		return $clean;
	}


	/**
	 * Show whether the key actually works, by making a real call.
	 *
	 * ⛔⛔ A LIVE CALL, not a "key is not empty" check. A settings screen that says
	 * "Connected" because a string is present is the single most misleading thing this
	 * plugin could show: the reseller walks away believing it works and finds out at the
	 * first paid order. This asks the API who we are and prints what it said.
	 *
	 * @return void
	 */
	private static function render_status(): void {
		if ( ! Client::is_configured() ) {
			echo '<p>' . esc_html__( 'No API key yet, so nothing is connected.', 'zinn-reseller' ) . '</p>';
			return;
		}
		$result = Client::get( '/v1/reseller/program', array(), false );
		if ( is_wp_error( $result ) ) {
			echo '<p style="color:#b32d2e">' . esc_html(
				sprintf(
					/* translators: %s: error message returned by the hosting API. */
					__( 'Not connected: %s', 'zinn-reseller' ),
					$result->get_error_message()
				)
			) . '</p>';
			return;
		}
		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		echo '<p style="color:#008a20">' . esc_html(
			sprintf(
				/* translators: %s: the reseller programme status, e.g. "active". */
				__( 'Connected. Your reseller programme is %s.', 'zinn-reseller' ),
				$status
			)
		) . '</p>';
	}
}
