<?php
/**
 * Registers one ability the way every Zinn plugin must: with a real permission check.
 *
 * Generated from wp/packages/zinn-mcp-kit/src/class-ability.php by wp/bin/build-mcp-kit.php.
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
 * A thin, strict wrapper over `wp_register_ability()`.
 *
 * ⛔⛔ THE CVE-2024-1285 LESSON. Page Builder Sandwich's WordPress.org listing was closed over one
 * AJAX handler that checked a nonce and no capability. An ability is the same kind of door, open
 * to every AI agent a user connects, so this wrapper REFUSES to register one without a
 * `permission_callback`, and a callback that throws or returns anything but `true` denies. The
 * real-WordPress access gate (wp/tests/wp-integration/plugin_gates.py, `ability-*` codes) then
 * calls every ability the plugin registers as an anonymous visitor and as a subscriber, and fails
 * the build when one is not refused.
 *
 * Every ability is also shown in the REST API (`/wp-abilities/v1/abilities/<name>/run`), so the
 * same actions an agent runs over MCP are available to any REST client with an application
 * password, with the same permission check.
 */
final class Ability {

	/**
	 * Register an ability.
	 *
	 * @param string               $name Ability name, `<namespace>/<action>`.
	 * @param array<string, mixed> $args `label`, `description`, `category`, `input_schema`,
	 *                                   `output_schema`, `execute_callback`,
	 *                                   `permission_callback`, `capability` (the check in words,
	 *                                   for the ability registry and docs), `edition` (`free` or
	 *                                   `pro`), and optional `annotations` (`readonly`,
	 *                                   `destructive`, `idempotent`).
	 * @return bool Registered.
	 */
	public static function register( string $name, array $args ): bool {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return false;
		}
		if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( $name . ': an ability needs a permission_callback.' ), '1.0.0' );
			return false;
		}
		if ( '' === (string) ( $args['capability'] ?? '' ) ) {
			_doing_it_wrong( __METHOD__, esc_html( $name . ': an ability must say which capability it checks.' ), '1.0.0' );
			return false;
		}
		$schema = (array) ( $args['input_schema'] ?? array() );
		// An object input with nothing required defaults to {} — so a read-only ability runs as a
		// plain `GET …/run` with no `input` at all, instead of refusing it as "not an object".
		if ( 'object' === ( $schema['type'] ?? '' ) && empty( $schema['required'] ) && ! array_key_exists( 'default', $schema ) ) {
			$schema['default'] = array();
		}
		$check   = $args['permission_callback'];
		$execute = $args['execute_callback'];
		$annot   = (array) ( $args['annotations'] ?? array() );

		$registered = wp_register_ability(
			$name,
			array(
				'label'               => (string) $args['label'],
				'description'         => (string) $args['description'],
				'category'            => (string) $args['category'],
				'input_schema'        => $schema,
				'output_schema'       => (array) ( $args['output_schema'] ?? array() ),
				'execute_callback'    => static function ( $input = null ) use ( $execute ) {
					try {
						return $execute( is_array( $input ) ? $input : array() );
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'zinn_ability_failed', __( 'The action could not be completed. Try again, and if it keeps failing, contact the site administrator.', 'zinn-reseller' ), array( 'status' => 500 ) );
					}
				},
				'permission_callback' => static function ( $input = null ) use ( $check ): bool {
					try {
						return true === $check( is_array( $input ) ? $input : array() );
					} catch ( \Throwable $e ) {
						return false;
					}
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => (bool) ( $annot['readonly'] ?? false ),
						'destructive' => (bool) ( $annot['destructive'] ?? false ),
						'idempotent'  => (bool) ( $annot['idempotent'] ?? false ),
					),
					// The ability registry (abilities.json) and the docs are generated from this.
					'zinn'         => array(
						'edition'    => 'pro' === ( $args['edition'] ?? 'free' ) ? 'pro' : 'free',
						'capability' => (string) $args['capability'],
					),
					'mcp'          => array(
						'public' => true,
						// `prompt`: a guided workflow the agent's user picks (MCP prompts/list);
						// anything else is a tool.
						'type'   => 'prompt' === ( $args['mcp_type'] ?? 'tool' ) ? 'prompt' : 'tool',
					),
				),
			)
		);

		return null !== $registered;
	}

	/**
	 * The integer the input holds under a key, 0 when absent.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $key   Key.
	 * @return int
	 */
	public static function int( array $input, string $key ): int {
		return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : 0;
	}

	/**
	 * The string list the input holds under a key.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $key   Key.
	 * @return array<int, string>
	 */
	public static function strings( array $input, string $key ): array {
		$value = $input[ $key ] ?? array();
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return array_values( array_filter( array_map( static fn( $v ): string => trim( (string) $v ), (array) $value ), 'strlen' ) );
	}
}
