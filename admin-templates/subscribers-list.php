<?php
/**
 * Admin: Subscribers list.
 *
 * Variables expected from the caller (`Admin_Subscribers::render_subscribers`):
 *
 * - `$list_table` Subscribers_List_Table Prepared list table.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$add_url = add_query_arg(
	array(
		'page'   => SUBSCRIBERS_PAGE_SLUG,
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

$import_url = add_query_arg(
	array(
		'page'   => SUBSCRIBERS_PAGE_SLUG,
		'action' => 'import',
	),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a> <a href="%s" class="page-title-action">%s</a>',
	esc_html__( 'Subscribers', 'heads-up-mailer' ),
	esc_url( $add_url ),
	esc_html__( 'Add new', 'heads-up-mailer' ),
	esc_url( $import_url ),
	esc_html__( 'Import CSV', 'heads-up-mailer' )
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$search_term = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';

if ( '' !== $search_term ) {
	printf(
		'<span class="subtitle">%s</span>',
		esc_html(
			sprintf(
				/* translators: %s: search query. */
				__( 'Search results for: %s', 'heads-up-mailer' ),
				$search_term
			)
		)
	);
}

printf( '<hr class="wp-header-end">' );

// Flash notices.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$deleted = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$never_contact_flag = isset( $_GET['never_contact'] ) ? sanitize_key( wp_unslash( $_GET['never_contact'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$bulk_never_contact = isset( $_GET['bulk_never_contact'] ) ? absint( $_GET['bulk_never_contact'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$bulk_deleted = isset( $_GET['bulk_deleted'] ) ? absint( $_GET['bulk_deleted'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $updated ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Subscriber saved.', 'heads-up-mailer' )
	);
}

if ( '' !== $deleted ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Subscriber deleted.', 'heads-up-mailer' )
	);
}

if ( '' !== $never_contact_flag ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Subscriber flagged as "never contact".', 'heads-up-mailer' )
	);
}

if ( $bulk_never_contact > 0 ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of subscribers flipped to never-contact. */
				_n( '%d subscriber flagged as "never contact".', '%d subscribers flagged as "never contact".', $bulk_never_contact, 'heads-up-mailer' ),
				$bulk_never_contact
			)
		)
	);
}

if ( $bulk_deleted > 0 ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of subscribers deleted. */
				_n( '%d subscriber deleted.', '%d subscribers deleted.', $bulk_deleted, 'heads-up-mailer' ),
				$bulk_deleted
			)
		)
	);
}

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_subscriber_exists'         => __( 'A subscriber with that email already exists.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_email'  => __( 'A valid email address is required.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_status' => __( 'Invalid subscriber status.', 'heads-up-mailer' ),
		'hum_subscriber_not_found'      => __( 'Subscriber not found.', 'heads-up-mailer' ),
		'hum_subscriber_insert_failed'  => __( 'Failed to insert subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_update_failed'  => __( 'Failed to update subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_delete_failed'  => __( 'Failed to delete subscriber.', 'heads-up-mailer' ),
		'hum_bulk_no_selection'         => __( 'Select at least one subscriber before applying a bulk action.', 'heads-up-mailer' ),
		'hum_bulk_unknown_action'       => __( 'That bulk action is not recognised.', 'heads-up-mailer' ),
	);

	$msg = isset( $error_messages[ $error_code ] )
		? $error_messages[ $error_code ]
		: __( 'An error occurred.', 'heads-up-mailer' );

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html( $msg )
	);
}

$list_table->views();

$bulk_confirm = __( 'Apply this bulk action to the selected subscribers? Deleting is permanent and cannot be undone. Marking as "never contact" is sticky — future imports will skip these rows.', 'heads-up-mailer' );

// GET to admin.php, like core's list screens: search, filters, sorting and
// bulk actions all travel in the URL. Bulk actions are applied in
// Admin_Subscribers::load_list_screen() before any output.
printf( '<form method="get" class="hum-subscribers-form" data-hum-bulk-confirm="%s">', esc_attr( $bulk_confirm ) );
printf( '<input type="hidden" name="page" value="%s" />', esc_attr( SUBSCRIBERS_PAGE_SLUG ) );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$current_status = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

if ( in_array( $current_status, Subscribers_Controller::statuses(), true ) ) {
	printf( '<input type="hidden" name="status" value="%s" />', esc_attr( $current_status ) );
}

$list_table->search_box( __( 'Search subscribers', 'heads-up-mailer' ), 'hum-subscriber' );
$list_table->display();

printf( '</form>' );

printf( '</div>' );
