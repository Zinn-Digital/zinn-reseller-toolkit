<?php
/**
 * Uninstall handler: remove the plugin's stored settings.
 *
 * ⛔ The per-user service and organisation links are deliberately NOT deleted. They are the
 * only record connecting a customer of this shop to the hosting they are paying for, and a
 * plugin deactivated to try something else must not orphan live services. Order meta is
 * left for the same reason: it is part of the shop's commercial record, not ours.
 *
 * @package Zinn\Reseller
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

const ZINN_RESELLER_UNINSTALL_OPTION = 'zinn_reseller_settings';

if ( is_multisite() ) {
	$zinn_reseller_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $zinn_reseller_site_ids as $zinn_reseller_site_id ) {
		switch_to_blog( (int) $zinn_reseller_site_id );
		delete_option( ZINN_RESELLER_UNINSTALL_OPTION );
		restore_current_blog();
	}
} else {
	delete_option( ZINN_RESELLER_UNINSTALL_OPTION );
}
