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
const DB_VERSION = 4;

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
const TABLE_EVENTS            = 'hum_events';

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
 * Autonomous send master switch.
 *
 * Global on/off for the REST trigger-send route, independent of the
 * `hum_send_newsletters` capability so REST sending can be revoked
 * instantly without touching roles. Dangerous default is OFF: with
 * this disabled the send route always refuses, regardless of the
 * per-group `allow_automated_send` flags.
 *
 * @since 1.5.0
 */
const OPTION_AUTONOMOUS_SEND_ENABLED = 'hum_autonomous_send_enabled';

/**
 * Mailbox (IMAP) settings.
 *
 * @since 0.1.0
 */
const OPTION_MAILBOX_POLL_ENABLED  = 'hum_mailbox_poll_enabled';
const OPTION_MAILBOX_HOST          = 'hum_mailbox_host';
const OPTION_MAILBOX_PORT          = 'hum_mailbox_port';
const OPTION_MAILBOX_USER          = 'hum_mailbox_user';
const OPTION_MAILBOX_PASSWORD      = 'hum_mailbox_password';
const OPTION_MAILBOX_FOLDER        = 'hum_mailbox_folder';
const OPTION_MAILBOX_TLS           = 'hum_mailbox_tls';
const OPTION_MAILBOX_VALIDATE_CERT = 'hum_mailbox_validate_cert';
const OPTION_MAILBOX_INTERVAL      = 'hum_mailbox_interval';

/**
 * Mailbox poller health state — written by `Mailbox_Poller` on
 * each tick, read by the admin notice and the dashboard.
 *
 * @since 0.6.0
 */
const OPTION_MAILBOX_LAST_OK       = 'hum_mailbox_last_ok';
const OPTION_MAILBOX_LAST_ERROR    = 'hum_mailbox_last_error';
const OPTION_MAILBOX_LAST_ERROR_AT = 'hum_mailbox_last_error_at';

/**
 * WooCommerce integration settings.
 *
 *   - `OPTION_WC_CUSTOMERS_GROUP_SLUG` — group new checkout
 *     customers land in. Empty = the integration's
 *     auto-tracking is disabled.
 *   - `OPTION_WC_CHECKOUT_INTRO` — intro paragraph rendered
 *     above the per-group opt-in checkboxes on the checkout
 *     page.
 *   - `OPTION_WC_CHECKOUT_GROUPS_JSON` — JSON map keyed by group
 *     slug; each value is `{ "at_checkout": bool, "label": string }`.
 *
 * @since 0.9.0
 */
const OPTION_WC_CUSTOMERS_GROUP_SLUG = 'hum_wc_customers_group_slug';
const OPTION_WC_CHECKOUT_INTRO       = 'hum_wc_checkout_intro';
const OPTION_WC_CHECKOUT_GROUPS_JSON = 'hum_wc_checkout_groups_json';

/**
 * Subscriber statuses.
 *
 * @since 0.1.0
 */
const STATUS_SUBSCRIBED    = 'subscribed';
const STATUS_UNSUBSCRIBED  = 'unsubscribed';
const STATUS_BOUNCED       = 'bounced';
const STATUS_COMPLAINED    = 'complained';
const STATUS_NEVER_CONTACT = 'never_contact';

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
 * Activity event types (`hum_events.event_type`).
 *
 * Per-group membership transitions, recorded whenever
 * `Subscribers_Controller::set_groups()` adds or removes a junction
 * row. They power the dashboard's per-group sign-up / departure
 * trends. Account-wide unsubscribes (one-click, IMAP) flip the
 * subscriber status without touching memberships, so they are NOT
 * events — the dashboard reads those from `subscribers.status`.
 *
 * @since 1.3.0
 */
const EVENT_GROUP_JOIN  = 'group_join';
const EVENT_GROUP_LEAVE = 'group_leave';

/**
 * Dashboard overview tuning.
 *
 * `WINDOW_DAYS` is the rolling look-back for every "recent" metric.
 * `ALERT_FAIL_PCT` is the send-failure rate (percent of *completed*
 * recipients — sent + failed, ignoring still-pending ones) at or
 * above which the dashboard raises a red alert. `RECENT_LIMIT` caps
 * the rows in the recent-failures list.
 *
 * @since 1.3.0
 */
const DASHBOARD_WINDOW_DAYS    = 30;
const DASHBOARD_ALERT_FAIL_PCT = 5;
const DASHBOARD_RECENT_LIMIT   = 5;

/**
 * Transient keys / prefixes.
 *
 * @since 0.1.0
 */
const TRANSIENT_DRAIN_LOCK = 'hum_drain_lock';
const TRANSIENT_POLL_LOCK  = 'hum_poll_lock';
const TRANSIENT_RATE_LIMIT = 'hum_rate_limit_';

/**
 * GitHub auto-updater.
 *
 * Configuration the updater needs at runtime — repo slug to
 * check, transient key for the cached release payload, and TTL
 * before we re-poll. Pattern lifted from quick-2fa.
 *
 * @since 0.10.0
 */
const UPDATER_GITHUB_REPO = 'headwalluk/heads-up-mailer';
const UPDATER_CACHE_KEY   = 'hum_updater_release';
const UPDATER_CACHE_TTL   = 12 * HOUR_IN_SECONDS;

/**
 * Cron hook names and custom interval slug.
 *
 * @since 0.1.0
 */
const CRON_DRAIN_QUEUE           = 'hum_drain_queue';
const CRON_POLL_MAILBOX          = 'hum_poll_mailbox';
const CRON_INTERVAL_TICK         = 'hum_tick';
const CRON_INTERVAL_MAILBOX_TICK = 'hum_mailbox_tick';

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
 * Mailbox poller — folders that processed and failed messages
 * are moved into, plus the threshold beyond which a healthy
 * poller is considered stale enough to warn the admin.
 *
 * @since 0.6.0
 */
const MAILBOX_FOLDER_PROCESSED        = 'Processed';
const MAILBOX_FOLDER_ERRORS           = 'Errors';
const MAILBOX_STALE_THRESHOLD_SECONDS = 2 * HOUR_IN_SECONDS;

/**
 * Defaults.
 *
 * @since 0.1.0
 */
const DEF_BATCH_SIZE              = 10;
const DEF_TICK_MINUTES            = 5;
const DEF_MAILBOX_POLL_ENABLED    = true;
const DEF_MAILBOX_PORT            = 993;
const DEF_MAILBOX_USER            = 'unsub@headwall-hosting.com';
const DEF_MAILBOX_FOLDER          = 'INBOX';
const DEF_MAILBOX_TLS             = true;
const DEF_MAILBOX_VALIDATE_CERT   = true;
const DEF_MAILBOX_INTERVAL        = 5;
const DEF_DRAFT_SUBJECT_MAX       = 200;
const DEF_FROM_NAME               = '';
const DEF_FROM_EMAIL              = '';
const DEF_MANAGE_SLUG             = 'manage-comms';
const DEF_AUTONOMOUS_SEND_ENABLED = false;

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

/**
 * Custom capabilities granted to WordPress roles on activation /
 * first-run, and checked by the REST permission callback. Granted
 * to Administrator + Editor so the AI agent can operate without
 * Administrator role inflation.
 *
 * @since 1.1.0
 */
const CAP_CREATE_DRAFTS = 'hum_create_drafts';

/**
 * Capability gating the REST trigger-send route
 * (`POST /drafts/{id}/send`). Separate from `hum_create_drafts` so
 * "can draft" and "can blast the list" are independently grantable
 * and revocable. Granted to Administrator + Editor on activation /
 * first-run alongside `hum_create_drafts`.
 *
 * @since 1.5.0
 */
const CAP_SEND_NEWSLETTERS = 'hum_send_newsletters';
