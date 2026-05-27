# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.0] — 2026-05-27

### Added

- Pluggable integrations framework:
  - `includes/class-integration.php` — abstract base class. Six
    abstract methods (`slug`, `label`, `parent_label`,
    `is_active`, `register_hooks`, `render_settings_section`)
    define the integration surface.
  - `includes/class-integrations.php` — registry that applies
    the new `hum_integrations` filter on `plugins_loaded` (prio
    20). Active integrations get their hooks bound automatically.
  - `Plugin` gains `get_integrations()` accessor so admin pages
    can read the registry.
  - New "Integrations" Settings tab. Renders each active
    integration's `render_settings_section()` inline, plus an
    "Other available integrations" footer card when at least
    one registered integration is inactive. When no integrations
    are active, the tab shows a list of all registered ones with
    "install X to enable" copy.
- Built-in Contact Form 7 integration
  (`integrations/contact-form-7/class-contact-form-7.php`):
  - Registers a new `[hum_signup group:slug "Label"]` form tag.
    Editors drop it into any CF7 form to add a sign-up checkbox.
    The tag's `group:` attribute names the target Heads Up group.
  - On `wpcf7_before_send_mail`, ticked sign-up checkboxes
    enrol the form's email address into the target group via
    `Subscribers_Controller::ensure_in_group()`. Consent source
    stamped as `cf7-form:<form_id>`.
  - Falls back to the form's `your-name` / `name` field for the
    subscriber name; empty if absent.
  - Unknown group slugs, missing email fields, never-contact
    rows are skipped silently — a form submission should never
    surface a Heads Up error to the visitor.
- Built-in WooCommerce integration
  (`integrations/woocommerce/class-woocommerce.php`):
  - Two checkout-driven sign-up flows:
    1. **Customers group** — admin selects an existing group in
       the integration's settings. Every order's billing
       customer is auto-added on
       `woocommerce_checkout_order_processed`. T&C acceptance is
       the consent record. Empty slug = disabled.
    2. **Per-group opt-in checkboxes** — admin marks individual
       groups as "show at checkout" and supplies the label text.
       Renders below the billing form on the checkout page; on
       submit, ticked boxes enrol the customer in the
       corresponding groups. Configured groups are stored in a
       single `OPTION_WC_CHECKOUT_GROUPS_JSON` map — no schema
       migration needed.
  - Hidden order-meta `_hum_opt_in_slugs` captures the ticked
    set on `woocommerce_checkout_create_order` so the data
    survives WC's checkout-form sanitisation.
  - Admin JS rebuilds the JSON map on every repeater
    checkbox / label change so a Save settings click persists
    the live state.
- `Subscribers_Controller::ensure_in_group( email, name, group_id,
  consent_source )` helper — used by both built-in integrations
  and any third-party. Creates the subscriber if missing, unions
  group memberships if existing, refuses with
  `hum_subscriber_never_contact` for flagged rows.
- New constants: `OPTION_WC_CUSTOMERS_GROUP_SLUG`,
  `OPTION_WC_CHECKOUT_INTRO`, `OPTION_WC_CHECKOUT_GROUPS_JSON`.

### Changed

- `Plugin::check_first_run()` now backfills any new-version
  option defaults on upgrade — walks `get_default_settings()`
  and `add_option()`'s missing keys. Idempotent (`add_option()`
  no-ops on existing rows), so admin-edited values are never
  clobbered.

## [0.8.0] — 2026-05-27

### Added

- `STATUS_NEVER_CONTACT` subscriber status. Sticky GDPR-flavoured
  "do not contact under any circumstances" terminal state. Used by:
  - `Subscribers_Controller::mark_never_contact()` — idempotent
    helper mirroring `unsubscribe()` / `resubscribe()`. Stamps
    `unsubscribed_at`; existing stamp preserved on re-call.
  - The new "Unsubscribe from everything" button on `/manage-comms/`.
  - The CSV importer (refuses to update never-contact rows; the
    row counts toward a new `skipped` figure with a per-row message).
  - The new admin row action ("Mark never-contact") and bulk action
    on the subscribers list page.
- `/manage-comms/` redesigned as two visibly distinct sections:
  - Section A: groups + "Save preferences" button (resubscribe via
    group tick stays as-is).
  - Section B: dedicated card with a danger-styled "Unsubscribe me
    from everything" button (real button, not a checkbox). Posts to
    a separate handler with its own CSRF nonce, calls
    `mark_never_contact()`, redirects to the same URL — which now
    renders a lockdown view because the subscriber is never-contact.
- Lockdown view on `/manage-comms/` when the subscriber is
  never-contact: spare card with "you're already unsubscribed,
  contact us if this is wrong" — no group list (avoids leaking
  group memberships to anyone holding a stale token), no form, no
  buttons.
- Subscriber edit form gains `never_contact` in the status
  dropdown and a `notice-warning` banner when the current row is
  in that state, calling out that future imports will skip it and
  manual restoration requires deliberate intent.
- Subscribers list page: bulk-actions UI with "Mark as never
  contact"; column-header select-all checkbox toggles every row's
  selection via JS; "Mark never-contact" row action (hidden on
  rows already in that state) sits between Edit and Delete.

### Changed

- One-click RFC 8058 unsubscribe (mail-client triggered) keeps
  flipping to `unsubscribed`, NOT `never_contact`. Native client
  buttons are easy to hit by accident; preserving the
  resubscribe-via-groups recovery path is the kinder default. The
  explicit on-page button IS the never-contact trigger.
- `Subscribers_Controller::update()` / `create()` now treat
  `unsubscribed` and `never_contact` as a unified "stopped" bucket
  for the `unsubscribed_at` stamping logic. Entering either state
  stamps the timestamp; leaving for any other status clears it;
  staying within the bucket preserves the original stamp.

## [0.7.1] — 2026-05-26

### Added

- "Enable polling" master switch on the Mailbox settings tab
  (`OPTION_MAILBOX_POLL_ENABLED`, default on). When unchecked,
  the WP-Cron mailbox tick bails immediately without opening
  IMAP, and the stale-poll admin notice is suppressed. The
  "Poll now" button is unaffected — it's an explicit manual
  action and the switch governs the recurring one.

## [0.7.0] — 2026-05-26

### Added

- `includes/class-sent-log-controller.php` — read-only query
  layer over `hum_sends` / `hum_send_recipients`:
  - `get_sends_with_counts()` returns every send joined to its
    parent draft, decorated with live per-status recipient
    counters (`COUNT(r.id)`, `SUM(r.status = …)`). Live counts are
    used rather than the cached `attempted` / `sent` / `failed`
    columns on `hum_sends` so in-flight sends show accurate
    numbers before `Worker::finalize_completed_sends()` runs.
  - `get_send_with_counts( int $send_id )` — same shape, single
    row, used by the drill-down.
  - `get_recipients_for_send( int $send_id, string $status = '' )` —
    recipient rows joined to `hum_subscribers` so the email column
    doesn't need a second lookup; optional `SEND_STATUS_*` filter.
- New "Sent log" admin submenu — list view shows every send with
  per-status counters and links into a per-send drill-down. The
  drill-down has a status filter row (All / Sent / Failed /
  Pending / Processing) with counts on each link.
- Templates: `admin-templates/sent-log-list.php` and
  `admin-templates/sent-log-detail.php` — code-first PHP, matches
  the drafts-list table conventions.

## [0.6.0] — 2026-05-26

### Added

- `includes/class-mailbox-poller.php` — WP-Cron-driven IMAP
  poller for the `unsub@…` mailbox. Each tick acquires the
  shared `TRANSIENT_POLL_LOCK`, decrypts the stored password via
  `Crypto`, opens IMAP with a single retry (same flag the
  test-connection handler uses), ensures `Processed` /
  `Errors` sibling folders exist, walks UNSEEN messages within a
  25s wall-clock budget, and translates subjects matching
  `^unsubscribe-([A-Za-z0-9._-]+)$` into status flips via the M6
  idempotent `unsubscribe()` helper. Anything unparseable or
  token-invalid is moved to `Errors`. Cron schedule is a custom
  `hum_mailbox_tick` interval driven by `OPTION_MAILBOX_INTERVAL`,
  auto-reschedules when the setting changes (same pattern as the
  M5 worker).
- "Poll now" button on the Mailbox settings tab —
  `wp_ajax_hum_poll_mailbox` runs `Mailbox_Poller::poll_now()`
  against the stored credentials. Honours the transient lock so
  it can't race the cron tick.
- Stale-poll admin notice — when mailbox credentials are
  configured but `OPTION_MAILBOX_LAST_OK` is older than
  `MAILBOX_STALE_THRESHOLD_SECONDS` (2 hours), `Plugin::admin_notices()`
  surfaces the last `imap_errors()` message with a `human_time_diff`
  on how long since it failed.
- New constants: `OPTION_MAILBOX_LAST_OK`, `OPTION_MAILBOX_LAST_ERROR`,
  `OPTION_MAILBOX_LAST_ERROR_AT`, `CRON_INTERVAL_MAILBOX_TICK`,
  `MAILBOX_FOLDER_PROCESSED`, `MAILBOX_FOLDER_ERRORS`,
  `MAILBOX_STALE_THRESHOLD_SECONDS`.

### Changed

- Activation hook now also calls `Mailbox_Poller::ensure_scheduled()`
  so the poll event is registered on first activate.
- Deactivation hook now also clears `CRON_POLL_MAILBOX` so a
  removed plugin doesn't leave a ghost cron entry behind.

## [0.5.0] — 2026-05-26

### Added

- `includes/class-public-controller.php` — public `/manage-comms/`
  endpoint. Rewrite rule driven by `OPTION_MANAGE_SLUG`. Bearer
  token parsed via the shared `Tokens::verify()` helper. Per-token
  rate limit (`RATE_LIMIT_MANAGE_PER_HOUR = 20` requests/hour) via
  transient counter. Dispatch:
  - **GET** → renders `public-templates/manage-comms.php` with per-
    group checkboxes reflecting current memberships and an
    "Unsubscribe all" option.
  - **POST `action=unsubscribe`** → idempotent one-click flow:
    flips status, stamps `unsubscribed_at`, returns 200 + plain
    text. Re-POSTing is a no-op.
  - **POST form** → updates group memberships and re-renders with
    confirmation.
  All POSTs carry a CSRF nonce that's separate from the bearer
  token.
- `Subscribers_Controller::unsubscribe()` / `resubscribe()` —
  idempotent status flips with `unsubscribed_at` housekeeping.
  `resubscribe()` only acts on rows currently in `unsubscribed`;
  `bounced` / `complained` are left alone.
- Activation hook now pre-registers the rewrite rule before
  `flush_rewrite_rules()` so the flushed table includes
  `/manage-comms/` on first activation.
- Deactivation hook now flushes rewrites so the public slug stops
  resolving after the plugin is removed.

### Changed

- `Sends_Controller::compute_recipient_ids()` — phpcs ignore
  comment switched from single-line `// phpcs:ignore` to a
  `disable` / `enable` block. No behavioural change.
- `Worker::run()` — moved the `WordPress.WP.CronInterval.ChangeDetected`
  ignore comment to the `add_filter()` call site so phpcs actually
  honours it; the prior placement above `register_interval()` was
  on the wrong statement.

## [0.4.0] — 2026-05-21

### Added

- `includes/class-tokens.php` — bearer-token primitive shared by
  the M5 worker (emit) and M6 `/manage-comms/` handler (verify).
  Format `{subscriber_id}.{hmac_hex}` where the HMAC key is the
  subscriber's stored `token_salt`. `verify()` is constant-time
  (`hash_equals`); every failure mode (bad format, unknown
  subscriber, missing salt, MAC mismatch) collapses to `null` so
  callers can't distinguish them.
- `Subscribers_Controller::regenerate_token_salt()` — rotates a
  subscriber's salt and invalidates all outstanding tokens.
- `includes/class-sends-controller.php` — `queue()` orchestrates
  the send: pre-flight (draft exists; not already in flight;
  `from_email` configured; at least one valid group; at least one
  subscribed recipient), then a single transaction that writes
  the `hum_sends` row, N `hum_send_recipients` rows (deduped
  union of the selected groups, `status = subscribed` only), and
  flips the draft to a new `DRAFT_STATUS_SENDING`. Re-sending
  from a `sent` draft writes a fresh `send_id`.
- `compute_recipient_ids()` SQL helper — one query against
  `hum_subscriber_groups` ⨝ `hum_subscribers` with `DISTINCT`
  + `status = subscribed`.
- `admin_post_hum_send_draft` handler and a real Send button on
  the draft-edit page. The form is its own `<form>` (separate
  from Save) with a confirm dialog that names the recipient count
  ("Send to N recipients?" / "Send AGAIN to N recipients?" for
  resends). When sending is blocked, the button is disabled and
  the reason is shown inline.
- "Sending" settings tab with four new options: `hum_from_name`,
  `hum_from_email`, `hum_footer_html` (with `{{unsubscribe_url}}`
  placeholder), and `hum_manage_slug`. Each has its own sanitize
  callback. Slug changes hook `update_option_hum_manage_slug` to
  flush the WordPress rewrite-rules cache so M6's eventual
  `/manage-comms/` rewrite picks up the new value on save.
- `includes/class-worker.php` — WP-Cron-driven worker. Registers
  a custom `hum_tick` interval (clamped 1–60 min from settings),
  scheduled on activation + `admin_init`, cleared on
  deactivation. Each tick: acquires `TRANSIENT_DRAIN_LOCK`,
  claims pending rows atomically (`UPDATE … WHERE
  status = pending`), builds the message, calls `wp_mail()`,
  writes the outcome back, then runs `finalize_completed_sends()`
  to stamp counters + `finished_at` and flip the draft to `sent`.
  Wall-clock budget of 25s per tick.
- Outgoing headers per RFC 8058: `List-Unsubscribe` (mailto +
  https), `List-Unsubscribe-Post: List-Unsubscribe=One-Click`,
  `List-ID: <heads-up-mailer.<host>>`, `Precedence: bulk`,
  `Content-Type: text/html; charset=UTF-8`. Sender identity
  applied via `wp_mail_from` / `wp_mail_from_name` filters
  attached and detached per call, so the override is scoped to
  the newsletter send.
- Footer injection: the `{{unsubscribe_url}}` token in the
  configured template is replaced with the per-recipient
  `esc_url`-quoted URL; the rendered footer is inserted before
  the last `</body>` (appended for fragment HTML).
- Plain-text alternative attached via `phpmailer_init`:
  `wp_strip_all_tags()` + leading-space trim + collapsed runs of
  blank lines.
- New constants: `DRAFT_STATUS_SENDING`, `SEND_STATUS_PROCESSING`,
  `OPTION_FROM_NAME`, `OPTION_FROM_EMAIL`, `OPTION_FOOTER_HTML`,
  `OPTION_MANAGE_SLUG`, `DEF_FROM_NAME`, `DEF_FROM_EMAIL`,
  `DEF_MANAGE_SLUG`, and a `DEF_FOOTER_HTML` template carrying
  the unsubscribe placeholder.
- `register_deactivation_hook` clears the recurring drain event
  so a removed plugin doesn't leave a ghost cron entry.

### Changed

- `Drafts_Controller::update()` refuses edits on `sending`
  drafts (`hum_draft_locked_while_sending`). The draft-edit
  template disables form inputs and the Save button in the UI
  while the worker is processing.

## [0.3.0] — 2026-05-21

### Added

- `includes/class-drafts-controller.php` — CRUD on `hum_drafts`
  with validation: subject required and ≤ `DEF_DRAFT_SUBJECT_MAX`
  (200) characters, `html_body` non-empty after
  `wp_strip_all_tags()`, `suggested_groups` resolved against
  `Groups_Controller::get_by_slug()` (unknown slugs surfaced as
  `hum_draft_unknown_groups`). HTML body stored raw — the
  endpoint is gated on `manage_options` and the preview iframe
  is sandboxed (see below). Stores `suggested_groups_json` as a
  JSON-encoded string list, `created_by` from
  `get_current_user_id()`, and `created_at` from `now_utc()`.
- `includes/class-rest-controller.php` — registers the
  `heads-up-mailer/v1` REST namespace on `rest_api_init`. Routes:
  `POST /drafts` (returns 201) and `GET /drafts/{id}` (404 when
  missing). `permission_callback` checks `manage_options`, which
  works with WordPress application passwords. Controller errors
  decorated with `status` so REST returns 400 instead of 500.
- `admin-templates/drafts-list.php` — list table with id, subject,
  status chip, created_at, and edit / delete actions.
- `admin-templates/draft-edit.php` — add/edit form with subject
  input (maxlength enforced client-side), HTML body textarea
  (`large-text code` class), suggested-groups multi-checkbox
  picker, and an inline iframe preview (only shown for saved
  drafts). Disabled "Send (coming soon)" button placeholder for
  the M5 queue handler.
- `admin-templates/draft-preview.php` — echoes the raw stored
  HTML so an MJML-style full document (`<html>`, `<head>`,
  `<style>`) renders intact. Served via `admin-post.php` so no
  admin chrome is emitted; sets `X-Frame-Options: SAMEORIGIN` and
  `X-Content-Type-Options: nosniff`. The parent iframe carries
  `sandbox=""` (no allow-list), so scripts, forms, top-level
  navigation, and same-origin DOM access are disabled regardless
  of body content.
- `Plugin::run()` instantiates `REST_Controller` and registers
  `admin_post_hum_save_draft`, `admin_post_hum_delete_draft`, and
  `admin_post_hum_preview_draft` handlers.
- `Plugin::admin_menu()` adds a "Drafts" submenu.
- `Plugin::render_drafts()`, `handle_save_draft()`,
  `handle_delete_draft()`, and `handle_preview_draft()`.
- `DEF_DRAFT_SUBJECT_MAX` (200) and `REST_NAMESPACE`
  (`heads-up-mailer/v1`) constants.
- `docs/ai-agent-rest-guide.md` — end-user-facing guide for the
  AI agent operator: auth, endpoints, request / response shapes,
  group slugs, error codes, and `curl` examples.

### Fixed

- `Drafts_Controller::validate()` no longer pipes `html_body`
  through `wp_kses_post`. The sanitiser is built for fragment
  blog-post HTML and was stripping `<html>` / `<head>` / `<style>`
  / `<body>` from MJML-style full-document email HTML, leaving
  CSS reset rules dumped as visible text in the preview. The
  preview iframe's `sandbox=""` attribute carries the XSS
  containment now.

### Added (M3 — settings + IMAP credentials)

- `includes/class-crypto.php` — libsodium `crypto_secretbox`
  wrapper with a 32-byte key derived from `AUTH_KEY` via
  HKDF-SHA256 with a versioned plugin-specific `info` binding.
  Storage envelope is `base64( nonce || ciphertext )` so the
  nonce travels with the value. `decrypt()` returns `""` on any
  failure (bad base64, truncated payload, MAC mismatch).
- `includes/class-settings.php` — Settings API integration. All
  plugin settings share the `hum_settings` group so one
  `options.php` form can save the entire page in a single
  submit. Per-field sanitize callbacks clamp numeric ranges,
  normalise booleans, and route the mailbox password through
  `Crypto::encrypt()`. Blank password input keeps the existing
  stored value (so the form never round-trips a plaintext
  credential).
- `admin-templates/settings-page.php`,
  `admin-templates/tab-queue.php`, and
  `admin-templates/tab-mailbox.php` — settings page with
  hash-based tabs (Queue + Mailbox), all code-first PHP.
- `assets/admin/heads-up-mailer-admin.js`: extended with a
  delegated tab handler that toggles `nav-tab-active` /
  `tab-panel` visibility, persists state in the URL hash, and
  handles browser back/forward via `hashchange`.
- `Plugin::run()` now instantiates `Settings` early so the
  registration hook lands before `admin_init`.
- `Plugin::render_settings()` and a "Settings" submenu under the
  Heads Up Mailer top-level menu.
- `wp_ajax_hum_test_mailbox` AJAX endpoint backing the
  "Test connection" button. Opens the IMAP connection with a
  single retry to bound the AJAX timeout, surfaces the last
  `imap_errors()` entry on failure, and never persists the
  submitted credentials. A blank password falls back to the
  stored encrypted value, decrypted in place.
- `wp_localize_script` on plugin admin pages now publishes a
  `humAdminData` global containing the AJAX URL and the
  `hum_test_mailbox` nonce.
- `assets/admin/heads-up-mailer-admin.js`: delegated click
  handler for `#hum-mb-test` that gathers the current form
  values, POSTs them via `fetch`, and renders the result inline
  in `#hum-mb-test-result`. Aria-live region on the status span
  so screen readers announce the result.
- `OPTION_MAILBOX_VALIDATE_CERT` (default on) — when off, the
  IMAP mailbox string gets `/novalidate-cert` so PHP's c-client
  skips chain validation. Surfaces a clear description in the
  mailbox tab explaining when to untick (common Let's Encrypt /
  c-client CA-bundle mismatch). TLS encryption stays on; only
  the certificate chain check is skipped.
- Hidden `value="0"` siblings before the TLS and validate-cert
  checkboxes so unticking still POSTs a value. Otherwise the
  Settings API's sanitize callback never fires for an absent
  field and the unticked state never persists.

## [0.2.0] — 2026-05-19

### Added

- `TABLE_*` constants in `constants.php` for all six custom-table
  name suffixes; `Database::create_tables()` refactored to use
  them.
- `includes/class-groups-controller.php` — CRUD for the
  `hum_groups` table, returning `WP_Error` on failure, plus
  `seed_defaults()` that idempotently inserts the
  `hosting-customers` and `web-designers` groups.
- Seed call wired into `hum_activate()` and
  `Plugin::check_first_run()` (MU plugin safety).
- Top-level "Heads Up Mailer" admin menu with Dashboard
  (placeholder) and Groups submenus. Capability-gated on
  `manage_options`.
- `admin-templates/dashboard.php`, `groups-list.php`,
  `group-edit.php` — code-first PHP via `printf` / `echo`.
- `assets/admin/heads-up-mailer-admin.js` — delegated
  `data-hum-confirm` handler for destructive links.
- `admin-post.php` form handlers `hum_save_group` and
  `hum_delete_group` with nonce + capability checks.
- `includes/class-subscribers-controller.php` — CRUD on the
  `hum_subscribers` table with case-insensitive email storage,
  32-byte hex `token_salt` generation on insert, and
  status-transition logic that stamps / clears `unsubscribed_at`.
  Group memberships maintained via `set_groups()` and
  `get_groups()` against the `hum_subscriber_groups` junction
  table.
- `admin-templates/subscribers-list.php` and
  `subscriber-edit.php` — Subscribers list with status + group
  chips, and add/edit form with status select, groups
  multi-checkbox picker, and consent metadata fields.
- `Plugin` admin-post handlers `hum_save_subscriber` and
  `hum_delete_subscriber` with nonce + capability checks.
- `includes/class-csv-importer.php` — CSV import for the
  MailerLite export shape. Detects columns by name (`Subscriber`
  or `email`, optional `Name` + `Last name` or unified `name`,
  optional `Subscribed`/`consent_at` and `Groups`/`groups`).
  Group cells are split on `;` and `,`; each part is resolved
  via `sanitize_title()` so either display name or slug works.
  Update-or-create by lowercased email. Existing `consent_at`
  preserved; missing timezone markers default to `UTC`.
- `admin-templates/subscriber-import.php` — combined upload form
  and per-row result view, with counts header (inserted /
  updated / skipped / errors) and a result table including a
  message column for warnings (e.g. unknown groups).
- `Plugin::handle_csv_import()` — admin-post handler that
  validates the upload (`is_uploaded_file()`), runs the
  importer, stashes the per-row report in a per-user transient,
  and redirects to `?action=imported`.

### Changed

- `phpcs.xml` refined to match Headwall convention: excluded
  `PrefixAllGlobals`, `InterpolatedNotPrepared`, and
  `UnusedFunctionParameter` from the base `WordPress` rule, then
  re-added `PrefixAllGlobals` with explicit prefix list.

## [0.1.0] — 2026-05-19

### Added

- M1 foundation scaffold:
  - Bootstrap `heads-up-mailer.php` with `HUM_*` defines,
    activation hook, and `hum_plugin_run()` setting
    `global $hum_plugin`.
  - `constants.php` with namespaced constant groups
    (`OPTION_*`, `STATUS_*`, `DRAFT_STATUS_*`, `SEND_STATUS_*`,
    `TRANSIENT_*`, `CRON_*`, `DEF_*`) plus `DB_VERSION`.
  - `functions-private.php` with the canonical `get_plugin()`
    accessor, `get_default_settings()` lazy-init helper, and
    `now_utc()` for the project's stored datetime format.
  - `includes/class-plugin.php` with `run()` hook registration,
    `check_first_run()` for MU plugin safety, and
    `admin_notices()` warning when PHP `imap` extension is
    missing.
  - `includes/class-database.php` with `dbDelta` schema for all
    six tables. Datetime columns stored as `VARCHAR(30)` holding
    `Y-m-d H:i:s UTC` strings.
- `phpcs.xml` configured for the WordPress ruleset with the
  `Heads_Up_Mailer` / `HUM` / `hum` / `heads_up_mailer`
  prefixes.
- Design documentation in `dev-notes/`: requirements
  (`01-requirements.md`), design decisions (`02-design-notes.md`),
  style-guide review (`03-style-guide-review.md`), and the
  9-milestone project tracker (`00-project-tracker.md`).
- `CLAUDE.md` capturing project conventions for AI-assisted work.
- `README.md` and `readme.txt` for repository and WordPress
  consumers respectively.
- `docs/` directory placeholder for site-administrator,
  newsletter-editor, and hosting-provider documentation.
