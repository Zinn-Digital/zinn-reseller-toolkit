<?php
/**
 * Plugin Name:       Zinn® Reseller Toolkit
 * Plugin URI:        https://zinndigital.com
 * Description:       Sell Zinn® hosting from your own WordPress site. Search and offer domains from your reseller account, sign your clients straight into their hosting panel, and provision hosting automatically when a WooCommerce order is paid.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-reseller
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package Zinn\Reseller
 *
 * Zinn Reseller Toolkit
 * Copyright (C) 2026 Zinn Digital® Ltd (Neil Lock, CEO).
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 2, as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare( strict_types=1 );

namespace Zinn\Reseller;

defined( 'ABSPATH' ) || exit;

const ZINN_RESELLER_VERSION = '1.0.0';

/**
 * Option name holding every setting this plugin owns.
 *
 * One option rather than a dozen, deliberately: a single `get_option` on a site that
 * autoloads it is one row, and the three modules are toggled together on one screen.
 * Splitting them would put three autoloaded rows on every page load of a site that may
 * only use one module.
 */
const ZINN_RESELLER_OPTION = 'zinn_reseller_settings';

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-client.php';
require_once __DIR__ . '/includes/class-domain-search.php';
require_once __DIR__ . '/includes/class-panel-link.php';
require_once __DIR__ . '/includes/class-woocommerce-provisioning.php';

/**
 * Boot the plugin.
 *
 * Every module is behind its own setting and NOTHING is loaded for a module that is off.
 * A reseller who only wants the domain-search block must not pay — in autoloaded options,
 * in hooks, in enqueued CSS or in a WooCommerce dependency — for the two they did not
 * enable. That is the WordPress spelling of the same rule the rest of this platform holds
 * itself to for route-scoped assets.
 *
 * @return void
 */
function zinn_reseller_boot(): void {
	$settings = Settings::get();

	Settings::register();

	if ( ! empty( $settings['enable_domain_search'] ) ) {
		Domain_Search::register();
	}
	if ( ! empty( $settings['enable_panel_link'] ) ) {
		Panel_Link::register();
	}
	// ⛔ The class-exists check is on `WooCommerce`, not on our own setting alone. A
	// reseller who enables this module and then deactivates WooCommerce would otherwise
	// fatal their whole site on the next request, and a white screen on a shop is the
	// worst possible way to learn a plugin was left switched on.
	if ( ! empty( $settings['enable_woocommerce'] ) && class_exists( 'WooCommerce' ) ) {
		WooCommerce_Provisioning::register();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\zinn_reseller_boot' );

/**
 * Load the plugin's translations.
 *
 * @return void
 */
function zinn_reseller_load_textdomain(): void {
	load_plugin_textdomain( 'zinn-reseller', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\zinn_reseller_load_textdomain' );
