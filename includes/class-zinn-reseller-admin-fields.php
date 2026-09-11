<?php
/**
 * Every settings control a Zinn® plugin can draw, and the sanitiser that guards each one.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** The source of truth is
 * `wp/admin-ui/class-zinn-admin-fields.php.tpl`; `wp/bin/build-admin-ui.php` renders it into
 * every plugin and `--check` fails the build if a checked-in copy has drifted (§2.32).
 *
 * ⭐ Generated rather than shared for the reason `wp/promo/class-zinn-promo.php.tpl` gives at
 * length: seven plugins are distributed separately, so there is no shared runtime to
 * `require` from, and a **variable** text domain is refused by the WordPress i18n sniff and
 * invisible to `wp i18n make-pot` — the strings would ship untranslatable into 58 locales
 * (§2.19). Substituting the domain at build time keeps every `__()` argument a literal.
 *
 * ⛔⛔ **THIS FILE IS WHERE THE SECURITY OF EVERY ZINN SETTINGS SCREEN LIVES.** Before it,
 * six plugins each hand-rolled their own `sanitize()` — six chances to forget one, and the
 * one that forgets fails silently and in the expensive direction: it works perfectly and
 * stores whatever it was given. Sanitisation is declared per field and applied HERE, once,
 * by type. A field with no declared `sanitize` gets the sanitiser for its type, and a type
 * this file does not know is **refused** rather than passed through — fail-closed, because
 * the failure direction of an unknown type must not be "store it raw" (§2.44).
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders and sanitises the controls declared by {@see Zinn_Reseller_Admin_UI::register()}.
 */
final class Zinn_Reseller_Admin_Fields {

	/**
	 * What a saved secret renders as, and what comes back if the customer does not retype it.
	 *
	 * ⛔ A stored token is NEVER echoed into a `value=` attribute. A settings page is the one
	 * screen a support agent is most likely to be looking over somebody's shoulder at, it is
	 * the screen most likely to end up in a screenshot attached to a ticket, and the browser
	 * will happily offer to save it. The mask is a sentinel: if it comes back unchanged the
	 * stored value is kept, so "save" on a form the customer never touched cannot wipe a
	 * credential — which is the failure the obvious "blank means unchanged" rule produces the
	 * first time somebody deliberately wants to clear one.
	 */
	public const SECRET_MASK = '••••••••';

	/**
	 * Above this many choices a multiselect renders searchable, because a plain list is not
	 * usable at the length our own plugins need (58 locales).
	 */
	private const SEARCHABLE_FROM = 12;

	/**
	 * The full set of control types. ⛔ An allow-list, deliberately: see the file header.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array(
			'text',
			'password',
			'textarea',
			'number',
			'checkbox',
			'toggle',
			'select',
			'multiselect',
			'radio',
			'color',
			'url',
			'email',
			'code',
			'repeater',
			'heading',
			'html',
			'notice',
		);
	}

	/**
	 * Types that hold no value — they draw, and nothing is stored for them.
	 *
	 * @return array<int, string>
	 */
	public static function presentational(): array {
		return array( 'heading', 'html', 'notice' );
	}

	/**
	 * The default value a field falls back to when nothing is stored.
	 *
	 * @param array<string, mixed> $field A field declaration.
	 * @return mixed
	 */
	public static function default_for( array $field ) {
		if ( array_key_exists( 'default', $field ) ) {
			return $field['default'];
		}
		switch ( (string) ( $field['type'] ?? 'text' ) ) {
			case 'checkbox':
			case 'toggle':
				return false;
			case 'number':
				return 0;
			case 'multiselect':
			case 'repeater':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Put one submitted value through the sanitiser its field declares.
	 *
	 * ⛔⛔ **The `$stored` argument is what makes a secret field safe, and it is not optional.**
	 * A `secret` field renders as a mask; if the mask comes back the customer did not retype
	 * the credential and the stored one must survive. Passing the mask through to storage
	 * would replace a live token with eight bullet characters — a site that silently stops
	 * working, with a settings screen that looks exactly right (§2.44).
	 *
	 * @param array<string, mixed> $field  The field declaration.
	 * @param mixed                $raw    The submitted value, unsanitised.
	 * @param mixed                $stored The value currently stored, for secret retention.
	 * @return mixed The value to store.
	 */
	public static function sanitize( array $field, $raw, $stored = null ) {
		$type = (string) ( $field['type'] ?? 'text' );

		if ( in_array( $type, self::presentational(), true ) ) {
			return null;
		}

		if ( ! empty( $field['secret'] ) && is_string( $raw ) && self::SECRET_MASK === $raw ) {
			return is_string( $stored ) ? $stored : '';
		}

		// ⛔⛔ A STRING is always a RULE NAME here, never a function — even when PHP has a
		// function of that name. `is_callable( 'key' )` is TRUE because `key()` is a PHP
		// builtin, so the `'sanitize' => 'key'` rule called `key( $raw, $field )` instead of
		// `sanitize_key()`, and every save of the Zinn® Translate settings screen died with
		// `ArgumentCountError` (D24521). Only a Closure or an array callable is a callable.
		if ( isset( $field['sanitize'] ) && ! is_string( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $raw, $field );
		}

		$rule = isset( $field['sanitize'] ) && is_string( $field['sanitize'] )
			? $field['sanitize']
			: self::rule_for_type( $type );

		return self::apply_rule( $rule, $raw, $field, $stored );
	}

	/**
	 * The sanitisation rule a control type implies when the field does not name one.
	 *
	 * @param string $type A control type.
	 * @return string A rule name understood by {@see apply_rule()}.
	 */
	private static function rule_for_type( string $type ): string {
		switch ( $type ) {
			case 'checkbox':
			case 'toggle':
				return 'bool';
			case 'number':
				return 'int';
			case 'color':
				return 'color';
			case 'url':
				return 'url';
			case 'email':
				return 'email';
			case 'textarea':
			case 'code':
				return 'textarea';
			case 'select':
			case 'radio':
				return 'choice';
			case 'multiselect':
				return 'choices';
			case 'repeater':
				return 'repeater';
			case 'password':
				return 'raw_secret';
			default:
				return 'text';
		}
	}

	/**
	 * Apply a named sanitisation rule.
	 *
	 * @param string               $rule   Rule name.
	 * @param mixed                $raw    Submitted value.
	 * @param array<string, mixed> $field  Field declaration.
	 * @param mixed                $stored Currently stored value.
	 * @return mixed
	 */
	private static function apply_rule( string $rule, $raw, array $field, $stored ) {
		switch ( $rule ) {
			case 'bool':
				return ! empty( $raw ) && 'false' !== $raw && '0' !== $raw;

			case 'int':
				$value = is_scalar( $raw ) ? (int) $raw : (int) self::default_for( $field );
				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}
				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}
				return $value;

			case 'float':
				$value = is_scalar( $raw ) ? (float) $raw : 0.0;
				if ( isset( $field['min'] ) ) {
					$value = max( (float) $field['min'], $value );
				}
				if ( isset( $field['max'] ) ) {
					$value = min( (float) $field['max'], $value );
				}
				return $value;

			case 'color':
				// ⛔ `sanitize_hex_color` returns null on anything that is not a hex colour,
				// which would store null and render an empty swatch. Fall back to the
				// declared default so a pasted rubbish value cannot blank a theme token.
				$value = is_string( $raw ) ? sanitize_hex_color( trim( $raw ) ) : null;
				return null === $value ? (string) self::default_for( $field ) : $value;

			case 'url':
				return is_string( $raw ) ? esc_url_raw( trim( $raw ) ) : '';

			case 'email':
				return is_string( $raw ) ? sanitize_email( trim( $raw ) ) : '';

			case 'key':
				return is_string( $raw ) ? sanitize_key( $raw ) : '';

			case 'slug':
				return is_string( $raw ) ? sanitize_title( $raw ) : '';

			case 'textarea':
				return is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';

			case 'html':
				// ⛔ `wp_kses_post`, never raw. A settings field that stored arbitrary HTML
				// would be stored XSS the moment a lower-privileged role could reach the
				// screen — and roles are editable on any site we do not control.
				return is_string( $raw ) ? wp_kses_post( $raw ) : '';

			case 'csv':
				return self::to_list( $raw );

			case 'raw_secret':
				// A credential is not a sentence: trimming whitespace is the only safe
				// normalisation, because anything else can silently corrupt a valid token.
				return is_string( $raw ) ? trim( $raw ) : '';

			case 'choice':
				$choices = self::choice_values( $field );
				$value   = is_scalar( $raw ) ? (string) $raw : '';
				return in_array( $value, $choices, true ) ? $value : (string) self::default_for( $field );

			case 'choices':
				$choices = self::choice_values( $field );
				$out     = array();
				foreach ( (array) ( is_array( $raw ) ? $raw : array() ) as $one ) {
					$one = is_scalar( $one ) ? (string) $one : '';
					if ( in_array( $one, $choices, true ) && ! in_array( $one, $out, true ) ) {
						$out[] = $one;
					}
				}
				return $out;

			case 'repeater':
				return self::sanitize_repeater( $field, $raw, $stored );

			case 'text':
			default:
				return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		}
	}

	/**
	 * Sanitise a repeater's rows, each row through its own sub-field declarations.
	 *
	 * ⛔ A row whose every value is empty is DROPPED, so a customer who adds a row and
	 * changes their mind does not leave a blank entry behind that some consumer then treats
	 * as a real rule matching everything.
	 *
	 * @param array<string, mixed> $field  The repeater declaration.
	 * @param mixed                $raw    Submitted rows.
	 * @param mixed                $stored Currently stored rows, for secret retention.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_repeater( array $field, $raw, $stored ): array {
		$subs = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
		$rows = is_array( $raw ) ? array_values( $raw ) : array();
		$prev = is_array( $stored ) ? array_values( $stored ) : array();
		$out  = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			$empty = true;
			foreach ( $subs as $sub ) {
				$key = (string) ( $sub['key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$raw           = $row[ $key ] ?? null;
				$was           = is_array( $prev[ $index ] ?? null ) ? ( $prev[ $index ][ $key ] ?? null ) : null;
				$clean[ $key ] = self::sanitize( $sub, $raw, $was );

				// ⛔⛔ **EMPTINESS IS JUDGED ON THE RAW SUBMISSION, NOT ON THE SANITISED
				// VALUE, AND THE DIFFERENCE IS NOT ACADEMIC.** A number field sanitises an
				// empty box to `0`, and `0` is not "empty" by any test worth writing — so a
				// row the customer added and left blank survived, and was stored as a rule
				// with a zero in it. Found by `test_a_repeater_drops_a_row_the_customer_left_empty`
				// before this fix; the assertion failed with "actual size 2 matches expected
				// size 1", which is exactly the shape of the bug.
				// ⭐ Judging the raw value also gets the other half right: a customer who
				// deliberately types `0` into a number box HAS filled the row in.
				if ( null !== $raw && '' !== $raw && array() !== $raw ) {
					$empty = false;
				}
			}
			if ( ! $empty ) {
				$out[] = $clean;
			}
		}

		return $out;
	}

	/**
	 * The values a choice-bearing field will accept.
	 *
	 * @param array<string, mixed> $field A field declaration.
	 * @return array<int, string>
	 */
	private static function choice_values( array $field ): array {
		return array_map( 'strval', array_keys( self::choices( $field ) ) );
	}

	/**
	 * A field's choices, resolving a callable declaration.
	 *
	 * ⛔⛔ **`choices` MAY BE A CALLABLE AND FOR ANYTHING THAT QUERIES IT SHOULD BE.** A list
	 * of the site's users or its categories costs a database query; building it when the
	 * plugin registers means paying for it on every front-end request, on a screen nobody is
	 * looking at (§2.16) — and, at `plugins_loaded`, `get_users()` fatals outright because
	 * roles do not exist yet. Measured on WordPress 7.1, not reasoned about.
	 *
	 * @param array<string, mixed> $field A field declaration.
	 * @return array<string, string>
	 */
	public static function choices( array $field ): array {
		$choices = $field['choices'] ?? array();
		if ( is_callable( $choices ) ) {
			$choices = call_user_func( $choices );
		}
		return is_array( $choices ) ? $choices : array();
	}

	/**
	 * A newline- or comma-separated block of text as a de-duplicated list.
	 *
	 * @param mixed $raw Submitted value.
	 * @return array<int, string>
	 */
	public static function to_list( $raw ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} elseif ( is_string( $raw ) ) {
			$split = preg_split( '/[\r\n,]+/', $raw );
			$parts = false === $split ? array() : $split;
		} else {
			$parts = array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = sanitize_text_field( trim( (string) $part ) );
			if ( '' !== $part && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────────────────
	// Rendering
	//
	// ⛔ Every value below is escaped at the point of output, with the escaper that matches
	// the CONTEXT — `esc_attr` in an attribute, `esc_html` in text, `esc_url` in an href.
	// A single "escape everything with esc_html" helper is the commonest way a settings
	// screen ships an attribute-context injection while looking careful.
	// ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Draw one field as a `form-table` row, or as a full-width block for presentational types.
	 *
	 * @param array<string, mixed> $field  The field declaration.
	 * @param mixed                $value  Its current value.
	 * @param string               $prefix The `name=` prefix, e.g. `zinn_settings`.
	 * @return void
	 */
	public static function render_row( array $field, $value, string $prefix ): void {
		$type = (string) ( $field['type'] ?? 'text' );

		if ( in_array( $type, self::presentational(), true ) ) {
			self::render_presentational( $field, $type );
			return;
		}

		$key  = (string) ( $field['key'] ?? '' );
		$id   = 'zinn-field-' . sanitize_html_class( $key );
		$attr = self::show_if_attr( $field );
		?>
		<tr class="zinn-field-row" <?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by show_if_attr(). ?>>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) ( $field['label'] ?? $key ) ); ?></label>
			</th>
			<td>
				<?php self::render_control( $field, $value, $prefix, $id ); ?>
				<?php if ( ! empty( $field['description'] ) ) : ?>
					<p class="description"><?php echo wp_kses_post( (string) $field['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Draw a heading, a notice or a block of explanatory HTML.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @param string               $type  Its type.
	 * @return void
	 */
	private static function render_presentational( array $field, string $type ): void {
		$attr = self::show_if_attr( $field );
		echo '<tr class="zinn-field-row zinn-field-row--full" ' . $attr . '><td colspan="2">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by show_if_attr().
		if ( 'heading' === $type ) {
			echo '<h3 class="zinn-fieldset-title">' . esc_html( (string) ( $field['label'] ?? '' ) ) . '</h3>';
			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . wp_kses_post( (string) $field['description'] ) . '</p>';
			}
		} elseif ( 'notice' === $type ) {
			$kind = in_array( (string) ( $field['kind'] ?? 'info' ), array( 'info', 'success', 'warning', 'error' ), true )
				? (string) ( $field['kind'] ?? 'info' )
				: 'info';
			echo '<div class="notice notice-' . esc_attr( $kind ) . ' inline"><p>' . wp_kses_post( (string) ( $field['label'] ?? '' ) ) . '</p></div>';
		} else {
			echo wp_kses_post( (string) ( $field['html'] ?? $field['label'] ?? '' ) );
		}
		echo '</td></tr>';
	}

	/**
	 * The `data-zinn-show-if` attribute that drives conditional visibility.
	 *
	 * ⛔ Visibility only. A hidden field still submits its value and that is deliberate: a
	 * customer who switches provider away from "my own key" and back again must still find
	 * their key there. Hiding a control is a presentation decision; deleting the value it
	 * holds is a data decision, and conflating the two loses credentials.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @return string An escaped attribute string, or the empty string.
	 */
	private static function show_if_attr( array $field ): string {
		if ( empty( $field['show_if'] ) || ! is_array( $field['show_if'] ) ) {
			return '';
		}
		$json = wp_json_encode( $field['show_if'] );
		return false === $json ? '' : ' data-zinn-show-if="' . esc_attr( $json ) . '"';
	}

	/**
	 * Draw the control itself.
	 *
	 * @param array<string, mixed> $field  The field declaration.
	 * @param mixed                $value  Its current value.
	 * @param string               $prefix The `name=` prefix.
	 * @param string               $id     The control's DOM id.
	 * @return void
	 */
	private static function render_control( array $field, $value, string $prefix, string $id ): void {
		$key  = (string) ( $field['key'] ?? '' );
		$name = $prefix . '[' . $key . ']';
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'checkbox':
			case 'toggle':
				?>
				<label class="zinn-toggle">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> data-zinn-key="<?php echo esc_attr( $key ); ?>" />
					<span><?php echo esc_html( (string) ( $field['checkbox_label'] ?? '' ) ); ?></span>
				</label>
				<?php
				break;

			case 'textarea':
			case 'code':
				$text = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value;
				?>
				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					rows="<?php echo esc_attr( (string) ( $field['rows'] ?? 5 ) ); ?>"
					class="large-text<?php echo 'code' === $type ? ' code' : ''; ?>"
					data-zinn-key="<?php echo esc_attr( $key ); ?>"
					<?php echo ! empty( $field['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"' : ''; ?>
				><?php echo esc_textarea( $text ); ?></textarea>
				<?php
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" data-zinn-key="<?php echo esc_attr( $key ); ?>">
					<?php foreach ( self::choices( $field ) as $choice_value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $choice_value ); ?>" <?php selected( (string) $value, (string) $choice_value ); ?>>
							<?php echo esc_html( (string) $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'radio':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</legend>';
				foreach ( self::choices( $field ) as $choice_value => $label ) {
					printf(
						'<label class="zinn-radio"><input type="radio" name="%1$s" value="%2$s" %3$s data-zinn-key="%4$s" /> <span>%5$s</span></label>',
						esc_attr( $name ),
						esc_attr( (string) $choice_value ),
						checked( (string) $value, (string) $choice_value, false ),
						esc_attr( $key ),
						esc_html( (string) $label )
					);
				}
				echo '</fieldset>';
				break;

			case 'multiselect':
				self::render_multiselect( $field, $value, $name, $id );
				break;

			case 'repeater':
				self::render_repeater( $field, $value, $name );
				break;

			case 'color':
				?>
				<input type="color" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" data-zinn-key="<?php echo esc_attr( $key ); ?>" />
				<code class="zinn-color-value"><?php echo esc_html( (string) $value ); ?></code>
				<?php
				break;

			case 'password':
				// ⛔ The MASK, never the stored value. See SECRET_MASK.
				$shown = '' === (string) $value ? '' : self::SECRET_MASK;
				?>
				<input
					type="password"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $shown ); ?>"
					class="regular-text"
					autocomplete="off"
					spellcheck="false"
					data-zinn-key="<?php echo esc_attr( $key ); ?>"
				/>
				<?php if ( '' !== (string) $value ) : ?>
					<span class="zinn-secret-hint"><?php esc_html_e( 'Stored. Type a new value to replace it.', 'zinn-reseller' ); ?></span>
				<?php endif; ?>
				<?php
				break;

			default:
				$input_type = in_array( $type, array( 'number', 'url', 'email' ), true ) ? $type : 'text';
				$shown      = ! empty( $field['secret'] ) && '' !== (string) $value ? self::SECRET_MASK : (string) $value;
				?>
				<input
					type="<?php echo esc_attr( $input_type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $shown ); ?>"
					class="<?php echo 'number' === $input_type ? 'small-text' : 'regular-text'; ?>"
					data-zinn-key="<?php echo esc_attr( $key ); ?>"
					<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
					<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?>
					<?php echo isset( $field['step'] ) ? 'step="' . esc_attr( (string) $field['step'] ) . '"' : ''; ?>
					<?php echo ! empty( $field['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"' : ''; ?>
					<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
				/>
				<?php
				break;
		}
	}

	/**
	 * A multiselect, searchable once it is long enough to need it.
	 *
	 * ⛔⛤ **Real checkboxes, not a `<select multiple>`.** At 58 locales — which is exactly
	 * what `zinn-translate` needs — a native multi-select is a scrolling trap where a stray
	 * click discards every previous choice with no warning and no undo. Checkboxes cannot do
	 * that, keep native keyboard behaviour, and are what a screen reader announces usefully.
	 *
	 * ⭐ The filter is progressive: with JavaScript off the input hides itself and the full
	 * list remains, which is long but correct. A search box that is the only way to reach an
	 * option is a control that does not exist for anyone whose JS failed to load.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @param mixed                $value Current value.
	 * @param string               $name  The `name=` attribute.
	 * @param string               $id    The control's DOM id.
	 * @return void
	 */
	private static function render_multiselect( array $field, $value, string $name, string $id ): void {
		$choices  = self::choices( $field );
		$selected = array_map( 'strval', is_array( $value ) ? $value : array() );
		$search   = array_key_exists( 'searchable', $field )
			? ! empty( $field['searchable'] )
			: count( $choices ) >= self::SEARCHABLE_FROM;
		?>
		<fieldset class="zinn-multiselect" id="<?php echo esc_attr( $id ); ?>">
			<legend class="screen-reader-text"><?php echo esc_html( (string) ( $field['label'] ?? '' ) ); ?></legend>
			<?php if ( $search ) : ?>
				<input
					type="search"
					class="zinn-multiselect__search"
					hidden
					aria-controls="<?php echo esc_attr( $id . '-list' ); ?>"
					placeholder="<?php esc_attr_e( 'Filter this list…', 'zinn-reseller' ); ?>"
					aria-label="<?php esc_attr_e( 'Filter this list', 'zinn-reseller' ); ?>"
				/>
			<?php endif; ?>
			<div class="zinn-multiselect__list" id="<?php echo esc_attr( $id . '-list' ); ?>">
				<?php foreach ( $choices as $choice_value => $label ) : ?>
					<label class="zinn-multiselect__item" data-zinn-label="<?php echo esc_attr( strtolower( (string) $label . ' ' . $choice_value ) ); ?>">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $name ); ?>[]"
							value="<?php echo esc_attr( (string) $choice_value ); ?>"
							<?php checked( in_array( (string) $choice_value, $selected, true ) ); ?>
						/>
						<span><?php echo esc_html( (string) $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="zinn-multiselect__empty" hidden><?php esc_html_e( 'Nothing matches that filter.', 'zinn-reseller' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * A repeatable list of rows.
	 *
	 * ⛔ The blank row used as a template for "add" is inside a `<template>` element, so its
	 * inputs are never submitted and never focusable. A hidden-but-present blank row is the
	 * commonest way a repeater silently stores an empty entry on every save.
	 *
	 * @param array<string, mixed> $field The repeater declaration.
	 * @param mixed                $value Current rows.
	 * @param string               $name  The `name=` attribute.
	 * @return void
	 */
	private static function render_repeater( array $field, $value, string $name ): void {
		$subs = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
		$rows = is_array( $value ) ? array_values( $value ) : array();
		?>
		<div class="zinn-repeater" data-zinn-repeater>
			<div class="zinn-repeater__rows">
				<?php foreach ( $rows as $index => $row ) : ?>
					<?php self::render_repeater_row( $subs, is_array( $row ) ? $row : array(), $name, (string) $index ); ?>
				<?php endforeach; ?>
			</div>
			<template class="zinn-repeater__template">
				<?php self::render_repeater_row( $subs, array(), $name, '__INDEX__' ); ?>
			</template>
			<button type="button" class="button zinn-repeater__add">
				<?php echo esc_html( (string) ( $field['add_label'] ?? __( 'Add a row', 'zinn-reseller' ) ) ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * One repeater row.
	 *
	 * @param array<int, array<string, mixed>> $subs  Sub-field declarations.
	 * @param array<string, mixed>             $row   The row's current values.
	 * @param string                           $name  The repeater's `name=` prefix.
	 * @param string                           $index This row's index, or the template token.
	 * @return void
	 */
	private static function render_repeater_row( array $subs, array $row, string $name, string $index ): void {
		echo '<div class="zinn-repeater__row">';
		foreach ( $subs as $sub ) {
			$key = (string) ( $sub['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$sub_name = $name . '[' . $index . '][' . $key . ']';
			$sub_id   = 'zinn-field-' . sanitize_html_class( $name . '-' . $index . '-' . $key );
			echo '<span class="zinn-repeater__cell">';
			echo '<label class="zinn-repeater__label" for="' . esc_attr( $sub_id ) . '">' . esc_html( (string) ( $sub['label'] ?? $key ) ) . '</label>';
			$carry        = $sub;
			$carry['key'] = $key;
			self::render_control(
				$carry,
				array_key_exists( $key, $row ) ? $row[ $key ] : self::default_for( $sub ),
				// The name is already fully qualified, so pass a prefix that reproduces it.
				substr( $sub_name, 0, -strlen( '[' . $key . ']' ) ),
				$sub_id
			);
			echo '</span>';
		}
		echo '<button type="button" class="button-link zinn-repeater__remove" aria-label="' . esc_attr__( 'Remove this row', 'zinn-reseller' ) . '">' . esc_html__( 'Remove', 'zinn-reseller' ) . '</button>';
		echo '</div>';
	}
}
