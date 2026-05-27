# Project Tracker — heads-up-mailer

**Status:** v1 shipped; M10 + M11 complete; v1.1 candidate
**Current Version:** 0.9.0
**Current Phase:** Polish + post-launch (next deliberate work TBD)
**Last Updated:** 27 May 2026
**Progress:** 11 of 11 milestones complete (3 deferred for v1.1.0+)

---

## Milestone 1: Foundation

**Goal:** Stand up the plugin skeleton. Plugin activates without
errors, database tables exist, the version option is stamped, and
the IMAP extension is detected.

**Status:** ✅ Complete

### Tasks

#### Bootstrap (`heads-up-mailer.php`)

- [x] Plugin header (name, version, requires PHP 8.0, text domain)
- [x] Top-level `define()`s: `HUM_VERSION`, `HUM_FILE`, `HUM_PATH`,
      `HUM_URL`, `HUM_BASENAME`
- [x] `require_once` chain: `constants.php`,
      `functions-private.php`, each `includes/class-*.php`
- [x] `register_activation_hook` → `hum_activate()` (installs
      defaults, runs schema, stamps version option)
- [x] Bootstrap fn `hum_plugin_run()` → sets `global $hum_plugin`
      and calls `$hum_plugin->run()` (renamed from `hum_run()` to
      avoid potential collision)
- [x] ~~`register_deactivation_hook` → `hum_deactivate()`~~ —
      removed. Object cache hosts make the transient cleanup
      pointless; can reinstate later if we ship to cheap hosting.

#### Constants (`constants.php`)

- [x] Namespace `Heads_Up_Mailer`
- [x] Constant groups in use: `OPTION_*`, `STATUS_*`,
      `DRAFT_STATUS_*`, `SEND_STATUS_*`, `TRANSIENT_*`, `CRON_*`,
      `DEF_*`. `META_*` / `MODE_*` / `LOG_*` / `RATE_LIMIT_*` get
      added as their owning milestones land.
- [x] `DB_VERSION` constant (start at `1`)

#### Private helpers (`functions-private.php`)

- [x] `get_default_settings()` (lazy-init via
      `global $hum_default_settings`) — namespaced, no `hum_`
      prefix.
- [x] `get_plugin()` canonical accessor returning the bootstrap
      global.
- [x] `now_utc()` UTC-formatted timestamp helper.
- [ ] Utility stubs for IP / user-agent / current admin URL —
      deferred until first consumer needs them (probably M6 public
      endpoint).

#### Main plugin class (`includes/class-plugin.php`)

- [x] `run()` registers hooks (`init`, `admin_init`,
      `admin_notices`)
- [x] `check_first_run()` on `admin_init` priority 1 (MU plugin
      safety)
- [x] `load_textdomain()` on `init`
- [x] Migration runner: compare stamped vs `DB_VERSION`, re-run
      `create_tables()` if behind (currently a no-op at v1)

#### Database class (`includes/class-database.php`)

- [x] `create_tables()` runs `dbDelta` for all 6 tables
- [x] Schema (per `01-requirements.md` "Schema sketch"):
  - [x] `wp_hum_subscribers` with `UNIQUE(email)`
  - [x] `wp_hum_groups` with `UNIQUE(slug)`
  - [x] `wp_hum_subscriber_groups`
  - [x] `wp_hum_drafts`
  - [x] `wp_hum_sends`
  - [x] `wp_hum_send_recipients` with
        `UNIQUE(send_id, subscriber_id)`
- [x] All datetime columns stored as `VARCHAR(30)` holding
      `Y-m-d H:i:s UTC`

#### Activation safety

- [x] Detect missing `ext-imap` at runtime → admin notice via
      `Plugin::admin_notices()`. Live check on every admin page
      load (no transient/option to manage). Sending still works
      without it.

#### Tooling

- [x] `phpcs.xml` with `WordPress` ruleset, prefixes
      `Heads_Up_Mailer`, `HUM`, `hum`, `heads_up_mailer`; excludes
      for `dev-notes/`, `assets/`, `.git/`, `languages/`,
      `vendor/`, `node_modules/`
- [x] `README.md` (development readme) + `readme.txt`
      (WordPress.org format) + `CHANGELOG.md`
- [x] `docs/` directory with `.gitkeep` placeholder for
      end-user docs

#### Smoke test (wp-cli, 2026-05-19)

- [x] Plugin activates without PHP errors or warnings
- [x] All 6 tables exist with correct columns
- [x] `UNIQUE` constraints verified on `email` and
      `(send_id, subscriber_id)`
- [x] `OPTION_VERSION` = `0.1.0`, `OPTION_DB_VERSION` = `1`
- [x] Default settings options seeded
- [x] `Heads_Up_Mailer\get_plugin()` returns the `Plugin` instance
- [x] `Heads_Up_Mailer\now_utc()` returns
      `2026-05-19 ... UTC`
- [x] `phpcs` returns clean

**Deliverable:** Activatable plugin shell with database schema in
place and prefix conventions enforced. ✅ Achieved.

---

## Milestone 2: Subscribers and Groups

**Goal:** Admin can manually CRUD subscribers and groups, and can
import the MailerLite export via CSV. Sequenced early so the
MailerLite subscription can be cancelled.

**Status:** ✅ Complete (search/filter on subscribers list deferred — see Deferred section below)
**Dependencies:** M1

### Tasks

#### Groups admin

- [x] `Groups_Controller` class — CRUD methods returning `WP_Error`
      on failure
- [x] Admin page `admin-templates/groups-list.php` (code-first PHP,
      `printf` / `echo`)
- [x] Add / edit form `admin-templates/group-edit.php`
- [x] Seed `hosting-customers` and `web-designers` on activation
      (idempotent — only inserts if missing)
- [x] `TABLE_*` constants in `constants.php` to centralise the
      table-name suffixes used by the controllers
- [x] Top-level admin menu + Dashboard submenu (placeholder) +
      Groups submenu wired in `Plugin::admin_menu()`
- [x] Form submission via `admin-post.php` with nonces:
      `hum_save_group`, `hum_delete_group`
- [x] `assets/admin/heads-up-mailer-admin.js`: delegated
      `data-hum-confirm` handler for destructive links
- [x] `phpcs.xml` refined — excluded `PrefixAllGlobals`,
      `InterpolatedNotPrepared`, and `UnusedFunctionParameter`
      from the base `WordPress` rule, then re-added
      `PrefixAllGlobals` with our explicit prefix list

#### Subscribers admin

- [x] `Subscribers_Controller` class — CRUD + group attachment
- [x] List table with status chips and group chips
- [ ] Search by email, filter by group, filter by status —
      deferred. Small follow-up; not blocking the v1 deliverable
      since admins can scroll the list and the CSV flow does the
      heavy lifting.
- [x] Add / edit form (email, name, groups, status, consent fields)
- [x] Generate 32-byte `token_salt` on insert (via
      `random_bytes(32)`, store hex-encoded)
- [x] Status-transition logic: stamping / clearing
      `unsubscribed_at` when status moves to / from
      `unsubscribed`
- [x] Case-insensitive email storage (lowercased on validate) and
      lookup

#### CSV import

- [x] Upload form on subscribers page
- [x] Parser accepting both the MailerLite export shape
      (`Subscriber, ..., Subscribed, Name, Last name, Groups`) and
      the spec format (`email,name,groups,consent_at,consent_source`)
- [x] Update-or-create by email (case-insensitive)
- [x] Preserve incoming `consent_at` and `consent_source` — do
      **not** stamp "imported on X". (Imports stamp
      `consent_source = mailerlite-import` only when the CSV
      doesn't already supply one.)
- [x] Group references resolved by `sanitize_title()` so either
      slug or display name works; unknown names reported per-row
      as warnings, not errors
- [x] Per-row report after import with inserted / updated /
      errors counts plus message column
- [x] Timestamps without a timezone marker are treated as UTC
      and stored with a `UTC` suffix to match our format

**Deliverable:** Subscriber list populated from the MailerLite
export. MailerLite subscription can be cancelled. ✅ Achieved.

**Notes:** Released as 0.2.0 on 2026-05-19. Smoke-tested against
`Documents/headsup_mailerlite_redacted.csv` — 2 rows inserted,
both groups attached correctly, consent_at preserved, re-import
yielded 0 inserts + 2 updates (idempotent). Search and filtering
on the subscribers list are tracked as a follow-up against the
post-v1 polish.

---

## Milestone 3: Settings page

**Goal:** Configurable batch size, tick interval, and IMAP mailbox
credentials (encrypted at rest). Connection-test button.

**Status:** ✅ Complete
**Dependencies:** M1

### Tasks

#### Framework

- [x] `Settings` class instantiated by `Plugin::run()`; registers
      via `admin_init`
- [x] Hash-based tab navigation in
      `admin-templates/settings-page.php` (driven by
      `assets/admin/heads-up-mailer-admin.js`)
- [x] Capability check `manage_options` on `render_settings`

#### Queue tab

- [x] Batch size (default 10, clamped 1–100)
- [x] Tick interval in minutes (default 5, clamped 1–60)
- [ ] Re-schedule the cron event when interval changes — deferred
      to M5 since there is no scheduled event yet

#### Mailbox tab

- [x] Host
- [x] Port (default 993, clamped 1–65535)
- [x] Username (default `unsub@headwall-hosting.com`)
- [x] Password (encrypted on save; never round-tripped to form)
- [x] Folder name (default `INBOX`)
- [x] Polling interval (minutes; 1–60)
- [x] TLS toggle (default on)

#### Encryption helper (`includes/class-crypto.php`)

- [x] `sodium_crypto_secretbox` wrapper with `nonce || ciphertext`
      envelope, base64 encoded for storage
- [x] Key derived from `AUTH_KEY` via `hash_hkdf` SHA-256 with a
      plugin-specific `info` binding (`hum:credentials:v1`)
- [x] `decrypt()` returns `""` on any failure (bad base64,
      truncated, tampered) — callers treat that as "no usable
      value"

#### Sanitize callbacks

- [x] Per-setting `sanitize_callback` via the Settings API
- [x] Password field renders empty with `••••••••` placeholder
      when a value is stored; sanitize keeps the existing
      encrypted value on blank submit, encrypts on non-blank

#### Test-connection AJAX

- [x] Endpoint `wp_ajax_hum_test_mailbox` registered with a
      capability check (`manage_options`) and nonce check via
      `check_ajax_referer( 'hum_test_mailbox', 'nonce' )`
- [x] Opens an IMAP connection to the supplied host/port/folder
      with a single retry (`imap_open( …, 0, 1 )`) so the AJAX
      timeout stays bounded; reports the last `imap_errors()`
      entry on failure
- [x] Does not persist credentials again — operates on submitted
      form state; a blank password field falls back to the stored
      encrypted value, decrypted in place

**Deliverable:** Admin can configure send cadence and mailbox
credentials. Test-connection button verifies IMAP login.
✅ Achieved.

**Notes:** Released as part of 0.3.0. The browser-side test
button is wired but the live click flow needs a final manual
check against a real IMAP server (planned during M7's mailbox
poller work — same credentials, same code path).

---

## Milestone 4: Drafts

**Goal:** AI agent posts drafts via authenticated REST. Admin
lists, edits, previews, and selects target group(s).

**Status:** ✅ Complete
**Dependencies:** M1, M2

### Tasks

#### REST API

- [x] Register namespace `heads-up-mailer/v1` on `rest_api_init`
- [x] `POST /drafts` — accepts `subject`, `html_body`,
      `suggested_groups` (array of group slugs). Application-password
      auth via `permission_callback` checking `manage_options`.
      Returns 201 with serialized draft.
- [x] `GET /drafts/{id}` — returns the stored draft. Same auth.
- [x] Validation: `subject` ≤ 200 chars, `html_body` non-empty,
      `suggested_groups` resolved to known group slugs (unknown
      slugs returned as `hum_draft_unknown_groups`)
- [ ] Smoke test via `curl` from the AI agent host — pending. The
      in-process REST dispatch was verified (201 create, 200 read,
      404 unknown id, 400 bad payload, 401 anon). The on-host
      `curl` walk uses the same code path.

#### Admin UI

- [x] Drafts list table (id, subject, status chip, created_at)
- [x] Edit form: subject input, raw HTML textarea, suggested-groups
      multi-select (slugs)
- [x] iframe preview rendering the HTML body via
      `admin-post.php?action=hum_preview_draft` (no admin chrome,
      `X-Frame-Options: SAMEORIGIN`)
- [x] "Send (coming soon)" button — disabled placeholder; wires in M5

**Deliverable:** AI agent can POST a draft. Admin can review and
mark it ready to send.

**Notes:** Adds `Drafts_Controller`, `REST_Controller`,
`DEF_DRAFT_SUBJECT_MAX = 200`, and `REST_NAMESPACE` constants. The
HTML body is sanitised via `wp_kses_post` on save; the preview
endpoint re-emits it inside a minimal document.

---

## Milestone 5: Send pipeline

**Goal:** Sends are queued in one transaction and drained
asynchronously. Every outbound carries the required headers and a
plain-text alternative.

**Status:** ✅ Complete
**Dependencies:** M2, M3, M4

### Tasks

#### Tokens helper (M5 chunk A — also consumed by M6/M7)

- [x] `includes/class-tokens.php` — `generate(int): string` and
      `verify(string): ?int`. Constant-time `hash_equals`; all
      failure modes collapse to `null`.
- [x] `Subscribers_Controller::regenerate_token_salt()` for future
      rotation flows.

#### Sending settings tab (M5 chunk B)

- [x] New `OPTION_FROM_NAME`, `OPTION_FROM_EMAIL`,
      `OPTION_FOOTER_HTML`, `OPTION_MANAGE_SLUG` with sanitize
      callbacks. Footer ships with a default template carrying
      `{{unsubscribe_url}}`.
- [x] Slug-change hook flushes the WordPress rewrite-rules cache
      so M6's eventual `/manage-comms/` rewrite picks up the new
      value on save.

#### Queueing (M5 chunk C)

- [x] Admin "Send" handler — nonce + capability check, separate
      `<form>` on the draft-edit page with a confirm dialog that
      names the recipient count
- [x] `Sends_Controller::queue()` — single transaction: insert
      `hum_sends` row + N `hum_send_recipients` rows (status
      `pending`) + flip draft to `DRAFT_STATUS_SENDING`
- [x] Skip subscribers whose status is not `subscribed`
- [x] Return immediately to admin with success notice; re-sends
      from a `sent` draft write a fresh `send_id`

#### Worker (M5 chunk D)

- [x] Custom WP-Cron interval `hum_tick` (configurable, default 5
      minutes) registered via `cron_schedules` filter; rescheduled
      automatically when the tick-interval setting changes
- [x] Activation hook + `admin_init` ensure the recurring drain
      is scheduled; deactivation hook clears it
- [x] Worker hook `hum_drain_queue`:
  - [x] Acquire transient lock `hum_drain_lock` to prevent
        overlapping ticks
  - [x] Pull up to N pending rows ordered by ID
  - [x] Wall-clock budget: bail after ~25 s regardless of remaining
        rows
  - [x] Per-recipient: optimistic `pending → processing` claim,
        build `$headers`, build `$body`, call `wp_mail()`, update
        row status atomically
  - [x] Failures logged in `last_error`; `attempts` incremented
- [x] `phpmailer_init` action to attach plain-text alternative

#### Headers and footer

- [x] `List-Unsubscribe: <mailto:unsub@...?subject=unsubscribe-{token}>, <https://.../manage-comms/?token=...&action=unsubscribe>`
- [x] `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
- [x] `List-ID: <heads-up-mailer.<host>>`
- [x] `Precedence: bulk`
- [x] HTML footer injected before last `</body>` (appended for
      fragment HTML)
- [x] Plain-text alternative auto-generated from HTML

#### Save guard

- [x] `Drafts_Controller::update()` refuses edits while
      `status = sending` (`hum_draft_locked_while_sending`).
      Edit form inputs and Save button disable in the UI to match.

**Deliverable:** Drafts can be sent. Send completes within a few
ticks. Sent rows show in `hum_send_recipients` with timestamps.
✅ Achieved.

**Notes:** End-to-end verified against a live Gmail inbox on
2026-05-21. Sender identity, headers, footer, plain-text
alternative, optimistic claim, and finalisation all behaved as
designed. Released as 0.4.0.

---

## Milestone 6: Public /manage-comms/ endpoint

**Goal:** Recipients can manage preferences and one-click
unsubscribe via signed tokens.

**Status:** ✅ Complete
**Dependencies:** M2

### Tasks

- [x] Rewrite rule for `/manage-comms/` → handler (slug driven by
      `OPTION_MANAGE_SLUG`; activation flushes rewrite rules)
- [x] Token parser via shared `Tokens::verify()` (already landed in
      M5 chunk A; reused here)
- [x] **GET handler** — render preference page
      (`public-templates/manage-comms.php`)
  - [x] Per-group checkboxes reflecting current memberships
  - [x] "Unsubscribe all" checkbox
  - [x] CSRF nonce (separate from access token) on the form
- [x] **POST handler — preferences** — update group memberships,
      re-render with confirmation
- [x] **POST handler — `action=unsubscribe`** (one-click)
  - [x] Flip status to `unsubscribed`, stamp `unsubscribed_at`
  - [x] Return 200 + plain-text "thanks" (no confirmation page)
  - [x] Idempotent — re-POST does nothing (`unsubscribe()` returns
        early for rows already in `unsubscribed` status)
- [x] Throttle by token (transient counter) — 20 requests / hour
      per token (`RATE_LIMIT_MANAGE_PER_HOUR`)

**Deliverable:** Recipients can self-manage. Gmail's one-click
button works. ✅ Achieved.

**Notes:** Released as 0.5.0 on 2026-05-26. Adds
`Public_Controller`, `public-templates/manage-comms.php`, and
`Subscribers_Controller::unsubscribe()` / `resubscribe()`
idempotent helpers. Manually verified end-to-end on the dev
site — token URLs, preference save, one-click POST.

---

## Milestone 7: Mailbox poller (mailto unsubscribes)

**Goal:** Inbound mails to `unsub@…` are parsed and translated into
status flips.

**Status:** ✅ Complete
**Dependencies:** M3 (IMAP creds), M6 (token validation)

### Tasks

- [x] WP-Cron job `hum_poll_mailbox` on a custom `hum_mailbox_tick`
      interval (driven by `OPTION_MAILBOX_INTERVAL`, clamped 1–60
      min, auto-reschedules when the setting changes)
- [x] IMAP connection helper using `imap_open` (PHP `ext-imap`) —
      shares the connection-string convention with the M3
      test-connection AJAX handler
- [x] Fetch UNSEEN messages from the configured folder; wall-clock
      budget of 25s per tick (mirrors the M5 worker)
- [x] Subject parser: regex `^unsubscribe-([A-Za-z0-9._-]+)$`
- [x] Re-use shared `Tokens::verify()` (lifted in M5 chunk A)
- [x] Flip status via `Subscribers_Controller::unsubscribe()`
      (the M6 idempotent helper); stamping is handled there
- [x] Move processed messages to `Processed` subfolder (created via
      `imap_createmailbox` if missing, guarded by `imap_list`);
      anything unparseable / token-invalid / unsubscribe-failed →
      `Errors`
- [x] Connection error handling — `OPTION_MAILBOX_LAST_OK` /
      `OPTION_MAILBOX_LAST_ERROR` health-state options drive a
      `Plugin::admin_notices()` warning if the last successful
      poll is >`MAILBOX_STALE_THRESHOLD_SECONDS` (2 hours) old
      **and** creds are configured
- [x] **Bonus**: "Poll now" button on the Mailbox settings tab
      (AJAX endpoint `hum_poll_mailbox`) — runs `poll_now()`
      inline against stored creds. Honours the same transient
      lock so it can't race the cron tick.

**Deliverable:** Replying to a list email with the mailto: form
unsubscribes the recipient. ✅ Achieved.

**Notes:** Released as 0.6.0 on 2026-05-26. The chroot dev site's
"Validate certificate" toggle is already off (no CA bundle in the
PHP-FPM jail) — production should re-enable it. Verified the
poller wiring with `wp cron event run hum_poll_mailbox` and a
direct `Mailbox_Poller::poll_now()` call: IMAP opens, `Processed`
/ `Errors` folders are ensured, `OPTION_MAILBOX_LAST_OK` stamps
correctly. The end-to-end mailto round-trip lives in M9's soak
test.

---

## Milestone 8: Sent log UI

**Goal:** Admin can review what was sent, to whom, and what
failed.

**Status:** ✅ Complete
**Dependencies:** M5

### Tasks

- [x] Sent-log list table (`hum_sends` rows with live counters
      from a `LEFT JOIN` against `hum_send_recipients` — accurate
      mid-flight, not just after `finalize_completed_sends`)
- [x] Per-send drill-down (`hum_send_recipients` filtered by
      `send_id`, joined to `hum_subscribers` for the email column)
- [x] Status filters (sent / failed / pending / processing) — link
      row above the drill-down table, each link carrying a count
- [ ] **Deferred to v1.1:** Re-queue button for individual `failed`
      rows. Admin can re-send the draft to a one-person group as
      the manual workaround until then.

**Deliverable:** Visibility into past sends. ✅ Achieved.

**Notes:** Released as 0.7.0 on 2026-05-26. New
`Sent_Log_Controller` (read-only — no UPDATE / DELETE surface),
new admin submenu `heads-up-mailer-sent-log`, templates in
`admin-templates/sent-log-{list,detail}.php`. Schema integration
discovered the parent table column is `started_at` not
`created_at` — corrected before commit. Smoke-tested against the
two real sends from M5 (2026-05-25): list, detail, and all four
status filters return correct row counts.

---

## Milestone 9: Soak test

**Goal:** Verify headers and one-click POST in real-world
mailboxes before declaring v1.

**Status:** ✅ Complete (implicit — shipped to production)
**Dependencies:** M1–M7

**Notes:** v1 (0.7.1) was pushed to live on 2026-05-27 and the
MailerLite plugin retired. Real subscriber list of ~140 imported
from the MailerLite CSV. Headers, RFC-8058 one-click, footer
links, plain-text alternative, and IMAP poll all observed working
in production. Formal Gmail / Outlook / Fastmail matrix was
folded into the actual mail-out rather than a separate test
group.

---

## Milestone 10: Never-contact + manage-comms refactor

**Goal:** GDPR-flavoured sticky terminal state on the subscribers
table, plus a clearer UI on the public preference page that
splits "save group memberships" from "stop everything".

**Status:** ✅ Complete
**Dependencies:** M1, M2, M6

### Tasks

- [x] New `STATUS_NEVER_CONTACT` constant.
- [x] `Subscribers_Controller::mark_never_contact()` — idempotent,
      mirrors the existing `unsubscribe()` / `resubscribe()` shape.
- [x] CSV importer refuses to update rows in `never_contact`
      status, surfacing them in the per-row report's `skipped`
      bucket.
- [x] `/manage-comms/` template refactored into two visibly
      distinct sections — groups + Save preferences in the
      primary card, a separate danger-styled card with a single
      "Unsubscribe me from everything" button. New CSRF nonce on
      the button's form, separate handler in `Public_Controller`.
- [x] Lockdown view on `/manage-comms/` for never-contact
      subscribers — no group list, no controls, no info leak.
- [x] One-click RFC 8058 path KEEPS flipping to `unsubscribed`
      (not `never_contact`). The button on the page is the
      explicit-intent path.
- [x] Subscribers list page: bulk-actions UI with "Mark as never
      contact"; column-header select-all toggle (JS); per-row
      "Mark never-contact" action (hidden on rows already in that
      state).
- [x] Subscriber edit form: `never_contact` in the status
      dropdown + `notice-warning` banner explaining the
      stickiness on rows currently in that state.
- [x] `Subscribers_Controller::update()` / `create()`: unified
      "stopped" bucket — entering either `unsubscribed` or
      `never_contact` stamps `unsubscribed_at`, leaving for any
      other status clears it.

**Deliverable:** Released as 0.8.0 on 2026-05-27.

---

## Milestone 11: Plugin integrations framework

**Goal:** Pluggable integration system so other plugins / themes
can feed subscribers into Heads Up Mailer. Ship with Contact Form
7 and WooCommerce as the built-in integrations.

**Status:** ✅ Complete
**Dependencies:** M1, M2

### Tasks

- [x] Integration registry — `apply_filters( 'hum_integrations', $registry )`
      on `plugins_loaded` priority 20. Built-ins and third-parties
      register the same way. `Plugin::get_integrations()` exposes
      the populated registry to the admin layer.
- [x] Abstract `Integration` base class with six abstract methods
      (`slug`, `label`, `parent_label`, `is_active`,
      `register_hooks`, `render_settings_section`). Concrete
      subclasses live in `integrations/<slug>/class-<slug>.php`.
- [x] `integrations/<slug>/class-<slug>.php` always
      `require_once`'d from the bootstrap; each runs its own
      `class_exists()` / `function_exists()` parent-check before
      binding hooks. Inactive integrations cost ~nothing.
- [x] New "Integrations" settings tab with a section per active
      integration; "Other available integrations" footer card
      lists every registered-but-inactive integration; standalone
      "No integrations available — install one of: …" message
      when nothing is active.
- [x] **Contact Form 7**: new tag `[hum_signup group:slug "Label"]`
      registered via `wpcf7_add_form_tag`. Submit hook scans the
      form for ticked sign-up checkboxes and enrols the email
      field's address into the target group (consent source
      `cf7-form:<form_id>`).
- [x] **WooCommerce**: dropdown for "Customers group" (admin maps
      to an existing group; empty = disabled). Per-group
      checkout-opt-in repeater with intro text, "show at checkout"
      toggle, and label text per group. Config stored in
      `OPTION_WC_CHECKOUT_GROUPS_JSON` (single wp_option JSON
      map). Checkout hook captures ticked slugs via order meta;
      processed hook enrols the customer in every applicable
      group.
- [x] `Subscribers_Controller::ensure_in_group()` — shared helper
      for integration callers. Creates or unions group
      memberships; refuses on never-contact rows.
- [x] `Plugin::check_first_run()` backfill: on version bump,
      walks `get_default_settings()` and `add_option()`s any new
      keys. Idempotent so admin-edited values are preserved.

**Deliverable:** End-to-end signup flow from CF7 + WooCommerce
into the existing groups system. Released as 0.9.0 on 2026-05-27.

---

## Deferred (post-v1)

### Subscriber list search and filters

Search by email and filter by group / status on the
`heads-up-mailer-subscribers` admin page. Not blocking the
"replace MailerLite" goal because the CSV import is the bulk
operation and the list view stays usable as long as the
subscriber count is in the hundreds. Pick this up before the
plugin grows past a few thousand subscribers.

### ~~GitHub auto-updater~~ — shipped 0.10.0

Repo at `git@github.com:headwalluk/heads-up-mailer.git`. Updater
(`includes/class-github-updater.php`, pattern lifted from
quick-2fa, loaded admin + cron only) self-updates from release
tags. Releases built by `.github/workflows/release.yml` on
`v*.*.*` tag pushes. Push `git tag v0.10.0 && git push origin
v0.10.0` to publish the first release after the 0.10.0 commit
lands on master.

### WP-CLI commands

Candidate commands once admin flows are stable:

- `wp heads-up-mailer drain` — manual queue drain
- `wp heads-up-mailer test-send <email>` — send a single rendered
  draft to one address
- `wp heads-up-mailer import <csv>` — import without the admin UI
- `wp heads-up-mailer rotate-token <subscriber-id>` — regenerate
  `token_salt`, invalidate outstanding links

### Bounce processing

Schema reserves `bounced` status. A future cron / VERP
integration on `nexus.headwall.co.uk` flips rows. Out of scope for
v1.

### Other v1 exclusions

See `dev-notes/01-requirements.md` "Out of scope". Open / click
tracking, drip campaigns, automations, A/B, scheduling-for-later,
multi-site, built-in SMTP, CAPTCHA on unsubscribe.
