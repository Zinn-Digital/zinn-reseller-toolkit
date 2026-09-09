<?php
/**
 * Styling a Zinn® plugin puts on the FRONT end, without shipping a stylesheet per preset.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** Source of truth:
 * `wp/admin-ui/class-zinn-style-presets.php.tpl`, rendered by `wp/bin/build-admin-ui.php`.
 *
 * ⚖️ **Owner, 2026-09-08:** *"customisable options and styling optiins etc also where needed
 * so they can properly contorl it"*. Two plugins render on a visitor's page today — the
 * reseller toolkit's domain-search widget and the translate plugin's language switcher — and
 * both had colours and radii baked into their CSS.
 *
 * ⛔⛔ **PRESETS ARE CSS CUSTOM PROPERTIES, NOT A STYLESHEET PER PRESET.** Six presets shipped
 * as six stylesheets is six files to keep in step, and the customer can then have exactly six
 * looks and no seventh. Emitting `--zinn-<component>-<token>` values instead means the
 * component ships ONE stylesheet reading `var()`, a preset is a set of numbers, and a token
 * the customer overrides is the same mechanism as a preset — so "nearly the pill one but our
 * blue" is a supported answer rather than a support ticket.
 *
 * ⛔ **Logical properties only** (§2.7). A token named `radius` is fine; a token named
 * `margin-left` would push a hard-coded direction into a customer's site and break every RTL
 * locale we sell in.
 *
 * ⛔ **Nothing here is branded and nothing here is a footprint.** `docs/203` §8: our brand
 * stops at the publish boundary. These are the CUSTOMER's colours on the CUSTOMER's page —
 * no class name mentioning Zinn is added to their markup by this class, and no remote request
 * is made from a visitor's browser.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * A component's presets and design tokens, and the CSS they become.
 */
final class Zinn_Reseller_Style_Presets {

	/**
	 * Registered components, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $components = array();

	/**
	 * Declare a themeable component.
	 *
	 * @param array<string, mixed> $component {
	 *     The component.
	 *
	 *     @type string $id       Component id, used in the CSS variable names.
	 *     @type string $selector The CSS selector the variables are scoped to.
	 *     @type array  $presets  Preset id => `array{label:string, tokens:array<string,string>}`.
	 *     @type array  $tokens   Token key => `array{type:string, label:string}` for overrides.
	 * }
	 * @return void
	 */
	public static function register( array $component ): void {
		$id = (string) ( $component['id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		self::$components[ $id ] = array_merge(
			array(
				'selector' => '.zinn-' . $id,
				'presets'  => array(),
				'tokens'   => array(),
			),
			$component
		);

		if ( ! has_action( 'wp_head', array( __CLASS__, 'print_front_end_css' ) ) ) {
			// ⛔ Priority 20: after a theme has printed its own custom properties, so ours
			// win for our own component without the customer needing `!important` — and
			// `!important` in a plugin is a thing a site owner cannot override at all.
			add_action( 'wp_head', array( __CLASS__, 'print_front_end_css' ), 20 );
		}
	}

	/**
	 * The settings fields that let a customer choose a preset and override its tokens.
	 *
	 * ⭐ Returned as ordinary field declarations, so a styling tab is built with exactly the
	 * same machinery — and the same sanitisers, nonce and capability check — as every other
	 * tab. A styling screen that took a different path to storage would be a second place to
	 * get escaping wrong.
	 *
	 * @param string $id Component id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( string $id ): array {
		$component = self::$components[ $id ] ?? null;
		if ( null === $component ) {
			return array();
		}

		$choices = array();
		foreach ( (array) $component['presets'] as $preset_id => $preset ) {
			$choices[ (string) $preset_id ] = (string) ( $preset['label'] ?? $preset_id );
		}

		$fields = array(
			array(
				'key'         => self::preset_key( $id ),
				'type'        => 'radio',
				'label'       => __( 'Style preset', 'zinn-reseller' ),
				'description' => __( 'A starting point. Anything below overrides it.', 'zinn-reseller' ),
				'choices'     => $choices,
				'default'     => (string) array_key_first( $choices ),
			),
			array(
				'key'         => self::custom_key( $id ),
				'type'        => 'toggle',
				'label'       => __( 'Use my own colours and sizes', 'zinn-reseller' ),
				'description' => __( 'Leave this off and the preset is used exactly as it is.', 'zinn-reseller' ),
				'default'     => false,
			),
		);

		$first = (array) ( reset( $component['presets'] )['tokens'] ?? array() );

		foreach ( (array) $component['tokens'] as $token => $spec ) {
			$fields[] = array(
				'key'     => self::token_key( $id, (string) $token ),
				'type'    => (string) ( $spec['type'] ?? 'text' ),
				'label'   => (string) ( $spec['label'] ?? $token ),
				'default' => (string) ( $spec['default'] ?? ( $first[ $token ] ?? '' ) ),
				'show_if' => array( self::custom_key( $id ) => true ),
			);
		}

		return $fields;
	}

	/**
	 * The settings key holding the chosen preset.
	 *
	 * @param string $id Component id.
	 * @return string
	 */
	public static function preset_key( string $id ): string {
		return 'style_' . str_replace( '-', '_', $id ) . '_preset';
	}

	/**
	 * The settings key for "override the preset".
	 *
	 * @param string $id Component id.
	 * @return string
	 */
	public static function custom_key( string $id ): string {
		return 'style_' . str_replace( '-', '_', $id ) . '_custom';
	}

	/**
	 * The settings key holding one token override.
	 *
	 * @param string $id    Component id.
	 * @param string $token Token name.
	 * @return string
	 */
	public static function token_key( string $id, string $token ): string {
		return 'style_' . str_replace( '-', '_', $id ) . '_' . str_replace( '-', '_', $token );
	}

	/**
	 * The resolved token values for a component: its preset, with any overrides applied.
	 *
	 * @param string $id Component id.
	 * @return array<string, string>
	 */
	public static function resolve( string $id ): array {
		$component = self::$components[ $id ] ?? null;
		if ( null === $component ) {
			return array();
		}

		$presets = (array) $component['presets'];
		$chosen  = (string) Zinn_Reseller_Admin_UI::get( self::preset_key( $id ), (string) array_key_first( $presets ) );
		// ⛔ Validated against the declared presets. The value comes from the database, and a
		// preset name is interpolated into CSS below — an unvalidated one is a stylesheet
		// injection with a settings row as its delivery mechanism.
		if ( ! isset( $presets[ $chosen ] ) ) {
			$chosen = (string) array_key_first( $presets );
		}

		$tokens = array_map( 'strval', (array) ( $presets[ $chosen ]['tokens'] ?? array() ) );

		if ( ! empty( Zinn_Reseller_Admin_UI::get( self::custom_key( $id ), false ) ) ) {
			foreach ( (array) $component['tokens'] as $token => $spec ) {
				$value = (string) Zinn_Reseller_Admin_UI::get( self::token_key( $id, (string) $token ), '' );
				if ( '' !== $value ) {
					$tokens[ (string) $token ] = $value;
				}
			}
		}

		return $tokens;
	}

	/**
	 * Print the resolved custom properties into the front end.
	 *
	 * ⛔⛔ **EVERY VALUE IS FILTERED THROUGH AN ALLOW-LIST OF CHARACTERS BEFORE IT REACHES A
	 * STYLESHEET, AND THAT IS NOT BELT-AND-BRACES.** These values are stored by a settings
	 * screen and rendered into a `<style>` block on a public page. `esc_attr` does not make a
	 * string safe in CSS context — a value containing `}` closes the rule and everything
	 * after it is attacker-authored CSS on every page of the site. The only safe answer for a
	 * design token is a character allow-list, because a token is a colour, a length or a
	 * font stack and none of those needs a brace.
	 *
	 * @return void
	 */
	public static function print_front_end_css(): void {
		$blocks = array();

		foreach ( self::$components as $id => $component ) {
			$tokens = self::resolve( (string) $id );
			if ( array() === $tokens ) {
				continue;
			}
			$declarations = array();
			foreach ( $tokens as $token => $value ) {
				$name = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $token ) );
				$safe = self::css_value( (string) $value );
				if ( '' === $name || '' === $safe ) {
					continue;
				}
				$declarations[] = '--zinn-' . $id . '-' . $name . ':' . $safe;
			}
			if ( array() === $declarations ) {
				continue;
			}
			$selector = preg_replace( '/[^a-zA-Z0-9 .#_>:()-]/', '', (string) $component['selector'] );
			$blocks[] = $selector . '{' . implode( ';', $declarations ) . '}';
		}

		if ( array() === $blocks ) {
			return;
		}

		echo "<style id='zinn-zinn-reseller-tokens'>" . esc_html( implode( '', $blocks ) ) . '</style>' . "\n";
	}

	/**
	 * A design-token value reduced to characters that cannot escape a CSS declaration.
	 *
	 * ⛔ An ALLOW-LIST, and a short one. It admits hex colours, `rgb()`/`hsl()` functions,
	 * lengths with units, keywords, commas, spaces, dots and quotes for a font stack. It
	 * admits neither `{`, `}`, `;`, `<`, `>`, `\` nor `@` — so the value cannot end the
	 * declaration it is in, start a new rule, close the `<style>` element, or reach a CSS
	 * escape sequence. ⛔ `url(` is refused outright by the absence of `/` and `:`, which
	 * also stops a token becoming a request to a third party from a visitor's browser.
	 *
	 * @param string $value A stored token value.
	 * @return string The safe value, or the empty string if nothing survives.
	 */
	private static function css_value( string $value ): string {
		$value = trim( $value );
		if ( strlen( $value ) > 120 ) {
			return '';
		}
		$safe = preg_replace( '/[^A-Za-z0-9 ,.%#()\'"_-]/', '', $value );
		return null === $safe ? '' : trim( $safe );
	}
}
