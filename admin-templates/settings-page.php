<?php
/**
 * Admin: Settings page wrapper.
 *
 * Hosts the tab nav and a single `options.php` form covering all
 * tabs. Each tab template is `require`d into its own panel
 * container; the visible panel is toggled in JS by
 * `assets/admin/heads-up-mailer-admin.js`.
 *
 * @package Heads_Up_Mailer
 * @since 0.2.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

// "Settings saved" / "Settings error" notices, as posted by options.php.
ob_start();
settings_errors();
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_errors output is safe.

printf(
	'<form method="post" action="%s" id="hum-settings-form">',
	esc_url( admin_url( 'options.php' ) )
);

ob_start();
settings_fields( Settings::GROUP );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_fields output is safe.

printf( '<nav class="nav-tab-wrapper wp-clearfix">' );
printf(
	'<a href="#queue" class="nav-tab nav-tab-active" data-tab="queue">%s</a>',
	esc_html__( 'Queue', 'heads-up-mailer' )
);
printf(
	'<a href="#mailbox" class="nav-tab" data-tab="mailbox">%s</a>',
	esc_html__( 'Mailbox', 'heads-up-mailer' )
);
printf( '</nav>' );

printf( '<div class="tab-content">' );

printf( '<div id="queue-panel" class="tab-panel active">' );
require __DIR__ . '/tab-queue.php';
printf( '</div>' );

printf( '<div id="mailbox-panel" class="tab-panel" style="display:none;">' );
require __DIR__ . '/tab-mailbox.php';
printf( '</div>' );

printf( '</div>' );

ob_start();
submit_button( __( 'Save settings', 'heads-up-mailer' ) );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- submit_button output is safe.

printf( '</form>' );
printf( '</div>' );
