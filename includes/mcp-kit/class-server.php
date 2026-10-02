<?php
/**
 * The plugin's own MCP server: its abilities, as MCP tools, on the site itself.
 *
 * Generated from wp/packages/zinn-mcp-kit/src/class-server.php by wp/bin/build-mcp-kit.php.
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
 * One server per plugin, at `/wp-json/<rest namespace>/mcp` (HTTP) and
 * `wp mcp-adapter serve --server=<id> --user=<login>` (STDIO, WP-CLI).
 *
 * Who may connect: a SIGNED-IN user (an application password over HTTP, `--user` over WP-CLI)
 * holding the plugin's capability. Each tool then checks its own permission again for the exact
 * post it touches. Nothing is reachable anonymously, and a site owner turns the whole thing off
 * with the plugin's "Allow AI agents (MCP)" setting: with it off, neither the abilities nor the
 * server are registered at all.
 */
final class Server {

	/**
	 * The host's configuration.
	 *
	 * @var array<string, mixed>
	 */
	private static array $config = array();

	/**
	 * Boot the kit for a plugin.
	 *
	 * @param array<string, mixed> $config `id` (server id, the plugin slug), `rest_namespace`
	 *                                     (e.g. `pbs/v1`), `name`, `description` (string or
	 *                                     callable), `version`,
	 *                                     `capability` (to connect at all), `category`
	 *                                     (`slug`, `label`, `description`), `enabled` (callable
	 *                                     returning bool: the site owner's switch),
	 *                                     `abilities` (callable that registers the abilities
	 *                                     with Ability::register), `vendor_dir`, `docs`
	 *                                     (`guide`, `developers`: the product site's user
	 *                                     guide and developer docs URLs).
	 * @return void
	 */
	public static function boot( array $config ): void {
		self::$config = $config;
		if ( ! self::enabled() ) {
			return;
		}
		Adapter::offer( (string) $config['vendor_dir'] );
		// Claude, ChatGPT and other AI apps can also sign in with OAuth 2.1 (this site is the
		// authorization server). Application passwords keep working.
		OAuth::offer( (string) $config['rest_namespace'] . '/mcp', self::text( $config['name'] ), (string) $config['capability'] );
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', $config['abilities'] );
		add_action( 'mcp_adapter_init', array( self::class, 'create' ) );
	}

	/**
	 * Is the site owner's switch on? Read when the kit boots, so turning it off takes effect on
	 * the next request.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( Adapter::killed() ) {
			return false;
		}
		$enabled = self::$config['enabled'] ?? null;

		return is_callable( $enabled ) && true === (bool) $enabled();
	}

	/**
	 * Register the plugin's ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		$category = (array) self::$config['category'];
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				(string) $category['slug'],
				array(
					'label'       => self::text( $category['label'] ),
					'description' => self::text( $category['description'] ),
				)
			);
		}
	}

	/**
	 * The plugin's abilities (the ones in its category), by name.
	 *
	 * @param string $type `tool`, `prompt`, or '' for both.
	 * @return array<int, string>
	 */
	public static function ability_names( string $type = '' ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$slug  = (string) ( (array) self::$config['category'] )['slug'];
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$kind = (string) ( $ability->get_meta()['mcp']['type'] ?? 'tool' );
			if ( $ability->get_category() === $slug && ( '' === $type || $kind === $type ) ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );

		return $names;
	}

	/**
	 * Create the server on the adapter.
	 *
	 * @param object $adapter The adapter (`WP\MCP\Core\McpAdapter`).
	 * @return void
	 */
	public static function create( $adapter ): void {
		$config     = self::$config;
		$capability = (string) $config['capability'];
		$transport  = '\\WP\\MCP\\Transport\\HttpTransport';
		$errors     = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler';
		$metrics    = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$adapter->create_server(
			(string) $config['id'],
			(string) $config['rest_namespace'],
			'mcp',
			self::text( $config['name'] ),
			self::text( $config['description'] ),
			(string) $config['version'],
			array( ltrim( $transport, '\\' ) ),
			ltrim( $errors, '\\' ),
			ltrim( $metrics, '\\' ),
			self::ability_names( 'tool' ),
			array(),
			self::ability_names( 'prompt' ),
			static fn(): bool => is_user_logged_in() && current_user_can( $capability ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- the host names a core capability.
		);
	}

	/**
	 * A configured text: a string, or a callable returning one. Translated texts are passed as
	 * callables because the kit boots while the plugin file loads, before WordPress may load a
	 * translation (`_load_textdomain_just_in_time` notice since 6.7).
	 *
	 * @param mixed $value String or callable.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_callable( $value ) ? (string) $value() : (string) $value;
	}

	/**
	 * What the settings screen shows: the switch, where to connect, and the tools.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$config = self::$config;
		$route  = (string) ( $config['rest_namespace'] ?? '' ) . '/mcp';

		return array(
			'enabled'   => self::enabled(),
			'available' => function_exists( 'wp_register_ability' ),
			'endpoint'  => rest_url( $route ),
			'server'    => (string) ( $config['id'] ?? '' ),
			'abilities' => self::enabled() ? self::ability_names() : array(),
			'passwords' => admin_url( 'profile.php#application-passwords-section' ),
			'oauth'     => array(
				'available' => OAuth::available(),
				'apps'      => array_map(
					static fn( array $app ): array => array(
						'id'        => (string) $app['id'],
						'name'      => (string) ( $app['client_name'] ?? '' ),
						'created'   => (int) ( $app['created'] ?? 0 ),
						'last_used' => (int) ( $app['last_used'] ?? 0 ),
						'nonce'     => wp_create_nonce( 'zinn_mcp_disconnect_' . $app['id'] ),
					),
					OAuth::connected()
				),
				'action'    => admin_url( 'admin-post.php' ),
			),
			'docs'      => array(
				'guide'      => esc_url_raw( (string) ( $config['docs']['guide'] ?? '' ) ),
				'developers' => esc_url_raw( (string) ( $config['docs']['developers'] ?? '' ) ),
			),
		);
	}

	/**
	 * The same panel as js/McpPanel.js, rendered by PHP for a plugin whose settings screens are PHP.
	 *
	 * Prints a fieldset. With `$input_name` it carries the switch for the host's own settings form
	 * (a checkbox, value 1, posted with the rest of that form; the host saves it to the setting
	 * `enabled` reads). Without one the host draws the switch itself (a settings field of its own)
	 * and this shows the rest: the address, the client configuration, the tools and the docs.
	 *
	 * @param string $input_name The checkbox's form field name, or '' when the host draws it.
	 * @return void
	 */
	public static function render_panel( string $input_name = '' ): void {
		Rest_Bridge::allow(); // This screen lists every tool, the bridged ones included.
		$mcp    = self::describe();
		$server = (string) ( self::$config['id'] ?? '' );
		$config = (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$server => array(
						'command' => 'npx',
						'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
						'env'     => array(
							'WP_API_URL'      => $mcp['endpoint'],
							'WP_API_USERNAME' => 'your-username',
							'WP_API_PASSWORD' => 'your-application-password',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<fieldset class="zd-mcp-panel">
			<legend><strong><?php esc_html_e( 'AI agents (MCP)', 'zinn-reseller' ); ?></strong></legend>
			<p><?php esc_html_e( 'Let AI assistants such as Claude, Cursor or VS Code work on this site for you through MCP (Model Context Protocol). They sign in as a WordPress user (Claude and ChatGPT by approving them here, others with an application password) and can do only what that user is allowed to do.', 'zinn-reseller' ); ?></p>
			<?php if ( ! $mcp['available'] ) : ?>
				<p><?php esc_html_e( 'AI agents need WordPress 6.9 or later. Update WordPress to use them.', 'zinn-reseller' ); ?></p>
			<?php else : ?>
				<?php if ( '' !== $input_name ) : ?>
				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( $mcp['enabled'] ); ?> />
						<?php esc_html_e( 'Allow AI agents (MCP)', 'zinn-reseller' ); ?>
					</label>
					<br /><span class="description"><?php esc_html_e( 'Off: the tools below and their REST routes are not registered at all.', 'zinn-reseller' ); ?></span>
				</p>
				<?php endif; ?>
				<?php if ( $mcp['enabled'] ) : ?>
					<p>
						<?php esc_html_e( 'Connection address (MCP endpoint)', 'zinn-reseller' ); ?><br />
						<code dir="ltr"><?php echo esc_html( $mcp['endpoint'] ); ?></code>
					</p>
					<?php if ( $mcp['oauth']['available'] ) : ?>
						<p><strong><?php esc_html_e( 'Claude and ChatGPT: sign in instead', 'zinn-reseller' ); ?></strong><br />
						<?php esc_html_e( 'Add the connection address as a custom connector in Claude, or as an app in ChatGPT developer mode. They open this site so you can approve them; no password to copy.', 'zinn-reseller' ); ?></p>
						<?php if ( ! empty( $mcp['oauth']['apps'] ) ) : ?>
							<p><?php esc_html_e( 'AI apps connected to your account:', 'zinn-reseller' ); ?></p>
							<ul class="zd-mcp-panel__apps">
								<?php foreach ( $mcp['oauth']['apps'] as $app ) : ?>
									<li>
										<?php echo esc_html( $app['name'] ); ?>
										<form method="post" action="<?php echo esc_url( $mcp['oauth']['action'] ); ?>" style="display:inline">
											<input type="hidden" name="action" value="zinn_mcp_oauth_disconnect" />
											<input type="hidden" name="app" value="<?php echo esc_attr( $app['id'] ); ?>" />
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $app['nonce'] ); ?>" />
											<button type="submit" class="button-link"><?php esc_html_e( 'Disconnect', 'zinn-reseller' ); ?></button>
										</form>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p><strong><?php esc_html_e( 'Other AI apps: an application password', 'zinn-reseller' ); ?></strong></p>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( $mcp['passwords'] ); ?>"><?php esc_html_e( 'Create an application password for your user', 'zinn-reseller' ); ?></a></p>
					<p><?php esc_html_e( 'Add this to your AI app’s MCP settings, with your username and that password:', 'zinn-reseller' ); ?></p>
					<pre class="zd-mcp-panel__config" dir="ltr"><?php echo esc_html( $config ); ?></pre>
					<p class="description"><?php esc_html_e( 'VS Code: put the same entry under "servers" in .vscode/mcp.json, not under "mcpServers".', 'zinn-reseller' ); ?></p>
					<p>
						<?php
						/* translators: %d: how many tools an AI agent can use. */
						echo esc_html( sprintf( __( 'Tools an AI agent can use here (%d):', 'zinn-reseller' ), count( $mcp['abilities'] ) ) );
						?>
					</p>
					<ul class="zd-mcp-panel__tools" dir="ltr">
						<?php foreach ( $mcp['abilities'] as $name ) : ?>
							<li><code><?php echo esc_html( $name ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( '' !== $mcp['docs']['guide'] ) : ?>
				<p><a href="<?php echo esc_url( $mcp['docs']['guide'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'User guide: AI agents and MCP', 'zinn-reseller' ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $mcp['docs']['developers'] ) : ?>
				<p><a href="<?php echo esc_url( $mcp['docs']['developers'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Developer docs: abilities, REST and MCP', 'zinn-reseller' ); ?></a></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * The panel without its switch, as markup that survives `wp_kses_post` (for a settings screen
	 * that prints declared HTML fields through it, as the shared admin UI does).
	 *
	 * @return string
	 */
	public static function panel_html(): string {
		ob_start();
		self::render_panel();
		return (string) ob_get_clean();
	}
}
