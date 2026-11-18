<?php
/**
 * Reseller Toolkit abilities: the reseller's clients, plans and services on Zinn Digital®, for AI
 * agents (MCP) and REST.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller\Mcp;

use Zinn\Reseller\Client;
use Zinn\Reseller\McpKit\Ability;
use Zinn\Reseller\McpKit\Rest_Bridge;
use Zinn\Reseller\McpKit\Server;
use Zinn\Reseller\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The abilities (owner, 2026-09-30: list clients, sites and plans; create and suspend behind the
 * reseller's own permissions, through our API).
 *
 * ⛔ Nothing here decides what a reseller may do: every call goes to the Zinn Digital® API with the
 * reseller key saved in this plugin, and the API applies that account's permissions, its plan and
 * its good standing. On this site, only an administrator (the person who can see that key's
 * screen) may use them. The key itself is never returned.
 */
final class Abilities {

	/** The ability category. */
	public const CATEGORY = 'zinn-reseller';

	/** Who may use them on this site: the Reseller Toolkit screen's capability. */
	public const CAPABILITY = 'manage_options';

	/** An id the API issues (a UUID). */
	private const ID_PATTERN = '^[0-9a-fA-F-]{36}$';

	/**
	 * Boot the MCP kit for this plugin.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Server::boot(
			array(
				'id'             => 'zinn-reseller',
				'rest_namespace' => 'zinn-reseller/v1',
				'name'           => 'Zinn® Reseller Toolkit',
				'description'    => static fn(): string => __( 'Manage your hosting clients, their plans and their sites on Zinn Digital® from this site.', 'zinn-reseller' ),
				'version'        => \Zinn\Reseller\ZINN_RESELLER_VERSION,
				'capability'     => self::CAPABILITY,
				'category'       => array(
					'slug'        => self::CATEGORY,
					'label'       => static fn(): string => __( 'Reseller Toolkit', 'zinn-reseller' ),
					'description' => static fn(): string => __( 'Your clients, their plans and their sites, through your Zinn Digital® reseller account.', 'zinn-reseller' ),
				),
				'enabled'        => static fn(): bool => (bool) ( Settings::get()['mcp'] ?? true ),
				'abilities'      => array( self::class, 'register' ),
				'vendor_dir'     => dirname( __DIR__, 2 ) . '/vendor/wordpress',
				'docs'           => array(
					'guide'      => 'https://zinndigital.com/wordpress-plugins/zinn-reseller/mcp',
					'developers' => 'https://zinndigital.com/wordpress-plugins/zinn-reseller/mcp-api',
				),
			)
		);
	}

	/**
	 * Register the abilities (on `wp_abilities_api_init`).
	 *
	 * @return void
	 */
	public static function register(): void {
		$id     = array(
			'type'    => 'string',
			'pattern' => self::ID_PATTERN,
		);
		$page   = array(
			'cursor' => array( 'type' => 'string' ),
			'limit'  => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
		);
		$read   = array(
			'readonly'   => true,
			'idempotent' => true,
		);
		$schema = static function ( array $props, array $required = array() ): array {
			$out = array(
				'type'                 => 'object',
				'properties'           => $props,
				'additionalProperties' => false,
			);
			if ( $required ) {
				$out['required'] = $required;
			}
			return $out;
		};
		$defs   = array(
			'zinn-reseller/get-overview'    => array(
				__( 'Read your reseller account', 'zinn-reseller' ),
				__( 'Returns your reseller account in one read: brand, plans, clients and services counts, and what is set up.', 'zinn-reseller' ),
				$schema( array() ),
				array( self::class, 'overview' ),
				$read,
			),
			'zinn-reseller/list-clients'    => array(
				__( 'List your clients', 'zinn-reseller' ),
				__( 'Lists your client accounts, a page at a time (cursor, limit).', 'zinn-reseller' ),
				$schema( $page ),
				array( self::class, 'list_clients' ),
				$read,
			),
			'zinn-reseller/create-client'   => array(
				__( 'Create a client', 'zinn-reseller' ),
				__( 'Creates a client account under yours (name; default_currency, a three-letter code). Put them on a plan before their first site.', 'zinn-reseller' ),
				$schema(
					array(
						'name'             => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'default_currency' => array(
							'type'    => 'string',
							'pattern' => '^[A-Z]{3}$',
						),
					),
					array( 'name' )
				),
				array( self::class, 'create_client' ),
				array( 'destructive' => false ),
			),
			'zinn-reseller/get-client-plan' => array(
				__( 'Read a client\'s plan', 'zinn-reseller' ),
				__( 'Returns which plan a client is on and what it costs you (client_id).', 'zinn-reseller' ),
				$schema( array( 'client_id' => $id ), array( 'client_id' ) ),
				array( self::class, 'get_client_plan' ),
				$read,
			),
			'zinn-reseller/set-client-plan' => array(
				__( 'Put a client on a plan', 'zinn-reseller' ),
				__( 'Puts a client on one of your plans (client_id, plan_code; interval: month or year). Do this before their first site.', 'zinn-reseller' ),
				$schema(
					array(
						'client_id' => $id,
						'plan_code' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'interval'  => array(
							'type' => 'string',
							'enum' => array( 'month', 'year' ),
						),
					),
					array( 'client_id', 'plan_code' )
				),
				array( self::class, 'set_client_plan' ),
				array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			'zinn-reseller/list-plans'      => array(
				__( 'List the plans you can sell', 'zinn-reseller' ),
				__( 'Lists the hosting plans and their prices (product_line and currency narrow it).', 'zinn-reseller' ),
				$schema(
					array(
						'product_line' => array( 'type' => 'string' ),
						'currency'     => array(
							'type'    => 'string',
							'pattern' => '^[A-Z]{3}$',
						),
					)
				),
				array( self::class, 'list_plans' ),
				$read,
			),
			'zinn-reseller/list-sites'      => array(
				__( 'List your clients\' sites', 'zinn-reseller' ),
				__( 'Lists everything you have provisioned for your clients (client_id and status narrow it), a page at a time.', 'zinn-reseller' ),
				$schema(
					$page + array(
						'client_id' => $id,
						'status'    => array( 'type' => 'string' ),
					)
				),
				array( self::class, 'list_sites' ),
				$read,
			),
			'zinn-reseller/create-site'     => array(
				__( 'Create a site for a client', 'zinn-reseller' ),
				__( 'Creates a hosted site for one of your clients (client_id, product_line, primary_domain). It is billed to your account at the client\'s plan, as from your shop.', 'zinn-reseller' ),
				$schema(
					array(
						'client_id'      => $id,
						'product_line'   => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'primary_domain' => array(
							'type'      => 'string',
							'minLength' => 3,
							'maxLength' => 253,
						),
					),
					array( 'client_id', 'product_line', 'primary_domain' )
				),
				array( self::class, 'create_site' ),
				array( 'destructive' => false ),
			),
			'zinn-reseller/suspend-site'    => array(
				__( 'Suspend a client\'s site', 'zinn-reseller' ),
				__( 'Puts a client\'s site on a non-payment hold (site_id; reason: your own note, never shown to the client). The site stops serving until it is unsuspended.', 'zinn-reseller' ),
				$schema(
					array(
						'site_id' => $id,
						'reason'  => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
					),
					array( 'site_id' )
				),
				array( self::class, 'suspend_site' ),
				array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			'zinn-reseller/unsuspend-site'  => array(
				__( 'Unsuspend a client\'s site', 'zinn-reseller' ),
				__( 'Lifts a non-payment hold, so the site serves again (site_id).', 'zinn-reseller' ),
				$schema( array( 'site_id' => $id ), array( 'site_id' ) ),
				array( self::class, 'unsuspend_site' ),
				array(
					'destructive' => false,
					'idempotent'  => true,
				),
			),
			'zinn-reseller/get-settings'    => array(
				__( 'Read the Reseller Toolkit settings', 'zinn-reseller' ),
				__( 'Returns this plugin\'s settings (the API address, the plan new orders use, which features are on). The API key is never returned, only whether one is saved.', 'zinn-reseller' ),
				$schema( array() ),
				array( self::class, 'settings' ),
				$read,
			),
			'zinn-reseller/update-settings' => array(
				__( 'Change the Reseller Toolkit settings', 'zinn-reseller' ),
				__( 'Changes the settings you send (plan_code, enable_domain_search, enable_panel_link, enable_woocommerce) and keeps the rest. The API key and address are changed on the screen only.', 'zinn-reseller' ),
				$schema(
					array(
						'plan_code'            => array( 'type' => 'string' ),
						'enable_domain_search' => array( 'type' => 'boolean' ),
						'enable_panel_link'    => array( 'type' => 'boolean' ),
						'enable_woocommerce'   => array( 'type' => 'boolean' ),
					)
				),
				array( self::class, 'update_settings' ),
				array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		);

		foreach ( $defs as $name => $def ) {
			Ability::register(
				$name,
				array(
					'edition'             => 'free',
					'capability'          => self::CAPABILITY,
					'label'               => $def[0],
					'description'         => $def[1],
					'category'            => self::CATEGORY,
					'input_schema'        => $def[2],
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => $def[3],
					'permission_callback' => static fn(): bool => current_user_can( self::CAPABILITY ),
					'annotations'         => $def[4],
				)
			);
		}

		// Any REST route of the plugin, as an ability of its own (none today; a new route must be
		// named in wp/bin/mcp-rest-map.py, or the derived test fails).
		require_once __DIR__ . '/class-rest-map.php';
		Rest_Bridge::register( Rest_Map::entries(), self::CATEGORY );
	}

	/**
	 * The plugin must be connected to an account before anything can be asked of it.
	 *
	 * @return \WP_Error|null
	 */
	private static function unconnected(): ?\WP_Error {
		return Client::is_configured() ? null : new \WP_Error(
			'zinn_reseller_not_connected',
			__( 'Add your Zinn Digital® reseller API key on the Reseller Toolkit screen first.', 'zinn-reseller' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A GET to the API, never from this plugin's short cache (an agent acts on what it reads).
	 *
	 * @param string               $path  API path.
	 * @param array<string, mixed> $query Query.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function read( string $path, array $query = array() ) {
		$error = self::unconnected();
		if ( $error ) {
			return $error;
		}
		$query = array_filter(
			$query,
			static fn( $value ): bool => null !== $value && '' !== $value
		);
		$out   = Client::get( $path, array_map( 'strval', $query ), false );

		return is_wp_error( $out ) ? $out : (array) $out;
	}

	/**
	 * A POST to the API.
	 *
	 * @param string               $path API path.
	 * @param array<string, mixed> $body Body.
	 * @param string               $key  Idempotency key ('' for none).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function write( string $path, array $body, string $key = '' ) {
		$error = self::unconnected();
		if ( $error ) {
			return $error;
		}
		$out = Client::post( $path, $body, $key );

		return is_wp_error( $out ) ? $out : (array) $out;
	}

	/**
	 * Zinn-reseller/get-overview.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview() {
		return self::read( '/v1/reseller/overview' );
	}

	/**
	 * Zinn-reseller/list-clients.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_clients( array $input = array() ) {
		return self::read(
			'/v1/orgs',
			array(
				'type'   => 'customer',
				'cursor' => $input['cursor'] ?? null,
				'limit'  => $input['limit'] ?? null,
			)
		);
	}

	/**
	 * Zinn-reseller/create-client.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_client( array $input ) {
		$body = array(
			'type' => 'customer',
			'name' => sanitize_text_field( (string) $input['name'] ),
		);
		if ( ! empty( $input['default_currency'] ) ) {
			$body['default_currency'] = (string) $input['default_currency'];
		}

		return self::write( '/v1/orgs', $body );
	}

	/**
	 * Zinn-reseller/get-client-plan.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_client_plan( array $input ) {
		return self::read( '/v1/reseller/clients/' . rawurlencode( (string) $input['client_id'] ) . '/plan' );
	}

	/**
	 * Zinn-reseller/set-client-plan.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_client_plan( array $input ) {
		$body = array( 'plan_code' => (string) $input['plan_code'] );
		if ( ! empty( $input['interval'] ) ) {
			$body['interval'] = (string) $input['interval'];
		}

		return self::write( '/v1/reseller/clients/' . rawurlencode( (string) $input['client_id'] ) . '/plan', $body );
	}

	/**
	 * Zinn-reseller/list-plans.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_plans( array $input = array() ) {
		return self::read(
			'/v1/catalog/plans',
			array(
				'product_line' => $input['product_line'] ?? null,
				'currency'     => $input['currency'] ?? null,
			)
		);
	}

	/**
	 * Zinn-reseller/list-sites.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_sites( array $input = array() ) {
		return self::read(
			'/v1/reseller/services',
			array(
				'client_org_id' => $input['client_id'] ?? null,
				'status'        => $input['status'] ?? null,
				'cursor'        => $input['cursor'] ?? null,
				'limit'         => $input['limit'] ?? null,
			)
		);
	}

	/**
	 * Zinn-reseller/create-site (idempotent per client and domain, so a retried call cannot buy twice).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_site( array $input ) {
		$domain = strtolower( trim( (string) $input['primary_domain'] ) );

		return self::write(
			'/v1/sites',
			array(
				'org_id'         => (string) $input['client_id'],
				'product_line'   => (string) $input['product_line'],
				'primary_domain' => $domain,
				'name'           => $domain,
			),
			'mcp-site-' . md5( (string) $input['client_id'] . '|' . $domain )
		);
	}

	/**
	 * Zinn-reseller/suspend-site.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function suspend_site( array $input ) {
		$body = array();
		if ( isset( $input['reason'] ) ) {
			$body['reason'] = sanitize_text_field( (string) $input['reason'] );
		}

		return self::write( '/v1/reseller/services/' . rawurlencode( (string) $input['site_id'] ) . '/suspend', $body );
	}

	/**
	 * Zinn-reseller/unsuspend-site.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function unsuspend_site( array $input ) {
		return self::write( '/v1/reseller/services/' . rawurlencode( (string) $input['site_id'] ) . '/unsuspend', array() );
	}

	/**
	 * Zinn-reseller/get-settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$settings                = Settings::get();
		$settings['api_key_set'] = '' !== (string) ( $settings['api_key'] ?? '' );
		unset( $settings['api_key'], $settings['mcp'] );

		return $settings;
	}

	/**
	 * Zinn-reseller/update-settings (through the screen's own sanitiser).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function update_settings( array $input ): array {
		$allowed = array_intersect_key( $input, array_flip( array( 'plan_code', 'enable_domain_search', 'enable_panel_link', 'enable_woocommerce' ) ) );
		update_option( \Zinn\Reseller\ZINN_RESELLER_OPTION, Settings::sanitize( array_merge( Settings::get(), $allowed ) ) );

		return self::settings();
	}

	/**
	 * The screen's MCP details (the switch is the tab's own field).
	 *
	 * @return string
	 */
	public static function panel_html(): string {
		return Server::panel_html();
	}
}
