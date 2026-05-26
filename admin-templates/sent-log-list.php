<?php
/**
 * Admin: Sent log — list of every send with live counters.
 *
 * Variables expected from the caller (`Plugin::render_sent_log`):
 *
 * - `$sends` array<int, object> Rows from
 *   `Sent_Log_Controller::get_sends_with_counts()`.
 *
 * @package Heads_Up_Mailer
 * @since 0.7.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

printf( '<div class="wrap">' );
printf( '<h1 class="wp-heading-inline">%s</h1><hr class="wp-header-end">', esc_html__( 'Sent log', 'heads-up-mailer' ) );

if ( empty( $sends ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No sends recorded yet. Once a draft is queued from the Drafts page, it will appear here.', 'heads-up-mailer' )
	);
	printf( '</div>' );
	return;
}

printf( '<table class="wp-list-table widefat fixed striped"><thead><tr>' );
printf( '<th scope="col">%s</th>', esc_html__( 'ID', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Subject', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Sent / Total', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Failed', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'In progress', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Started (UTC)', 'heads-up-mailer' ) );
printf( '<th scope="col">%s</th>', esc_html__( 'Finished (UTC)', 'heads-up-mailer' ) );
printf( '</tr></thead><tbody>' );

foreach ( $sends as $row ) {
	$send_id       = (int) $row->id;
	$draft_subject = (string) ( $row->draft_subject ?? '' );
	$draft_status  = (string) ( $row->draft_status ?? '' );
	$total         = (int) ( $row->recipients_total ?? 0 );
	$sent_count    = (int) ( $row->recipients_sent ?? 0 );
	$failed_count  = (int) ( $row->recipients_failed ?? 0 );
	$pending_count = (int) ( $row->recipients_pending ?? 0 );
	$running_count = (int) ( $row->recipients_processing ?? 0 );
	$in_progress   = $pending_count + $running_count;
	$started_at    = (string) ( $row->started_at ?? '' );
	$finished_at   = (string) ( $row->finished_at ?? '' );

	$detail_url = add_query_arg(
		array(
			'page'    => 'heads-up-mailer-sent-log',
			'send_id' => $send_id,
		),
		admin_url( 'admin.php' )
	);

	$subject_display = '' === $draft_subject
		? __( '(draft deleted)', 'heads-up-mailer' )
		: $draft_subject;

	printf(
		'<tr><td>%d</td><td><a href="%s"><strong>%s</strong></a></td><td><span class="hum-status hum-status-%s">%s</span></td><td>%d / %d</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
		(int) $send_id,
		esc_url( $detail_url ),
		esc_html( $subject_display ),
		esc_attr( $draft_status ),
		esc_html( $draft_status ),
		(int) $sent_count,
		(int) $total,
		(int) $failed_count,
		(int) $in_progress,
		esc_html( $started_at ),
		esc_html( '' === $finished_at ? '—' : $finished_at )
	);
}

printf( '</tbody></table>' );
printf( '</div>' );
