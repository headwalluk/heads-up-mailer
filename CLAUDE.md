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

`docs/` is the maintained, tracked documentation. **This file is
the standard** for conventions. Anything durable — how a feature
works, why a mechanism exists, operator or developer guidance —
goes in `docs/`, not in `dev-notes/` and not in code comments.

`dev-notes/` is **local and untracked** (git-ignored, excluded from
the release zip, backed up with the dev server). It holds working
notes only:

| File                                 | Contents                                |
| ------------------------------------ | --------------------------------------- |
| `dev-notes/00-project-tracker.md`    | Milestones, tasks, current progress     |
| `dev-notes/01-requirements.md`       | v1 requirements distilled from handoff  |
| `dev-notes/02-design-notes.md`       | Pre-build Q&A, decisions, scope deltas  |
| `dev-notes/03-style-guide-review.md` | Conventions + tensions resolved         |

Never link to `dev-notes/` from tracked files — the link is dead on
GitHub. The original handoff at
`/home/pfaulkner/Projects/hosting/headwall-newsletter/HANDOFF.md` is
historical context only.

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
├── docs/                     # tracked docs (operators, AI agent, developers)
└── dev-notes/                # local working notes — untracked
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

- PHP 8.2+ (`true|\WP_Error` returns need 8.2). Type hints and return types everywhere.
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
- **An `if` with an `elseif` ends in a plain `else`**, even when it
  only holds a `// no action:` comment, so every case is handled on
  purpose. `phpcs.xml` allows these comment-only branches.
- **Callbacks on hooks we don't own take `mixed`** and check what
  they receive (`instanceof`, `is_array()`, `is_numeric()`). A filter
  callback passes a value it can't use through unchanged. Another
  plugin can hand over the wrong type, and a typed parameter turns
  that into a `TypeError` on a page we don't control.
- **Guard object lookups with `instanceof`**, not truthiness:
  `wc_get_order()`, `get_userdata()` and friends can return anything
  once another plugin is involved.
- **Hook callbacks are named methods**, not closures. Per-call state
  goes in a property, and anything added around a call (such as the
  worker's `wp_mail_from` override) is removed in a `finally`.
- **CSS uses logical properties** (`margin-inline-start`,
  `border-inline-start`, `text-align: end`) so right-to-left locales
  style the correct side. Top and bottom stay physical.
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

- **Hook registration**: `Plugin::run()` instantiates each component
  and calls its `run()`, which registers that component's hooks.
  Admin pages live in `Admin` (menu, notices, assets, Dashboard,
  Settings) and `Admin_Groups` / `Admin_Subscribers` /
  `Admin_Drafts`, loaded only when `is_admin()` — which covers
  `admin-post.php` and `admin-ajax.php`.
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

## Public contracts

Code and people outside the plugin depend on all of these, whether or
not they are documented. Treat each as a contract:

| Contract | Examples | Breaks when |
| -------- | -------- | ----------- |
| REST API | `heads-up-mailer/v1` — `/drafts`, `/drafts/{id}`, `/drafts/{id}/send`, `/groups`, `/groups/{id}`; request fields and response shapes | a route, field or status code changes. The AI agent is coded against `docs/ai-agent-rest-guide.md` |
| Unsubscribe URLs in sent mail | `/{manage slug}/?token={id}.{hmac}`, `QUERY_VAR_MANAGE`, the one-click `POST` | the token format, the query var or the HMAC input changes. Every newsletter already sent carries these links |
| Unsubscribe-by-mail subject | `unsubscribe-{token}`, matched by `Mailbox_Poller::SUBJECT_PATTERN` | the subject format changes. Sent `List-Unsubscribe` mailto links stop working |
| Filters | `hum_integrations`, `hum_updater_enabled` | renamed or removed, or an argument is removed or reordered |
| Capabilities | `hum_create_drafts`, `hum_send_newsletters`, `hum_read_groups`, `hum_manage_groups` | renamed. Roles granted the old cap (including the AI agent's) lose access |
| Stored key strings | `hum_*` options, `_hum_*` order meta, `wp_hum_*` tables | a constant's **value** changes. Data under the old string is orphaned, and `uninstall.php` finds options by the `hum_` prefix |
| Script and style handles | `heads-up-mailer-admin` | renamed |

- **Add, don't change.** New filter arguments go at the end. New
  REST fields are additive; never repurpose an existing one.
- **Deprecate, don't rename.** Fire an old filter name through
  `apply_filters_deprecated()` and pass its result into the new one.
- **Stored data changes need a migration** via `DB_VERSION` and
  `Plugin::check_first_run()`. If one isn't possible, stop and ask.
- Before changing anything in the table: name the contract, assume
  there are consumers you can't see, take the additive path, and
  record it in `CHANGELOG.md`. If unsure, stop and ask.

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
- Run `bin/check-sql-interpolation.sh` alongside `phpcs`. `phpcs.xml`
  has to exclude `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`
  (table names must be interpolated — `$wpdb->prepare()` can't
  placeholder an identifier), which would otherwise let a genuine
  `WHERE id = {$user_input}` through silently. The script restores that
  one guarantee: every `{$var}` inside a SQL string must be a table
  identifier or a generated placeholder list. Exit 1 = fix it or, if
  the variable really is a table name, add it to `ALLOWED_PATTERN`.
- Conventional commits: `feat:`, `fix:`, `chore:`, `refactor:`,
  `docs:`, `style:`, `test:`. 50-char subject + bullet body
  explaining the *why*.
- See `dev-notes/00-project-tracker.md` for what to pick up next.

<!-- wp-translate:begin v=1.2.0 hash=9f6eba224fa788af110ed383ea701287e5f5875f1ead78cba5ffd921bfcab313 -->
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

### 3. Use `_n()` for anything that can be counted

Never build a count-dependent sentence by hand, and never settle for a single
form that reads correctly only for one number. Languages differ in how many
plural forms they have — English and German have two, French treats 0 as
singular, Polish and Russian have three, Japanese has one, Arabic has six — and
`_n()` is the only way to express that.

```php
// Wrong — "1 reviews", and untranslatable into languages with other forms
printf( esc_html__( '%d reviews', 'heads-up-mailer' ), $count );

// Right — wp-translate fills every form the target locale needs
printf(
    esc_html( _n( '%d review', '%d reviews', $count, 'heads-up-mailer' ) ),
    $count
);
```

Keep the placeholder in **both** forms, even when the singular reads fine
without it (`'%d review'`, not `'One review'`) — some locales use the singular
slot for other numbers too.

For a short or ambiguous countable noun, use `_nx()` — the plural equivalent of
`_x()` — so the context reaches DeepL:

```php
// "Review" alone is ambiguous: critique? opinion? inspection?
_nx( '%d review', '%d reviews', $count, 'customer feedback on a company', 'heads-up-mailer' );
```

**Locales needing more than two forms will have their extra slots left empty for
a human translator.** DeepL supplies a singular and a plural; nobody can invent
Polish's third form from those, and wp-translate deliberately leaves it blank
rather than filling it with a plausible guess. Expect to see empty
`msgstr[2]` entries in `pl_PL` — that is correct behaviour, not a failure.

### 4. Acronyms and technical tokens

wp-translate keeps common acronyms (`TLS`, `API`, `SMTP`, `URL`, `ID`, `UTC`, …)
verbatim automatically. If you introduce an unusual acronym or product name that
must not be translated, keep it as its own standalone string so it is recognised,
or ask the maintainer to add it to the tool's acronym list.

### 5. Don't translate dates — let WordPress localise them

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

### 6. English source dialect

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
