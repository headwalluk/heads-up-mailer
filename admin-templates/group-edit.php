<?php
/**
 * Admin: Add / edit a group.
 *
 * Variables expected from the caller (`Plugin::render_groups`):
 *
 * - `$group`  ?object  Existing group row, or null when adding.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$is_edit    = ( null !== $group );
$group_id   = $is_edit ? (int) $group->id : 0;
$slug       = $is_edit ? (string) $group->slug : '';
$name       = $is_edit ? (string) $group->name : '';
$desc       = $is_edit ? (string) $group->description : '';
$is_private = $is_edit && ! empty( $group->is_private );

$page_title = $is_edit
	? __( 'Edit group', 'heads-up-mailer' )
	: __( 'Add group', 'heads-up-mailer' );

$back_url = add_query_arg(
	array( 'page' => 'heads-up-mailer-groups' ),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( $page_title ) );

// Echo per-field error in case the user came back from a failed save.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_group_exists'        => __( 'A group with that slug already exists.', 'heads-up-mailer' ),
		'hum_group_invalid_slug'  => __( 'A valid slug is required.', 'heads-up-mailer' ),
		'hum_group_invalid_name'  => __( 'Group name is required.', 'heads-up-mailer' ),
		'hum_group_not_found'     => __( 'Group not found.', 'heads-up-mailer' ),
		'hum_group_insert_failed' => __( 'Failed to insert group.', 'heads-up-mailer' ),
		'hum_group_update_failed' => __( 'Failed to update group.', 'heads-up-mailer' ),
	);

	$msg = isset( $error_messages[ $error_code ] )
		? $error_messages[ $error_code ]
		: __( 'An error occurred.', 'heads-up-mailer' );

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $msg )
	);
}

printf(
	'<form method="post" action="%s">',
	esc_url( admin_url( 'admin-post.php' ) )
);

printf( '<input type="hidden" name="action" value="hum_save_group" />' );
printf( '<input type="hidden" name="group_id" value="%d" />', (int) $group_id );

ob_start();
wp_nonce_field( 'hum_save_group' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

printf( '<table class="form-table" role="presentation"><tbody>' );

// Name — always the first field. Wide input for longer group names.
printf(
	'<tr><th scope="row"><label for="hum-group-name">%s</label></th><td>',
	esc_html__( 'Name', 'heads-up-mailer' )
);
printf(
	'<input name="name" id="hum-group-name" type="text" class="widefat" value="%s" required />',
	esc_attr( $name )
);

if ( ! $is_edit ) {
	// Add: the slug is auto-generated from the name. A checkbox reveals
	// the manual slug field; admin JS keeps the hidden input in sync and
	// adds the `required` attribute only when manual entry is enabled (a
	// `required` field that is `display:none` blocks form submission).
	printf(
		'<p class="description hum-slug-preview">%s <code class="hum-slug-value"></code></p>',
		esc_html__( 'Slug:', 'heads-up-mailer' )
	);
	printf(
		'<p><label><input type="checkbox" class="hum-slug-manual-toggle" /> %s</label></p>',
		esc_html__( 'Set the slug manually', 'heads-up-mailer' )
	);
	printf(
		'<p class="hum-slug-field-wrap" hidden><input name="slug" id="hum-group-slug" type="text" class="regular-text" value="" pattern="[a-z0-9-]+" /><span class="description">%s</span></p>',
		esc_html__( 'Lowercase letters, numbers, and hyphens. Used in REST and the database.', 'heads-up-mailer' )
	);
}

printf( '</td></tr>' );

if ( $is_edit ) {
	// Edit: the slug already exists, so keep it as an ordinary visible
	// field — auto-generating over a saved slug would invalidate links.
	printf(
		'<tr><th scope="row"><label for="hum-group-slug">%s</label></th><td><input name="slug" id="hum-group-slug" type="text" class="regular-text" value="%s" required pattern="[a-z0-9-]+" /><p class="description">%s</p></td></tr>',
		esc_html__( 'Slug', 'heads-up-mailer' ),
		esc_attr( $slug ),
		esc_html__( 'Lowercase letters, numbers, and hyphens. Used in REST and the database.', 'heads-up-mailer' )
	);
}

printf(
	'<tr><th scope="row"><label for="hum-group-description">%s</label></th><td><textarea name="description" id="hum-group-description" class="large-text" rows="4">%s</textarea><p class="description">%s</p></td></tr>',
	esc_html__( 'Description', 'heads-up-mailer' ),
	esc_textarea( $desc ),
	esc_html__( 'Plain text only — any HTML tags are stripped when the group is saved. A short sentence or two describing who belongs in this group is plenty.', 'heads-up-mailer' )
);

// Hidden value="0" sibling before the checkbox so an unchecked
// box still posts a value the handler can read.
printf(
	'<tr><th scope="row"><label for="hum-group-private">%s</label></th><td><input type="hidden" name="is_private" value="0" /><label><input name="is_private" id="hum-group-private" type="checkbox" value="1"%s /> %s</label><p class="description">%s</p></td></tr>',
	esc_html__( 'Private', 'heads-up-mailer' ),
	checked( $is_private, true, false ),
	esc_html__( 'Hide this group from the public preferences page for non-members.', 'heads-up-mailer' ),
	esc_html__( 'Private groups never appear as a sign-up option on /manage-comms/. Existing members still see it (so they can unsubscribe), but anyone else does not. Useful for internal / test groups and one-off targeted lists.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );

printf( '<p class="submit">' );
printf(
	'<button type="submit" class="button button-primary">%s</button> <a href="%s" class="button">%s</a>',
	esc_html__( 'Save group', 'heads-up-mailer' ),
	esc_url( $back_url ),
	esc_html__( 'Cancel', 'heads-up-mailer' )
);
printf( '</p>' );

printf( '</form>' );
printf( '</div>' );
