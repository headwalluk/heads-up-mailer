<?php
/**
 * Admin: Advanced settings tab.
 *
 * `require`d from `settings-page.php`, inside the shared
 * `options.php` form.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$delete_data_on_uninstall = filter_var( get_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEF_DELETE_DATA_ON_UNINSTALL ), FILTER_VALIDATE_BOOLEAN );

printf( '<h2>%s</h2>', esc_html_x( 'Uninstall', 'settings section heading; removing the plugin', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	esc_html__( 'Deactivating the plugin never deletes data. By default, deleting it from the Plugins screen keeps your data too, so a reinstall picks up where you left off.', 'heads-up-mailer' )
);

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-delete-data-on-uninstall">%s</label></th><td><input type="hidden" name="%s" value="0" /><label><input name="%s" id="hum-delete-data-on-uninstall" type="checkbox" value="1"%s /> %s</label><p class="description">%s</p></td></tr>',
	esc_html__( 'Delete data on uninstall', 'heads-up-mailer' ),
	esc_attr( OPTION_DELETE_DATA_ON_UNINSTALL ),
	esc_attr( OPTION_DELETE_DATA_ON_UNINSTALL ),
	checked( $delete_data_on_uninstall, true, false ),
	esc_html__( 'Delete all plugin data when the plugin is deleted', 'heads-up-mailer' ),
	esc_html__( 'Removes every subscriber, group, draft and send record, plus the plugin\'s settings and capabilities. This includes the never-contact list, so a later reinstall and import could email people who asked never to be contacted. This cannot be undone.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );
