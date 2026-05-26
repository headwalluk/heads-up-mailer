<?php
/**
 * Admin: Sent log — per-send drill-down with status filter.
 *
 * Variables expected from the caller (`Plugin::render_sent_log`):
 *
 * - `$send_id` int  ID of the parent send.
 * - `$send`    object|null  Sends row (or null if missing — caller
 *              should usually render a "not found" notice instead).
 * - `$recipients` array<int, object>  Rows from
 *              `Sent_Log_Controller::get_recipients_for_send()`.
 * - `$status_filter` string  Active status filter ('' = all).
 *
 * @package Heads_Up_Mailer
 * @since 0.7.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$back_url = add_query_arg(
	array( 'page' => 'heads-up-mailer-sent-log' ),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s #%d</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
	esc_html__( 'Sent log', 'heads-up-mailer' ),
	(int) $send_id,
	esc_url( $back_url ),
	esc_html__( 'Back to list', 'heads-up-mailer' )
);

if ( null === $send ) {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Send not found.', 'heads-up-mailer' )
	);
	printf( '</div>' );
	return;
}

$subject       = (string) ( $send->draft_subject ?? '' );
$started_at    = (string) ( $send->started_at ?? '' );
$finished_at   = (string) ( $send->finished_at ?? '' );
$total         = (int) ( $send->recipients_total ?? 0 );
$sent_count    = (int) ( $send->recipients_sent ?? 0 );
$failed_count  = (int) ( $send->recipients_failed ?? 0 );
$pending_count = (int) ( $send->recipients_pending ?? 0 );
$running_count = (int) ( $send->recipients_processing ?? 0 );

printf(
	'<p><strong>%s</strong> %s<br><strong>%s</strong> %s<br><strong>%s</strong> %s</p>',
	esc_html__( 'Subject:', 'heads-up-mailer' ),
	esc_html( '' === $subject ? __( '(draft deleted)', 'heads-up-mailer' ) : $subject ),
	esc_html__( 'Started (UTC):', 'heads-up-mailer' ),
	esc_html( $started_at ),
	esc_html__( 'Finished (UTC):', 'heads-up-mailer' ),
	esc_html( '' === $finished_at ? __( 'in progress', 'heads-up-mailer' ) : $finished_at )
);

// Status filter row. Each link rewrites the current page URL with
// the chosen status (or omits it for "all").
$filter_options = array(
	''                     => sprintf(
		/* translators: %d: total recipients across all statuses. */
		__( 'All (%d)', 'heads-up-mailer' ),
		$total
	),
	SEND_STATUS_SENT       => sprintf(
		/* translators: %d: number of recipients in this status. */
		__( 'Sent (%d)', 'heads-up-mailer' ),
		$sent_count
	),
	SEND_STATUS_FAILED     => sprintf(
		/* translators: %d: number of recipients in this status. */
		__( 'Failed (%d)', 'heads-up-mailer' ),
		$failed_count
	),
	SEND_STATUS_PENDING    => sprintf(
		/* translators: %d: number of recipients in this status. */
		__( 'Pending (%d)', 'heads-up-mailer' ),
		$pending_count
	),
	SEND_STATUS_PROCESSING => sprintf(
		/* translators: %d: number of recipients in this status. */
		__( 'Processing (%d)', 'heads-up-mailer' ),
		$running_count
	),
);

printf( '<ul class="subsubsub">' );
$first = true;

foreach ( $filter_options as $status_key => $label ) {
	$args = array(
		'page'    => 'heads-up-mailer-sent-log',
		'send_id' => (int) $send_id,
	);

	if ( '' !== $status_key ) {
		$args['status'] = $status_key;
	}

	$url = add_query_arg( $args, admin_url( 'admin.php' ) );

	$separator = $first ? '' : ' | ';
	$first     = false;

	$active_class = ( $status_filter === $status_key ) ? ' class="current"' : '';

	printf(
		'<li>%s<a href="%s"%s>%s</a></li>',
		esc_html( $separator ),
		esc_url( $url ),
		$active_class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute fragment.
		esc_html( $label )
	);
}
printf( '</ul><br class="clear">' );

if ( empty( $recipients ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No recipients match this filter.', 'heads-up-mailer' )
	);
	printf( '</div>' );
	return;
}

printf( '<table class="wp-list-table widefat fixed striped"><thead><tr>' );
printf( '<th scope="col">%s</th>', esc_html__( 'ID', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Email', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Attempts', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Sent at (UTC)', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Last error', 'heads-up-mailer' ) );
printf( '</tr></thead><tbody>' );

foreach ( $recipients as $recipient ) {
	$row_id       = (int) $recipient->id;
	$email        = (string) ( $recipient->email ?? '' );
	$status_value = (string) ( $recipient->status ?? '' );
	$attempts     = (int) ( $recipient->attempts ?? 0 );
	$sent_at      = (string) ( $recipient->sent_at ?? '' );
	$last_error   = (string) ( $recipient->last_error ?? '' );

	$email_display = '' === $email
		? __( '(subscriber removed)', 'heads-up-mailer' )
		: $email;

	printf(
		'<tr><td>%d</td><td>%s</td><td><span class="hum-status hum-status-%s">%s</span></td><td>%d</td><td>%s</td><td>%s</td></tr>',
		(int) $row_id,
		esc_html( $email_display ),
		esc_attr( $status_value ),
		esc_html( $status_value ),
		(int) $attempts,
		esc_html( '' === $sent_at ? '—' : $sent_at ),
		esc_html( '' === $last_error ? '—' : $last_error )
	);
}

printf( '</tbody></table>' );
printf( '</div>' );
