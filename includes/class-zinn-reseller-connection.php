<?php
/**
 * "Are we connected?" — answered with a reason and a fix, never with a bare coloured dot.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** Source of truth:
 * `wp/admin-ui/class-zinn-connection.php.tpl`, rendered by `wp/bin/build-admin-ui.php`.
 *
 * ⚖️ **Owner, 2026-09-08:** *"it needs to show status of ocnnetion and all those things
 * properly"* — said about the connector, the bridge between a customer's WordPress and our
 * panel, and applied here to every plugin that talks to us.
 *
 * ⛔⛔ **A RED DOT IS NOT A STATUS. IT IS A DEAD END.** The customer looking at it cannot act
 * on it, so they open a ticket, and the ticket says "it says not connected". This class
 * refuses to render that: a state is drawn with its **reason** and, wherever one exists, a
 * **fix action** the customer can press. That is `CLAUDE.md` §2.57's posture — *say which kind
 * of failure it is, and give the person the thing only they can do* — applied inside a plugin
 * on somebody else's server, where a support agent cannot look for themselves.
 *
 * ⛔ **`standalone` is a first-class state, not a failure.** A plugin using the customer's own
 * provider key is working exactly as intended and must not be painted amber; painting a
 * deliberate configuration as a fault teaches people to ignore the one place we tell them
 * something is wrong. Same reason §2.57 keeps `refused` apart from `outage`.
 *
 * @package ZinnReseller
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders the connection card, and supplies the vocabulary every plugin reports in.
 */
final class Zinn_Reseller_Connection {

	/**
	 * Every state a connection may be in.
	 *
	 * ⛔ An allow-list. An unrecognised state renders as `unknown` — grey, with an honest
	 * "we could not establish this" — rather than falling through to `connected`. §2.44: the
	 * ambiguous value must not resolve to the reassuring one.
	 *
	 * @return array<int, string>
	 */
	public static function states(): array {
		return array( 'connected', 'connecting', 'degraded', 'disconnected', 'standalone', 'unknown' );
	}

	/**
	 * The human label for a state, used when a caller supplies no summary of its own.
	 *
	 * @param string $state One of {@see states()}.
	 * @return string
	 */
	public static function label( string $state ): string {
		switch ( $state ) {
			case 'connected':
				return __( 'Connected', 'zinn-reseller' );
			case 'connecting':
				return __( 'Connecting…', 'zinn-reseller' );
			case 'degraded':
				return __( 'Connected, but something needs attention', 'zinn-reseller' );
			case 'disconnected':
				return __( 'Not connected', 'zinn-reseller' );
			case 'standalone':
				return __( 'Running on your own account', 'zinn-reseller' );
			default:
				return __( 'We could not check this', 'zinn-reseller' );
		}
	}

	/**
	 * Normalise a status array reported by a plugin.
	 *
	 * ⛔⛔ **A NON-`connected` STATE WITH NO REASON IS A BUG, AND THIS SAYS SO OUT LOUD.**
	 * The whole value of this card is the sentence under the headline; a plugin that reports
	 * `disconnected` with nothing else has told the customer exactly what a red dot tells
	 * them. Rather than invent a plausible reason — which would be §2.57's *"confident,
	 * plausible lie"* — the card says the plugin did not report one, which is true and is
	 * visible to us in a diagnostics report.
	 *
	 * @param array<string, mixed> $status A status as reported by a plugin.
	 * @return array<string, mixed> A status with every key present and valid.
	 */
	public static function normalise( array $status ): array {
		$state = (string) ( $status['state'] ?? 'unknown' );
		if ( ! in_array( $state, self::states(), true ) ) {
			$state = 'unknown';
		}

		$reason = isset( $status['reason'] ) ? (string) $status['reason'] : '';
		if ( '' === $reason && ! in_array( $state, array( 'connected', 'standalone' ), true ) ) {
			$reason = __( 'This plugin did not say why. Send a diagnostics report and we will look.', 'zinn-reseller' );
		}

		$details = array();
		foreach ( (array) ( $status['details'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['label'] ) ) {
				continue;
			}
			$details[] = array(
				'label' => (string) $row['label'],
				'value' => (string) ( $row['value'] ?? '' ),
			);
		}

		return array(
			'state'      => $state,
			'summary'    => (string) ( $status['summary'] ?? self::label( $state ) ),
			'reason'     => $reason,
			'actions'    => self::normalise_actions( $status ),
			'details'    => $details,
			'checked_at' => isset( $status['checked_at'] ) ? (int) $status['checked_at'] : 0,
		);
	}

	/**
	 * The fix actions on a status, as a list, whichever way the caller expressed them.
	 *
	 * @param array<string, mixed> $status A reported status.
	 * @return array<int, array<string, string>>
	 */
	private static function normalise_actions( array $status ): array {
		$raw = array();
		if ( isset( $status['action'] ) && is_array( $status['action'] ) ) {
			$raw[] = $status['action'];
		}
		foreach ( (array) ( $status['actions'] ?? array() ) as $one ) {
			if ( is_array( $one ) ) {
				$raw[] = $one;
			}
		}

		$out = array();
		foreach ( $raw as $one ) {
			$label = (string) ( $one['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'label'   => $label,
				'url'     => isset( $one['url'] ) ? (string) $one['url'] : '',
				'action'  => isset( $one['action'] ) ? (string) $one['action'] : '',
				'confirm' => isset( $one['confirm'] ) ? (string) $one['confirm'] : '',
				'style'   => isset( $one['style'] ) ? (string) $one['style'] : '',
			);
		}
		return $out;
	}

	/**
	 * Draw the card.
	 *
	 * @param array<string, mixed> $status A status as reported by a plugin.
	 * @return void
	 */
	public static function render_card( array $status ): void {
		$status = self::normalise( $status );
		$state  = (string) $status['state'];
		?>
		<section class="zinn-connection zinn-connection--<?php echo esc_attr( sanitize_html_class( $state ) ); ?>" aria-label="<?php esc_attr_e( 'Connection status', 'zinn-reseller' ); ?>">
			<div class="zinn-connection__body">
				<p class="zinn-connection__summary zinn-status zinn-status--<?php echo esc_attr( sanitize_html_class( $state ) ); ?>">
					<span class="zinn-status__dot" aria-hidden="true"></span>
					<span class="zinn-status__text">
						<?php echo esc_html( (string) $status['summary'] ); ?>
					</span>
					<span class="screen-reader-text"><?php echo esc_html( self::label( $state ) ); ?></span>
				</p>

				<?php if ( '' !== (string) $status['reason'] ) : ?>
					<p class="zinn-connection__reason"><?php echo esc_html( (string) $status['reason'] ); ?></p>
				<?php endif; ?>

				<?php if ( array() !== $status['details'] ) : ?>
					<dl class="zinn-connection__details">
						<?php foreach ( $status['details'] as $row ) : ?>
							<dt><?php echo esc_html( (string) $row['label'] ); ?></dt>
							<dd><?php echo esc_html( (string) $row['value'] ); ?></dd>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>

				<?php if ( (int) $status['checked_at'] > 0 ) : ?>
					<p class="zinn-connection__checked">
						<?php
						printf(
							/* translators: %s: a human-readable interval such as "5 minutes". */
							esc_html__( 'Last checked %s ago.', 'zinn-reseller' ),
							esc_html( human_time_diff( (int) $status['checked_at'], time() ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $status['actions'] ) : ?>
				<div class="zinn-connection__actions">
					<?php foreach ( $status['actions'] as $action ) : ?>
						<?php self::render_action( $action ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * One fix action — a link out, or a nonce-bearing form that posts back to us.
	 *
	 * ⛔ An action that CHANGES something is a form, never a link. A `GET` that disconnects a
	 * site is a link a browser prefetcher, a security scanner or an email client will follow
	 * on the customer's behalf.
	 *
	 * ⭐ An external link opens in a new tab with `rel="noopener"` — the customer is part-way
	 * through configuring a plugin and taking the tab away from them loses that work.
	 *
	 * @param array<string, string> $action A normalised action.
	 * @return void
	 */
	private static function render_action( array $action ): void {
		if ( '' !== $action['action'] ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Zinn_Reseller_Admin_UI::act_action() ); ?>" />
				<input type="hidden" name="zinn_action" value="<?php echo esc_attr( $action['action'] ); ?>" />
				<?php wp_nonce_field( Zinn_Reseller_Admin_UI::act_action() ); ?>
				<button
					type="submit"
					class="button <?php echo 'primary' === $action['style'] ? 'button-primary' : ''; ?><?php echo 'danger' === $action['style'] ? ' zinn-button--danger' : ''; ?>"
					<?php echo '' === $action['confirm'] ? '' : 'data-zinn-confirm="' . esc_attr( $action['confirm'] ) . '"'; ?>
				><?php echo esc_html( $action['label'] ); ?></button>
			</form>
			<?php
			return;
		}

		if ( '' === $action['url'] ) {
			return;
		}

		$external = 0 !== strpos( $action['url'], admin_url() );
		?>
		<a
			class="button <?php echo 'primary' === $action['style'] ? 'button-primary' : ''; ?>"
			href="<?php echo esc_url( $action['url'] ); ?>"
			<?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
		>
			<?php echo esc_html( $action['label'] ); ?>
			<?php if ( $external ) : ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'zinn-reseller' ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}
}
