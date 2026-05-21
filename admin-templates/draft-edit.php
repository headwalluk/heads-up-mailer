<?php
/**
 * Admin: Add / edit a draft.
 *
 * Variables expected from the caller (`Plugin::render_drafts`):
 *
 * - `$draft`            ?object              Existing draft row, or null when adding.
 * - `$all_groups`       array<int, object>   All groups, for the multi-select.
 * - `$suggested_slugs`  array<int, string>   Pre-checked slugs (decoded from draft).
 *
 * @package Heads_Up_Mailer
 * @since 0.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$is_edit   = ( null !== $draft );
$draft_id  = $is_edit ? (int) $draft->id : 0;
$subject   = $is_edit ? (string) $draft->subject : '';
$html_body = $is_edit ? (string) $draft->html_body : '';

$page_title = $is_edit
	? __( 'Edit draft', 'heads-up-mailer' )
	: __( 'Add draft', 'heads-up-mailer' );

$back_url = add_query_arg(
	array( 'page' => 'heads-up-mailer-drafts' ),
	admin_url( 'admin.php' )
);

$preview_url = $is_edit
	? add_query_arg(
		array(
			'action'   => 'hum_preview_draft',
			'draft_id' => $draft_id,
			'_wpnonce' => wp_create_nonce( 'hum_preview_draft_' . $draft_id ),
		),
		admin_url( 'admin-post.php' )
	)
	: '';

printf( '<div class="wrap">' );
printf( '<h1>%s</h1>', esc_html( $page_title ) );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read only.
$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

if ( '' !== $error_code ) {
	$error_messages = array(
		'hum_draft_invalid_subject'   => __( 'Subject is required.', 'heads-up-mailer' ),
		'hum_draft_subject_too_long'  => __( 'Subject is too long.', 'heads-up-mailer' ),
		'hum_draft_invalid_html_body' => __( 'HTML body cannot be empty.', 'heads-up-mailer' ),
		'hum_draft_unknown_groups'    => __( 'One or more group slugs are unknown.', 'heads-up-mailer' ),
		'hum_draft_not_found'         => __( 'Draft not found.', 'heads-up-mailer' ),
		'hum_draft_insert_failed'     => __( 'Failed to insert draft.', 'heads-up-mailer' ),
		'hum_draft_update_failed'     => __( 'Failed to update draft.', 'heads-up-mailer' ),
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

printf( '<input type="hidden" name="action" value="hum_save_draft" />' );
printf( '<input type="hidden" name="draft_id" value="%d" />', (int) $draft_id );

ob_start();
wp_nonce_field( 'hum_save_draft' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-draft-subject">%s</label></th><td><input name="subject" id="hum-draft-subject" type="text" class="regular-text" value="%s" maxlength="%d" required /></td></tr>',
	esc_html__( 'Subject', 'heads-up-mailer' ),
	esc_attr( $subject ),
	(int) DEF_DRAFT_SUBJECT_MAX
);

printf(
	'<tr><th scope="row"><label for="hum-draft-body">%s</label></th><td><textarea name="html_body" id="hum-draft-body" class="large-text code" rows="16" required>%s</textarea><p class="description">%s</p></td></tr>',
	esc_html__( 'HTML body', 'heads-up-mailer' ),
	esc_textarea( $html_body ),
	esc_html__( 'Raw HTML. Footer and unsubscribe link are appended automatically at send time.', 'heads-up-mailer' )
);

// Group picker.
printf(
	'<tr><th scope="row">%s</th><td>',
	esc_html__( 'Suggested groups', 'heads-up-mailer' )
);

if ( empty( $all_groups ) ) {
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'No groups defined. Add one on the Groups page first.', 'heads-up-mailer' )
	);
} else {
	foreach ( $all_groups as $group ) {
		$checked = in_array( (string) $group->slug, $suggested_slugs, true ) ? ' checked' : '';

		printf(
			'<label style="display:inline-block;margin:0 12px 4px 0;"><input type="checkbox" name="suggested_groups[]" value="%s"%s /> %s <code style="font-size:11px;">%s</code></label>',
			esc_attr( (string) $group->slug ),
			esc_attr( $checked ),
			esc_html( $group->name ),
			esc_html( $group->slug )
		);
	}
}

printf( '</td></tr>' );

printf( '</tbody></table>' );

printf( '<p class="submit">' );
printf(
	'<button type="submit" class="button button-primary">%s</button> <a href="%s" class="button">%s</a>',
	esc_html__( 'Save draft', 'heads-up-mailer' ),
	esc_url( $back_url ),
	esc_html__( 'Cancel', 'heads-up-mailer' )
);

// Disabled Send button — wired in M5 once the queue handler lands.
printf(
	' <button type="button" class="button" disabled title="%s">%s</button>',
	esc_attr__( 'Queue worker not yet built — coming in milestone 5.', 'heads-up-mailer' ),
	esc_html__( 'Send (coming soon)', 'heads-up-mailer' )
);

printf( '</p>' );
printf( '</form>' );

// Preview iframe — only for saved drafts (needs a draft_id to render).
if ( $is_edit ) {
	printf(
		'<h2>%s</h2>',
		esc_html__( 'Preview', 'heads-up-mailer' )
	);
	// `sandbox` with no allow-list disables scripts, forms, and
	// top-level navigation but still lets the HTML render visually.
	// `allow-same-origin` would re-enable DOM access from the iframe;
	// we deliberately leave it off so a malicious body can't reach
	// into the admin page.
	printf(
		'<iframe src="%s" sandbox="" style="width:100%%;height:600px;border:1px solid #c3c4c7;background:#fff;" title="%s"></iframe>',
		esc_url( $preview_url ),
		esc_attr__( 'Draft preview', 'heads-up-mailer' )
	);
}

printf( '</div>' );
