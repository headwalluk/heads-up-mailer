<?php
/**
 * Heads Up Mailer — Uninstaller
 *
 * Runs when the plugin is deleted from the Plugins screen, not on
 * deactivation. Deletes nothing unless the "Delete data on uninstall"
 * setting is on. WooCommerce order meta (`_hum_*`) is kept: it is the
 * order's record of the consent given at checkout.
 *
 * @package Heads_Up_Mailer
 */

namespace Heads_Up_Mailer;

defined( 'WP_UNINSTALL_PLUGIN' ) || die();

require_once __DIR__ . '/constants.php';

$hum_delete_data = (bool) filter_var(
	get_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEF_DELETE_DATA_ON_UNINSTALL ),
	FILTER_VALIDATE_BOOLEAN
);

if ( $hum_delete_data ) {
	global $wpdb;

	wp_clear_scheduled_hook( CRON_DRAIN_QUEUE );
	wp_clear_scheduled_hook( CRON_POLL_MAILBOX );

	$hum_tables = array(
		TABLE_SEND_RECIPIENTS,
		TABLE_SENDS,
		TABLE_SUBSCRIBER_GROUPS,
		TABLE_EVENTS,
		TABLE_DRAFTS,
		TABLE_GROUPS,
		TABLE_SUBSCRIBERS,
	);

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-off teardown at uninstall; there is no API for it.
	foreach ( $hum_tables as $hum_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$hum_table}" );
	}

	// Found by prefix so keys from older versions go too; each is then
	// deleted through the WordPress API so object caches are cleared with it.
	$hum_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( OPTION_PREFIX ) . '%'
		)
	);

	// Under a persistent object cache, transients never reach the options
	// table; those are locks and rate limits that expire on their own.
	$hum_transient_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . OPTION_PREFIX ) . '%'
		)
	);
	// phpcs:enable

	foreach ( $hum_option_names as $hum_option_name ) {
		delete_option( $hum_option_name );
	}

	foreach ( $hum_transient_names as $hum_transient_name ) {
		delete_transient( substr( $hum_transient_name, strlen( '_transient_' ) ) );
	}

	// Per-user Screen Options for the subscribers list; core names the hidden-columns key after the screen ID.
	delete_metadata( 'user', 0, META_SUBSCRIBERS_PER_PAGE, '', true );
	delete_metadata( 'user', 0, 'manageheads-up-mailer_page_' . SUBSCRIBERS_PAGE_SLUG . 'columnshidden', '', true );

	foreach ( wp_roles()->role_objects as $hum_role ) {
		foreach ( array( CAP_CREATE_DRAFTS, CAP_SEND_NEWSLETTERS, CAP_READ_GROUPS, CAP_MANAGE_GROUPS ) as $hum_cap ) {
			$hum_role->remove_cap( $hum_cap );
		}
	}

	delete_transient( TRANSIENT_DRAIN_LOCK );
	delete_transient( TRANSIENT_POLL_LOCK );
	delete_transient( UPDATER_CACHE_KEY );
	delete_transient( UPDATER_FAILURE_CACHE_KEY );
}
