# CLAUDE.md — heads-up-mailer

Project-specific conventions and pointers for AI-assisted work on
this plugin. Read this first.

## What this plugin does

Lightweight in-house newsletter sender to replace MailerLite for
headwall-hosting.com. An AI agent drafts weekly newsletters via
REST, the admin reviews/edits in WP admin, and the plugin sends to
segmented customer groups via `wp_mail()` with async queueing,
RFC-8058 one-click unsubscribe, and IMAP-poll harvesting of
mailto-form unsubscribes.

Single-site, internal. No open/click tracking, no built-in SMTP,
in-plugin GitHub auto-updater (since 0.10.0).

## Where to look

| File                                 | Contents                                |
| ------------------------------------ | --------------------------------------- |
| `dev-notes/00-project-tracker.md`    | Milestones, tasks, current progress     |
| `dev-notes/01-requirements.md`       | v1 requirements distilled from handoff  |
| `dev-notes/02-design-notes.md`       | Pre-build Q&A, decisions, scope deltas  |
| `dev-notes/03-style-guide-review.md` | Conventions + tensions resolved         |

The original handoff lives at
`/home/pfaulkner/Projects/hosting/headwall-newsletter/HANDOFF.md`.
Treat it as historical context — `dev-notes/` is authoritative.

## File layout

```
heads-up-mailer/
├── heads-up-mailer.php       # bootstrap: defines, requires, activation
├── constants.php             # namespaced constants
├── functions-private.php     # internal helpers
├── phpcs.xml                 # WordPress ruleset + HUM prefixes
├── includes/                 # class-*.php (namespace Heads_Up_Mailer)
├── admin-templates/          # admin pages, code-first PHP
├── public-templates/         # /manage-comms/ pages, code-first PHP
├── assets/
│   ├── admin/                # admin CSS/JS
│   └── public/               # public CSS/JS
├── languages/                # .pot, .mo, .po
├── docs/                     # end-user docs (admins, editors, hosts)
└── dev-notes/                # design + tracker (build-time only)
```

## Prefixes and naming

| What                                       | Prefix                          | Example                                  |
| ------------------------------------------ | ------------------------------- | ---------------------------------------- |
| Root-level PHP constants                   | `HUM_*`                         | `HUM_VERSION`, `HUM_PATH`                |
| Namespaced PHP constants                   | typed (see below)               | `OPTION_BATCH_SIZE`                      |
| Root-namespace functions (bootstrap)       | `hum_*`                         | `hum_activate()`, `hum_plugin_run()`     |
| Namespaced functions (`functions-private.php`, `includes/`) | none — namespace handles it | `get_plugin()`, `get_default_settings()` |
| Namespaced public helpers (`functions.php`, if added) | `hum_*`              | `hum_render_unsubscribe_link()`          |
| Variables and globals                      | `hum_*`                         | `$hum_plugin`, `$hum_default_settings`   |
| `wp_options` keys (string values)          | `hum_*`                         | `'hum_batch_size'`                       |
| User meta keys (string values)             | `_hum_*`                        | `'_hum_consent_at'`                      |
| Transient prefixes                         | `hum_*`                         | `'hum_imap_lock'`                        |
| DB tables (after `$wpdb->prefix`)          | `hum_*`                         | `wp_hum_subscribers`                     |
| PHP namespace                              | `Heads_Up_Mailer`               |                                          |
| Text domain                                | `heads-up-mailer`               |                                          |
| phpcs `PrefixAllGlobals`                   | `Heads_Up_Mailer`, `HUM`, `hum` |                                          |

Namespaced constant groups (no extra prefix — namespace handles it):
`OPTION_*`, `META_*`, `DEF_*`, `MODE_*`, `TRANSIENT_*`, `LOG_*`,
`RATE_LIMIT_*`.

## PHP rules

- PHP 8.0+. Type hints and return types everywhere.
- **No** `declare(strict_types=1);` — breaks WordPress hook
  signatures.
- **Plugin instance**: `global $hum_plugin;` is set during
  bootstrap. Read it everywhere else via the canonical accessor
  `Heads_Up_Mailer\get_plugin()` — don't touch the global
  directly. No singleton, no static `instance()` method.
- **SESE (modified)**: validate-and-bail guards at the **top** of
  a function are fine. **No** mid-function returns. **No** returns
  nested inside loops. Body converges to a single `return` at the
  bottom.
- **Empty / no-op branches** must carry a comment: `// no action: …`
  with the reason.
- **No magic strings/numbers** — everything in `constants.php`.
  Exception: translatable strings via `__()` etc.
- **Boolean options**: read via
  `filter_var( get_option( OPT_X, false ), FILTER_VALIDATE_BOOLEAN )`
  to handle `'1'`, `'yes'`, `'on'`, `true`, etc.

## Templates and HTML

- Admin templates in `admin-templates/`, public templates in
  `public-templates/`.
- **Code-first PHP only.** Use `printf()` / `echo`. Do not mix
  inline HTML with `<?php ?>` snippets inside templates.
- Sanitize on input, escape on output (`esc_html`, `esc_url`,
  `esc_attr`).

## JavaScript

- Vanilla JS, modern `fetch`. No jQuery for new code.
- Class-based selectors. No IDs except unique admin elements.
- No inline JS — everything via `wp_enqueue_script` from
  `assets/admin/` or `assets/public/`.
- Every `<button>` carries the `button` CSS class.

## WordPress patterns

- **Hook registration** is centralised in `Plugin::run()`.
- **Activation + first-run double-register**:
  `register_activation_hook` installs defaults;
  `Plugin::check_first_run()` does the same on `admin_init` for MU
  plugin installs.
- **AJAX**: `check_ajax_referer` + `current_user_can` + sanitize +
  `wp_send_json_success` / `wp_send_json_error`.
- **DB**: `dbDelta` for schema, `DB_VERSION` constant in
  `constants.php`, version stored in `wp_options`, migrations run
  on activation and on `admin_init` if the stamped version differs.
- **Lazy-init caching** uses a `global $hum_<name>;` per cache,
  e.g. `function hum_get_default_settings(): array { global $hum_default_settings; ... return $hum_default_settings; }`.

## Decisions baked into v1

- **Async send pipeline**: admin POST writes `hum_sends` +
  `hum_send_recipients` rows (status `pending`) in one transaction
  and returns immediately. A WP-Cron worker drains in configurable
  batches (default 10 every 5 minutes) with a wall-clock budget per
  tick.
- **Datetimes in custom tables**: store as `Y-m-d H:i:s UTC`
  strings (always UTC, sorts lexically). Format on read for
  display in the site timezone.
- **Send-window logic** evaluates in the site timezone (e.g.
  08:00–18:00 Mon–Fri).
- **Settings storage**: Settings API, **one option per field**.
  Each registered via `register_setting()` with its own
  `sanitize_callback`.
- **Mailbox credentials (IMAP)**: encrypted at rest via
  `sodium_crypto_secretbox`. Key derived from `AUTH_KEY` with a
  plugin-specific salt. Decrypt only inside the IMAP poller.
- **IMAP-only** (no POP3 fallback). Mail server is Dovecot —
  mailbox `unsub@headwall-hosting.com`. Subjects matching
  `^unsubscribe-([A-Za-z0-9._-]+)$` flip subscriber status.
- **Tokens**: `{subscriber_id}.{hmac_hex}` where
  `hmac_hex = HMAC-SHA256(subscriber_id, token_salt)`. 32-byte
  per-subscriber salt at row insert. Rotating the salt invalidates
  outstanding links.
- **Idempotency**: `hum_send_recipients UNIQUE(send_id,
  subscriber_id)` prevents double-firing the same draft to the
  same recipient.

## Out of scope (v1)

- Open / click tracking — no pixels, no link rewriting.
- Bounce processing — schema reserves `bounced` for a future job.
- Drip campaigns, automations, A/B testing, scheduling-for-later.
- Multi-site / multi-tenant.
- Built-in SMTP — `wp_mail()` is the only sending surface.
- GitHub auto-updater shipped in 0.10.0 (pattern lifted from
  quick-2fa, loaded admin + cron only). Repo at
  `git@github.com:headwalluk/heads-up-mailer.git`; releases are
  built by `.github/workflows/release.yml` on `v*.*.*` tag pushes.
- WP-CLI commands — deferred to a project-tracker milestone.
- Composer / build step — `phpcs` and `phpcbf` are installed
  globally on the host.

## Workflow

- Run `phpcs` before every commit, `phpcbf` to auto-fix, `phpcs`
  again to verify. Optional pre-commit hook in
  `.git/hooks/pre-commit`.
- Conventional commits: `feat:`, `fix:`, `chore:`, `refactor:`,
  `docs:`, `style:`, `test:`. 50-char subject + bullet body
  explaining the *why*.
- See `dev-notes/00-project-tracker.md` for what to pick up next.

<!-- wp-translate:begin v=1.1.0 hash=2c4f10a0e186874bf421e0beb8a65a136290f9a51d0384675d0374a9d42d545b -->
## Translating this plugin (wp-translate conventions)

This plugin's `.po`/`.mo` files are generated from source by
[wp-translate](https://github.com/headwalluk/wp-translate-tool), which
machine-translates strings with DeepL. Machine translation is only as good as
the strings you give it — follow these conventions when adding or editing
user-facing text.

### 1. Disambiguate short or ambiguous strings with `_x()`

DeepL handles full sentences well but guesses badly on short, context-free
labels. Give it context with `_x()` (or `esc_html_x()`, `_ex()`):

```php
// Ambiguous out of context — DeepL may read "Sent" as "late", "Folder" as "leaflet"
__( 'Sent', 'heads-up-mailer' );

// Disambiguated — the context is passed to the translator and to DeepL
_x( 'Sent', 'email delivery status', 'heads-up-mailer' );
_x( 'Folder', 'IMAP mailbox', 'heads-up-mailer' );
_x( 'Open', 'verb; button label', 'heads-up-mailer' );
```

The context (2nd argument) is never shown to users. Use it whenever a string is a
single word, a short label, or has more than one plausible meaning.

### 2. Use placeholders, never concatenation

Build dynamic text with `printf`/`sprintf` so the whole sentence translates as a
unit, and add a `translators:` comment to explain each placeholder:

```php
/* translators: %s is the user's display name */
printf( esc_html__( 'Welcome back, %s', 'heads-up-mailer' ), $name );
```

Never split a sentence across multiple translation calls — word order differs
between languages.

### 3. Acronyms and technical tokens

wp-translate keeps common acronyms (`TLS`, `API`, `SMTP`, `URL`, `ID`, `UTC`, …)
verbatim automatically. If you introduce an unusual acronym or product name that
must not be translated, keep it as its own standalone string so it is recognised,
or ask the maintainer to add it to the tool's acronym list.

### 4. Don't translate dates — let WordPress localise them

Never add month or day-of-week names (full or abbreviated) as translatable
strings. DeepL frequently mistranslates short forms like `Mon`, `Tue`, `Jan`,
`Feb` even with context hints. WordPress already ships locale-aware names — use
`$wp_locale`:

```php
global $wp_locale;
$wp_locale->get_month( $month_number );        // "January" (1-based)
$wp_locale->get_month_abbrev( $month_name );   // "Jan"
$wp_locale->get_weekday( $weekday_number );     // "Monday" (0 = Sunday)
$wp_locale->get_weekday_abbrev( $weekday_name ); // "Mon"
```

For formatted dates, prefer `wp_date()` / `date_i18n()`, which localise month and
day names automatically.

### 5. English source dialect

Write source strings in standard English. wp-translate handles English targets
locally (no DeepL): `en`/`en_US` use the source as-is, and `en_GB`/`en_AU`/… get
American spellings converted to British automatically (`color` → `colour`).

### Running wp-translate

After changing strings, regenerate translations:

```bash
wp-translate /path/to/this-plugin              # auto-detect locales from languages/
wp-translate /path/to/this-plugin en_GB,fr_FR  # explicit locales
wp-translate /path/to/this-plugin --dry-run    # preview; no API calls, no writes
```

Requires WP-CLI (`wp`) and a DeepL API key at `~/.config/deepl.env`. The tool
regenerates the `.pot` from source, translates new/changed strings for each
locale, and compiles the `.mo` files.
<!-- wp-translate:end -->
