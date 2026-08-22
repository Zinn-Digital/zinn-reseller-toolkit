<?php
/**
 * WooCommerce provisioning — hosting is set up when an order is paid.
 *
 * The reseller sells hosting on their own site, takes the money into their own account
 * through their own WooCommerce gateway, and this module tells the Zinn® platform to
 * provision it under a client organisation belonging to that reseller.
 *
 * ⛔ We are never in the payment path. WooCommerce settles the money; this runs after it.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

/**
 * Provisions hosting from a paid WooCommerce order.
 */
class WooCommerce_Provisioning {

	/**
	 * Product meta naming the Zinn® product line this product sells.
	 */
	public const PRODUCT_LINE_META = '_zinn_reseller_product_line';

	/**
	 * Order meta recording the Zinn® site we created, so we never create a second one.
	 */
	public const ORDER_SITE_META = '_zinn_reseller_site_id';

	/**
	 * Order meta recording the client organisation we created or reused.
	 */
	public const ORDER_ORG_META = '_zinn_reseller_org_id';

	/**
	 * Order meta recording why provisioning failed, so the shop owner can see it.
	 */
	public const ORDER_ERROR_META = '_zinn_reseller_error';

	/**
	 * Item meta holding the hostname the customer asked for at checkout.
	 */
	public const ITEM_DOMAIN_META = 'zinn_domain';

	/**
	 * Wire the order hooks and the product field.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'provision' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'provision' ), 10, 1 );
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'order_meta_box' ) );
	}

	/**
	 * Provision hosting for a paid order.
	 *
	 * ⛔⛔ IDEMPOTENT AT THREE LEVELS, and every one of them is load-bearing because
	 * WooCommerce fires these hooks more than once in ordinary operation — a gateway
	 * retry, an admin moving an order from processing to completed, a webhook arriving
	 * twice. Without all three the client is billed for a second site they did not order:
	 *
	 *   1. The order meta is checked first, so a completed provision is never repeated.
	 *   2. Every call carries an `Idempotency-Key` derived from the ORDER id, so even a
	 *      race between two workers resolves to one site at the API.
	 *   3. The client organisation is looked up by the reseller's own reference before it
	 *      is created, so a returning customer gets a second site under the SAME account
	 *      rather than a second account.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public static function provision( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::ORDER_SITE_META ) ) {
			return; // Already done.
		}
		if ( ! Client::is_configured() ) {
			self::fail( $order, __( 'No Zinn® API key is configured, so nothing was provisioned.', 'zinn-reseller' ) );
			return;
		}

		$line = self::hosting_line( $order );
		if ( null === $line ) {
			return; // Not a hosting order — nothing to do, and not an error.
		}

		$org_id = self::client_org( $order );
		if ( '' === $org_id ) {
			return; // `client_org` has already recorded why.
		}

		$domain = self::requested_domain( $order, $line );
		if ( '' === $domain ) {
			self::fail(
				$order,
				__( 'This order did not include a domain name, so there was nothing to provision.', 'zinn-reseller' )
			);
			return;
		}

		$response = Client::post(
			'/v1/sites',
			array(
				'org_id'         => $org_id,
				'product_line'   => $line['product_line'],
				'primary_domain' => $domain,
				'name'           => $domain,
			),
			'wc-site-' . $order->get_id()
		);
		if ( is_wp_error( $response ) || empty( $response['id'] ) ) {
			self::fail(
				$order,
				is_wp_error( $response )
					? $response->get_error_message()
					: __( 'The hosting platform did not return a site.', 'zinn-reseller' )
			);
			return;
		}

		$site_id = (string) $response['id'];
		$order->update_meta_data( self::ORDER_SITE_META, $site_id );
		$order->delete_meta_data( self::ORDER_ERROR_META );
		$order->add_order_note(
			sprintf(
				/* translators: 1: domain name, 2: hosting service id. */
				__( 'Zinn® hosting provisioned for %1$s (service %2$s).', 'zinn-reseller' ),
				$domain,
				$site_id
			)
		);
		$order->save();

		// So `[zinn_panel_link]` has something to sign this customer into. A guest
		// checkout has no user to link, which is fine — the link simply does not render.
		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			Panel_Link::link( $user_id, $site_id );
		}
	}

	/**
	 * The client organisation for this order — reused if we have one, created if not.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Organisation id, or an empty string when it could not be resolved.
	 */
	private static function client_org( \WC_Order $order ): string {
		$existing = (string) $order->get_meta( self::ORDER_ORG_META );
		if ( '' !== $existing ) {
			return $existing;
		}

		// A returning customer must land in the account they already have. Their WordPress
		// user id is the reseller's own stable reference to them; a guest checkout falls
		// back to the order id, which is the honest answer — we cannot tell two guests
		// apart, and pretending otherwise would merge two strangers' hosting.
		$user_id   = (int) $order->get_user_id();
		$reference = $user_id > 0 ? 'wpuser-' . $user_id : 'wcorder-' . $order->get_id();

		if ( $user_id > 0 ) {
			$linked = (string) get_user_meta( $user_id, '_zinn_reseller_org_id', true );
			if ( '' !== $linked ) {
				$order->update_meta_data( self::ORDER_ORG_META, $linked );
				$order->save();
				return $linked;
			}
		}

		$name = trim( (string) $order->get_billing_company() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === $name ) {
			$name = (string) $order->get_billing_email();
		}

		$response = Client::post(
			'/v1/orgs',
			array(
				'type' => 'customer',
				'name' => $name,
			),
			'wc-org-' . $reference
		);
		if ( is_wp_error( $response ) || empty( $response['id'] ) ) {
			self::fail(
				$order,
				is_wp_error( $response )
					? $response->get_error_message()
					: __( 'The hosting platform did not return a client account.', 'zinn-reseller' )
			);
			return '';
		}

		$org_id = (string) $response['id'];
		$order->update_meta_data( self::ORDER_ORG_META, $org_id );
		$order->save();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, '_zinn_reseller_org_id', $org_id );
		}
		return $org_id;
	}

	/**
	 * The first line item on the order that sells hosting.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{item:\WC_Order_Item_Product,product_line:string}|null
	 */
	private static function hosting_line( \WC_Order $order ): ?array {
		$fallback = (string) Settings::get()['plan_code'];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$line    = $product ? (string) $product->get_meta( self::PRODUCT_LINE_META ) : '';

			// ⛔⛔ THE SHOP HAS TO HAVE SAID THIS LINE IS HOSTING. The first version applied
			// the default plan code to ANY item, so a shop that sells hosting *and* anything
			// else — with a default set, which the settings screen invites — treated the
			// first line of every order as hosting. A t-shirt order got
			// "Zinn® hosting was NOT provisioned: this order did not include a domain name"
			// written onto it, in front of the customer, for ever.
			//
			// ⭐ Two signals count as saying so, and both are things only a hosting product
			// has: the product NAMES a Zinn® product line, or the item carries a hostname the
			// customer was asked for. The default supplies the line for the second case,
			// which is what it was always for — it is a default LINE, not a switch that makes
			// every product hosting.
			if ( '' === $line ) {
				if ( '' === self::item_domain( $item ) ) {
					continue;
				}
				$line = $fallback;
			}
			if ( '' !== $line ) {
				return array(
					'item'         => $item,
					'product_line' => $line,
				);
			}
		}
		return null;
	}

	/**
	 * The hostname recorded against one order item, or an empty string.
	 *
	 * @param \WC_Order_Item_Product $item The order item.
	 * @return string
	 */
	private static function item_domain( \WC_Order_Item_Product $item ): string {
		return strtolower( trim( (string) $item->get_meta( self::ITEM_DOMAIN_META ) ) );
	}

	/**
	 * The hostname the customer asked for.
	 *
	 * @param \WC_Order           $order The order.
	 * @param array<string,mixed> $line  The resolved hosting line.
	 * @return string
	 */
	private static function requested_domain( \WC_Order $order, array $line ): string {
		// ⛔ Through `item_domain()`, the same helper `hosting_line()` asks — so "does this
		// item carry a hostname" is decided in ONE place. Two spellings of that question is
		// how a line comes to be treated as hosting by one and not by the other.
		$item = $line['item'];
		if ( $item instanceof \WC_Order_Item_Product ) {
			$domain = self::item_domain( $item );
			if ( '' !== $domain ) {
				return $domain;
			}
		}
		return strtolower( trim( (string) $order->get_meta( self::ITEM_DOMAIN_META ) ) );
	}

	/**
	 * Record a failure on the order, where the shop owner will actually see it.
	 *
	 * ⛔⛔ An order note AND a meta field, never a silent `error_log`. A provisioning
	 * failure means a customer has paid and has nothing; if the only trace is a PHP log on
	 * a shared host, the first person to notice is the customer, days later. The note puts
	 * it on the order screen the shop owner already reads.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $message What went wrong, in the API's own words.
	 * @return void
	 */
	private static function fail( \WC_Order $order, string $message ): void {
		$order->update_meta_data( self::ORDER_ERROR_META, $message );
		$order->add_order_note(
			sprintf(
				/* translators: %s: the reason provisioning failed. */
				__( 'Zinn® hosting was NOT provisioned: %s', 'zinn-reseller' ),
				$message
			)
		);
		$order->save();
	}

	/**
	 * The product-line field on a product's Inventory tab.
	 *
	 * @return void
	 */
	public static function product_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => self::PRODUCT_LINE_META,
				'label'       => __( 'Zinn® product line', 'zinn-reseller' ),
				'description' => __( 'The Zinn® product line to provision when this product is bought. Leave blank to use the default from the plugin settings.', 'zinn-reseller' ),
				'desc_tip'    => true,
			)
		);
	}

	/**
	 * Save the product-line field.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 */
	public static function save_product_field( int $product_id ): void {
		// WooCommerce has already verified its own nonce for this save; re-checking a
		// nonce it owns is not ours to do, but the input still gets sanitized.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::PRODUCT_LINE_META ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::PRODUCT_LINE_META ] ) )
			: '';
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$product->update_meta_data( self::PRODUCT_LINE_META, $value );
		$product->save();
	}

	/**
	 * A panel on the order screen showing what was provisioned, or why nothing was.
	 *
	 * @return void
	 */
	public static function order_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'zinn-reseller-order',
				__( 'Zinn® hosting', 'zinn-reseller' ),
				array( __CLASS__, 'render_order_meta_box' ),
				$screen,
				'side'
			);
		}
	}

	/**
	 * Render the order panel.
	 *
	 * @param mixed $post_or_order The post or order object WooCommerce hands us.
	 * @return void
	 */
	public static function render_order_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : 0 );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$site  = (string) $order->get_meta( self::ORDER_SITE_META );
		$error = (string) $order->get_meta( self::ORDER_ERROR_META );
		if ( '' !== $site ) {
			echo '<p>' . esc_html__( 'Service id', 'zinn-reseller' ) . '<br /><code>' . esc_html( $site ) . '</code></p>';
			return;
		}
		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Nothing provisioned for this order.', 'zinn-reseller' ) . '</p>';
	}
}
