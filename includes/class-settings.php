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
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
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

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'Zinn® Reseller Toolkit', 'zinn-reseller' ),
			__( 'Zinn® Reseller', 'zinn-reseller' ),
			'manage_options',
			'zinn-reseller',
			array( __CLASS__, 'render' )
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
		return array(
			'api_base'             => esc_url_raw( (string) ( $input['api_base'] ?? self::DEFAULTS['api_base'] ) ),
			'panel_base'           => esc_url_raw( (string) ( $input['panel_base'] ?? self::DEFAULTS['panel_base'] ) ),
			'api_key'              => trim( (string) ( $input['api_key'] ?? '' ) ),
			'plan_code'            => sanitize_text_field( (string) ( $input['plan_code'] ?? '' ) ),
			'enable_domain_search' => ! empty( $input['enable_domain_search'] ),
			'enable_panel_link'    => ! empty( $input['enable_panel_link'] ),
			'enable_woocommerce'   => ! empty( $input['enable_woocommerce'] ),
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Zinn® Reseller Toolkit', 'zinn-reseller' ); ?></h1>
			<p>
				<?php
				echo esc_html__(
					'Connect this site to your Zinn® reseller account, then switch on only the parts you need.',
					'zinn-reseller'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'zinn_reseller' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="zinn-reseller-key"><?php echo esc_html__( 'API key', 'zinn-reseller' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								class="regular-text"
								id="zinn-reseller-key"
								name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[api_key]"
								value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>"
								autocomplete="off"
							/>
							<p class="description">
								<?php
								echo esc_html__(
									'Create one in your Zinn® dashboard under API keys. Give it only the permissions the modules below need.',
									'zinn-reseller'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zinn-reseller-api-base"><?php echo esc_html__( 'API address', 'zinn-reseller' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								class="regular-text code"
								id="zinn-reseller-api-base"
								name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[api_base]"
								value="<?php echo esc_attr( (string) $settings['api_base'] ); ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zinn-reseller-panel-base"><?php echo esc_html__( 'Panel address', 'zinn-reseller' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								class="regular-text code"
								id="zinn-reseller-panel-base"
								name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[panel_base]"
								value="<?php echo esc_attr( (string) $settings['panel_base'] ); ?>"
							/>
							<p class="description">
								<?php
								echo esc_html__(
									'Where your clients are sent to manage their hosting. If you have set a panel hostname under Reselling → Your brand, put it here — your clients then never see our address at all.',
									'zinn-reseller'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Modules', 'zinn-reseller' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[enable_domain_search]"
										value="1"
										<?php checked( ! empty( $settings['enable_domain_search'] ) ); ?>
									/>
									<?php
									echo esc_html__(
										'Domain search — the [zinn_domain_search] shortcode, priced from your own domain account.',
										'zinn-reseller'
									);
									?>
								</label><br />
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[enable_panel_link]"
										value="1"
										<?php checked( ! empty( $settings['enable_panel_link'] ) ); ?>
									/>
									<?php
									echo esc_html__(
										'Panel link — the [zinn_panel_link] shortcode signs a logged-in client into their hosting.',
										'zinn-reseller'
									);
									?>
								</label><br />
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[enable_woocommerce]"
										value="1"
										<?php checked( ! empty( $settings['enable_woocommerce'] ) ); ?>
									/>
									<?php
									echo esc_html__(
										'WooCommerce — provision hosting automatically when an order is paid.',
										'zinn-reseller'
									);
									?>
								</label>
								<?php if ( ! empty( $settings['enable_woocommerce'] ) && ! class_exists( 'WooCommerce' ) ) : ?>
									<p class="description" style="color:#b32d2e">
										<?php
										echo esc_html__(
											'WooCommerce is not active on this site, so this module is doing nothing.',
											'zinn-reseller'
										);
										?>
									</p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="zinn-reseller-plan"><?php echo esc_html__( 'Default plan code', 'zinn-reseller' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text code"
								id="zinn-reseller-plan"
								name="<?php echo esc_attr( ZINN_RESELLER_OPTION ); ?>[plan_code]"
								value="<?php echo esc_attr( (string) $settings['plan_code'] ); ?>"
							/>
							<p class="description">
								<?php
								echo esc_html__(
									'Used for a WooCommerce product that does not name its own plan. Set the per-product plan on the product itself under Inventory.',
									'zinn-reseller'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php echo esc_html__( 'Connection', 'zinn-reseller' ); ?></h2>
			<?php self::render_status(); ?>
		</div>
		<?php
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
