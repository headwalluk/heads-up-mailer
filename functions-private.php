<?php
/**
 * Plugin Functions
 *
 * Internal helpers used across the plugin. Not for public consumption.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Get the plugin instance.
 *
 * Canonical accessor — prefer this over reading `$hum_plugin` directly.
 *
 * @since 0.1.0
 * @return Plugin The bootstrap-installed plugin instance.
 */
function get_plugin(): Plugin {
	global $hum_plugin;
	return $hum_plugin;
}

/**
 * Get default plugin settings.
 *
 * Lazy-initialised and cached in a global for the duration of the
 * request.
 *
 * @since 0.1.0
 * @return array<string, mixed> Default settings keyed by option name.
 */
function get_default_settings(): array {
	global $hum_default_settings;

	if ( null === $hum_default_settings ) {
		$hum_default_settings = array(
			OPTION_BATCH_SIZE              => DEF_BATCH_SIZE,
			OPTION_TICK_MINUTES            => DEF_TICK_MINUTES,
			OPTION_FROM_NAME               => DEF_FROM_NAME,
			OPTION_FROM_EMAIL              => DEF_FROM_EMAIL,
			OPTION_FOOTER_HTML             => DEF_FOOTER_HTML,
			OPTION_MANAGE_SLUG             => DEF_MANAGE_SLUG,
			OPTION_AUTONOMOUS_SEND_ENABLED => DEF_AUTONOMOUS_SEND_ENABLED,
			OPTION_MAILBOX_POLL_ENABLED    => DEF_MAILBOX_POLL_ENABLED,
			OPTION_MAILBOX_HOST            => '',
			OPTION_MAILBOX_PORT            => DEF_MAILBOX_PORT,
			OPTION_MAILBOX_USER            => DEF_MAILBOX_USER,
			OPTION_MAILBOX_PASSWORD        => '',
			OPTION_MAILBOX_FOLDER          => DEF_MAILBOX_FOLDER,
			OPTION_MAILBOX_TLS             => DEF_MAILBOX_TLS,
			OPTION_MAILBOX_VALIDATE_CERT   => DEF_MAILBOX_VALIDATE_CERT,
			OPTION_MAILBOX_INTERVAL        => DEF_MAILBOX_INTERVAL,
			OPTION_WC_CUSTOMERS_GROUP_SLUG => '',
			OPTION_WC_CHECKOUT_INTRO       => '',
			OPTION_WC_CHECKOUT_GROUPS_JSON => '{}',
		);
	}

	return $hum_default_settings;
}

/**
 * Current UTC timestamp in the project's stored datetime format.
 *
 * Format: `Y-m-d H:i:s UTC` — always UTC, sortable lexically,
 * human-readable in manual SQL queries.
 *
 * @since 0.1.0
 * @return string Current timestamp.
 */
function now_utc(): string {
	return gmdate( 'Y-m-d H:i:s' ) . ' UTC';
}

/**
 * Audit a REST trigger-send attempt to the PHP error log.
 *
 * Autonomous sends to the whole list are irreversible and
 * outward-facing, so every trigger — queued or refused — leaves a
 * server-side trace naming the acting user, the draft, and the
 * outcome. Successful sends are *also* flagged durably in-DB via
 * `hum_sends.is_automated`; this log additionally captures refusals,
 * which write no send row. Lightweight by design: one line to
 * `error_log`, no new schema.
 *
 * @since 1.5.0
 * @param string $outcome  `'queued'` or `'refused'`.
 * @param int    $user_id  Acting WordPress user ID (the agent account).
 * @param int    $draft_id Draft the trigger targeted.
 * @param string $detail   Send ID on success, or the refusal reason code.
 */
function audit_autonomous_send( string $outcome, int $user_id, int $draft_id, string $detail ): void {
	$message = sprintf(
		'[heads-up-mailer] autonomous send %s — user=%d draft=%d %s',
		$outcome,
		$user_id,
		$draft_id,
		$detail
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate audit trail for an irreversible, outward-facing action.
	error_log( $message );
}
