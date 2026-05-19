<?php
/**
 * Admin: Heads Up Mailer dashboard placeholder.
 *
 * Replaced with real content as M4+ milestones land. For now, this
 * just confirms the menu and capability checks are wired up.
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
		'Heads Up Mailer is installed. Use the submenu to manage groups. Subscribers, drafts, and the send pipeline will land in later milestones.',
		'heads-up-mailer'
	)
);

printf( '</div>' );
