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
 * Three render branches:
 *
 *   - `$is_never_contact` — read-only lockdown card. No forms,
 *     no group list (avoids leaking memberships to anyone with a
 *     stale token).
 *   - `$is_already_unsubscribed` — same form as default, with a
 *     resubscribe-via-groups hint at the top.
 *   - Default — full preference form.
 *
 * Variables expected from the caller
 * (`Public_Controller::render_preferences`):
 *
 * - `$subscriber`              object              Subscriber row.
 * - `$token`                   string              Validated bearer token (caller already passed `Tokens::verify()`).
 * - `$all_groups`              array<int, object>  Every group.
 * - `$attached_ids`            array<int, int>     Group IDs the subscriber currently belongs to.
 * - `$is_never_contact`        bool                Subscriber is flagged never-contact — render the lockdown view.
 * - `$is_already_unsubscribed` bool                Subscriber is in `unsubscribed` status (NOT never-contact).
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
.hum-card { max-width: 560px; margin: 0 auto 16px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 32px; }
.hum-card h1 { margin: 0 0 8px; font-size: 22px; }
.hum-card h2 { margin: 0 0 8px; font-size: 17px; }
.hum-card p { margin: 0 0 16px; }
.hum-muted { color: #646970; font-size: 13px; }
.hum-group { display: block; padding: 10px 0; border-top: 1px solid #f0f0f1; }
.hum-group:first-of-type { border-top: 0; }
.hum-group input[type=checkbox] { margin-right: 10px; transform: scale(1.2); vertical-align: middle; }
.hum-group strong { font-weight: 600; }
.hum-group .hum-muted { display: block; margin-top: 2px; margin-left: 28px; }
.hum-actions { margin-top: 24px; }
button { font-size: 15px; padding: 8px 18px; border-radius: 6px; cursor: pointer; }
.hum-btn-primary { border: 1px solid #2271b1; background: #2271b1; color: #fff; }
.hum-btn-primary:hover { background: #135e96; border-color: #135e96; }
.hum-btn-danger { border: 1px solid #b32d2e; background: #fff; color: #b32d2e; }
.hum-btn-danger:hover { background: #b32d2e; color: #fff; }
.hum-notice { background: #fbeaea; border-left: 4px solid #d63638; padding: 10px 14px; margin: 0 0 16px; }
.hum-success { background: #ecf7ed; border-left: 4px solid #00a32a; padding: 10px 14px; margin: 0 0 16px; }
.hum-info { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 10px 14px; margin: 0 0 16px; }
.hum-danger-card { border-color: #f5c6cb; }
.hum-danger-card h2 { color: #b32d2e; }
</style>'
);

printf( '</head><body>' );

if ( $is_never_contact ) {
	// Lockdown view. Deliberately spare — no forms, no group list,
	// no resubscribe controls. The subscriber asked to never be
	// contacted; admins are the only path back from here, by
	// design.
	printf( '<div class="hum-card">' );
	printf(
		'<h1>%s</h1>',
		esc_html__( 'You\'re unsubscribed', 'heads-up-mailer' )
	);
	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: site name, 2: subscriber email. */
				__( 'We have you on record as no longer wishing to receive newsletters from %1$s at %2$s.', 'heads-up-mailer' ),
				$site_name,
				(string) $subscriber->email
			)
		)
	);
	printf(
		'<p class="hum-muted">%s</p>',
		esc_html__( 'If you believe this is a mistake, please contact us — we\'ll need to update your record manually.', 'heads-up-mailer' )
	);
	printf( '</div>' );
	printf( '</body></html>' );
	return;
}

// Default + already-unsubscribed branch share the rest of the
// template. The latter just shows a contextual notice on top.

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
		'<p class="hum-info">%s</p>',
		esc_html__( "You're currently unsubscribed from all newsletters. Tick a group below and save to resubscribe.", 'heads-up-mailer' )
	);
}

// Section A: groups + Save preferences.

printf(
	'<form method="post" action="%s">',
	esc_url( home_url( '/' . trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' ) . '/' ) )
);

printf( '<input type="hidden" name="token" value="%s" />', esc_attr( $token ) );

ob_start();
wp_nonce_field( 'hum_manage_prefs_' . (int) $subscriber->id, '_hum_nonce' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

printf(
	'<h2>%s</h2>',
	esc_html__( 'Which newsletters?', 'heads-up-mailer' )
);

if ( empty( $all_groups ) ) {
	printf(
		'<p class="hum-muted">%s</p>',
		esc_html__( 'No mailing groups are defined yet.', 'heads-up-mailer' )
	);
} else {
	foreach ( $all_groups as $group ) {
		$checked = in_array( (int) $group->id, $attached_ids, true ) ? ' checked' : '';

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

printf(
	'<div class="hum-actions"><button type="submit" class="hum-btn-primary">%s</button></div>',
	esc_html__( 'Save preferences', 'heads-up-mailer' )
);

printf( '</form>' );
printf( '</div>' );

// Section B: distinct card, danger-styled "Unsubscribe from
// everything" button. Separate form so its CSRF nonce can't be
// confused with the preferences form's, and so the visual break
// matches the semantic break.

printf( '<div class="hum-card hum-danger-card">' );
printf(
	'<h2>%s</h2>',
	esc_html__( 'Unsubscribe from everything', 'heads-up-mailer' )
);
printf(
	'<p class="hum-muted">%s</p>',
	esc_html__( 'One click removes you from every group and flags your record so future imports can\'t resubscribe you by mistake. Use this if you want our newsletters to stop completely.', 'heads-up-mailer' )
);

printf(
	'<form method="post" action="%s">',
	esc_url( home_url( '/' . trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' ) . '/' ) )
);

printf( '<input type="hidden" name="token" value="%s" />', esc_attr( $token ) );

ob_start();
wp_nonce_field( 'hum_manage_unsub_all_' . (int) $subscriber->id, '_hum_unsubscribe_all_nonce' );
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe.

printf(
	'<div class="hum-actions"><button type="submit" class="hum-btn-danger">%s</button></div>',
	esc_html__( 'Unsubscribe me from everything', 'heads-up-mailer' )
);

printf( '</form>' );
printf( '</div>' );

printf( '</body></html>' );
