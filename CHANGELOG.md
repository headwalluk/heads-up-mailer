# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
