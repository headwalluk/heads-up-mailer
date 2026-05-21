<?php
/**
 * Admin: Sending settings tab.
 *
 * `require`d from `settings-page.php`. Fields: From name + email
 * (the identity every send uses), the footer template injected
 * before `</body>`, and the public unsubscribe slug.
 *
 * @package Heads_Up_Mailer
 * @since 0.4.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$from_name   = (string) get_option( OPTION_FROM_NAME, DEF_FROM_NAME );
$from_email  = (string) get_option( OPTION_FROM_EMAIL, DEF_FROM_EMAIL );
$footer_html = (string) get_option( OPTION_FOOTER_HTML, DEF_FOOTER_HTML );
$manage_slug = (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG );

$manage_url_example = home_url( '/' . $manage_slug . '/' );

printf( '<h2>%s</h2>', esc_html__( 'Sender identity', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	esc_html__(
		'Every send uses this From: identity. Leave blank and the worker will refuse to queue.',
		'heads-up-mailer'
	)
);

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-from-name">%s</label></th><td><input name="%s" id="hum-from-name" type="text" class="regular-text" value="%s" placeholder="Headwall Hosting" /></td></tr>',
	esc_html__( 'From name', 'heads-up-mailer' ),
	esc_attr( OPTION_FROM_NAME ),
	esc_attr( $from_name )
);

printf(
	'<tr><th scope="row"><label for="hum-from-email">%s</label></th><td><input name="%s" id="hum-from-email" type="email" class="regular-text" value="%s" placeholder="hello@headwall-hosting.com" /><p class="description">%s</p></td></tr>',
	esc_html__( 'From email', 'heads-up-mailer' ),
	esc_attr( OPTION_FROM_EMAIL ),
	esc_attr( $from_email ),
	esc_html__( 'Must be an address your outbound mail provider is authorised to send as (SPF / DKIM aligned).', 'heads-up-mailer' )
);

printf( '</tbody></table>' );

printf( '<h2>%s</h2>', esc_html__( 'Footer', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	wp_kses(
		sprintf(
			/* translators: %s: literal placeholder token shown inline. */
			__( 'HTML appended to every outgoing newsletter immediately before the closing %1$s tag (or at the end of the body for fragment HTML). The %2$s token is replaced with the recipient\'s personalised unsubscribe URL at send time.', 'heads-up-mailer' ),
			'<code>&lt;/body&gt;</code>',
			'<code>{{unsubscribe_url}}</code>'
		),
		array( 'code' => array() )
	)
);

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-footer-html">%s</label></th><td><textarea name="%s" id="hum-footer-html" class="large-text code" rows="10">%s</textarea></td></tr>',
	esc_html__( 'Footer HTML', 'heads-up-mailer' ),
	esc_attr( OPTION_FOOTER_HTML ),
	esc_textarea( $footer_html )
);

printf( '</tbody></table>' );

printf( '<h2>%s</h2>', esc_html__( 'Public preferences page', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	esc_html__(
		'The URL slug where recipients land to manage preferences or one-click unsubscribe. Changing this flushes the WordPress rewrite rules.',
		'heads-up-mailer'
	)
);

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-manage-slug">%s</label></th><td><code>%s</code>/<input name="%s" id="hum-manage-slug" type="text" class="regular-text" value="%s" placeholder="manage-comms" pattern="[a-z0-9-]+" />/<p class="description">%s <code>%s</code></p></td></tr>',
	esc_html__( 'Slug', 'heads-up-mailer' ),
	esc_html( trailingslashit( home_url() ) ),
	esc_attr( OPTION_MANAGE_SLUG ),
	esc_attr( $manage_slug ),
	esc_html__( 'Recipients will visit:', 'heads-up-mailer' ),
	esc_html( $manage_url_example )
);

printf( '</tbody></table>' );
