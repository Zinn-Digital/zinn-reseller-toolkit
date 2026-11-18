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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Product meta: the stack the site is built on (`stack_type`; default `wordpress`).
	 */
	public const STACK_META = '_zinn_reseller_stack_type';

	/**
	 * Product meta: the package (one of the reseller's plan codes) the client is put on.
	 */
	public const PACKAGE_META = '_zinn_reseller_package';

	/**
	 * Product meta: the interval of the client's wholesale line (`monthly` or `annual`).
	 */
	public const INTERVAL_META = '_zinn_reseller_interval';

	/**
	 * Stacks the platform provisions (`hosting.enums.OFFERED_STACK_TYPES`).
	 */
	public const STACKS = array( 'wordpress', 'woocommerce', 'php', 'static', 'node' );

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
		// ⛔⛔ Ask "is this hosting?" BEFORE "is there a key?". Until 1.2.9 the key check came
		// first, so with the module on and no key yet EVERY paid order — a t-shirt included —
		// had "No Zinn® API key is configured" written onto it: D12895's defect again, one
		// branch earlier (reproduced on the directory's 1.2.8, order 16 of the proof site).
		$line = self::hosting_line( $order );
		if ( null === $line ) {
			return; // Not a hosting order — nothing to do, and not an error.
		}

		if ( ! Client::is_configured() ) {
			self::fail( $order, __( 'No Zinn® API key is configured, so nothing was provisioned.', 'zinn-reseller' ) );
			return;
		}

		// ⛔ Checked BEFORE the client account is made (P7-202, owner answer A267): a client
		// with no package can do nothing, so an order for a package-less product must not
		// leave behind an account that looks provisioned. The shop owner reads why here.
		$spec = self::product_spec( $line['item'], $line['product_line'] );
		if ( '' === $spec['package'] ) {
			self::fail( $order, self::no_package_message() );
			return;
		}

		$domain = self::requested_domain( $order, $line );
		if ( '' === $domain ) {
			self::fail(
				$order,
				__( 'This order did not include a domain name, so there was nothing to provision.', 'zinn-reseller' )
			);
			return;
		}

		$org_id = self::client_org( $order );
		if ( '' === $org_id ) {
			return; // `client_org` has already recorded why.
		}

		$result = self::provision_requests(
			$org_id,
			$spec,
			$domain,
			(string) $order->get_id(),
			array( Client::class, 'post' )
		);
		if ( isset( $result['error'] ) ) {
			self::fail( $order, $result['error'] );
			return;
		}

		$site_id = $result['site_id'];
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
	 * The two platform calls a paid hosting order makes, in their only safe order.
	 *
	 * ⛔⛔ PLAN FIRST, THEN SITE, exactly as the WHMCS module does (`zinn_clientPlan`). The
	 * plan call puts the client on the reseller's package and returns the subscription the
	 * site is recorded against: that is the line on the reseller's wholesale statement and
	 * the client's allowances (D17700). A site created without it is unbilled and
	 * allowance-less, so a failed plan call creates NO site. `stack_type` is mandatory: the
	 * API refuses a create with neither it nor a blueprint (D17360).
	 *
	 * Public and transport-injected so the request bodies are pinned by a contract test
	 * (`wp/tests/unit/ResellerToolkitProvisioningContractTest.php`).
	 *
	 * @param string                                                                      $org_id    Client organisation id.
	 * @param array{product_line:string,stack_type:string,package:string,interval:string} $spec      What the product sells.
	 * @param string                                                                      $domain    The site's hostname.
	 * @param string                                                                      $order_key Stable per order, for the idempotency keys.
	 * @param callable                                                                    $post      `Client::post`-shaped transport.
	 * @return array{site_id:string}|array{error:string}
	 */
	public static function provision_requests( string $org_id, array $spec, string $domain, string $order_key, callable $post ): array {
		$package = trim( (string) $spec['package'] );
		if ( '' === $package ) {
			return array( 'error' => self::no_package_message() );
		}
		$interval = in_array( $spec['interval'], array( 'monthly', 'annual' ), true ) ? $spec['interval'] : 'monthly';

		$plan = $post(
			'/v1/reseller/clients/' . rawurlencode( $org_id ) . '/plan',
			array(
				'plan_code' => $package,
				'interval'  => $interval,
			),
			'wc-plan-' . $order_key
		);
		if ( is_wp_error( $plan ) ) {
			return array( 'error' => $plan->get_error_message() );
		}
		$subscription_id = is_array( $plan ) ? trim( (string) ( $plan['subscription_id'] ?? '' ) ) : '';
		if ( '' === $subscription_id ) {
			return array(
				'error' => sprintf(
					/* translators: %s: the package (plan code) set on the product. */
					__( 'The hosting platform did not put the client on package "%s", so no site was created.', 'zinn-reseller' ),
					$package
				),
			);
		}

		$site = $post(
			'/v1/sites',
			self::site_body( $org_id, (string) $spec['product_line'], $domain, (string) $spec['stack_type'], $subscription_id ),
			'wc-site-' . $order_key
		);
		if ( is_wp_error( $site ) ) {
			return array( 'error' => $site->get_error_message() );
		}
		if ( ! is_array( $site ) || empty( $site['id'] ) ) {
			return array( 'error' => __( 'The hosting platform did not return a site.', 'zinn-reseller' ) );
		}
		return array( 'site_id' => (string) $site['id'] );
	}

	/**
	 * The `POST /v1/sites` body, shared with the AI action so the two can never drift.
	 *
	 * @param string $org_id          Client organisation id.
	 * @param string $product_line    Zinn® product line.
	 * @param string $domain          Hostname (lower-cased here).
	 * @param string $stack           Stack; anything not offered becomes `wordpress`.
	 * @param string $subscription_id The client's subscription from the plan call.
	 * @return array<string,string>
	 */
	public static function site_body( string $org_id, string $product_line, string $domain, string $stack, string $subscription_id ): array {
		$domain = strtolower( trim( $domain ) );
		return array(
			'org_id'          => $org_id,
			'product_line'    => $product_line,
			'primary_domain'  => $domain,
			'name'            => $domain,
			'stack_type'      => in_array( $stack, self::STACKS, true ) ? $stack : 'wordpress',
			'subscription_id' => $subscription_id,
		);
	}

	/**
	 * What the product says to provision: line, stack, package and interval.
	 *
	 * @param \WC_Order_Item_Product $item         The hosting line.
	 * @param string                 $product_line The resolved product line.
	 * @return array{product_line:string,stack_type:string,package:string,interval:string}
	 */
	private static function product_spec( \WC_Order_Item_Product $item, string $product_line ): array {
		$product = $item->get_product();
		$meta    = static fn( string $key ): string => $product ? trim( (string) $product->get_meta( $key ) ) : '';
		return array(
			'product_line' => $product_line,
			'stack_type'   => $meta( self::STACK_META ),
			'package'      => $meta( self::PACKAGE_META ),
			'interval'     => $meta( self::INTERVAL_META ),
		);
	}

	/**
	 * The plain reason an order for a product with no package was not provisioned.
	 *
	 * @return string
	 */
	private static function no_package_message(): string {
		return __( 'This hosting product has no Zinn® package, so nothing was provisioned and no client account was created. Choose a package in the product\'s Inventory tab, then set the order back to Processing.', 'zinn-reseller' );
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

		$stacks = array();
		foreach ( self::STACKS as $stack ) {
			$stacks[ $stack ] = $stack;
		}
		woocommerce_wp_select(
			array(
				'id'          => self::STACK_META,
				'label'       => __( 'Zinn® stack', 'zinn-reseller' ),
				'description' => __( 'What the site is built on. WordPress unless you choose otherwise.', 'zinn-reseller' ),
				'desc_tip'    => true,
				'options'     => $stacks,
			)
		);

		$packages = self::package_options();
		$help     = __( 'Required for hosting: the package your client is put on. It sets their allowances and the line on your wholesale statement. An order for a product with no package is not provisioned.', 'zinn-reseller' );
		if ( array() === $packages ) {
			// The price list could not be read (no key yet, or the platform unreachable):
			// the code can still be typed, and the order path checks it.
			woocommerce_wp_text_input(
				array(
					'id'          => self::PACKAGE_META,
					'label'       => __( 'Zinn® package (plan code)', 'zinn-reseller' ),
					'description' => $help,
					'desc_tip'    => true,
				)
			);
		} else {
			woocommerce_wp_select(
				array(
					'id'          => self::PACKAGE_META,
					'label'       => __( 'Zinn® package', 'zinn-reseller' ),
					'description' => $help,
					'desc_tip'    => true,
					'options'     => array( '' => __( 'Choose a package', 'zinn-reseller' ) ) + $packages,
				)
			);
		}

		woocommerce_wp_select(
			array(
				'id'          => self::INTERVAL_META,
				'label'       => __( 'Zinn® billing interval', 'zinn-reseller' ),
				'description' => __( 'How often the client\'s line appears on your wholesale statement.', 'zinn-reseller' ),
				'desc_tip'    => true,
				'options'     => array(
					'monthly' => __( 'Monthly', 'zinn-reseller' ),
					'annual'  => __( 'Annual', 'zinn-reseller' ),
				),
			)
		);
	}

	/**
	 * The reseller's packages from `GET /v1/reseller/prices`, as `plan_code => plan_name`.
	 *
	 * @return array<string,string> Empty when the list cannot be read.
	 */
	private static function package_options(): array {
		if ( ! Client::is_configured() ) {
			return array();
		}
		$prices = Client::get( '/v1/reseller/prices' );
		if ( is_wp_error( $prices ) || ! is_array( $prices['data'] ?? null ) ) {
			return array();
		}
		$out = array();
		foreach ( $prices['data'] as $row ) {
			$code = is_array( $row ) ? (string) ( $row['plan_code'] ?? '' ) : '';
			if ( '' !== $code ) {
				$out[ $code ] = (string) ( $row['plan_name'] ?? $code );
			}
		}
		return $out;
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
		foreach ( array( self::STACK_META, self::PACKAGE_META, self::INTERVAL_META ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
			$product->update_meta_data( $key, $posted );
		}
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
