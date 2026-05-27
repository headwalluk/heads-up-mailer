<?php
/**
 * Admin: Heads Up Mailer dashboard.
 *
 * Intentionally minimal in 1.0.0. Stats / activity charts are
 * tracked as a follow-up.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

printf(
	'<p>%s</p>',
	esc_html__(
		'Use the submenu to manage subscribers, groups, drafts, and the sent log, or to review the send queue and integrations under Settings.',
		'heads-up-mailer'
	)
);

printf(
	'<p>%s</p>',
	esc_html__(
		'Send statistics and activity analytics will arrive in a future release.',
		'heads-up-mailer'
	)
);

printf( '</div>' );
