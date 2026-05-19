<?php
/**
 * Admin: Mailbox (IMAP) settings tab.
 *
 * `require`d from `settings-page.php`. The password field never
 * round-trips a decrypted value back to the browser — it renders
 * an empty input with a placeholder when a value is already stored,
 * and the sanitize callback keeps the existing value when input is
 * blank on save.
 *
 * @package Heads_Up_Mailer
 * @since 0.2.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$host          = (string) get_option( OPTION_MAILBOX_HOST, '' );
$port          = (int) get_option( OPTION_MAILBOX_PORT, DEF_MAILBOX_PORT );
$user          = (string) get_option( OPTION_MAILBOX_USER, DEF_MAILBOX_USER );
$pwd_stored    = (string) get_option( OPTION_MAILBOX_PASSWORD, '' );
$folder        = (string) get_option( OPTION_MAILBOX_FOLDER, DEF_MAILBOX_FOLDER );
$tls           = (bool) filter_var( get_option( OPTION_MAILBOX_TLS, DEF_MAILBOX_TLS ), FILTER_VALIDATE_BOOLEAN );
$interval      = (int) get_option( OPTION_MAILBOX_INTERVAL, DEF_MAILBOX_INTERVAL );
$has_stored_pw = ( '' !== $pwd_stored );

$imap_loaded = extension_loaded( 'imap' );

printf( '<h2>%s</h2>', esc_html__( 'Unsubscribe mailbox (IMAP)', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	esc_html__(
		'Credentials for the mailbox that receives mailto: unsubscribe replies. The password is encrypted with libsodium on save and only decrypted by the poller.',
		'heads-up-mailer'
	)
);

if ( ! $imap_loaded ) {
	printf(
		'<div class="notice notice-warning inline"><p>%s</p></div>',
		esc_html__(
			'The PHP imap extension is not loaded on this host. You can still save credentials, but the mailbox poller and the test-connection button will not work until php-imap is installed.',
			'heads-up-mailer'
		)
	);
}

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-mb-host">%s</label></th><td><input name="%s" id="hum-mb-host" type="text" class="regular-text" value="%s" placeholder="imap.example.com" /></td></tr>',
	esc_html__( 'Host', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_HOST ),
	esc_attr( $host )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-port">%s</label></th><td><input name="%s" id="hum-mb-port" type="number" min="1" max="65535" value="%d" class="small-text" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Port', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_PORT ),
	(int) $port,
	esc_html__( 'Default 993 for IMAPS.', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-user">%s</label></th><td><input name="%s" id="hum-mb-user" type="text" class="regular-text" value="%s" autocomplete="username" /></td></tr>',
	esc_html__( 'Username', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_USER ),
	esc_attr( $user )
);

$pwd_placeholder = $has_stored_pw ? '••••••••' : '';
$pwd_description = $has_stored_pw
	? __( 'A password is currently stored. Leave this field blank to keep it. Type a new value to replace it.', 'heads-up-mailer' )
	: __( 'New values are encrypted with libsodium and an AUTH_KEY-derived key on save.', 'heads-up-mailer' );

printf(
	'<tr><th scope="row"><label for="hum-mb-password">%s</label></th><td><input name="%s" id="hum-mb-password" type="password" class="regular-text" value="" placeholder="%s" autocomplete="new-password" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Password', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_PASSWORD ),
	esc_attr( $pwd_placeholder ),
	esc_html( $pwd_description )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-folder">%s</label></th><td><input name="%s" id="hum-mb-folder" type="text" class="regular-text" value="%s" placeholder="INBOX" /></td></tr>',
	esc_html__( 'Folder', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_FOLDER ),
	esc_attr( $folder )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-tls">%s</label></th><td><label><input name="%s" id="hum-mb-tls" type="checkbox" value="1"%s /> %s</label></td></tr>',
	esc_html__( 'TLS', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_TLS ),
	checked( $tls, true, false ),
	esc_html__( 'Connect over SSL / TLS (recommended).', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-interval">%s</label></th><td><input name="%s" id="hum-mb-interval" type="number" min="1" max="60" value="%d" class="small-text" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Polling interval (minutes)', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_INTERVAL ),
	(int) $interval,
	esc_html__( 'How often the plugin polls the mailbox for inbound unsubscribe mails.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );

// Test-connection button — wiring lands in the next chunk.
printf(
	'<p><button type="button" class="button" id="hum-mb-test" disabled>%s</button> <span class="description">%s</span></p>',
	esc_html__( 'Test connection', 'heads-up-mailer' ),
	esc_html__( 'AJAX wiring in the next commit.', 'heads-up-mailer' )
);
