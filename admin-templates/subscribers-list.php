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

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
	esc_html__( 'Subscribers', 'heads-up-mailer' ),
	esc_url( $add_url ),
	esc_html__( 'Add new', 'heads-up-mailer' )
);

// Flash notices.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$deleted = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
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

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_subscriber_exists'         => __( 'A subscriber with that email already exists.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_email'  => __( 'A valid email address is required.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_status' => __( 'Invalid subscriber status.', 'heads-up-mailer' ),
		'hum_subscriber_not_found'      => __( 'Subscriber not found.', 'heads-up-mailer' ),
		'hum_subscriber_insert_failed'  => __( 'Failed to insert subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_update_failed'  => __( 'Failed to update subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_delete_failed'  => __( 'Failed to delete subscriber.', 'heads-up-mailer' ),
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
	printf( '<table class="wp-list-table widefat striped">' );
	printf(
		'<thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead>',
		esc_html__( 'Email', 'heads-up-mailer' ),
		esc_html__( 'Name', 'heads-up-mailer' ),
		esc_html__( 'Status', 'heads-up-mailer' ),
		esc_html__( 'Groups', 'heads-up-mailer' ),
		esc_html__( 'Consent at', 'heads-up-mailer' ),
		esc_html__( 'Actions', 'heads-up-mailer' )
	);
	printf( '<tbody>' );

	$status_labels = array(
		STATUS_SUBSCRIBED   => __( 'Subscribed', 'heads-up-mailer' ),
		STATUS_UNSUBSCRIBED => __( 'Unsubscribed', 'heads-up-mailer' ),
		STATUS_BOUNCED      => __( 'Bounced', 'heads-up-mailer' ),
		STATUS_COMPLAINED   => __( 'Complained', 'heads-up-mailer' ),
	);

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
		$confirm = sprintf( __( 'Delete the subscriber "%s"?', 'heads-up-mailer' ), $sub->email );

		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td><span class="hum-status hum-status-%s">%s</span></td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a href="%s" data-hum-confirm="%s">%s</a></td></tr>',
			esc_url( $edit_url ),
			esc_html( $sub->email ),
			esc_html( $sub->name ),
			esc_attr( (string) $sub->status ),
			esc_html( $status_label ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $chips built from esc_html'd values above.
			$chips,
			esc_html( $sub->consent_at ),
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
