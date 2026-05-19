# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  - `includes/class-plugin.php` with `run()` hook registration
    and `check_first_run()` for MU plugin safety.
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
