# Style guide review

Read of:

- `.github/copilot-instructions.md`
- `dev-notes/patterns/*.md`
- `dev-notes/workflows/code-standards.md`
- `dev-notes/workflows/commit-to-git.md`

These are the portable Headwall WordPress plugin standards. This
doc records what's been absorbed (will follow), what's settled for
this plugin, and the tensions to resolve before coding starts.

## Conventions absorbed (will follow unless flagged in §Tensions)

### Layout

- `heads-up-mailer.php` — bootstrap
- `constants.php` — all `DEF_*` / `OPT_*` constants (no magic values)
- `functions-private.php` — internal helpers (e.g.,
  `get_plugin_instance()`)
- `phpcs.xml` — code-standards config
- `includes/` — `class-*.php`, namespace `Heads_Up_Mailer`
- `admin-templates/` — admin templates (code-first printf/echo)
- `templates/` — public templates (code-first printf/echo)
- `assets/admin/` and `assets/public/`
- `dev-notes/` — design + working notes (this directory)
- `languages/` — translations

### PHP

- PHP 8.0+, type hints, return types
- **No** `declare(strict_types=1)`
- Namespace `Heads_Up_Mailer`; class files `class-{name}.php`
- Single-entry single-exit (SESE) — one `return` at end of function
- Empty/no-op branches annotated `// no action: …`
- `filter_var(..., FILTER_VALIDATE_BOOLEAN)` for boolean options
- `WP_Error` for fallible operations
- Text domain: `heads-up-mailer`

### Templates

- **No inline HTML in functions or templates.** Every template emits
  via `printf()` / `echo`. Admin pages will be code-first throughout.

### Security

- Sanitize on input (`absint`, `sanitize_email`,
  `sanitize_text_field` + `wp_unslash`)
- Escape on output (`esc_html`, `esc_url`, `esc_attr`)
- Nonces on every form; capability checks before privileged actions
- `$wpdb->prepare` for every dynamic query

### WordPress integration

- Hook registration centralised in `Plugin::run()`
- Lazy-loaded hook classes via `get_*` accessors; **Settings**
  instantiated early (before `admin_init`)
- Global `$heads_up_mailer_instance` + `get_plugin_instance()` helper
- AJAX: `check_ajax_referer` + capability check + `wp_send_json_*`
- Custom tables: versioned `DB_VERSION`, `dbDelta`, prefix
  `$wpdb->prefix . 'hum_'`

### JavaScript

- Vanilla JS, modern `fetch` for AJAX
- Class-based selectors (no IDs except unique admin elements)
- Container-scoped initialization; delegated events for dynamic
  content
- No inline JS — all in `assets/admin/*.js` via `wp_enqueue_script`
- Every `<button>` carries the `button` class

### Admin UI tabs

For the multi-section admin page (drafts, subscribers, groups,
sends, settings):

- Hash-based `#tab-name` navigation
- WordPress `.nav-tab` styling
- Panel show/hide via JS; deep-link-friendly

### Code standards + git

- phpcs / phpcbf with WordPress ruleset
- PHPCS prefixes for `PrefixAllGlobals`: `heads_up_mailer`, `hum`,
  `Heads_Up_Mailer`
- Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`,
  `docs:`, `style:`, `test:`) — 50-char subject + bullet body
- Run phpcs before every commit; optional pre-commit hook installable
  per `dev-notes/workflows/commit-to-git.md`

### Not relevant to this plugin

- WooCommerce / HPOS patterns — newsletter doesn't touch WC.
  `dev-notes/patterns/woocommerce.md` stays as portable reference.
- `pwpl/` licence controller — not present unless we choose to ship
  this plugin commercially. Assuming internal-only for v1.

## Patterns observed in `quick-2fa` (reference plugin)

Read of:

- `quick-2fa.php`, `constants.php`, `functions-private.php`,
  `includes/class-plugin.php`

Real-world conventions that refine or contradict the written
guide:

### Adopt verbatim

- **Bootstrap shape**: top-level `define()` for `*_VERSION`,
  `*_FILE`, `*_PATH`, `*_URL`, `*_BASENAME`; explicit
  `require_once` per class file (no autoloader); WP-CLI and
  GitHub-updater loads behind conditionals; bootstrap fn at the
  end (`heads_up_mailer_run()`).
- **Activation + first-run double-register**: defaults installed
  in `register_activation_hook` AND `Plugin::check_first_run()`
  for MU plugin compatibility (activation hooks don't fire under
  MU).
- **Plugin instance via `global $hum_plugin`**: follow the
  guide's global-variable pattern. quick-2fa currently uses a
  singleton with `Plugin::instance()`, but the user will update
  Quick 2FA to match the global-variable pattern.
  **Follow the guide, not the code.**
- **Constants prefixes**: `OPTION_*`, `META_*`, `MODE_*`,
  `RATE_LIMIT_*`, `TRANSIENT_*`, `LOG_*`, `DEF_*`. The written
  guide says `DEF_*`; quick-2fa uses `DEFAULT_*`. **User prefers
  `DEF_*` — follow the guide.**
- **Naming-collapse for prefixes**: options use collapsed slug
  (`quick2fa_mode`); transients use initials (`q2fa_*`); user
  meta uses underscore + collapsed slug (`_quick2fa_*`). For us:
  lean **`hum_` everywhere** (options `hum_*`, transients
  `hum_*`, meta `_hum_*`) for brevity and PHPCS-prefix
  alignment.
- **Lazy-init via global cache**: e.g., `get_default_settings()`
  uses `global $quick_2fa_default_settings` to memoize.
- **PHPCS inline suppression** with
  `// phpcs:ignore ... -- reason` is heavily used. The reason
  after `--` is mandatory.

### Code deviates from the written guide

- **SESE pragmatism**: guide says "single return at end of every
  function." Code uses guard-clause early returns freely in
  validation-style functions and SESE only in value-computing
  functions. **Resolved (user):** validate-and-bail guards at
  the **top** of a function are fine. **No** mid-function
  returns. **No** returns nested inside loops. The function body
  converges to a single `return` at the bottom.
- **Templates directory**: quick-2fa uses `views/` with direct
  `require`. Written guide says `admin-templates/` +
  `templates/` with override-aware loader. T5 (your call)
  settles us on `admin-templates/`.
- **Date storage**: guide says "human-readable strings, not Unix
  timestamps." Code uses Unix timestamps freely
  (`META_CODE_TIMESTAMP`, `META_LOCKED_UNTIL = time() + N`).
  Pragmatic: timestamps for comparison-only state,
  human-readable for "you'll need to read this in SQL." T1
  (your call) settles the policy for our DATETIME columns.

### Optional patterns worth a yes/no

- **GitHub auto-updater** (`class-github-updater.php`, loaded in
  admin + cron only). Should heads-up-mailer self-update from a
  Headwall GitHub repo, or do you install/update by other means?
- **WP-CLI commands**. Useful candidates for v1: drain the queue
  manually (`wp heads-up-mailer drain`), send a test message
  (`... test-send <email>`), import CSV (`... import <file>`),
  rotate a subscriber's `token_salt` (`... rotate-token <id>`).
  Any of these worth shipping in v1?

## Decisions (T1–T6, resolved 2026-05-19)

### T1. Date/time storage

**Resolved:** store as `Y-m-d H:i:s T` strings in site timezone
for human-readability via manual SQL. Send-window logic
evaluates in site timezone (e.g., 08:00–18:00 Mon-Fri, site
default tz).

Caveat: site-tz strings break lexical `ORDER BY` twice a year
on DST transitions (`...GMT` sorts before `...BST`
alphabetically). Listed as remaining sub-question below —
switching to `Y-m-d H:i:s UTC` storage with format-on-read
keeps readability and fixes ordering.

### T2. Settings storage

**Resolved:** Settings API, one option per field. Each setting
registered via `register_setting()` with its own sanitize
callback.

### T3. Mailbox password encryption

**Resolved:** encrypted at rest.
`sodium_crypto_secretbox` with key derived from `AUTH_KEY`
(HKDF-style, plugin-specific salt). Decrypt only inside the
IMAP-poll worker.

### T4. Composer

**Resolved:** no Composer, no `composer.json` in repo. `phpcs`
and `phpcbf` are installed globally on the host.

### T5. Settings page structure

**Resolved:** custom forms per tab. Admin pages live in
`admin-templates/`, code-first PHP (`printf` / `echo`, no
inline HTML).

### T6. Stale docs

**Resolved (sequenced):** once decisions in `dev-notes/` are
settled, build a project-specific `CLAUDE.md` distilling the
applicable conventions, then delete
`.github/copilot-instructions.md` and the project-agnostic
content in `dev-notes/patterns/` and `dev-notes/workflows/`.
Keep `dev-notes/01-*`, `02-*`, `03-*` as the living design
record.

## Final resolutions (2026-05-19)

- **T1 sub — DST**: store as `Y-m-d H:i:s UTC` (always UTC).
  Format on read into site timezone for display. Sorts cleanly,
  stays human-readable.
- **SESE**: validate-and-bail guards at the **top** are fine.
  **No** mid-function returns. **No** returns nested in loops.
  Body converges to a single `return` at the bottom.
- **Prefixes**:
  - Top-level constants: `HUM_*` (e.g., `HUM_VERSION`,
    `HUM_PATH`).
  - Variables and globals: `hum_*`.
  - Root-namespace bootstrap functions (in `heads-up-mailer.php`):
    `hum_*` (e.g., `hum_activate`, `hum_plugin_run`).
  - Namespaced functions in `functions-private.php` and
    `includes/`: **no prefix** — the `Heads_Up_Mailer` namespace
    handles it (e.g., `get_plugin`, `get_default_settings`,
    `now_utc`).
  - Namespaced public helpers in `functions.php` (if added later):
    `hum_*` to make their public-API role visible at call sites.
  - Namespaced PHP constants: `OPTION_*`, `META_*`, `DEF_*`,
    `MODE_*`, `TRANSIENT_*`, `LOG_*`, `RATE_LIMIT_*` (no extra
    prefix — namespace handles it).
  - Option string keys: `hum_*` (e.g., `OPTION_BATCH_SIZE = 'hum_batch_size'`).
  - User meta string keys: `_hum_*`.
  - Transient prefixes: `hum_*`.
  - DB table names: `{$wpdb->prefix}hum_*`.
  - Namespace: `Heads_Up_Mailer`.
  - PHPCS `PrefixAllGlobals`: `Heads_Up_Mailer`, `HUM`, `hum`.
- **Plugin instance**: `global $hum_plugin;` set during bootstrap.
  No singleton.
- **Defaults constant prefix**: `DEF_*` (e.g., `DEF_BATCH_SIZE`).
- **GitHub auto-updater**: deferred to v1.0.0 milestone.
  Currently the repo lives on a private Gitea instance.
- **WP-CLI commands**: deferred to a milestone in
  `00-project-tracker.md`.
- **Templates**: `admin-templates/` for admin pages,
  `public-templates/` for the public-facing `/manage-comms/`
  endpoint.
