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

$poll_enabled  = (bool) filter_var( get_option( OPTION_MAILBOX_POLL_ENABLED, DEF_MAILBOX_POLL_ENABLED ), FILTER_VALIDATE_BOOLEAN );
$host          = (string) get_option( OPTION_MAILBOX_HOST, '' );
$port          = (int) get_option( OPTION_MAILBOX_PORT, DEF_MAILBOX_PORT );
$user          = (string) get_option( OPTION_MAILBOX_USER, DEF_MAILBOX_USER );
$pwd_stored    = (string) get_option( OPTION_MAILBOX_PASSWORD, '' );
$folder        = (string) get_option( OPTION_MAILBOX_FOLDER, DEF_MAILBOX_FOLDER );
$tls           = (bool) filter_var( get_option( OPTION_MAILBOX_TLS, DEF_MAILBOX_TLS ), FILTER_VALIDATE_BOOLEAN );
$validate_cert = (bool) filter_var( get_option( OPTION_MAILBOX_VALIDATE_CERT, DEF_MAILBOX_VALIDATE_CERT ), FILTER_VALIDATE_BOOLEAN );
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

// Master switch. Defaults to enabled so upgrades from <0.8.0
// keep their current behaviour. When unchecked, the WP-Cron tick
// becomes a no-op; the "Poll now" button below still runs so
// admins can verify connectivity without re-enabling.
printf(
	'<tr><th scope="row"><label for="hum-mb-poll-enabled">%s</label></th><td><input type="hidden" name="%s" value="0" /><label><input name="%s" id="hum-mb-poll-enabled" type="checkbox" value="1"%s /> %s</label><p class="description">%s</p></td></tr>',
	esc_html__( 'Enable polling', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_POLL_ENABLED ),
	esc_attr( OPTION_MAILBOX_POLL_ENABLED ),
	checked( $poll_enabled, true, false ),
	esc_html__( 'Poll the unsubscribe mailbox on the configured interval.', 'heads-up-mailer' ),
	esc_html__( 'Untick to stop the scheduled poll without removing credentials. The "Poll now" button below still works.', 'heads-up-mailer' )
);

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

// Hidden value="0" sibling before each checkbox so an unchecked box still
// posts a value the Settings API can persist (otherwise the field would
// be absent from $_POST and the sanitize callback never fires).
printf(
	'<tr><th scope="row"><label for="hum-mb-tls">%s</label></th><td><input type="hidden" name="%s" value="0" /><label><input name="%s" id="hum-mb-tls" type="checkbox" value="1"%s /> %s</label></td></tr>',
	esc_html__( 'TLS', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_TLS ),
	esc_attr( OPTION_MAILBOX_TLS ),
	checked( $tls, true, false ),
	esc_html__( 'Connect over SSL / TLS (recommended).', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-validate-cert">%s</label></th><td><input type="hidden" name="%s" value="0" /><label><input name="%s" id="hum-mb-validate-cert" type="checkbox" value="1"%s /> %s</label><p class="description">%s</p></td></tr>',
	esc_html__( 'Validate certificate', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_VALIDATE_CERT ),
	esc_attr( OPTION_MAILBOX_VALIDATE_CERT ),
	checked( $validate_cert, true, false ),
	esc_html__( 'Verify the IMAP server\'s TLS certificate against the system CA bundle.', 'heads-up-mailer' ),
	esc_html__( 'Untick when PHP rejects a known-good certificate (common with Let\'s Encrypt and older c-client CA bundles — the error reads "unable to get local issuer certificate"). The connection still uses TLS encryption; only chain validation is skipped.', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-mb-interval">%s</label></th><td><input name="%s" id="hum-mb-interval" type="number" min="1" max="60" value="%d" class="small-text" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Polling interval (minutes)', 'heads-up-mailer' ),
	esc_attr( OPTION_MAILBOX_INTERVAL ),
	(int) $interval,
	esc_html__( 'How often the plugin polls the mailbox for inbound unsubscribe mails.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );

// Test-connection button — POSTs the current form values to the
// hum_test_mailbox AJAX endpoint. Nothing is persisted by the test.
printf(
	'<p><button type="button" class="button" id="hum-mb-test">%s</button> <span id="hum-mb-test-result" class="description" role="status" aria-live="polite"></span></p>',
	esc_html__( 'Test connection', 'heads-up-mailer' )
);

// Poll-now button — runs the IMAP poller inline against the
// stored credentials. The form values are ignored; this exists
// so admins can verify the mailto-unsubscribe round-trip without
// waiting for the next cron tick.
printf(
	'<p><button type="button" class="button" id="hum-mb-poll">%s</button> <span id="hum-mb-poll-result" class="description" role="status" aria-live="polite"></span><br><span class="description">%s</span></p>',
	esc_html__( 'Poll now', 'heads-up-mailer' ),
	esc_html__( 'Uses the saved credentials. Save changes before polling to apply edits.', 'heads-up-mailer' )
);
