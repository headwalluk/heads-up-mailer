<?php
/**
 * Admin: Subscribers list.
 *
 * Variables expected from the caller (`Plugin::render_subscribers`):
 *
 * - `$subscribers`  array<int, object> Subscriber rows.
 * - `$groups_by_id` array<int, object> Groups keyed by ID.
 * - `$memberships`  array<int, array<int>> Group IDs per subscriber.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$add_url = add_query_arg(
	array(
		'page'   => 'heads-up-mailer-subscribers',
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

$import_url = add_query_arg(
	array(
		'page'   => 'heads-up-mailer-subscribers',
		'action' => 'import',
	),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
	esc_html__( 'Subscribers', 'heads-up-mailer' ),
	esc_url( $add_url ),
	esc_html__( 'Add new', 'heads-up-mailer' ),
	esc_url( $import_url ),
	esc_html__( 'Import CSV', 'heads-up-mailer' )
);

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

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_subscriber_exists'         => __( 'A subscriber with that email already exists.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_email'  => __( 'A valid email address is required.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_status' => __( 'Invalid subscriber status.', 'heads-up-mailer' ),
		'hum_subscriber_not_found'      => __( 'Subscriber not found.', 'heads-up-mailer' ),
		'hum_subscriber_insert_failed'  => __( 'Failed to insert subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_update_failed'  => __( 'Failed to update subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_delete_failed'  => __( 'Failed to delete subscriber.', 'heads-up-mailer' ),
		'hum_bulk_no_selection'         => __( 'Select an action and at least one subscriber before clicking Apply.', 'heads-up-mailer' ),
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

if ( empty( $subscribers ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No subscribers yet. Add one or import from CSV.', 'heads-up-mailer' )
	);
} else {
	$status_labels = array(
		STATUS_SUBSCRIBED    => __( 'Subscribed', 'heads-up-mailer' ),
		STATUS_UNSUBSCRIBED  => __( 'Unsubscribed', 'heads-up-mailer' ),
		STATUS_BOUNCED       => __( 'Bounced', 'heads-up-mailer' ),
		STATUS_COMPLAINED    => __( 'Complained', 'heads-up-mailer' ),
		STATUS_NEVER_CONTACT => __( 'Never contact', 'heads-up-mailer' ),
	);

	$bulk_confirm = __( 'Apply this bulk action to the selected subscribers? Marking as "never contact" is sticky — future imports will skip these rows.', 'heads-up-mailer' );

	// Confirm lives on the Apply BUTTON, not the form. Otherwise
	// every click inside the table (Edit, email link) bubbles up
	// to the form and triggers the bulk-action confirm via
	// `closest('[data-hum-confirm]')`.
	printf(
		'<form method="post" action="%s">',
		esc_url( admin_url( 'admin-post.php' ) )
	);

	printf( '<input type="hidden" name="action" value="hum_bulk_subscribers" />' );

	ob_start();
	wp_nonce_field( 'hum_bulk_subscribers' );
	echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

	printf( '<div class="tablenav top">' );
	printf(
		'<label for="hum-bulk-action" class="screen-reader-text">%s</label><select name="bulk_action" id="hum-bulk-action"><option value="">%s</option><option value="never_contact">%s</option></select> <button type="submit" class="button action" data-hum-confirm="%s">%s</button>',
		esc_html__( 'Bulk actions', 'heads-up-mailer' ),
		esc_html__( 'Bulk actions', 'heads-up-mailer' ),
		esc_html__( 'Mark as never contact', 'heads-up-mailer' ),
		esc_attr( $bulk_confirm ),
		esc_html__( 'Apply', 'heads-up-mailer' )
	);
	printf( '</div>' );

	printf( '<table class="wp-list-table widefat striped">' );
	printf(
		'<thead><tr><td class="manage-column column-cb check-column"><label class="screen-reader-text" for="hum-cb-all">%s</label><input type="checkbox" id="hum-cb-all" /></td><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead>',
		esc_html__( 'Select all', 'heads-up-mailer' ),
		esc_html__( 'Email', 'heads-up-mailer' ),
		esc_html__( 'Name', 'heads-up-mailer' ),
		esc_html__( 'Status', 'heads-up-mailer' ),
		esc_html__( 'Groups', 'heads-up-mailer' ),
		esc_html__( 'Consent at', 'heads-up-mailer' ),
		esc_html__( 'Actions', 'heads-up-mailer' )
	);
	printf( '<tbody>' );

	foreach ( $subscribers as $sub ) {
		$edit_url = add_query_arg(
			array(
				'page'          => 'heads-up-mailer-subscribers',
				'action'        => 'edit',
				'subscriber_id' => (int) $sub->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'hum_delete_subscriber',
					'subscriber_id' => (int) $sub->id,
				),
				admin_url( 'admin-post.php' )
			),
			'hum_delete_subscriber_' . (int) $sub->id
		);

		$mark_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'hum_mark_never_contact',
					'subscriber_id' => (int) $sub->id,
				),
				admin_url( 'admin-post.php' )
			),
			'hum_mark_never_contact_' . (int) $sub->id
		);

		$status_label = isset( $status_labels[ $sub->status ] ) ? $status_labels[ $sub->status ] : (string) $sub->status;

		// Compose the group chips.
		$chips = '';
		foreach ( $memberships[ (int) $sub->id ] ?? array() as $group_id ) {
			if ( isset( $groups_by_id[ (int) $group_id ] ) ) {
				$chips .= sprintf(
					'<span class="hum-group-chip" style="display:inline-block;margin:0 4px 2px 0;padding:1px 8px;border-radius:9px;background:#eef;font-size:11px;">%s</span>',
					esc_html( $groups_by_id[ (int) $group_id ]->name )
				);
			}
		}

		/* translators: %s: Subscriber email. */
		$delete_confirm = sprintf( __( 'Delete the subscriber "%s"?', 'heads-up-mailer' ), $sub->email );
		/* translators: %s: Subscriber email. */
		$mark_confirm = sprintf( __( 'Flag %s as "never contact"? This is sticky — future imports will skip them, and the only way back is to edit the status manually.', 'heads-up-mailer' ), $sub->email );

		// Never-contact rows hide the row action so it doesn't
		// look like an undo lever.
		$is_never_contact = ( STATUS_NEVER_CONTACT === (string) $sub->status );

		$row_actions = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_url ),
			esc_html__( 'Edit', 'heads-up-mailer' )
		);

		if ( ! $is_never_contact ) {
			$row_actions .= sprintf(
				' | <a href="%s" data-hum-confirm="%s">%s</a>',
				esc_url( $mark_url ),
				esc_attr( $mark_confirm ),
				esc_html__( 'Mark never-contact', 'heads-up-mailer' )
			);
		}

		$row_actions .= sprintf(
			' | <a href="%s" data-hum-confirm="%s">%s</a>',
			esc_url( $delete_url ),
			esc_attr( $delete_confirm ),
			esc_html__( 'Delete', 'heads-up-mailer' )
		);

		printf(
			'<tr><th scope="row" class="check-column"><label class="screen-reader-text" for="hum-cb-%1$d">%2$s</label><input id="hum-cb-%1$d" type="checkbox" name="subscriber_ids[]" value="%1$d" /></th><td><a href="%3$s"><strong>%4$s</strong></a></td><td>%5$s</td><td><span class="hum-status hum-status-%6$s">%7$s</span></td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>',
			(int) $sub->id,
			esc_html(
				sprintf(
					/* translators: %s: Subscriber email used as accessible checkbox label. */
					__( 'Select %s', 'heads-up-mailer' ),
					(string) $sub->email
				)
			),
			esc_url( $edit_url ),
			esc_html( $sub->email ),
			esc_html( $sub->name ),
			esc_attr( (string) $sub->status ),
			esc_html( $status_label ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $chips built from esc_html'd values above.
			$chips,
			esc_html( $sub->consent_at ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $row_actions assembled from esc_url / esc_html / esc_attr above.
			$row_actions
		);
	}

	printf( '</tbody></table>' );
	printf( '</form>' );
}

printf( '</div>' );
