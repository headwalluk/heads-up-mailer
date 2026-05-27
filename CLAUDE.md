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
no auto-updater (yet).

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
- GitHub auto-updater — deferred. Repo now lives at
  `git@github.com:headwalluk/heads-up-mailer.git`; the updater
  itself (pattern lifted from quick-2fa, loaded admin + cron
  only) is still TODO.
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
