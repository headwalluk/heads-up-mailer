<?php
/**
 * Admin: Heads Up Mailer dashboard.
 *
 * A privacy-first overview of the last `DASHBOARD_WINDOW_DAYS` days:
 * send health (with a failure-rate alert), audience snapshot, a
 * per-group breakdown, and recent send failures. No open / click
 * tracking — every figure is derived from data the plugin owns.
 *
 * Variables expected from the caller (`Plugin::render_dashboard`):
 *
 * - `$overview` array  View-model from `Dashboard_Controller::get_overview()`.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$window = (int) $overview['window_days'];
$health = $overview['send_health'];
$people = $overview['audience'];
$groups = $overview['groups'];
$failed = $overview['recent_failures'];

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

// Failure-rate alert — the one thing that should grab attention.
if ( ! empty( $health['alert'] ) ) {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		sprintf(
			/* translators: 1: failure rate percent, 2: number of days, 3: failed count, 4: completed count. */
			esc_html__( '%1$s%% of emails failed in the last %2$d days (%3$s of %4$s attempted). Check Settings &rarr; Sending for your From: address and mail provider.', 'heads-up-mailer' ),
			esc_html( (string) $health['failure_pct'] ),
			absint( $window ),
			esc_html( number_format_i18n( (int) $health['failed'] ) ),
			esc_html( number_format_i18n( (int) $health['completed'] ) )
		)
	);
}

printf(
	'<p class="description">%s</p>',
	sprintf(
		/* translators: %d: number of days in the rolling window. */
		esc_html__( 'Overview of the last %d days.', 'heads-up-mailer' ),
		absint( $window )
	)
);

printf( '<div class="hum-dash-grid">' );

// ---- Send health card ------------------------------------------------
printf( '<div class="hum-card">' );
printf( '<h2 class="hum-card-title">%s</h2>', esc_html__( 'Send health', 'heads-up-mailer' ) );

if ( $health['completed'] > 0 ) {
	$rate_class = ! empty( $health['alert'] ) ? 'hum-stat-num hum-bad' : 'hum-stat-num hum-good';

	printf(
		'<p class="%1$s">%2$s%%</p><p class="hum-stat-label">%3$s</p>',
		esc_attr( $rate_class ),
		esc_html( (string) $health['success_pct'] ),
		esc_html__( 'delivery success rate', 'heads-up-mailer' )
	);
	printf(
		'<ul class="hum-stat-list"><li>%1$s</li><li>%2$s</li><li>%3$s</li></ul>',
		sprintf(
			/* translators: %s: number of sends. */
			esc_html__( 'Sends: %s', 'heads-up-mailer' ),
			esc_html( number_format_i18n( (int) $health['sends'] ) )
		),
		sprintf(
			/* translators: %s: number of emails sent. */
			esc_html__( 'Delivered: %s', 'heads-up-mailer' ),
			esc_html( number_format_i18n( (int) $health['sent'] ) )
		),
		sprintf(
			/* translators: %s: number of emails failed. */
			esc_html__( 'Failed: %s', 'heads-up-mailer' ),
			esc_html( number_format_i18n( (int) $health['failed'] ) )
		)
	);
} elseif ( $health['sends'] > 0 ) {
	printf(
		'<p class="hum-stat-label">%s</p>',
		esc_html__( 'A send is queued or in progress. Rates appear once recipients have been attempted.', 'heads-up-mailer' )
	);
} else {
	printf(
		'<p class="hum-stat-label">%s</p>',
		esc_html__( 'No newsletters sent in this window.', 'heads-up-mailer' )
	);
}

printf( '</div>' );

// ---- Audience card ---------------------------------------------------
printf( '<div class="hum-card">' );
printf( '<h2 class="hum-card-title">%s</h2>', esc_html__( 'Audience', 'heads-up-mailer' ) );
printf(
	'<p class="hum-stat-num">%s</p><p class="hum-stat-label">%s</p>',
	esc_html( number_format_i18n( (int) $people['active'] ) ),
	esc_html__( 'active subscribers', 'heads-up-mailer' )
);
printf(
	'<ul class="hum-stat-list"><li>%s</li></ul>',
	sprintf(
		/* translators: 1: number of unsubscribes, 2: number of days. */
		esc_html__( 'Account unsubscribes (last %2$d days): %1$s', 'heads-up-mailer' ),
		esc_html( number_format_i18n( (int) $people['unsubscribes_window'] ) ),
		absint( $window )
	)
);
printf( '</div>' );

printf( '</div>' ); // .hum-dash-grid

// ---- Per-group breakdown --------------------------------------------
printf( '<h2>%s</h2>', esc_html__( 'Groups', 'heads-up-mailer' ) );

if ( empty( $groups ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No groups yet.', 'heads-up-mailer' )
	);
} else {
	printf( '<table class="widefat striped hum-groups-table"><thead><tr>' );
	printf( '<th scope="col">%s</th>', esc_html__( 'Group', 'heads-up-mailer' ) );
	printf( '<th scope="col" class="hum-num-col">%s</th>', esc_html__( 'Active members', 'heads-up-mailer' ) );
	printf(
		'<th scope="col" class="hum-num-col">%s</th>',
		sprintf(
			/* translators: %d: number of days. */
			esc_html__( 'Joined (%dd)', 'heads-up-mailer' ),
			absint( $window )
		)
	);
	printf(
		'<th scope="col" class="hum-num-col">%s</th>',
		sprintf(
			/* translators: %d: number of days. */
			esc_html__( 'Left (%dd)', 'heads-up-mailer' ),
			absint( $window )
		)
	);
	printf( '</tr></thead><tbody>' );

	foreach ( $groups as $group ) {
		printf(
			'<tr><td>%1$s</td><td class="hum-num-col">%2$s</td><td class="hum-num-col">%3$s</td><td class="hum-num-col">%4$s</td></tr>',
			esc_html( $group['name'] ),
			esc_html( number_format_i18n( (int) $group['active_members'] ) ),
			esc_html( number_format_i18n( (int) $group['joins'] ) ),
			esc_html( number_format_i18n( (int) $group['leaves'] ) )
		);
	}

	printf( '</tbody></table>' );
}

// ---- Recent send failures -------------------------------------------
printf( '<h2>%s</h2>', esc_html__( 'Recent send failures', 'heads-up-mailer' ) );

if ( empty( $failed ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No send failures recorded — everything delivered cleanly.', 'heads-up-mailer' )
	);
} else {
	printf( '<table class="widefat striped hum-failures-table"><thead><tr>' );
	printf( '<th scope="col">%s</th>', esc_html__( 'Email', 'heads-up-mailer' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Error', 'heads-up-mailer' ) );
	printf( '<th scope="col" class="hum-num-col">%s</th>', esc_html__( 'Send', 'heads-up-mailer' ) );
	printf( '</tr></thead><tbody>' );

	foreach ( $failed as $failure ) {
		$email = ( '' !== $failure['email'] )
			? $failure['email']
			: __( '(subscriber removed)', 'heads-up-mailer' );

		$error_text = ( '' !== $failure['last_error'] )
			? $failure['last_error']
			: __( '(no error recorded)', 'heads-up-mailer' );

		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td class="hum-num-col">#%3$d</td></tr>',
			esc_html( $email ),
			esc_html( $error_text ),
			absint( $failure['send_id'] )
		);
	}

	printf( '</tbody></table>' );
}

printf( '</div>' ); // .wrap
