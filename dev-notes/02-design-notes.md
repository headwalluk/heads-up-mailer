# Design notes

Working notes from the pre-build conversation. Updated as decisions
land. Companion to `01-requirements.md`.

## Pre-build Q&A

### Q1 — Plugin slug

**Decision:** slug = `heads-up-mailer`.

Drives:

- plugin directory + main file (`heads-up-mailer.php`)
- text domain (`heads-up-mailer`)
- REST namespace (`heads-up-mailer/v1`)
- option key prefix (TBD — `hum_` or `heads_up_mailer_`)
- DB table prefix: `hum_` (used as `{$wpdb->prefix}hum_subscribers`
  etc., so full table names are `wp_hum_subscribers`)

### Q2 — WP-Cron reliability

**Resolved:** not a concern. A Linux system cron pings `wp-cron.php`
every few minutes. Standard `wp_schedule_event` hook is sufficient;
no need for a CLI/REST drain endpoint as backup.

### Q3 — Batch size and tick interval

**Decision:** configurable, with defaults:

- batch size: **10**
- tick interval: **every 5 minutes**

Implications:

- Settings page exists in v1 (the handoff did not call this out
  explicitly).
- Even at batch 10, the worker should still carry a wall-clock budget
  (~25 s) so a single slow `wp_mail()` call can't stall the whole
  tick. Cheap insurance.

### Q4 — `unsub@headwall-hosting.com` mailbox

**Decision:** mailbox lives on the external mail server. Plugin gets
POP3/IMAP credentials via the admin settings page and **polls the
mailbox** to harvest incoming unsubscribe mails.

The `List-Unsubscribe` mailto form is
`mailto:unsub@…?subject=unsubscribe-{token}` — the subject is the
parse key.

Scope additions vs. the handoff:

- A second cron job: poll mailbox, fetch unread, match
  `^unsubscribe-([A-Za-z0-9._-]+)$`, validate token, flip status,
  delete or move to a `processed` folder.
- Settings: host, port, protocol (POP3/IMAP), username, password,
  TLS toggle, polling interval, folder name (IMAP).
- `ext-imap` becomes a runtime dependency. Detect at activation; show
  an admin notice if missing.
- Credential storage: encrypt-at-rest in `wp_options` (AES via
  `sodium_crypto_secretbox`, key derived from `AUTH_KEY`) so a DB
  dump doesn't leak the mailbox password.

**Decision:** IMAP-only. Mail server is Dovecot (same credentials,
same endpoint as outbound), so POP3 buys nothing and gives up
folders, flags, and idempotent reads.

### Q5 — PHP style rules

**Resolved:** style lives in `.github/copilot-instructions.md`
(portable WP plugin standards), with detailed patterns under
`dev-notes/patterns/` and workflows under `dev-notes/workflows/`.

See `03-style-guide-review.md` for the read-through, what we'll
follow, and open tensions to settle before coding.

## Scope deltas from the handoff

| Item                                       | Source |
| ------------------------------------------ | ------ |
| Settings page (batch size, interval, IMAP) | Q3, Q4 |
| Mailbox poller cron (IMAP/POP3)            | Q4     |
| `ext-imap` activation check                | Q4     |
| Encrypted mailbox credential storage       | Q4     |
| Worker wall-clock budget                   | Q3     |

## Revised plan of attack

Adjusted from the handoff's 9 steps:

1. **Schema + activator** — `dbDelta`, version option, activation
   hook checks `ext-imap` and surfaces an admin notice if missing.
2. **Subscribers admin** — list table, add/edit, CSV import from
   MailerLite (do this early so the MailerLite subscription can be
   cancelled).
3. **Groups admin** — small CRUD, seed `hosting-customers` and
   `web-designers`.
4. **Settings page skeleton** — batch size, tick interval, mailbox
   creds (encrypted on save), test-connection button.
5. **Draft REST endpoints** — `POST /drafts` + `GET /drafts/{id}`.
   Smoke-test with `curl` from the AI agent host.
6. **Draft admin UI** — list, view, edit, iframe preview, group
   picker.
7. **Send pipeline** — queue insertion + WP-Cron worker + `wp_mail()`
   with headers and footer injection. Wall-clock budget per tick.
8. **Token + `/manage-comms/` endpoint** — GET preference page, POST
   one-click handler.
9. **Mailbox poller cron** — IMAP fetch, parse
   `unsubscribe-{token}`, flip status, archive.
10. **Sent log UI** — read-only table of past sends with counters
    and a per-recipient drill-down.
11. **Soak test** — send to a 3-address test group across Gmail /
    Outlook / Fastmail to confirm headers honoured and the one-click
    POST works.

## Open items

T1–T6 resolved 2026-05-19 (see `03-style-guide-review.md`).
Remaining sub-questions tracked in that doc's "Still open"
section.
