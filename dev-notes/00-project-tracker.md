# Project Tracker — heads-up-mailer

**Status:** M1 complete; M2 ready to start
**Current Version:** 0.1.0
**Current Phase:** M2 — Subscribers and Groups
**Last Updated:** 19 May 2026
**Progress:** 1 of 9 milestones complete (3 deferred for v1.0.0+)

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

**Status:** 🔄 In progress (groups foundation done; admin UI next)
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
      (search by email, filter by group, filter by status — see
      chunk 4 below)
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
export. MailerLite subscription can be cancelled.

---

## Milestone 3: Settings page

**Goal:** Configurable batch size, tick interval, and IMAP mailbox
credentials (encrypted at rest). Connection-test button.

**Status:** 📋 Not started
**Dependencies:** M1

### Tasks

#### Framework

- [ ] `Settings` class instantiated early (before `admin_init`)
- [ ] Hash-based tab navigation in
      `admin-templates/settings-page.php`
- [ ] Capability check `manage_options` on every handler

#### Queue tab

- [ ] Batch size (default 10, range 1–100)
- [ ] Tick interval in minutes (default 5, range 1–60)
- [ ] Re-schedule the cron event when interval changes

#### Mailbox tab

- [ ] Host
- [ ] Port (default 993)
- [ ] Username (default `unsub@headwall-hosting.com`)
- [ ] Password (encrypted on save)
- [ ] Folder name (default `INBOX`)
- [ ] Polling interval (minutes)
- [ ] TLS toggle

#### Encryption helper (`includes/class-crypto.php`)

- [ ] `sodium_crypto_secretbox` wrapper
- [ ] Key derived from `AUTH_KEY` + plugin-specific salt via
      `hash_hkdf` or `sodium_crypto_generichash`
- [ ] Encrypt on save, decrypt on read inside the poller only

#### Sanitize callbacks

- [ ] Per-setting `sanitize_callback` via the Settings API
- [ ] Password field never round-trips plain text to the form —
      render as `••••••••` placeholder, only update if a new value
      is submitted

#### Test-connection AJAX

- [ ] Endpoint `hum_test_mailbox` (nonce + capability)
- [ ] Open IMAP, list folder, report success / error
- [ ] Does not persist credentials again — operates on submitted
      form state

**Deliverable:** Admin can configure send cadence and mailbox
credentials. Test-connection button verifies IMAP login.

---

## Milestone 4: Drafts

**Goal:** AI agent posts drafts via authenticated REST. Admin
lists, edits, previews, and selects target group(s).

**Status:** 📋 Not started
**Dependencies:** M1, M2

### Tasks

#### REST API

- [ ] Register namespace `heads-up-mailer/v1` on `rest_api_init`
- [ ] `POST /drafts` — accepts `subject`, `html_body`,
      `suggested_groups` (array of group slugs). Application-password
      auth. Returns draft ID.
- [ ] `GET /drafts/{id}` — returns the stored draft. Same auth.
- [ ] Validation: `subject` ≤ 200 chars, `html_body` non-empty,
      `suggested_groups` resolved to known group IDs
- [ ] Smoke test via `curl` from the AI agent host

#### Admin UI

- [ ] Drafts list table (id, subject, created, status)
- [ ] Edit form: subject input, raw HTML textarea (TinyMCE optional
      later), group picker (multi-select)
- [ ] iframe preview rendering the HTML body
- [ ] "Send" button → triggers M5 queue insertion

**Deliverable:** AI agent can POST a draft. Admin can review and
mark it ready to send.

---

## Milestone 5: Send pipeline

**Goal:** Sends are queued in one transaction and drained
asynchronously. Every outbound carries the required headers and a
plain-text alternative.

**Status:** 📋 Not started
**Dependencies:** M2, M3, M4

### Tasks

#### Queueing

- [ ] Admin "Send" handler — nonce + capability check
- [ ] Single transaction: insert `hum_sends` row + N
      `hum_send_recipients` rows (status `pending`)
- [ ] Skip subscribers whose status is not `subscribed`
- [ ] Return immediately to admin with success notice + sent-log
      link

#### Worker

- [ ] Custom WP-Cron interval `hum_tick` (configurable, default 5
      minutes) registered via `cron_schedules` filter
- [ ] Worker hook `hum_drain_queue`:
  - [ ] Acquire transient lock `hum_drain_lock` to prevent
        overlapping ticks
  - [ ] Pull up to N pending rows ordered by ID
  - [ ] Wall-clock budget: bail after ~25 s regardless of remaining
        rows
  - [ ] Per-recipient: build `$headers`, build `$body`, call
        `wp_mail()`, update row status atomically
  - [ ] Failures logged in `last_error`; `attempts` incremented
- [ ] `phpmailer_init` action to attach plain-text alternative

#### Headers and footer

- [ ] `List-Unsubscribe: <mailto:unsub@...?subject=unsubscribe-{token}>, <https://.../manage-comms/?token=...&action=unsubscribe>`
- [ ] `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
- [ ] `List-ID: <heads-up-mailer.headwall-hosting.com>`
- [ ] `Precedence: bulk`
- [ ] HTML footer injected with unsubscribe link
- [ ] Plain-text alternative auto-generated from HTML

**Deliverable:** Drafts can be sent. Send completes within a few
ticks. Sent rows show in `hum_send_recipients` with timestamps.

---

## Milestone 6: Public /manage-comms/ endpoint

**Goal:** Recipients can manage preferences and one-click
unsubscribe via signed tokens.

**Status:** 📋 Not started
**Dependencies:** M2

### Tasks

- [ ] Rewrite rule for `/manage-comms/` → handler
- [ ] Token parser: split on `.`, validate `subscriber_id` integer,
      verify `HMAC-SHA256(subscriber_id, token_salt)`
- [ ] **GET handler** — render preference page
      (`public-templates/manage-comms.php`)
  - [ ] Per-group checkboxes reflecting current memberships
  - [ ] "Unsubscribe all" checkbox
  - [ ] CSRF nonce (separate from access token) on the form
- [ ] **POST handler — preferences** — update group memberships,
      re-render with confirmation
- [ ] **POST handler — `action=unsubscribe`** (one-click)
  - [ ] Flip status to `unsubscribed`, stamp `unsubscribed_at`
  - [ ] Return 200 + plain-text "thanks" (no confirmation page)
  - [ ] Idempotent — re-POST does nothing
- [ ] Throttle by token (transient counter) — 20 requests / hour
      per token

**Deliverable:** Recipients can self-manage. Gmail's one-click
button works.

---

## Milestone 7: Mailbox poller (mailto unsubscribes)

**Goal:** Inbound mails to `unsub@…` are parsed and translated into
status flips.

**Status:** 📋 Not started
**Dependencies:** M3 (IMAP creds), M6 (token validation)

### Tasks

- [ ] WP-Cron job `hum_poll_mailbox` (interval from settings)
- [ ] IMAP connection helper using `imap_open` (PHP `ext-imap`)
- [ ] Fetch unread messages from the configured folder
- [ ] Subject parser: regex `^unsubscribe-([A-Za-z0-9._-]+)$`
- [ ] Re-use M6 token validator
- [ ] Flip status, stamp `unsubscribed_at`
- [ ] Move processed messages to `Processed` subfolder (create if
      missing); failures move to `Errors`
- [ ] Connection error handling — surface as admin notice if the
      poller can't connect for > 1 hour

**Deliverable:** Replying to a list email with the mailto: form
unsubscribes the recipient.

---

## Milestone 8: Sent log UI

**Goal:** Admin can review what was sent, to whom, and what
failed.

**Status:** 📋 Not started
**Dependencies:** M5

### Tasks

- [ ] Sent-log list table (`hum_sends` rows with counters)
- [ ] Per-send drill-down (`hum_send_recipients` filtered by
      `send_id`)
- [ ] Status filters (sent / failed / pending)
- [ ] Re-queue button for individual `failed` rows — TBD whether to
      model this (new `send_id` keeps the UNIQUE constraint clean)

**Deliverable:** Visibility into past sends.

---

## Milestone 9: Soak test

**Goal:** Verify headers and one-click POST in real-world
mailboxes before declaring v1.

**Status:** 📋 Not started
**Dependencies:** M1–M7

### Tasks

- [ ] Test group with 3 addresses: a Gmail, an Outlook.com, a
      Fastmail
- [ ] Send a sample newsletter
- [ ] Verify in each client:
  - [ ] List-Unsubscribe button appears
  - [ ] One-click POST succeeds (status flips)
  - [ ] mailto-form fallback works (poller picks up the inbound)
  - [ ] Footer link works for opted-out reactivation
- [ ] Verify `Precedence: bulk` is honoured (Gmail tabbing)
- [ ] Verify HTML + plain-text render correctly in each client
- [ ] Confirm SPF / DKIM / DMARC alignment on
      `nexus.headwall.co.uk` — out of plugin scope but blocking
      for the soak

**Deliverable:** v1 release candidate.

---

## Deferred (post-v1)

### GitHub auto-updater

Move repo from private Gitea to a Headwall GitHub repo near
v1.0.0. Add `class-github-updater.php` (pattern lifted from
quick-2fa, loaded admin + cron only). Self-update from GitHub
release tags.

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
