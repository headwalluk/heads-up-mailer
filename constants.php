<?php
/**
 * Plugin Constants
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Database schema version. Bump and add a migration when schema changes.
 *
 * @since 0.1.0
 */
const DB_VERSION = 1;

/**
 * Custom table name suffixes (after `$wpdb->prefix`).
 *
 * @since 0.1.0
 */
const TABLE_SUBSCRIBERS       = 'hum_subscribers';
const TABLE_GROUPS            = 'hum_groups';
const TABLE_SUBSCRIBER_GROUPS = 'hum_subscriber_groups';
const TABLE_DRAFTS            = 'hum_drafts';
const TABLE_SENDS             = 'hum_sends';
const TABLE_SEND_RECIPIENTS   = 'hum_send_recipients';

/**
 * Option keys (wp_options).
 *
 * @since 0.1.0
 */
const OPTION_VERSION      = 'hum_version';
const OPTION_DB_VERSION   = 'hum_db_version';
const OPTION_BATCH_SIZE   = 'hum_batch_size';
const OPTION_TICK_MINUTES = 'hum_tick_minutes';

/**
 * Sending (sender identity, footer, public unsubscribe slug).
 *
 * @since 0.4.0
 */
const OPTION_FROM_NAME   = 'hum_from_name';
const OPTION_FROM_EMAIL  = 'hum_from_email';
const OPTION_FOOTER_HTML = 'hum_footer_html';
const OPTION_MANAGE_SLUG = 'hum_manage_slug';

/**
 * Mailbox (IMAP) settings.
 *
 * @since 0.1.0
 */
const OPTION_MAILBOX_HOST          = 'hum_mailbox_host';
const OPTION_MAILBOX_PORT          = 'hum_mailbox_port';
const OPTION_MAILBOX_USER          = 'hum_mailbox_user';
const OPTION_MAILBOX_PASSWORD      = 'hum_mailbox_password';
const OPTION_MAILBOX_FOLDER        = 'hum_mailbox_folder';
const OPTION_MAILBOX_TLS           = 'hum_mailbox_tls';
const OPTION_MAILBOX_VALIDATE_CERT = 'hum_mailbox_validate_cert';
const OPTION_MAILBOX_INTERVAL      = 'hum_mailbox_interval';

/**
 * Subscriber statuses.
 *
 * @since 0.1.0
 */
const STATUS_SUBSCRIBED   = 'subscribed';
const STATUS_UNSUBSCRIBED = 'unsubscribed';
const STATUS_BOUNCED      = 'bounced';
const STATUS_COMPLAINED   = 'complained';

/**
 * Draft statuses.
 *
 * @since 0.1.0
 */
const DRAFT_STATUS_DRAFT     = 'draft';
const DRAFT_STATUS_SENDING   = 'sending';
const DRAFT_STATUS_SENT      = 'sent';
const DRAFT_STATUS_CANCELLED = 'cancelled';

/**
 * Send-recipient statuses.
 *
 * @since 0.1.0
 */
const SEND_STATUS_PENDING    = 'pending';
const SEND_STATUS_PROCESSING = 'processing';
const SEND_STATUS_SENT       = 'sent';
const SEND_STATUS_FAILED     = 'failed';

/**
 * Transient keys / prefixes.
 *
 * @since 0.1.0
 */
const TRANSIENT_DRAIN_LOCK = 'hum_drain_lock';
const TRANSIENT_POLL_LOCK  = 'hum_poll_lock';
const TRANSIENT_RATE_LIMIT = 'hum_rate_limit_';

/**
 * Cron hook names and custom interval slug.
 *
 * @since 0.1.0
 */
const CRON_DRAIN_QUEUE   = 'hum_drain_queue';
const CRON_POLL_MAILBOX  = 'hum_poll_mailbox';
const CRON_INTERVAL_TICK = 'hum_tick';

/**
 * Query var the rewrite rule sets to flag a `/manage-comms/` hit.
 *
 * @since 0.5.0
 */
const QUERY_VAR_MANAGE = 'hum_manage';

/**
 * Public-endpoint rate limit.
 *
 * @since 0.5.0
 */
const RATE_LIMIT_MANAGE_PER_HOUR = 20;

/**
 * Defaults.
 *
 * @since 0.1.0
 */
const DEF_BATCH_SIZE            = 10;
const DEF_TICK_MINUTES          = 5;
const DEF_MAILBOX_PORT          = 993;
const DEF_MAILBOX_USER          = 'unsub@headwall-hosting.com';
const DEF_MAILBOX_FOLDER        = 'INBOX';
const DEF_MAILBOX_TLS           = true;
const DEF_MAILBOX_VALIDATE_CERT = true;
const DEF_MAILBOX_INTERVAL      = 5;
const DEF_DRAFT_SUBJECT_MAX     = 200;
const DEF_FROM_NAME             = '';
const DEF_FROM_EMAIL            = '';
const DEF_MANAGE_SLUG           = 'manage-comms';

/**
 * Default footer HTML. The worker replaces `{{unsubscribe_url}}`
 * with the per-recipient URL at send time and injects this block
 * before the body's closing `</body>` tag (or appends, for
 * fragment HTML).
 *
 * @since 0.4.0
 */
const DEF_FOOTER_HTML = '<hr style="border:none;border-top:1px solid #ddd;margin:32px 0 16px;">' .
	'<p style="font-size:11px;color:#666;text-align:center;">' .
	'You\'re receiving this because you subscribed to Headwall Hosting updates.<br>' .
	'<a href="{{unsubscribe_url}}">Unsubscribe</a>' .
	'</p>';

/**
 * REST API namespace.
 *
 * @since 0.3.0
 */
const REST_NAMESPACE = 'heads-up-mailer/v1';
