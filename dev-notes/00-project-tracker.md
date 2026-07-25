# Project Tracker — heads-up-mailer

**Status:** Shipping post-1.0.0 increments — 1.6.0 released; 1.7.0
(WooCommerce paid-order fix + subscribers-list scaling) ready to tag
**Current Version:** 1.7.0 (bumped; not yet committed or tagged)
**Current Phase:** Production-driven fixes and admin scaling
**Last Updated:** 25 July 2026
**Progress:** 16 of 16 milestones complete — Milestone 16 (WooCommerce
paid-order enrolment + subscribers-list pagination) 2026-07-25

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

## Milestone 12: GitHub auto-updater + release pipeline

**Goal:** Repo on GitHub with a one-command release flow:
`git tag v1.2.3 && git push origin v1.2.3` builds the zip and
publishes a release; live sites pick it up via the standard
WordPress plugin update UI.

**Status:** ✅ Complete
**Dependencies:** Repo on GitHub.

### Tasks

- [x] Repo moved to `git@github.com:headwalluk/heads-up-mailer.git`
      and CLAUDE.md / tracker updated to match.
- [x] `.github/workflows/release.yml` — triggers on `v*.*.*`
      tag pushes. Uses `rsync --exclude-from=.distignore` to
      build a clean plugin folder, packages it as both
      `heads-up-mailer-<version>.zip` (versioned, for humans)
      and `heads-up-mailer.zip` (byte-identical, stable name
      for the in-plugin updater), and attaches both to a GitHub
      Release via `softprops/action-gh-release@v1`.
- [x] `.distignore` — keeps repo-internal files (`.git`,
      `.github`, `dev-notes/`, `phpcs.xml`, `CLAUDE.md`, IDE
      noise) out of the release zip.
- [x] `includes/class-github-updater.php` — pattern lifted from
      quick-2fa. Hooks `pre_set_site_transient_update_plugins`,
      `plugins_api`, and `upgrader_process_complete`. Caches the
      latest-release payload in transient `hum_updater_release`
      (12-hour TTL). Filter `hum_updater_enabled` lets site
      owners pin / disable. Wired only on admin / cron contexts
      (`is_admin() || wp_doing_cron()`) to skip the init on
      front-end requests.
- [x] `Plugin::check_first_run()` extended to backfill new
      `get_default_settings()` entries on every version bump —
      idempotent, so admin-edited values are preserved.
- [x] Subscribers_Controller::update() partial-update bug fix
      (0.10.1): omitted fields now keep their existing values
      instead of being wiped to defaults. Caught by the M10
      smoke test damaging row 143's consent fields.

**Deliverable:** First release publishes via
`git tag v1.0.0 && git push origin v1.0.0`. Updater verified
live (404 on no-release-tagged-yet is the steady state and
falls through cleanly). Released as 0.10.0 / 0.10.1 on
2026-05-27.

---

## Milestone 13: Dashboard + per-group activity log

**Goal:** Turn the placeholder dashboard into a useful, privacy-first
overview that shows current state and alerts to problems — without
open / click tracking. Every figure derives from data the plugin
already owns.

**Status:** ✅ Complete — released 1.3.0 on 2026-06-17.
**Dependencies:** Sends / send_recipients schema (M5), subscriber
groups (M2).

### Tasks

- [x] `hum_events` table (DB_VERSION 2 → 3, auto-migrates via the
      existing `Plugin::check_first_run()` runner). Columns
      `event_type`, `subscriber_id`, `group_id`, `created_at`, with
      a `(group_id, event_type, created_at)` composite key.
- [x] `EVENT_GROUP_JOIN` / `EVENT_GROUP_LEAVE` recorded from the
      single membership choke-point, `Subscribers_Controller::set_groups()`,
      by diffing old vs new memberships. Captures every path (admin
      edit, public preferences, checkout opt-in,
      unsubscribe-everything); a no-op change writes nothing.
- [x] `Events_Controller` — append + per-group / total count queries
      over a UTC cut-off.
- [x] `Dashboard_Controller::get_overview()` — assembles the whole
      view-model so the template stays presentation-only.
- [x] Dashboard template rebuilt: send-health card (delivery success
      rate, newsletters sent / emails delivered / emails failed) with
      a red alert banner when the failure rate (over completed
      recipients) crosses `DASHBOARD_ALERT_FAIL_PCT` (5%); audience
      card (active subscribers + account unsubscribes in the window);
      per-group breakdown (active members, joins, leaves); recent
      send failures with error text.
- [x] Tuning constants: `DASHBOARD_WINDOW_DAYS` (30),
      `DASHBOARD_ALERT_FAIL_PCT` (5), `DASHBOARD_RECENT_LIMIT` (5).
- [x] Group "add" screen polish (same release): slug auto-generates
      from the name with an opt-in manual-override checkbox, full-width
      (`widefat`) name field, plain-text help on the description.
- [x] Verified end-to-end against the dev DB (migration, overview
      queries, set_groups event round-trip) and the alignment / label
      tweaks from the first dashboard screenshot review.

**Design decisions:**

- **Account unsubscribes vs group leaves.** One-click (RFC 8058) and
  IMAP unsubscribes flip subscriber *status* without touching group
  memberships, so they are NOT events — the dashboard reads those
  from `subscribers.status` / `unsubscribed_at`. Per-group
  `group_leave` events come only from explicit membership changes.
  The two metrics are shown separately and deliberately.
- **Per-group counts are forward-looking.** Existing memberships carry
  no recorded join date, so nothing is backfilled — Joined / Left
  start at zero on upgrade and accumulate from there. A `created_at`
  approximation was rejected as misleading (it would invent a spike
  on upgrade day).

**Deliverable:** Released 1.3.0 (`v1.3.0`) on 2026-06-17. Schema
migrates on first admin load post-upgrade, as verified on the dev
site. New admin UI strings are not yet translated — folded into the
[Translation polish](#translation-polish-native-speaker-pass) item
below, pending the `wp-translate-tool` review.

---

## Milestone 14: Autonomous draft → send (daily security email)

**Goal:** Let a trusted AI agent trigger a send end-to-end via REST,
without a human pressing Send — scoped so it can only ever reach
groups that have been explicitly, per-group, opted into automation.
The use case is a daily security email going to one small, managed
group; the design guarantees the agent can never autonomously email
the general customer list.

**Status:** ✅ Shipped 2026-06-20 — released as **1.5.0** (tagged,
pushed, release built) and **validated in production** with a real
end-to-end autonomous send via the daily-security agent. Schema,
gates, REST route, admin UI, docs, and translations all done. Master
switch ships OFF; it is now on for the managed security group only.
**Dependencies:** Send pipeline (M5), REST drafts API (M4 / 1.1.0),
groups (M2).
**Design source:** `dev-notes/04-autonomous-send-plan.md` (full
rationale + phased rollout). This milestone is the agreed v1 cut.

### Decisions (locked 2026-06-20)

- **Auth model:** one new capability `CAP_SEND_NEWSLETTERS`
  (`hum_send_newsletters`), granted to the **same Editor role** the
  agent already uses for `CAP_CREATE_DRAFTS`. One identity drafts and
  sends; the send right is a separate, independently-revocable cap.
  No dedicated service account in v1.
- **Two control layers only** (dangerous default is OFF for both):
  1. **Global setting** `hum_autonomous_send_enabled` (Settings API,
     one option, **default OFF**) — master enable for REST-triggered
     autonomous sending. Off ⇒ the route always refuses, independent
     of the capability, so autonomy is revocable instantly without
     touching roles.
  2. **Per-group `allow_automated_send` flag** (new column on
     `hum_groups`, **default 0**) — the primary, data-level gate.
     The route refuses unless **every** group the draft targets is
     flagged. One unflagged group in the set blocks the whole send.
- **No recipient ceiling, subscriber-count threshold, daily cap, or
  send-window enforcement in v1.** The small managed group plus the
  two gates above are sufficient. These remain documented in
  `04-autonomous-send-plan.md` as later options if the audience grows.
- **Route shape:** `POST /heads-up-mailer/v1/drafts/{id}/send` — a
  thin wrapper over `Sends_Controller::queue()`, which already does
  all the existing pre-flight.
- **Idempotency (machine-level, required):** the route refuses a
  draft already `sent` or `sending`. There is no machine "Send
  AGAIN" — that human confirm in the admin UI does not exist over
  REST. (Agent-supplied idempotency key is a deferred stretch, not
  v1.)
- **HTTP status map:**
  - `200` — queued; body returns the new `send_id`.
  - `403 Forbidden` — master setting OFF, **or** a targeted group is
    not automation-enabled. (Missing capability is handled by the
    `permission_callback` as usual.)
  - `409 Conflict` — draft already `sent` or `sending`.
  - `404` — draft not found.
  - `422` — `queue()` pre-flight failure (no groups, no recipients,
    From: not configured). Map the existing `WP_Error` codes.

### Tasks — schema & constants (DB_VERSION 3 → 4) ✅

- [x] `allow_automated_send tinyint(1) unsigned NOT NULL DEFAULT 0`
      column on `hum_groups`, alongside `is_private`. Auto-migrates
      via `Plugin::check_first_run()`. Verified on dev DB.
- [x] `is_automated tinyint(1) unsigned NOT NULL DEFAULT 0` column on
      `hum_sends`, so the Sent log can distinguish autonomous from
      human-pressed sends. Written in the same transaction as the send
      row (threaded through `queue()` → `commit_queue()` →
      `insert_send_atomic()` via a `bool $is_automated = false` param,
      so the human Send path is unchanged).
- [x] Bump `DB_VERSION` to `4`; constants `CAP_SEND_NEWSLETTERS`,
      `OPTION_AUTONOMOUS_SEND_ENABLED`, `DEF_AUTONOMOUS_SEND_ENABLED`.
      (No new `EVENT_*` type — see audit note below.)

### Tasks — capability ✅

- [x] `CAP_SEND_NEWSLETTERS` registered and granted to Administrator +
      Editor in `hum_ensure_caps()` (the same choke-point as
      `CAP_CREATE_DRAFTS`, fires on activation + version-change).
      Verified: editor + administrator have it, author does not;
      removable independently of `hum_create_drafts`.

### Tasks — global setting (admin) ✅

- [x] `hum_autonomous_send_enabled` registered via Settings API
      (boolean, `sanitize_boolean`, default OFF). Gate reads it with
      the `filter_var( … FILTER_VALIDATE_BOOLEAN )` idiom.
- [x] Surfaced on Settings → Sending under an "Autonomous sending"
      section with a warning that ON + per-group flags both required.

### Tasks — per-group flag (admin) ✅

- [x] "Allow autonomous send" checkbox on the group add/edit screen
      (hidden-0 sibling + checkbox), sanitized in `Groups_Controller::validate()`
      and read in `handle_save_group()`. Default unchecked.
- [x] Groups list shows an "· Auto-send" marker in the Visibility cell
      for automation-enabled groups.
- [x] **Drafting is unaffected** — `POST /drafts` and group assignment
      are untouched; the flag gates only the new send route.

### Tasks — REST send route ✅

- [x] `POST /drafts/{id}/send` registered in `REST_Controller`,
      `permission_callback` → `check_send_permission()` →
      `current_user_can( CAP_SEND_NEWSLETTERS )`.
- [x] Gates live in `Sends_Controller::autonomous_gate()`: master ON →
      draft not `sent`/`sending` (409) → draft targets groups →
      **every** group `allow_automated_send` (else 403, naming the
      blocked slugs) → then `queue( $id, true )`; `queue()` `WP_Error`s
      map to 422 (pre-flight) / 500 (insert faults) via
      `send_error_to_rest()`.
- [x] Each refusal returns a descriptive message identifying which
      gate failed / which group(s) blocked it. Gate matrix verified on
      dev DB (master-off 403, missing 404, already-sent 409, all-off
      403, partial-off 403, all-on ALLOW).

### Tasks — audit & observability ✅

- [x] **Audit approach (revised from the scoping note):** `hum_events`
      was rejected — its schema is per-group membership only
      (`event_type`/`subscriber_id`/`group_id`), and `record()` hard-
      rejects unknown types, so an autonomous-send trigger (draft_id,
      multiple groups, recipient count, result/reason) does not fit.
      Instead: every successful autonomous send is durably flagged
      in-DB via `hum_sends.is_automated`, and every trigger — queued
      **or** refused — is written to the PHP error log via
      `audit_autonomous_send()` (user, draft, outcome, reason/send_id).
      No new schema, security-relevant action still leaves a trail.
- [x] Sent log distinguishes agent-triggered sends via a new "Trigger"
      column (Auto / Manual), backed by `hum_sends.is_automated`.

### Tasks — docs & release

- [x] `phpcs` clean across all touched files; migration + gate matrix
      + `is_automated` write verified on the dev DB (queued then torn
      down before the worker could drain — no test email sent).
- [x] Documented: `docs/ai-agent-rest-guide.md` gains the
      `POST /drafts/{id}/send` endpoint, send-route error codes, the
      `hum_send_newsletters` cap, and a two-mode workflow; new
      `docs/autonomous-send-setup.md` is the host/admin guide to the
      two gates. `CHANGELOG.md`, `readme.txt`, and `README.md` all
      updated for 1.5.0.
- [x] Committed (`feat: autonomous draft->send via gated REST route`,
      `9f8af8a`) on `master`. Bundles the unreleased bulk-delete.
- [x] Tagged + pushed `v1.5.0` (2026-06-20) — release workflow built
      successfully; GitHub release published with
      `heads-up-mailer-1.5.0.zip` + the stable `heads-up-mailer.zip`.
      Translations regenerated in the same session (see the
      translation-polish note); native-speaker pass still pending.
- [x] **Validated in production** (2026-06-20) — the owner ran an
      end-to-end autonomous send via the daily-security agent and
      confirmed the workflow is good. Phase 0 soak is effectively
      satisfied for this flow; the master switch can stay on for the
      managed security group.

### Open items deliberately deferred (not v1)

- Agent-supplied idempotency key (status-based refusal covers v1).
- Recipient ceiling, subscriber-count threshold, daily cap,
  send-window enforcement, hold/abort window — all in
  `04-autonomous-send-plan.md` if the audience ever outgrows the
  two-gate model.
- Dedicated service account for the send right (revisit if the
  Editor-role grant proves too broad).

---

## Milestone 15: Groups REST API (agent-managed segments)

**Goal:** Let a trusted AI agent discover, create, and maintain
groups over REST, instead of group slugs being tribal knowledge the
agent has to be told out-of-band. Today `POST /drafts` accepts
`suggested_groups` as an array of slugs (`REST_Controller::draft_args()`)
but there is **no route to enumerate valid slugs** — a typo silently
yields a draft targeting nothing.

**Status:** 🔨 Built, tested, hardened and documented 2026-07-25
(43/43 checks pass, `phpcs` clean plugin-wide). Version bumped to
1.6.0. **Not yet committed or tagged.**

A full plugin security review was run before the version bump, rather
than after — REST routes are a heavily-targeted surface and this
milestone added five of them. Detailed findings live in the
maintainer's local notes (not in this repo); the fixes that came out of
it are described at an appropriate level in `CHANGELOG.md` under
1.6.0 → Security. Summary: no unauthenticated remotely-exploitable
vulnerability, no SQL injection, no missing capability or nonce check;
the substantive fixes were hardening controls that sat in the wrong
layer, plus file-exposure over HTTP.
**Dependencies:** REST drafts API (M4 / 1.1.0), groups (M2),
capability plumbing (M14 / 1.5.0).
**Target version:** 1.6.0 (new caps + `DB_VERSION` untouched — no
schema change needed).

### Decisions (proposed 2026-07-25)

- **Two new capabilities, split read from write** — see the
  reasoning in "Capability model" below.
- **Routes** (all under `heads-up-mailer/v1`):

  | Route | Method | Capability | Notes |
  | --- | --- | --- | --- |
  | `/groups` | GET | `hum_read_groups` | List all groups, incl. private |
  | `/groups` | POST | `hum_manage_groups` | Create; 409 on duplicate slug |
  | `/groups/(?P<id>\d+)` | GET | `hum_read_groups` | Single group |
  | `/groups/(?P<id>\d+)` | PATCH | `hum_manage_groups` | Partial update |
  | `/groups/(?P<id>\d+)` | DELETE | `hum_manage_groups` | **Refuses non-empty** |

- **ID-addressed, not slug-addressed**, for consistency with
  `/drafts/{id}`. Slugs are `sanitize_title()`-derived so a purely
  numeric slug (`2026`) is legal and would collide with a `\d+`
  path segment. The agent maps slug → id from `GET /groups`.
- **DELETE refuses a non-empty group** (the owner's requirement).
  "Non-empty" = **any** row in `hum_subscriber_groups` for that
  group id, regardless of subscriber status. Strictest reading, no
  surprises: a group holding only unsubscribed members still
  refuses, and the human clears it in admin. Returns `409` naming
  the member count.
- **The guard lives in the REST layer, NOT in
  `Groups_Controller::delete()`.** That method deliberately
  cascades (deletes junction rows, then the group) and backs the
  admin bulk-delete. Moving the guard down would change admin
  behaviour and break bulk-delete. Add a
  `Groups_Controller::count_members( int $id ): int` helper (no
  such helper exists today) and check it in the route callback.
- **🔒 Write routes MUST NOT accept `allow_automated_send`.**
  `Groups_Controller::validate()` currently reads that key from its
  input array. If a REST write route passed it through, an identity
  holding `hum_manage_groups` + `hum_send_newsletters` could flip
  the flag on any group and then autonomously mail it — collapsing
  M14's per-group gate into a single-actor privilege escalation.
  The per-group automation flag stays **admin-UI-only, human-set**.
  Allowlist the accepted fields explicitly (`slug`, `name`,
  `description`, `is_private`); do not spread the request body into
  `validate()`.
- **`is_private` is writable** over REST — it only controls
  visibility on `/manage-comms/`, carries no send authority, and an
  agent creating an internal/test group legitimately wants it.
- **Response shape** — per group: `id`, `slug`, `name`,
  `description`, `is_private`, `allow_automated_send`
  (**read-only**, so the agent can see why a send was refused),
  `member_count` (all membership rows), `subscribed_count` (rows
  where `hum_subscribers.status = subscribed`, i.e. what a send
  would actually reach). Two counts because they differ and the
  agent needs the deliverable one for reporting and the total one
  to understand a delete refusal.
- **HTTP status map:**
  - `200` — read / update OK. `201` — created.
  - `400` — invalid slug or missing name (map `hum_group_invalid_*`).
  - `404` — group id not found.
  - `409` — duplicate slug on create/update (`hum_group_exists`),
    **or** DELETE against a non-empty group.
  - `500` — insert/update/delete DB fault.
- **No pagination.** Groups are a handful of rows, ordered by name
  via the existing `get_all()`.

### Capability model

Reusing `hum_create_drafts` was rejected: every Editor that can
draft would silently gain the ability to create and destroy
segments on upgrade. M14 set the precedent — one independently
grantable/revocable cap per verb-class.

- **`CAP_READ_GROUPS` (`hum_read_groups`)** — granted to
  Administrator + Editor in `hum_ensure_caps()`, alongside the
  existing two. Closes the discovery gap immediately for the
  existing agent identity with **zero new blast radius**: it can
  already target groups, it just couldn't name them. Also lets a
  read-only reporting agent list segments without holding draft
  rights.
- **`CAP_MANAGE_GROUPS` (`hum_manage_groups`)** — granted to
  **Administrator only** on activation/first-run. The existing
  Editor-role agent does **not** gain mutation rights on upgrade;
  the owner opts in deliberately, per-user or by adding the cap to
  Editor. Mutation is the destructive half (a wrong DELETE drops
  membership rows) so it defaults closed, matching the
  dangerous-default-is-OFF stance from M14.

One cap for all three write verbs rather than three caps —
create/update/delete is one job ("maintain segments") and splitting
further buys granularity nobody has asked for.

### Tasks — constants & capability ✅

- [x] `CAP_READ_GROUPS`, `CAP_MANAGE_GROUPS` in `constants.php`
      with doc blocks (`@since 1.6.0`) explaining the split.
- [x] `hum_ensure_caps()` restructured from one `$caps` list applied
      to both roles into a `$caps_by_role` map, so
      `hum_manage_groups` can go to Administrator only. Verified:
      admin read+manage, editor read-only, author neither.
- [x] Caps land via `Plugin::check_first_run()` on both fresh
      activation and version-change (existing choke-point, unchanged).
      **Note:** because the version is deliberately *not* bumped yet,
      the dev site was granted the new caps by calling
      `hum_ensure_caps()` directly. The version bump is what makes
      this automatic for real installs.

### Tasks — controller ✅

- [x] `Groups_Controller::count_members( int $id ): int` — `COUNT(*)`
      on `hum_subscriber_groups`, status-blind (see the delete-guard
      decision above).
- [x] `Groups_Controller::member_counts(): array` — one batched
      query returning both `members` and `subscribed` per group,
      keyed by group ID. Replaces the planned pair of per-group
      helpers, so the list route costs one query rather than N+1.
      `subscribed` mirrors the join in
      `Sends_Controller::compute_recipient_ids()`.
- [x] `LEFT JOIN` (not `INNER`) in `member_counts()` so `members`
      counts raw junction rows and always agrees with
      `count_members()`. An inner join would hide an orphaned
      membership row, letting a group report zero members while the
      delete guard refused it.
- [x] PATCH merge confirmed necessary and implemented in the REST
      layer: `update()` does full-replace, so the route rebuilds all
      five fields from the existing row before overlaying the
      allowlisted changes. **Verified by test:** a PATCH omitting
      `allow_automated_send` leaves a human-set flag at 1.

### Tasks — REST routes ✅

- [x] Five route/method pairs registered in
      `REST_Controller::register_routes()`, reusing `id_arg()`.
      Confirmed advertised correctly in the namespace index.
- [x] `check_read_groups_permission()` /
      `check_manage_groups_permission()` alongside the existing two
      gates.
- [x] `group_args()` — explicit field allowlist with a "do not add
      `allow_automated_send`" security note in the doc block.
- [x] `serialize_group()` — both counts plus read-only
      `allow_automated_send`.
- [x] DELETE callback: 404 → 409 if `count_members() > 0` (with the
      count in both message and error data) → `delete()`.
- [x] `group_error_to_rest()` added rather than extending
      `wp_error_to_rest()`, which hard-codes 400 and is shared with
      the drafts routes. Maps the `hum_group_*` codes onto
      400 / 404 / 409 / 500.

### Delta found during testing (not in the original scope)

- [x] **Over-long input returned 500, not 400.** `slug varchar(100)`
      / `name varchar(255)` meant a long value was refused by MySQL
      and surfaced as an opaque `hum_group_insert_failed` (500) — a
      client error dressed as a server fault, and a raw `$wpdb`
      failure on the happy path of a valid-looking request. Fixed by
      adding `MAX_GROUP_SLUG_LENGTH` / `MAX_GROUP_NAME_LENGTH` /
      `MAX_GROUP_DESCRIPTION_LENGTH` (new `MAX_*` constant group) and
      enforcing them in `Groups_Controller::validate()` — the shared
      choke-point, so the admin UI gains the same guard. Slug length
      is checked in **bytes after sanitising**, because
      `sanitize_title()` percent-encodes non-ASCII and can grow a
      slug well past its input length.
- [x] Length failures use **distinct** error codes
      (`hum_group_slug_too_long` etc.) rather than reusing
      `hum_group_invalid_slug`, whose admin message ("A valid slug is
      required") would misdescribe a valid-but-too-long slug.
      `admin-templates/group-edit.php` gained matching messages.

### Tasks — verification ✅

- [x] `phpcs` clean across all five touched files.
- [x] 43-check matrix on the dev DB, 0 failures: editor read (200),
      anonymous read (401), missing group (404), editor write
      lockout (403 × POST/PATCH/DELETE), admin create (201),
      duplicate slug (409), unsanitisable slug (400), empty name
      (400), missing required field (400), PATCH preserving every
      omitted field, PATCH to a taken slug (409), PATCH missing
      (404), DELETE populated (409 + memberships intact), DELETE
      empty (200), DELETE already-deleted (404).
- [x] **Escalation test passed:** as Administrator (holding
      `hum_manage_groups` + `hum_send_newsletters`),
      `allow_automated_send` could not be set via POST (DB stayed 0)
      nor cleared via PATCH (DB stayed 1), and a PATCH omitting it
      preserved it.
- [x] Injection / robustness probe: `<script>` and `<img onerror>`
      stripped by `sanitize_text_field` / `sanitize_textarea_field`;
      SQL-ish slug and name stored inertly with the table intact
      (prepared statements); null bytes stripped; unicode slugs
      percent-encoded by `sanitize_title()` as core does.
- [x] Real-HTTP check: routes advertised in the namespace index;
      unauthenticated `GET` → 401, `DELETE` → 401, and
      `POST` with a valid body → 401 with nothing created.

### Note for the security audit

- Unauthenticated `POST /groups` **with no body** returns `400
  rest_missing_callback_param` (naming the missing params) rather
  than `401`. This is WP core's dispatch order — the required-param
  check runs before `permission_callback` — and it affects every
  route in the plugin including the pre-existing `POST /drafts`. The
  gate itself holds: with a valid body the same request is `401` and
  writes nothing. Flagged as a minor unauthenticated
  parameter-name disclosure, inherited from core, rather than
  something to "fix" by moving validation into the callback.
- A temporary `hum_test_editor` user (ID 60, Editor) was created on
  the dev site for the gate matrix. **Delete before release.**

### Tasks — docs & release

- [x] Version bumped 1.5.0 → 1.6.0 in `heads-up-mailer.php` (header +
      `HUM_VERSION`), `readme.txt` (`Stable tag`), and the `README.md`
      badge. `DB_VERSION` deliberately unchanged at 4 — no schema
      change in this milestone.
- [x] **Upgrade path verified for real**, not assumed: stripped both
      new caps, rolled the stamped version back to 1.5.0, ran
      `Plugin::check_first_run()`, and confirmed the version-change
      branch re-granted `hum_read_groups` to Administrator + Editor and
      `hum_manage_groups` to Administrator only, then stamped 1.6.0.
      `author` gained nothing.
- [x] `docs/ai-agent-rest-guide.md`: all five group endpoints with
      request/response shapes and `curl` examples, the two new caps in
      the auth section, a full group-error-code table, and
      `GET /groups` added as step 0 of the workflow. Also **corrected a
      pre-existing inaccuracy**: the guide claimed `html_body` was
      "sanitised via `wp_kses_post`", which it never was — it is stored
      verbatim by design so MJML output survives. That wrong sentence
      was the exact assumption behind one of the security findings, so
      it now states the real behaviour and what it implies for the
      agent credential.
- [x] `docs/autonomous-send-setup.md`: new section stating that
      `allow_automated_send` is unreachable over REST at any privilege
      level, why the split exists, and that granting
      `hum_manage_groups` therefore does not widen the autonomous-send
      blast radius.
- [x] `CHANGELOG.md` 1.6.0 entry (Added / Security / Changed / Fixed /
      Notes), absorbing the previously-unreleased groups-list nowrap
      change. Security items are described by impact class without
      publishing reproduction detail — the repo is public and 1.5.0
      installs exist in the wild.
- [x] `readme.txt` changelog + upgrade-notice entries, and the
      Description section updated for the groups API. `README.md`
      feature list plus a full route/capability table.
- [x] Docs verified against the implementation rather than written from
      memory: all 11 documented group error codes confirmed present in
      source, documented length limits confirmed against
      `MAX_GROUP_*_LENGTH`, and the example `GET /groups` payload
      confirmed byte-for-byte against a live response.
- [x] `wp-translate .` re-run — 40 strings across all eight locales,
      `.mo` files recompiled and verified loading at runtime.
      Plural-Forms headers survived intact (including `pl_PL`'s
      three-form rule), and `AUTH_KEY` / `wp-config.php` came through
      verbatim, which matters since that notice tells the reader which
      constant to edit.
- [x] Deleted the `hum_test_editor` dev user (ID 60); confirmed no
      plugin caps linger on `author` / `contributor` / `subscriber`.
- [x] **Browser check done (2026-07-25)** — `content-security-policy:
      sandbox` confirmed present on a live draft-preview response
      (HTTP 200, Firefox devtools). Could not be verified from the
      shell: application passwords authenticate REST/XML-RPC only, not
      `admin-post.php`, and a forged auth cookie 302s — so this needed
      a real browser session. The empty `sandbox` value applies the
      full restriction set, so scripts in agent-supplied draft HTML are
      inert even when the preview URL is opened as a top-level
      document.
- [x] Committed and tagged `v1.6.0`.

### Known-untranslated (deferred to the native-speaker pass)

- **`wp-translate` does not fill `msgid_plural` entries.** Confirmed by
  diffing against a pre-run backup: `%d subscribers deleted.` was
  already empty before this run, and the new
  `Group still has %d member(s)…` plural came back empty too. The
  plural *slots* are created correctly (three for `pl_PL`), just not
  populated. WordPress falls back to the English source, so the
  behaviour is graceful — non-English admins see English for those two
  messages. Two untranslated plurals now, up from one.
- **`wp-translate --dry-run` is unreliable** and cost a round of
  confusion here: it reported "Nothing new to translate" and
  "Would translate 0 strings" for all eight locales, then the real run
  translated five per locale. It also regenerated
  `languages/heads-up-mailer.pot` on disk while printing "No files will
  be modified" — verified by mtime and content. Do not trust the
  dry-run to decide whether a translation pass is needed.
- **Short technical terms still need a human eye.** `pl_PL` rendered
  "slug" as "Slag grupy", which is not the Polish term in any sense;
  every other locale sensibly kept it as a loanword
  (`Gruppen-Slug`, `slug del grupo`, `groepsslug`). `fr_FR` expanded the
  "Heads Up Mailer:" notice prefix into "Avertissement concernant le
  Mailer", inventing prose rather than treating it as a product name.
  Both are candidates for the tool's no-translate list.

### Deliberately out of scope

- Membership writes (adding/removing subscribers from a group over
  REST) — that is a subscribers-API milestone, not this one, and it
  is the higher-risk surface (consent semantics, `hum_events`
  instrumentation via `Subscribers_Controller::set_groups()`).
- Slug-addressed routes (`/groups/by-slug/{slug}`) — add only if
  the id round-trip proves annoying in practice.
- `allow_automated_send` over REST — see above. Not a "later",
  a "no".

---

## Milestone 16: WooCommerce paid-order enrolment + list scaling

**Goal:** Stop fake card-testing orders adding people to the mailing
list, and make the subscribers screen survive a growing list.

**Status:** ✅ Built + tested 2026-07-25, released as **1.7.0**.
**Trigger:** Found in production immediately after the 1.6.0 deploy —
card-testing bots were creating an order plus a WP user account, the
card was declined (or blocked by the owner's anti-fraud tooling), and
the subscriber row survived anyway.

### The bug

`on_order_processed()` was hooked to
`woocommerce_checkout_order_processed`, which fires when the order row
is *written* — before any payment is attempted. Enrolment therefore had
nothing to do with whether money moved.

### Fix

- Enrolment moved to `woocommerce_payment_complete` +
  `woocommerce_order_status_changed`, both funnelling into
  `maybe_enrol_paid_order()`, gated on `$order->is_paid()`.
  Two hooks because gateways differ: most call `payment_complete()`,
  but an admin marking a bank-transfer order paid only fires a status
  change.
- **`capture_opt_ins_on_order()` is untouched** — and that is what made
  this fix cheap. It already stashed the ticked slugs into order meta at
  checkout, because the checkbox state only exists during the checkout
  POST. Deferring enrolment therefore loses nothing.
- Idempotency via `META_WC_ENROLLED_AT`; both hooks can fire for one
  order.
- "Paid" = WooCommerce's `is_paid()` (`processing` or `completed`), not
  completed-only — for hosting, payment is captured at `processing` and
  an order may never be marked completed.
- Refund/chargeback deliberately does **not** un-enrol. Consent was
  given, payment happened; silent removal would be surprising.
- Order-meta keys promoted to `META_WC_*` constants. `_hum_opt_in_slugs`
  keeps its stored value so pre-1.7.0 orders still resolve.

**Bug caught during testing, worth remembering:** the WC integration
lives in `Heads_Up_Mailer\Integrations`, not `Heads_Up_Mailer`, and PHP
only falls back to the *global* namespace for unqualified constants — not
to a parent namespace. The new constants and `now_utc()` needed explicit
`use const` / `use function` imports, matching what the file already did
for the `OPTION_*` constants. Without them it was a fatal error on the
first status transition, which the end-to-end test surfaced immediately.

### Subscribers list

- **N+1 removed.** The Groups column called `get_groups()` per row —
  144 queries for 143 subscribers. Replaced with
  `group_ids_for_subscribers()`, one grouped query. **Measured: a
  25-row page render costs 5 queries total, vs 144 for the old
  whole-list approach.**
- **Pagination** at `DEF_SUBSCRIBERS_PER_PAGE` (25) via `get_page()` +
  `count_all()` and `paginate_links()`. Out-of-range `paged` is clamped,
  so deleting the last row on the final page can't land on an empty
  view. Bulk actions round-trip `paged` so you return where you were.
- Select-all relabelled **"Select all on this page"** — with pagination
  it only reaches rendered rows, and an admin could otherwise assume it
  covered the whole list.
- **WP-user dashicon** via `user_ids_for_emails()` — one bulk lookup for
  the page, never `get_user_by()` per row. Links to the user's profile
  (falls back to an unlinked badge when the viewer can't edit that
  user).

### Caveat on using the dashicon to find junk rows

Absence of the icon means "no WordPress user with this email" — which
is **also** true of every CSV-imported and public sign-up. On the dev
DB, 141 of 143 subscribers are unlinked. So unlinked alone does not
isolate bot rows. What makes the owner's approach work in practice is
that the list is ordered newest-first, so recent bot rows sit at the top
of page 1 where the icon plus the consent date identify them. If this
ever needs to be systematic, surface `consent_source` (already stored,
values like `woocommerce-checkout`) as a column — it is not currently
displayed in the list, only on the edit screen.

### Verification

- 13-check end-to-end WooCommerce test: pending / failed / cancelled
  enrol nobody; `processing` enrols into both the customers group and
  the ticked opt-in group; stamp unchanged across
  `processing → completed` and a `payment_complete` replay; `on-hold`
  bank transfer enrols only when marked paid.
- List test: 25 rows/page, 6 pages for 143 subscribers, total count
  rendered, out-of-range clamped, page 1 ≠ page 2, exactly 2 dashicons
  across all pages matching the 2 linked accounts.
- `phpcs` clean plugin-wide; SQL guard clean and still catching a
  deliberate probe.

---

## Planned: public sign-up hardening (double opt-in + rate limiting)

**Goal:** Make public, self-service sign-ups safe to expose on a
marketing page — protect both list quality (no unconfirmed addresses
get newsletters) and send reputation (no bot-triggered flood of
confirmation emails).

**Status:** 📋 Planned — not started. Design agreed 2026-06-17.

### Context: sign-up surfaces and their gates

There are only two ways a member of the public adds themselves:

1. **CF7 sign-up form** (`[hum_signup]` on a public page; see the
   curated agency / web-designer newsletter page). Untrusted —
   needs an anti-bot gate **and** double opt-in.
2. **WooCommerce checkout opt-in checkbox.** Making a payment IS the
   gate, and a completed order is consent — so this path needs
   **neither** a captcha nor double opt-in.

Admin add and CSV import are trusted (no gate, no confirmation).

### Key decision: the anti-bot gate lives at the CF7 layer

`[hum_signup]` processes on `wpcf7_before_send_mail`, which only
fires **after** CF7 validation passes. So an off-the-shelf CF7 spam
gate (Cloudflare Turnstile addon, a CF7 honeypot plugin, or CF7's
native reCAPTCHA v3) stops bots **before** heads-up-mailer ever sees
the submission — no pending row, no confirmation email. We do NOT
reimplement Turnstile in the plugin; that would duplicate a mature
layer. Plugin-side anti-bot code only becomes necessary if a
dedicated public shortcode / block is ever built (deferred), since
that would post straight to a plugin handler, bypassing CF7.

**Why double opt-in and the gate are both needed (not alternatives):**
double opt-in protects *list quality* — a forged / bot address never
becomes an active subscriber. But it does NOT stop the confirmation
email being *sent* to whatever (often forged, innocent) address was
submitted; a flood of those makes us the spam source. The gate
protects *send reputation* by stopping the submission upstream. Hence
**gate first, then double opt-in.**

### Tasks — plugin-side rate-limit backstop

- [ ] Per-IP rate limit on hum sign-ups / confirmation-email sends,
      reusing the existing `/manage-comms/` limiter
      (`RATE_LIMIT_MANAGE_PER_HOUR`, `within_rate_limit()`). Defence
      in depth behind the CF7 gate, so nothing slipping through can
      trigger a burst of confirmation mail.

### Tasks — double opt-in

- [ ] `STATUS_PENDING` ('pending') subscriber status. New public
      sign-ups land here, not `subscribed`; the send worker and the
      dashboard's active counts already filter `status = 'subscribed'`,
      so a pending row is inert for free.
- [ ] Stash the intended group IDs on the pending row
      (`pending_groups` column on `hum_subscribers`; DB_VERSION 4 → 5,
      auto-migrates via `check_first_run()`). Memberships and the
      dashboard `group_join` events are created only on confirmation,
      so `subscriber_groups` and the per-group stats reflect confirmed
      humans only — spam that never confirms leaves no trace.
- [ ] **One** confirmation email per sign-up, even for multiple
      groups — one click activates every chosen group together.
- [ ] Confirm link reuses the existing token (`{id}.{hmac}`) and the
      `/manage-comms/` endpoint with a new `action=confirm` branch:
      verify token → flip `pending → subscribed`, stamp `consent_at`
      at confirm time (a stronger consent record than single opt-in),
      activate memberships, render a "You're subscribed" page.
      Idempotent on a second click.
- [ ] Confirmation email — subject + body using the configured From
      identity; likely a configurable template alongside the footer
      setting.
- [ ] `hum_double_optin_enabled` setting (Settings API, one option per
      field). Gates the **public / CF7 path only**; WooCommerce
      checkout, admin add, and CSV import always bypass.
- [ ] Existing confirmed subscribers adding a new group (e.g. ticking
      another newsletter) do NOT re-confirm — only brand-new emails
      get the double opt-in.
- [ ] Cleanup cron purges `pending` rows older than ~7 days, so
      unconfirmed spam doesn't accumulate and a later genuine
      re-sign-up works cleanly.

### Sequencing

1. (Owner) Configure + test the corrected CF7 sign-up form on dev.
2. (Owner) Add the CF7-layer gate — Turnstile addon + honeypot, or
   CF7 reCAPTCHA — and verify bots bounce before submission.
3. (Plugin) Rate-limit backstop, then the double opt-in feature set
   above. Versioned as a minor release with the DB_VERSION 5
   migration (4 is taken by Milestone 14, which ships first).

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
v1. Now the natural next observability step after the 1.3.0
dashboard: a bounce-mailbox IMAP poller (modelled on the existing
`Mailbox_Poller`) would feed a bounce stats card on the dashboard,
which currently has no bounce figure because nothing ingests them
yet.

### WooCommerce Block checkout support

The M11 WC integration uses `woocommerce_after_checkout_billing_form`
to render per-group opt-in checkboxes — a classic-checkout hook
that doesn't fire under the React-based Block checkout introduced
in WC 8.x. Block support is a separate code path:

- Register opt-in fields via `woocommerce_register_additional_checkout_field()`
  OR implement an Integration via `Automattic\WooCommerce\Blocks\Package::container()`.
- Separate field-types — the classic markup is plain HTML; Block
  fields are React/JSON-described.
- Separate read path for submitted values (the order meta capture
  hook may still work, but the field declarations and rendering
  are independent).

Roughly half a day on its own. The classic-checkout path
continues to work fully; admins on Block checkout will see only
the auto-add-to-customers-group flow (which is order-processed
hook driven and Blocks-compatible) until this lands.

### Re-queue failed recipient

M8 deferred — the sent log is read-only. To retry a single failed
recipient today, an admin can re-send the parent draft to a
one-person group as the manual workaround. A proper "Re-queue
this row" button writes a fresh `send_id` with just that
recipient, keeping the `UNIQUE(send_id, subscriber_id)` story
clean.

### Translation polish (native-speaker pass)

Shipped 1.2.1 bundles `languages/` catalogues for eight locales
(de_DE, el_GR, en_GB, es_ES, fr_FR, it_IT, nl_NL, pl_PL),
machine-kickstarted with `wp-translate-tool`. The tool is weaker on
short strings than long ones, so the short admin UI labels are the
likeliest to read awkwardly. Before any non-English locale goes
live, get a native-speaker pass over the short labels. The plural
strings already have correct `Plural-Forms` headers (fixed in
1.2.1), so this is wording polish only, not a structural fix.

1.2.2 hand-corrected a batch of DeepL short-string artifacts across
all eight locales (e.g. "Sent" → "late", "Folder" → "brochure",
acronym expansion); a seed glossary for the upstream fix is filed in
the `wp-translate-tool` repo.

**1.5.0 catalogue regen (2026-06-20):** ran `wp-translate .`, which
cleared the standing backlog — the ~two dozen 1.3.0 dashboard /
group-screen strings *and* the new 1.5.0 autonomous-send strings are
now machine-translated across all eight locales (40 strings/locale,
`.mo` recompiled). Two follow-ups remain for the native-speaker pass:
- **Short labels** still want a human eye. The new ones use `_x()`
  context (`Trigger`, `Auto`, `Manual`, `Auto-send`), which fixed
  `Manual` → "Manuell"/"Manuel" — but `Auto` came back literally
  (German "Auto" also = *car*); fine next to "Manuell", worth a check.
- **One untranslated plural**: `%d subscriber(s) deleted.` (the
  bulk-delete notice) came through empty in every locale — the tool
  doesn't translate new plural forms. English fallback until the
  native pass fills it (mirror the existing `%d subscriber(s) flagged
  as "never contact".` plural, which is already translated with the
  right 2-/3-form cases).

### Other v1 exclusions

See `dev-notes/01-requirements.md` "Out of scope". Open / click
tracking, drip campaigns, automations, A/B, scheduling-for-later,
multi-site, built-in SMTP, CAPTCHA on unsubscribe.
