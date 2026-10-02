<?php
/**
 * Every REST route of a plugin as an ability, run through WordPress's own REST dispatch.
 *
 * Generated from wp/packages/zinn-mcp-kit/src/class-rest-bridge.php by wp/bin/build-mcp-kit.php.
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
 * The owner's "full spec" order (2026-09-30): everything a plugin can do from its screens, an AI
 * agent can do too. The screens all go through the plugin's REST routes, so the complete set of
 * actions IS the route set, and a bridged ability is the route itself:
 *
 * - its permission check is the route's own `permission_callback`, asked with the same request
 *   WordPress would build (so a per-post check sees the post), and the kit's wrapper denies on
 *   anything but `true`;
 * - its execution is `rest_do_request()`, so the route's argument validation, sanitising and
 *   permission check run AGAIN exactly as for the plugin's own screen — there is no second code
 *   path to drift or to forget a check on;
 * - its input schema is the route's declared arguments plus its path parameters.
 *
 * The map (which route is which ability, which is covered by a hand-written one, which is exempt
 * and why) is generated per plugin (includes/mcp/class-rest-map.php) and held to the live route
 * list by the unit suite, so a new route cannot ship without an answer.
 */
final class Rest_Bridge {

	/**
	 * Set by a screen that lists the tools outside a REST request (the PHP settings panel).
	 *
	 * @var bool
	 */
	private static $allowed = false;

	/**
	 * Let this request resolve REST routes (our own settings tab, which lists the tools).
	 *
	 * @return void
	 */
	public static function allow(): void {
		self::$allowed = true;
	}

	/**
	 * May this request build the REST server to bridge routes?
	 *
	 * ⛔⛔ INCIDENT 2026-10-01 (PF-450): bridging a route needs `rest_get_server()`, which builds
	 * EVERY plugin's REST routes — 69 MB more on a WooCommerce site — and the bridge ran wherever
	 * the abilities were listed, a WP-CLI command included. Routes are resolved only where they
	 * are already being served: a REST request (the abilities API and the MCP endpoint are both
	 * REST), `wp mcp-adapter …` (the platform's MCP path), or a screen that opted in.
	 *
	 * @return bool
	 */
	public static function may_resolve(): bool {
		if ( self::$allowed || ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) || did_action( 'rest_api_init' ) ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return ! Adapter::cli_without_mcp();
		}

		return false;
	}

	/**
	 * Register an ability for every bridged entry whose route is registered on this request
	 * (a Pro route exists only while Pro is licensed, so its ability does too).
	 *
	 * @param array<int, array<string, mixed>> $entries  Rest_Map::entries(); an entry may carry `args` (MCP input properties its route reads but does not declare).
	 * @param string                           $category Ability category.
	 * @return int How many were registered.
	 */
	public static function register( array $entries, string $category ): int {
		if ( ! self::may_resolve() ) {
			return 0;
		}
		$registered = 0;
		foreach ( $entries as $entry ) {
			if ( empty( $entry['name'] ) ) {
				continue; // Covered by a hand-written ability, or exempt.
			}
			$handler = self::handler( (string) $entry['route'], (string) $entry['method'] );
			if ( null === $handler ) {
				continue;
			}
			$method      = (string) $entry['method'];
			$ok          = Ability::register(
				(string) $entry['name'],
				array(
					'label'               => (string) $entry['label'],
					'description'         => (string) $entry['description'],
					'category'            => $category,
					'edition'             => (string) $entry['edition'],
					'capability'          => (string) $entry['capability'],
					// A route that reads parameters without declaring them (most admin-screen routes)
					// declares them on its map entry instead, so an MCP client can see them: an
					// empty schema left the model guessing `message` for `body` (MCP-STORE 2026-10-02).
					'input_schema'        => self::schema( $handler['pattern'], array_merge( (array) ( $handler['args'] ?? array() ), (array) ( $entry['args'] ?? array() ) ) ),
					'execute_callback'    => static fn( array $input ) => self::dispatch( $entry, $input ),
					'permission_callback' => static fn( array $input ): bool => self::allowed( $entry, $input ),
					'annotations'         => array(
						'readonly'    => 'GET' === $method,
						'destructive' => 'DELETE' === $method,
						'idempotent'  => in_array( $method, array( 'GET', 'PUT', 'DELETE' ), true ),
					),
				)
			);
			$registered += $ok ? 1 : 0;
		}

		return $registered;
	}

	/**
	 * The registered handler for a normalised route (`/ns/v1/items/{id}`) and method, with the
	 * route's real regex as `pattern`; null when nothing registers it on this request.
	 *
	 * @param string $route  Normalised route.
	 * @param string $method GET, POST, PUT (the editable POST/PUT/PATCH handler) or DELETE.
	 * @return array<string, mixed>|null
	 */
	public static function handler( string $route, string $method ): ?array {
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			if ( self::normalise( (string) $pattern ) !== $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) $handler['methods'] ) );
				sort( $methods );
				$key = array( 'PATCH', 'POST', 'PUT' ) === $methods ? 'PUT' : implode( ',', $methods );
				if ( $key === $method ) {
					return $handler + array( 'pattern' => (string) $pattern );
				}
			}
		}

		return null;
	}

	/**
	 * A route regex with each named group written `{name}` (nested groups included).
	 *
	 * @param string $route Route regex.
	 * @return string
	 */
	public static function normalise( string $route ): string {
		$out = '';
		$len = strlen( $route );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '(?P<' === substr( $route, $i, 4 ) ) {
				$close = (int) strpos( $route, '>', $i );
				$name  = substr( $route, $i + 4, $close - $i - 4 );
				$depth = 0;
				for ( $j = $i; $j < $len; $j++ ) {
					if ( '(' === $route[ $j ] && ( 0 === $j || '\\' !== $route[ $j - 1 ] ) ) {
						++$depth;
					} elseif ( ')' === $route[ $j ] && '\\' !== $route[ $j - 1 ] && 0 === --$depth ) {
						break;
					}
				}
				$out .= '{' . $name . '}';
				$i    = $j;
				continue;
			}
			$out .= $route[ $i ];
		}

		return $out;
	}

	/**
	 * The path parameters of a route regex, name => its own sub-pattern.
	 *
	 * @param string $pattern Route regex.
	 * @return array<string, string>
	 */
	public static function path_params( string $pattern ): array {
		preg_match_all( '/\(\?P<([a-z_]+)>/', $pattern, $names );
		$out = array();
		foreach ( $names[1] as $name ) {
			$start = (int) strpos( $pattern, '(?P<' . $name . '>' ) + strlen( '(?P<' . $name . '>' );
			$depth = 1;
			$len   = strlen( $pattern );
			for ( $j = $start; $j < $len; $j++ ) {
				if ( '(' === $pattern[ $j ] && '\\' !== $pattern[ $j - 1 ] ) {
					++$depth;
				} elseif ( ')' === $pattern[ $j ] && '\\' !== $pattern[ $j - 1 ] && 0 === --$depth ) {
					break;
				}
			}
			$out[ $name ] = substr( $pattern, $start, $j - $start );
		}

		return $out;
	}

	/**
	 * The ability's input schema: the route's declared arguments and its path parameters.
	 * Extra properties are allowed because several routes read a JSON body they validate
	 * themselves; the route's own validation still runs on dispatch.
	 *
	 * @param string               $pattern Route regex.
	 * @param array<string, mixed> $args    Route args.
	 * @return array<string, mixed>
	 */
	public static function schema( string $pattern, array $args ): array {
		$properties = array();
		$required   = array();
		foreach ( self::path_params( $pattern ) as $name => $regex ) {
			$properties[ $name ] = array(
				'type'    => 'string',
				'pattern' => '^' . $regex . '$',
			);
			$required[]          = $name;
		}
		foreach ( $args as $name => $arg ) {
			if ( isset( $properties[ $name ] ) || ! is_array( $arg ) ) {
				continue;
			}
			$prop = array_intersect_key( $arg, array_flip( array( 'type', 'enum', 'default', 'items', 'description', 'minimum', 'maximum', 'pattern', 'properties', 'format' ) ) );
			if ( array() === $prop ) {
				$prop = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ) );
			}
			$properties[ $name ] = $prop;
			if ( ! empty( $arg['required'] ) ) {
				$required[] = $name;
			}
		}
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}
		if ( array() !== $required ) {
			$schema['required'] = array_values( array_unique( $required ) );
		}

		return $schema;
	}

	/**
	 * The request WordPress would build for this input, or null when a path parameter is missing
	 * or does not match the route's own pattern.
	 *
	 * @param array<string, string> $entry Map entry.
	 * @param array<string, mixed>  $input Input.
	 * @return \WP_REST_Request|null
	 */
	public static function request( array $entry, array $input ): ?\WP_REST_Request {
		$handler = self::handler( (string) $entry['route'], (string) $entry['method'] );
		if ( null === $handler ) {
			return null;
		}
		$path = (string) $entry['route'];
		foreach ( self::path_params( $handler['pattern'] ) as $name => $regex ) {
			$value = isset( $input[ $name ] ) && is_scalar( $input[ $name ] ) ? (string) $input[ $name ] : '';
			if ( 1 !== preg_match( '#^' . str_replace( '#', '\\#', $regex ) . '$#', $value ) ) {
				return null;
			}
			$path = str_replace( '{' . $name . '}', $value, $path );
			unset( $input[ $name ] );
		}
		$request = new \WP_REST_Request( (string) $entry['method'], $path );
		if ( in_array( $entry['method'], array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $input );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( (object) $input ) );
		}

		return $request;
	}

	/**
	 * The route's own permission check, asked with the request this input makes.
	 *
	 * @param array<string, string> $entry Map entry.
	 * @param array<string, mixed>  $input Input.
	 * @return bool
	 */
	public static function allowed( array $entry, array $input ): bool {
		$handler = self::handler( (string) $entry['route'], (string) $entry['method'] );
		$request = self::request( $entry, $input );
		if ( null === $handler || null === $request || ! isset( $handler['permission_callback'] ) || ! is_callable( $handler['permission_callback'] ) ) {
			return false;
		}
		// The route's URL parameters, as WordPress's dispatch would have set them.
		$params = array();
		if ( 1 === preg_match( '@^' . $handler['pattern'] . '$@i', $request->get_route(), $m ) ) {
			foreach ( $m as $key => $value ) {
				if ( is_string( $key ) ) {
					$params[ $key ] = $value;
				}
			}
		}
		$request->set_url_params( $params );
		$request->set_attributes( $handler );

		return true === call_user_func( $handler['permission_callback'], $request );
	}

	/**
	 * Run the route through WordPress's REST dispatch (its checks run again there).
	 *
	 * @param array<string, string> $entry Map entry.
	 * @param array<string, mixed>  $input Input.
	 * @return mixed|\WP_Error
	 */
	public static function dispatch( array $entry, array $input ) {
		$request = self::request( $entry, $input );
		if ( null === $request ) {
			return new \WP_Error( 'zinn_bad_input', __( 'A required value is missing or has the wrong form. Check the ability\'s input schema.', 'zinn-reseller' ), array( 'status' => 400 ) );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return self::refusal( $response );
		}

		return $response->get_data();
	}

	/**
	 * A refused route as a WP_Error that always says why.
	 *
	 * ⛔ A route that answers `{ "error": "…" }` (or nothing) with no `message` became a WP_Error with
	 * an EMPTY message, which an MCP client shows as "Failed to execute tool" and an admin as a blank
	 * notice (found by L11-P10, 2026-10-01). Falls back to the body's `error`, then the HTTP status
	 * text, then a plain sentence — so any route, now or later, degrades to a readable refusal.
	 *
	 * @param \WP_REST_Response $response A response whose status is 400 or above.
	 * @return \WP_Error
	 */
	public static function refusal( $response ): \WP_Error {
		$error = $response->as_error();
		if ( '' !== trim( (string) $error->get_error_message() ) ) {
			return $error;
		}
		$data   = $response->get_data();
		$status = (int) $response->get_status();
		$reason = is_array( $data ) && is_string( $data['error'] ?? null ) ? trim( $data['error'] ) : '';
		if ( '' === $reason ) {
			$reason = function_exists( 'get_status_header_desc' ) ? (string) get_status_header_desc( $status ) : '';
		}
		if ( '' === $reason ) {
			$reason = __( 'The site refused the request.', 'zinn-reseller' );
		}
		$code = (string) $error->get_error_code();

		return new \WP_Error( '' !== $code ? $code : 'zinn_refused', $reason, array( 'status' => $status ) );
	}
}
