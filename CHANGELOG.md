# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Groups list table: the Slug, Visibility, and Actions columns no
  longer wrap, so a long group Description can't squeeze them onto
  multiple lines. CSS-only (new reusable `.hum-nowrap` class), no
  behaviour change.

## [1.5.0] — 2026-06-20

### Added

- **Autonomous draft → send via REST.** A new
  `POST /heads-up-mailer/v1/drafts/{id}/send` route lets a trusted
  agent trigger a send end-to-end — the same async pipeline a human
  triggers from the admin Send button — without a human in the loop.
  Built for a daily security email going to one small, managed group.
  Gated by two fail-safe controls, **both default OFF**:
  - **Master switch** `hum_autonomous_send_enabled` (*Settings →
    Sending → "Allow trigger-send via REST API"*). Site-wide on/off,
    independent of capabilities, so REST sending revokes instantly.
  - **Per-group `allow_automated_send` flag** (checkbox on each
    group's edit screen; `· Auto-send` marker in the groups list).
    The route refuses unless **every** group the draft targets is
    flagged — one un-flagged group blocks the whole send. The
    guarantee is by data, not config: the general list can never be
    auto-mailed while its group stays un-flagged.
  - New capability `hum_send_newsletters`, granted to Administrator +
    Editor alongside `hum_create_drafts`, but separate so the send
    right can be revoked independently of drafting.
  - Machine-level idempotency: a draft already `sent`/`sending` is
    refused (no REST "Send AGAIN"). Status map: `200` queued, `403`
    master-off or a group not enabled (names the blocked slugs),
    `409` already sent/sending, `404` not found, `422` pre-flight
    failure.
  - Audit: a new `is_automated` column on `hum_sends` (written in the
    send transaction) drives a **Trigger** column (Auto / Manual) in
    the Sent log; every trigger — queued or refused — is written to
    the PHP error log via `audit_autonomous_send()`.
  - **Drafting is unaffected** — posting drafts and assigning groups
    over REST works exactly as before; the flag gates only the new
    send route.
- **Bulk "Delete" action on the Subscribers list.** Joins the existing
  "Mark as never contact" bulk action; reuses the per-row
  `Subscribers_Controller::delete()` (which removes group memberships
  first), with a plural-aware success notice and an Apply-confirm that
  warns deletion is permanent. No schema change.

### Changed

- Schema version bumped to 4. The migration runs automatically on
  upgrade (activation and `admin_init`), adding `allow_automated_send`
  to `hum_groups` and `is_automated` to `hum_sends`, both defaulting
  to 0 — so the upgrade changes no behaviour until an admin opts a
  group in and flips the master switch.
- Translations refreshed across all eight bundled locales (de_DE,
  el_GR, en_GB, es_ES, fr_FR, it_IT, nl_NL, pl_PL). The `.pot` was
  regenerated and the new autonomous-send strings machine-translated,
  which also cleared the previously-untranslated 1.3.0 dashboard /
  group-screen backlog. Short, ambiguous admin labels (`Trigger`,
  `Auto`, `Manual`, `Auto-send`) now carry `_x()` context per the
  project i18n guidance. A native-speaker pass over the short labels
  and the `%d subscriber(s) deleted.` plural is still pending — those
  fall back to English until then (tracked, not blocking).

## [1.4.0] — 2026-06-17

### Added

- **`checked` option for the Contact Form 7 `[hum_signup]` tag.**
  Adding the bare word `checked` (e.g.
  `[hum_signup signup-news group:wordpress-news checked "…"]`) renders
  the sign-up checkbox pre-ticked. Intended for a dedicated subscribe
  page where pre-selecting the most popular list is a reasonable nudge
  — the visitor can still untick it, and the submit handler only
  enrols boxes that come back ticked. Like CF7 core's `include_blank`,
  it is a bare option, so it never pollutes the checkbox label. The
  Integrations → Contact Form 7 help text documents it. No schema
  change.

## [1.3.0] — 2026-06-17

### Added

- **Admin dashboard.** The previously-placeholder landing page is now
  a privacy-first overview of the last 30 days
  (`DASHBOARD_WINDOW_DAYS`):
  - Send health — delivery success rate, sends / delivered / failed
    counts, and a red alert banner when the failure rate (over
    completed recipients) crosses `DASHBOARD_ALERT_FAIL_PCT` (5%).
  - Audience — active subscriber total and account unsubscribes in
    the window (one-click and IMAP unsubscribes included, read from
    subscriber status).
  - Per-group breakdown — active members, plus sign-ups and
    departures over the window.
  - Recent send failures — the latest failed recipients with their
    error text.

  No tracking pixels and no link rewriting; every figure is derived
  from data the plugin already owns.
- **Activity event log (`hum_events`).** New table recording per-group
  join / leave events, written from the single membership choke-point
  (`Subscribers_Controller::set_groups()`), so every path — admin
  edit, public preferences, checkout opt-in, unsubscribe-everything —
  is captured. Powers the dashboard's per-group trends.
- **Group "add" screen polish.** The slug now auto-generates from the
  name as you type, with an opt-in "Set the slug manually" checkbox;
  the Name field is full-width (`widefat`). Editing an existing group
  keeps the slug as an ordinary editable field (auto-generating over a
  saved slug would invalidate live links).

### Changed

- The group Description field gained inline help noting it is plain
  text — any HTML is stripped on save.
- Schema version bumped to 3. The migration runs automatically on
  upgrade (activation and `admin_init`). Per-group sign-up / departure
  counts are **forward-looking**: they accumulate from the upgrade and
  are not backfilled, since existing memberships carry no recorded
  join date.

## [1.2.2] — 2026-06-17

### Fixed

- Machine-translation artifacts in the bundled `languages/`
  catalogues. DeepL mistranslated several short, context-free admin
  UI labels identically across all eight locales (de_DE, el_GR,
  en_GB, es_ES, fr_FR, it_IT, nl_NL, pl_PL):
  - `Sent` (email status) was rendered in the "late / delayed"
    sense (e.g. de "Spät", es "Tarde", nl "Laat"), which also
    propagated into the `Sent log`, `Sent (%d)`, `Sent / Total`
    and `Sent at (UTC)` compounds built on it.
  - `Folder` (IMAP mailbox folder) became "brochure / leaflet"
    (e.g. de "Broschüre", fr "Dépliant").
  - The acronyms `TLS` and `ID` were expanded into prose (e.g.
    "The latest security standards", "Identification number").
  Corrected per locale and the `.mo` files recompiled. No code or
  schema change. Root cause and a seed glossary for the upstream
  fix are filed in the `wp-translate-tool` repo.

## [1.2.1] — 2026-06-09

### Added

- Translation catalogues under `languages/` for eight locales
  (de_DE, el_GR, en_GB, es_ES, fr_FR, it_IT, nl_NL, pl_PL),
  kickstarted with the `wp-translate-tool` and committed alongside
  the `.pot` template. Compiled `.mo` files are tracked because the
  plugin has no build step, so they must ship in the release zip.
  Catalogues cover all strings through 1.2.0.

### Fixed

- Plural-form handling in the translation catalogues. None of the
  `.po` files carried a `Plural-Forms` header, so the plural string
  `%d subscriber flagged as "never contact".` would not have
  pluralised. Added the correct header per locale — two forms for
  the European languages, three for Polish (with the extra "many"
  `msgstr[2]`) — and the placeholder header to the `.pot` template.
  All `.mo` files recompiled with `msgfmt --check`; no untranslated
  or fuzzy strings remain.

## [1.2.0] — 2026-06-09

### Added

- Groups column on the Drafts list table
  (`admin-templates/drafts-list.php`). Each draft's selected
  groups render as pills, resolved from the stored slugs to their
  display names via a slug→name map built in
  `Plugin::render_drafts()`. Slugs whose group was since deleted
  fall back to the raw slug; drafts with no groups show a muted
  em-dash.
- `assets/admin/heads-up-mailer-admin.css` — first admin
  stylesheet for the plugin. Carries the `.hum-group-pill` and
  `.hum-group-none` styles, enqueued on all HUM admin pages
  alongside the existing admin JS.

### Changed

- Draft editor Subject field switched from `regular-text` to
  `large-text`, so it is full width and consistent with the HTML
  body field below it (`admin-templates/draft-edit.php`).

## [1.1.0] — 2026-05-27

### Added

- New custom capability `hum_create_drafts`, granted to the
  Administrator and Editor roles on activation / first-run /
  version-bump. The REST permission callback now checks this cap
  instead of `manage_options`, so a dedicated AI-agent user can
  post drafts via `POST /heads-up-mailer/v1/drafts` while sitting
  at the Editor role — no Administrator role inflation required.
- `hum_ensure_caps()` bootstrap helper. Idempotent
  (`WP_Role::add_cap()` no-ops when the role already has the cap)
  and called from `hum_activate()` plus both branches of
  `Plugin::check_first_run()`, so existing 1.0.0 deployments pick
  up the new cap on the first admin pageload after upgrade — no
  manual deactivate/reactivate cycle needed.
- `CAP_CREATE_DRAFTS` constant (`'hum_create_drafts'`) added to
  the `constants.php` capability group.

### Changed

- `REST_Controller::check_permission()` switched from
  `current_user_can( 'manage_options' )` to
  `current_user_can( CAP_CREATE_DRAFTS )`. Existing admin REST
  callers keep working because Administrator is granted the new
  cap on the same upgrade.
- `docs/ai-agent-rest-guide.md` updated: the agent account now
  needs the `hum_create_drafts` capability (Editor or
  Administrator role both suffice) rather than `manage_options`.

## [1.0.0] — 2026-05-27

First stable release. Replaces MailerLite at headwall-hosting.com
and is in active production use.

The detailed feature history is in the 0.x entries below; this
entry frames what the 1.0.0 surface actually covers.

### Highlights

- **Drafts → review → send**: AI agent posts drafts via
  authenticated REST (`POST /wp-json/heads-up-mailer/v1/drafts`).
  Admin reviews + edits in WP admin with sandboxed preview, picks
  one or more target groups, and clicks Send. Queue + worker
  drain the recipients asynchronously in batches.
- **Subscribers and groups**: full CRUD admin, MailerLite-export
  CSV import (idempotent update-or-create), per-subscriber group
  memberships, configurable send window enforced in the site
  timezone, optimistic per-recipient claim against
  `UNIQUE(send_id, subscriber_id)` so concurrent ticks never
  double-send.
- **RFC 8058 compliance**: every send carries `List-Unsubscribe`
  (mailto + https), `List-Unsubscribe-Post: One-Click`, `List-ID`,
  and `Precedence: bulk`. One-click POST and the on-page
  `/manage-comms/` flow both work; mailto-form replies are
  harvested by an IMAP poller. Plain-text alternative
  auto-generated from the HTML body.
- **Never-contact status**: GDPR-flavoured sticky terminal state.
  CSV re-imports refuse to update never-contact rows; the public
  "Unsubscribe from everything" button flips to it; admin row +
  bulk actions on the subscribers list expose it.
- **Sent log**: list view of every send with live per-status
  counters, drill-down to per-recipient rows with status filter.
- **Integrations framework**: pluggable `Integration` base class +
  `hum_integrations` filter so built-ins and third-parties
  register the same way. Ships with **Contact Form 7** (new
  `[hum_signup group:slug "Label"]` form tag) and **WooCommerce**
  (auto-enrol customers via T&C-accepted checkout, plus per-group
  opt-in checkboxes on classic checkout).
- **In-plugin GitHub auto-updater**: GitHub releases land as
  standard WordPress plugin updates. Built by a `v*.*.*` tag
  workflow that publishes `heads-up-mailer.zip` and a versioned
  copy.
- **Privacy-positive throughout**: no open tracking, no click
  tracking, no link rewriting. Mailbox credentials encrypted at
  rest with libsodium.
- **Private groups**: a new `is_private` column on `hum_groups`
  (DB_VERSION bumped to 2 with an idempotent dbDelta migration).
  Private groups never appear on `/manage-comms/` for non-members
  — useful for test groups and one-off targeted lists. Existing
  members still see them (so they can leave). The preferences-save
  handler validates posted group IDs against the visible-for-this-
  subscriber set computed from pre-save memberships, so a
  tampered POST can't enrol the subscriber in a private group
  they weren't shown.
- **UI polish**: scrubbed internal-milestone references (e.g. "M5",
  "M6") from user-facing copy. Dashboard reduced to a minimal
  welcome card with a "stats arriving in a future release" line —
  real analytics are tracked as a follow-up.

### Out of scope for 1.0.0 (deferred)

- WooCommerce Block checkout (classic checkout works fully).
- Bounce processing (schema reserves `bounced` status).
- Re-queue button for individual failed sent-log rows.
- Subscriber list search / filter (CSV import is the bulk
  operation; the list view stays usable into the low thousands).
- WP-CLI commands.

See `dev-notes/00-project-tracker.md` for the full deferred list.

## [0.10.1] — 2026-05-27

### Fixed

- `Subscribers_Controller::update()` partial-payload bug: calling
  with a subset of fields (e.g. `{ email, name, status }`) used to
  silently wipe omitted columns (`consent_at`, `consent_source`)
  because the validated array was written wholesale. Internal
  callers always passed complete arrays so it hadn't bitten in
  production, but it was a footgun for ad-hoc scripts and future
  integration code. Reproduced by the M10 smoke test damaging row
  143's consent fields (since restored).
  - New `validate_partial()` validates only the fields present
    in the input; missing fields are not defaulted.
  - `update()` writes only the validated subset, so omitted
    columns keep their existing values.
  - `unsubscribed_at` stamping logic now gates on whether
    `status` was actually passed — a name-only update no longer
    touches the timestamp.
  - Groups-only updates (`{ groups: [...] }` with no column
    fields) now skip the column write entirely and just update
    memberships. Empty `$data` is a clean no-op.

## [0.10.0] — 2026-05-27

### Added

- `includes/class-github-updater.php` — in-plugin GitHub
  auto-updater. Pattern lifted from quick-2fa: hooks
  `pre_set_site_transient_update_plugins`, `plugins_api`, and
  `upgrader_process_complete` to surface new GitHub releases as
  standard WordPress plugin updates. Wired only on admin / cron
  requests (`is_admin() || wp_doing_cron()`) — front-end pages
  skip the init.
  - Cached release data lives in transient
    `hum_updater_release` (12-hour TTL).
  - Filter `hum_updater_enabled` lets site owners disable
    update checks (staging environments, pinned versions).
  - Prefers the stable `heads-up-mailer.zip` asset emitted by
    the release workflow; falls back to any
    `heads-up-mailer-*.zip` for older releases.
  - Errors (HTTP failures, missing `tag_name`, no zip asset)
    log unconditionally with a `Heads Up Mailer Github_Updater
    [error]:` prefix. Routine flow tracing logs only when
    `WP_DEBUG` is on.
- `.github/workflows/release.yml` — GitHub Actions workflow that
  builds and publishes a release zip on every `v*.*.*` tag
  push. Produces both `heads-up-mailer-<version>.zip` and a
  byte-identical `heads-up-mailer.zip` (the stable filename the
  updater prefers).
- `.distignore` — files excluded from the release zip
  (`.git`, `.github`, `dev-notes/`, `phpcs.xml`, `CLAUDE.md`,
  IDE / editor noise).
- New constants: `UPDATER_GITHUB_REPO`, `UPDATER_CACHE_KEY`,
  `UPDATER_CACHE_TTL`.

## [0.9.1] — 2026-05-27

### Fixed

- Subscribers list: clicking Edit (or the email link) on a row
  fired the bulk-action confirm dialog because `data-hum-confirm`
  was on the form wrapper, and the JS handler's
  `closest('[data-hum-confirm]')` walked up to it from any link
  inside the table. Moved the attribute onto the Apply button
  itself so only that button triggers the prompt. Row actions
  with their own `data-hum-confirm` (Mark never-contact, Delete)
  still work — `closest()` finds the link before walking past it.

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
