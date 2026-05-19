# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
