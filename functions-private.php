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
			OPTION_BATCH_SIZE            => DEF_BATCH_SIZE,
			OPTION_TICK_MINUTES          => DEF_TICK_MINUTES,
			OPTION_MAILBOX_HOST          => '',
			OPTION_MAILBOX_PORT          => DEF_MAILBOX_PORT,
			OPTION_MAILBOX_USER          => DEF_MAILBOX_USER,
			OPTION_MAILBOX_PASSWORD      => '',
			OPTION_MAILBOX_FOLDER        => DEF_MAILBOX_FOLDER,
			OPTION_MAILBOX_TLS           => DEF_MAILBOX_TLS,
			OPTION_MAILBOX_VALIDATE_CERT => DEF_MAILBOX_VALIDATE_CERT,
			OPTION_MAILBOX_INTERVAL      => DEF_MAILBOX_INTERVAL,
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
