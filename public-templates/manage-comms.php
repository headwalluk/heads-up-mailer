<?php
/**
 * Public: preference management page.
 *
 * Standalone HTML document — does not inherit the theme. The
 * design rationale: this page is reached from an email-client
 * unsubscribe link by users who may not have a logged-in session
 * with the site, and theme styling could obscure the
 * preference controls. Minimal, accessible markup.
 *
 * Variables expected from the caller
 * (`Public_Controller::render_preferences`):
 *
 * - `$subscriber`              object              Subscriber row.
 * - `$token`                   string              Validated bearer token (caller already passed `Tokens::verify()`).
 * - `$all_groups`              array<int, object>  Every group.
 * - `$attached_ids`            array<int, int>     Group IDs the subscriber currently belongs to.
 * - `$prefill_unsub_all`       bool                Pre-tick the "unsubscribe from all" checkbox.
 * - `$is_already_unsubscribed` bool                Subscriber is already in `unsubscribed` status.
 *
 * @package Heads_Up_Mailer
 * @since 0.5.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$site_name = (string) get_bloginfo( 'name' );

printf( '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex" />' );
printf( '<title>%s</title>', esc_html__( 'Email preferences', 'heads-up-mailer' ) );

printf(
	'<style>
:root { color-scheme: light; }
body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; background: #f6f7f7; margin: 0; padding: 32px 16px; }
.hum-card { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 32px; }
.hum-card h1 { margin: 0 0 8px; font-size: 22px; }
.hum-card p { margin: 0 0 16px; }
.hum-muted { color: #646970; font-size: 13px; }
.hum-group { display: block; padding: 10px 0; border-top: 1px solid #f0f0f1; }
.hum-group:first-of-type { border-top: 0; }
.hum-group input[type=checkbox] { margin-right: 10px; transform: scale(1.2); vertical-align: middle; }
.hum-group strong { font-weight: 600; }
.hum-group .hum-muted { display: block; margin-top: 2px; margin-left: 28px; }
.hum-unsub-all { margin-top: 18px; padding-top: 14px; border-top: 2px solid #c3c4c7; }
.hum-actions { margin-top: 24px; display: flex; gap: 12px; align-items: center; }
button { font-size: 15px; padding: 8px 18px; border-radius: 6px; border: 1px solid #2271b1; background: #2271b1; color: #fff; cursor: pointer; }
button:hover { background: #135e96; border-color: #135e96; }
.hum-notice { background: #fbeaea; border-left: 4px solid #d63638; padding: 10px 14px; margin: 0 0 16px; }
.hum-success { background: #ecf7ed; border-left: 4px solid #00a32a; padding: 10px 14px; margin: 0 0 16px; }
</style>'
);

printf( '</head><body>' );
printf( '<div class="hum-card">' );
printf(
	'<h1>%s</h1>',
	esc_html__( 'Email preferences', 'heads-up-mailer' )
);
printf(
	'<p class="hum-muted">%s</p>',
	esc_html(
		sprintf(
		/* translators: 1: site name, 2: subscriber email. */
			__( 'Manage your subscription to %1$s newsletters sent to %2$s.', 'heads-up-mailer' ),
			$site_name,
			(string) $subscriber->email
		)
	)
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash flag on a GET render.
$show_saved = isset( $_GET['saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['saved'] ) );

if ( $show_saved ) {
	printf(
		'<p class="hum-success">%s</p>',
		esc_html__( 'Your preferences have been saved.', 'heads-up-mailer' )
	);
}

if ( $is_already_unsubscribed ) {
	printf(
		'<p class="hum-notice">%s</p>',
		esc_html__( "You're currently unsubscribed from all our newsletters. Tick a group below and save to resubscribe.", 'heads-up-mailer' )
	);
}

printf(
	'<form method="post" action="%s">',
	esc_url( home_url( '/' . trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' ) . '/' ) )
);

printf( '<input type="hidden" name="token" value="%s" />', esc_attr( $token ) );

ob_start();
wp_nonce_field( 'hum_manage_prefs_' . (int) $subscriber->id, '_hum_nonce' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

if ( empty( $all_groups ) ) {
	printf(
		'<p class="hum-muted">%s</p>',
		esc_html__( 'No mailing groups are defined yet.', 'heads-up-mailer' )
	);
} else {
	foreach ( $all_groups as $group ) {
		$checked = ( ! $prefill_unsub_all && in_array( (int) $group->id, $attached_ids, true ) ) ? ' checked' : '';

		printf(
			'<label class="hum-group"><input type="checkbox" name="groups[]" value="%d"%s /><strong>%s</strong>',
			(int) $group->id,
			esc_attr( $checked ),
			esc_html( (string) $group->name )
		);

		if ( '' !== (string) $group->description ) {
			printf(
				'<span class="hum-muted">%s</span>',
				esc_html( (string) $group->description )
			);
		}

		printf( '</label>' );
	}
}

$unsub_all_checked = $prefill_unsub_all ? ' checked' : '';
printf(
	'<label class="hum-group hum-unsub-all"><input type="checkbox" name="unsubscribe_all" value="1"%s /><strong>%s</strong><span class="hum-muted">%s</span></label>',
	esc_attr( $unsub_all_checked ),
	esc_html__( 'Unsubscribe from everything', 'heads-up-mailer' ),
	esc_html__( 'Removes you from every group and stops all future newsletters.', 'heads-up-mailer' )
);

printf(
	'<div class="hum-actions"><button type="submit">%s</button></div>',
	esc_html__( 'Save preferences', 'heads-up-mailer' )
);

printf( '</form>' );
printf( '</div>' );
printf( '</body></html>' );
