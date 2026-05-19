<?php
/**
 * Admin: Add / edit a subscriber.
 *
 * Variables expected from the caller (`Plugin::render_subscribers`):
 *
 * - `$subscriber`      ?object  Existing subscriber row, or null when adding.
 * - `$all_groups`      array<int, object> All groups for the picker.
 * - `$attached_groups` array<int> Group IDs already attached to this subscriber.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$is_edit        = ( null !== $subscriber );
$subscriber_id  = $is_edit ? (int) $subscriber->id : 0;
$email          = $is_edit ? (string) $subscriber->email : '';
$name           = $is_edit ? (string) $subscriber->name : '';
$current_status = $is_edit ? (string) $subscriber->status : STATUS_SUBSCRIBED;
$source         = $is_edit ? (string) $subscriber->consent_source : '';
$consent_at     = $is_edit ? (string) $subscriber->consent_at : '';

$page_title = $is_edit
	? __( 'Edit subscriber', 'heads-up-mailer' )
	: __( 'Add subscriber', 'heads-up-mailer' );

$back_url = add_query_arg(
	array( 'page' => 'heads-up-mailer-subscribers' ),
	admin_url( 'admin.php' )
);

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( $page_title ) );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_subscriber_exists'         => __( 'A subscriber with that email already exists.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_email'  => __( 'A valid email address is required.', 'heads-up-mailer' ),
		'hum_subscriber_invalid_status' => __( 'Invalid subscriber status.', 'heads-up-mailer' ),
		'hum_subscriber_not_found'      => __( 'Subscriber not found.', 'heads-up-mailer' ),
		'hum_subscriber_insert_failed'  => __( 'Failed to insert subscriber.', 'heads-up-mailer' ),
		'hum_subscriber_update_failed'  => __( 'Failed to update subscriber.', 'heads-up-mailer' ),
	);

	$msg = isset( $error_messages[ $error_code ] )
		? $error_messages[ $error_code ]
		: __( 'An error occurred.', 'heads-up-mailer' );

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
}

printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
printf( '<input type="hidden" name="action" value="hum_save_subscriber" />' );
printf( '<input type="hidden" name="subscriber_id" value="%d" />', (int) $subscriber_id );

ob_start();
wp_nonce_field( 'hum_save_subscriber' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-sub-email">%s</label></th><td><input name="email" id="hum-sub-email" type="email" class="regular-text" value="%s" required /></td></tr>',
	esc_html__( 'Email', 'heads-up-mailer' ),
	esc_attr( $email )
);

printf(
	'<tr><th scope="row"><label for="hum-sub-name">%s</label></th><td><input name="name" id="hum-sub-name" type="text" class="regular-text" value="%s" /></td></tr>',
	esc_html__( 'Name', 'heads-up-mailer' ),
	esc_attr( $name )
);

// Status select.
$status_options = array(
	STATUS_SUBSCRIBED   => __( 'Subscribed', 'heads-up-mailer' ),
	STATUS_UNSUBSCRIBED => __( 'Unsubscribed', 'heads-up-mailer' ),
	STATUS_BOUNCED      => __( 'Bounced', 'heads-up-mailer' ),
	STATUS_COMPLAINED   => __( 'Complained', 'heads-up-mailer' ),
);

$status_html = '';
foreach ( $status_options as $value => $label ) {
	$status_html .= sprintf(
		'<option value="%s"%s>%s</option>',
		esc_attr( $value ),
		selected( $value, $current_status, false ),
		esc_html( $label )
	);
}

printf(
	'<tr><th scope="row"><label for="hum-sub-status">%s</label></th><td><select name="status" id="hum-sub-status">%s</select></td></tr>',
	esc_html__( 'Status', 'heads-up-mailer' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status_html built from esc_attr / esc_html above.
	$status_html
);

// Groups picker (checkbox list).
$groups_html = '';
foreach ( $all_groups as $group ) {
	$checked = in_array( (int) $group->id, $attached_groups, true );

	$groups_html .= sprintf(
		'<label style="display:block;margin:2px 0;"><input type="checkbox" name="groups[]" value="%d"%s /> %s <code>%s</code></label>',
		(int) $group->id,
		$checked ? ' checked' : '',
		esc_html( $group->name ),
		esc_html( $group->slug )
	);
}

if ( '' === $groups_html ) {
	$groups_html = sprintf(
		'<em>%s</em>',
		esc_html__( 'No groups yet. Add one on the Groups page.', 'heads-up-mailer' )
	);
}

printf(
	'<tr><th scope="row">%s</th><td>%s</td></tr>',
	esc_html__( 'Groups', 'heads-up-mailer' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $groups_html built from escaped values above.
	$groups_html
);

printf(
	'<tr><th scope="row"><label for="hum-sub-consent-source">%s</label></th><td><input name="consent_source" id="hum-sub-consent-source" type="text" class="regular-text" value="%s" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Consent source', 'heads-up-mailer' ),
	esc_attr( $source ),
	esc_html__( 'Free-text label for where consent was captured (e.g. "checkout", "imported-from-mailerlite").', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-sub-consent-at">%s</label></th><td><input name="consent_at" id="hum-sub-consent-at" type="text" class="regular-text" value="%s" placeholder="2026-05-19 14:30:00 UTC" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Consent at', 'heads-up-mailer' ),
	esc_attr( $consent_at ),
	esc_html__( 'Format: Y-m-d H:i:s UTC. Leave blank to use the current time.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );

printf( '<p class="submit">' );
printf(
	'<button type="submit" class="button button-primary">%s</button> <a href="%s" class="button">%s</a>',
	esc_html__( 'Save subscriber', 'heads-up-mailer' ),
	esc_url( $back_url ),
	esc_html__( 'Cancel', 'heads-up-mailer' )
);
printf( '</p>' );

printf( '</form>' );
printf( '</div>' );
