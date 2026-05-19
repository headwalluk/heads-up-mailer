<?php
/**
 * Admin: Subscriber CSV import.
 *
 * Renders the upload form when `$report` is null, or the per-row
 * report when it's an array. Both views share this template so the
 * "back to upload" link is always close at hand.
 *
 * Variables expected from the caller (`Plugin::render_subscribers`):
 *
 * - `$report` ?array Per-row report from `CSV_Importer::import_from_file()`,
 *                   or null when first opening the page.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$back_url   = add_query_arg( array( 'page' => 'heads-up-mailer-subscribers' ), admin_url( 'admin.php' ) );
$import_url = add_query_arg(
	array(
		'page'   => 'heads-up-mailer-subscribers',
		'action' => 'import',
	),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html__( 'Import subscribers from CSV', 'heads-up-mailer' ) );

if ( null === $report ) {
	printf(
		'<p>%s</p>',
		esc_html__(
			'Upload a CSV exported from MailerLite. Existing subscribers are matched by email (case-insensitive) and their fields updated; new emails are added. Existing consent timestamps are preserved.',
			'heads-up-mailer'
		)
	);

	printf( '<p>%s</p>', esc_html__( 'Recognised columns:', 'heads-up-mailer' ) );

	printf(
		'<ul style="list-style:disc;margin-left:20px;"><li><code>Subscriber</code> or <code>email</code> %s</li><li><code>Name</code> + <code>Last name</code>, or a single <code>name</code> column</li><li><code>Subscribed</code> or <code>consent_at</code> %s</li><li><code>Groups</code> or <code>groups</code> %s</li></ul>',
		esc_html__( '(required)', 'heads-up-mailer' ),
		esc_html__( '— timestamps without a timezone are treated as UTC', 'heads-up-mailer' ),
		esc_html__( '— semicolon- or comma-separated; names are matched to group slugs', 'heads-up-mailer' )
	);

	printf(
		'<form method="post" action="%s" enctype="multipart/form-data">',
		esc_url( admin_url( 'admin-post.php' ) )
	);

	printf( '<input type="hidden" name="action" value="hum_csv_import" />' );

	ob_start();
	wp_nonce_field( 'hum_csv_import' );
	echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

	printf( '<table class="form-table" role="presentation"><tbody>' );
	printf(
		'<tr><th scope="row"><label for="hum-csv-file">%s</label></th><td><input type="file" id="hum-csv-file" name="csv_file" accept=".csv,text/csv" required /></td></tr>',
		esc_html__( 'CSV file', 'heads-up-mailer' )
	);
	printf( '</tbody></table>' );

	printf( '<p class="submit">' );
	printf(
		'<button type="submit" class="button button-primary">%s</button> <a href="%s" class="button">%s</a>',
		esc_html__( 'Upload and import', 'heads-up-mailer' ),
		esc_url( $back_url ),
		esc_html__( 'Cancel', 'heads-up-mailer' )
	);
	printf( '</p>' );

	printf( '</form>' );
} else {
	$counts = array(
		'inserted' => isset( $report['inserted'] ) ? (int) $report['inserted'] : 0,
		'updated'  => isset( $report['updated'] ) ? (int) $report['updated'] : 0,
		'skipped'  => isset( $report['skipped'] ) ? (int) $report['skipped'] : 0,
		'errors'   => isset( $report['errors'] ) ? (int) $report['errors'] : 0,
	);

	$class = ( $counts['errors'] > 0 ) ? 'notice-warning' : 'notice-success';

	printf(
		'<div class="notice %s"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px;"><li>%s: %d</li><li>%s: %d</li><li>%s: %d</li><li>%s: %d</li></ul></div>',
		esc_attr( $class ),
		esc_html__( 'Import complete.', 'heads-up-mailer' ),
		esc_html__( 'Inserted', 'heads-up-mailer' ),
		(int) $counts['inserted'],
		esc_html__( 'Updated', 'heads-up-mailer' ),
		(int) $counts['updated'],
		esc_html__( 'Skipped', 'heads-up-mailer' ),
		(int) $counts['skipped'],
		esc_html__( 'Errors', 'heads-up-mailer' ),
		(int) $counts['errors']
	);

	$rows = isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array();

	if ( ! empty( $rows ) ) {
		printf( '<table class="wp-list-table widefat striped">' );
		printf(
			'<thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead>',
			esc_html__( 'Line', 'heads-up-mailer' ),
			esc_html__( 'Email', 'heads-up-mailer' ),
			esc_html__( 'Result', 'heads-up-mailer' ),
			esc_html__( 'Notes', 'heads-up-mailer' )
		);
		printf( '<tbody>' );

		$status_labels = array(
			'inserted' => __( 'Inserted', 'heads-up-mailer' ),
			'updated'  => __( 'Updated', 'heads-up-mailer' ),
			'skipped'  => __( 'Skipped', 'heads-up-mailer' ),
			'errors'   => __( 'Error', 'heads-up-mailer' ),
		);

		foreach ( $rows as $row ) {
			$line       = isset( $row['line'] ) ? (int) $row['line'] : 0;
			$email      = isset( $row['email'] ) ? (string) $row['email'] : '';
			$row_status = isset( $row['status'] ) ? (string) $row['status'] : 'errors';
			$message    = isset( $row['message'] ) ? (string) $row['message'] : '';
			$label      = $status_labels[ $row_status ] ?? $row_status;

			printf(
				'<tr><td>%d</td><td>%s</td><td><span class="hum-import-status hum-import-status-%s">%s</span></td><td>%s</td></tr>',
				(int) $line,
				esc_html( $email ),
				esc_attr( $row_status ),
				esc_html( $label ),
				esc_html( $message )
			);
		}

		printf( '</tbody></table>' );
	}

	printf( '<p class="submit">' );
	printf(
		'<a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a>',
		esc_url( $back_url ),
		esc_html__( 'Back to subscribers', 'heads-up-mailer' ),
		esc_url( $import_url ),
		esc_html__( 'Import another file', 'heads-up-mailer' )
	);
	printf( '</p>' );
}

printf( '</div>' );
