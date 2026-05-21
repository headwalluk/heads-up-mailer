<?php
/**
 * Admin: Drafts list.
 *
 * Variables expected from the caller (`Plugin::render_drafts`):
 *
 * - `$drafts` array<int, object> Rows from `Drafts_Controller::get_all()`.
 *
 * @package Heads_Up_Mailer
 * @since 0.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$add_url = add_query_arg(
	array(
		'page'   => 'heads-up-mailer-drafts',
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
	esc_html__( 'Drafts', 'heads-up-mailer' ),
	esc_url( $add_url ),
	esc_html__( 'Add new', 'heads-up-mailer' )
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$deleted = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $updated ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Draft saved.', 'heads-up-mailer' )
	);
}

if ( '' !== $deleted ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Draft deleted.', 'heads-up-mailer' )
	);
}

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_draft_invalid_subject'   => __( 'Subject is required.', 'heads-up-mailer' ),
		'hum_draft_subject_too_long'  => __( 'Subject is too long.', 'heads-up-mailer' ),
		'hum_draft_invalid_html_body' => __( 'HTML body cannot be empty.', 'heads-up-mailer' ),
		'hum_draft_unknown_groups'    => __( 'One or more group slugs are unknown.', 'heads-up-mailer' ),
		'hum_draft_not_found'         => __( 'Draft not found.', 'heads-up-mailer' ),
		'hum_draft_insert_failed'     => __( 'Failed to insert draft.', 'heads-up-mailer' ),
		'hum_draft_update_failed'     => __( 'Failed to update draft.', 'heads-up-mailer' ),
		'hum_draft_delete_failed'     => __( 'Failed to delete draft.', 'heads-up-mailer' ),
	);

	$msg = isset( $error_messages[ $error_code ] )
		? $error_messages[ $error_code ]
		: __( 'An error occurred.', 'heads-up-mailer' );

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html( $msg )
	);
}

$status_labels = array(
	DRAFT_STATUS_DRAFT     => __( 'Draft', 'heads-up-mailer' ),
	DRAFT_STATUS_SENT      => __( 'Sent', 'heads-up-mailer' ),
	DRAFT_STATUS_CANCELLED => __( 'Cancelled', 'heads-up-mailer' ),
);

if ( empty( $drafts ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No drafts yet. Add one or POST to the REST endpoint.', 'heads-up-mailer' )
	);
} else {
	printf( '<table class="wp-list-table widefat striped">' );
	printf(
		'<thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead>',
		esc_html__( 'ID', 'heads-up-mailer' ),
		esc_html__( 'Subject', 'heads-up-mailer' ),
		esc_html__( 'Status', 'heads-up-mailer' ),
		esc_html__( 'Created', 'heads-up-mailer' ),
		esc_html__( 'Actions', 'heads-up-mailer' )
	);
	printf( '<tbody>' );

	foreach ( $drafts as $draft ) {
		$edit_url = add_query_arg(
			array(
				'page'     => 'heads-up-mailer-drafts',
				'action'   => 'edit',
				'draft_id' => (int) $draft->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'hum_delete_draft',
					'draft_id' => (int) $draft->id,
				),
				admin_url( 'admin-post.php' )
			),
			'hum_delete_draft_' . (int) $draft->id
		);

		$status_label = isset( $status_labels[ $draft->status ] ) ? $status_labels[ $draft->status ] : (string) $draft->status;

		/* translators: %s: Draft subject. */
		$confirm = sprintf( __( 'Delete the draft "%s"?', 'heads-up-mailer' ), $draft->subject );

		printf(
			'<tr><td>%d</td><td><a href="%s"><strong>%s</strong></a></td><td><span class="hum-status hum-status-%s">%s</span></td><td>%s</td><td><a href="%s">%s</a> | <a href="%s" data-hum-confirm="%s">%s</a></td></tr>',
			(int) $draft->id,
			esc_url( $edit_url ),
			esc_html( $draft->subject ),
			esc_attr( (string) $draft->status ),
			esc_html( $status_label ),
			esc_html( (string) $draft->created_at ),
			esc_url( $edit_url ),
			esc_html__( 'Edit', 'heads-up-mailer' ),
			esc_url( $delete_url ),
			esc_attr( $confirm ),
			esc_html__( 'Delete', 'heads-up-mailer' )
		);
	}

	printf( '</tbody></table>' );
}

printf( '</div>' );
