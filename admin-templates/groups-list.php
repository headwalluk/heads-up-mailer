<?php
/**
 * Admin: Groups list.
 *
 * Variables expected from the caller (`Plugin::render_groups`):
 *
 * - `$groups` array<int, object> Rows from `Groups_Controller::get_all()`.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$add_url = add_query_arg(
	array(
		'page'   => 'heads-up-mailer-groups',
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf(
	'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
	esc_html__( 'Groups', 'heads-up-mailer' ),
	esc_url( $add_url ),
	esc_html__( 'Add new', 'heads-up-mailer' )
);

// Flash notices on successful save / delete or on error.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$deleted = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $updated ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Group saved.', 'heads-up-mailer' )
	);
}

if ( '' !== $deleted ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Group deleted.', 'heads-up-mailer' )
	);
}

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_group_exists'        => __( 'A group with that slug already exists.', 'heads-up-mailer' ),
		'hum_group_invalid_slug'  => __( 'A valid slug is required.', 'heads-up-mailer' ),
		'hum_group_invalid_name'  => __( 'Group name is required.', 'heads-up-mailer' ),
		'hum_group_not_found'     => __( 'Group not found.', 'heads-up-mailer' ),
		'hum_group_insert_failed' => __( 'Failed to insert group.', 'heads-up-mailer' ),
		'hum_group_update_failed' => __( 'Failed to update group.', 'heads-up-mailer' ),
		'hum_group_delete_failed' => __( 'Failed to delete group.', 'heads-up-mailer' ),
	);

	$msg = isset( $error_messages[ $error_code ] )
		? $error_messages[ $error_code ]
		: __( 'An error occurred.', 'heads-up-mailer' );

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html( $msg )
	);
}

if ( empty( $groups ) ) {
	printf(
		'<p>%s</p>',
		esc_html__( 'No groups yet. Add one to get started.', 'heads-up-mailer' )
	);
} else {
	printf( '<table class="wp-list-table widefat striped">' );
	printf(
		'<thead><tr><th scope="col">%s</th><th scope="col" class="hum-nowrap">%s</th><th scope="col" class="hum-nowrap">%s</th><th scope="col">%s</th><th scope="col" class="hum-nowrap">%s</th></tr></thead>',
		esc_html__( 'Name', 'heads-up-mailer' ),
		esc_html__( 'Slug', 'heads-up-mailer' ),
		esc_html__( 'Visibility', 'heads-up-mailer' ),
		esc_html__( 'Description', 'heads-up-mailer' ),
		esc_html__( 'Actions', 'heads-up-mailer' )
	);
	printf( '<tbody>' );

	foreach ( $groups as $group ) {
		$edit_url = add_query_arg(
			array(
				'page'     => 'heads-up-mailer-groups',
				'action'   => 'edit',
				'group_id' => (int) $group->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'hum_delete_group',
					'group_id' => (int) $group->id,
				),
				admin_url( 'admin-post.php' )
			),
			'hum_delete_group_' . (int) $group->id
		);

		/* translators: %s: Group name. */
		$confirm = sprintf( __( 'Delete the group "%s"?', 'heads-up-mailer' ), $group->name );

		$visibility = ! empty( $group->is_private )
			? __( 'Private', 'heads-up-mailer' )
			: __( 'Public', 'heads-up-mailer' );

		// Surface the autonomous-send opt-in inline with visibility so
		// the primary safety gate is visible at a glance from the list.
		if ( ! empty( $group->allow_automated_send ) ) {
			$visibility .= ' · ' . _x( 'Auto-send', 'groups list marker: group is enabled for autonomous sending', 'heads-up-mailer' );
		}

		printf(
			'<tr><td><strong>%s</strong></td><td class="hum-nowrap"><code>%s</code></td><td class="hum-nowrap">%s</td><td>%s</td><td class="hum-nowrap"><a href="%s">%s</a> | <a href="%s" class="hum-delete-link" data-hum-confirm="%s">%s</a></td></tr>',
			esc_html( $group->name ),
			esc_html( $group->slug ),
			esc_html( $visibility ),
			esc_html( $group->description ),
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
