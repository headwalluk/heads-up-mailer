# Requirements (v1)

Source: `/home/pfaulkner/Projects/hosting/headwall-newsletter/HANDOFF.md`
(dated 2026-05-19). This is a distilled mirror — refer to the original
for full prose. Updated to reflect decisions made in chat (see
`02-design-notes.md`).

## Purpose

Replace MailerLite for headwall-hosting.com. Send weekly HTML
newsletters to segmented customer groups. Drafts authored by an AI
agent via REST, approved manually in WP admin before send.

## Subscribers

Fields: `email`, `name` (opt), groups (M:N), `status`
(`subscribed | unsubscribed | bounced | complained`),
`consent_source`, `consent_at`, `unsubscribed_at`, `token_salt`,
`created_at`.

Two seed groups: **Hosting customers**, **Web designers**. Schema
supports N groups.

## Drafts and sending

- AI agent posts drafts via authenticated REST (WP application
  password).
- Admin reviews/edits in WP admin (TinyMCE or raw textarea), previews
  in iframe, picks group(s), sends.
- Send handler **never** calls `wp_mail()` inline. It writes one
  `hw_sends` row plus N `hw_send_recipients` rows (status `pending`)
  in a single transaction and returns immediately.
- WP-Cron worker drains the queue in configurable batches. Defaults:
  10 recipients per tick, tick every 5 minutes.
- Worker skips any subscriber whose status is not `subscribed`,
  regardless of group membership.

## Email headers and footer

Every outgoing message carries:

- `List-Unsubscribe: <mailto:unsub@headwall-hosting.com?subject=unsubscribe-{token}>, <https://.../manage-comms/?token=…&action=unsubscribe>`
- `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058)
- `List-ID:` header
- `Precedence: bulk`
- HTML footer + plain-text alternative both contain a visible
  unsubscribe link.

## Public endpoints

- `GET  /manage-comms/?token=…` — preference page with per-group
  checkboxes and an "unsubscribe all" option.
- `POST /manage-comms/?token=…&action=unsubscribe` — one-click target;
  returns 200 + thanks, no confirmation page.

## REST surface

- `POST /wp-json/heads-up-mailer/v1/drafts` — AI agent posts a draft
  (app-password auth).
- `GET  /wp-json/heads-up-mailer/v1/drafts/{id}` — AI agent re-reads
  what it posted.
- `POST /wp-json/heads-up-mailer/v1/drafts/{id}/send` — admin-only,
  nonce auth; queues the send.

## MailerLite import

CSV upload accepting `email,name,groups,consent_at,consent_source`.
Preserve original consent metadata — do **not** stamp "imported on X".

## Logging

- `hw_sends`: per-send summary (`draft_id`, `group_ids_json`,
  `started_at`, `finished_at`, `attempted`, `sent`, `failed`).
- `hw_send_recipients`: per-recipient row, `UNIQUE(send_id,
  subscriber_id)` guards against double-sending the same draft to the
  same address.

## Tokens

`{subscriber_id}.{hmac_hex}` where
`hmac_hex = HMAC-SHA256(subscriber_id, token_salt)`. `token_salt` is
32 random bytes generated at row insert. Rotating the salt
invalidates all outstanding links for that subscriber.

## Mailbox poller (added in chat)

`unsub@headwall-hosting.com` is a real mailbox on the external mail
server. The plugin polls it on a cron schedule, parses
`unsubscribe-{token}` subjects (from the `List-Unsubscribe` mailto
form), and flips status to `unsubscribed`. See
`02-design-notes.md` §Q4 for details.

## Schema sketch

```
hw_subscribers       (id, email UNIQUE, name, status, consent_source,
                      consent_at, unsubscribed_at, token_salt, created_at)
hw_groups            (id, slug UNIQUE, name, description)
hw_subscriber_groups (subscriber_id, group_id) PK(both)
hw_drafts            (id, subject, html_body, suggested_groups_json,
                      created_by, created_at, status: draft|sent|cancelled)
hw_sends             (id, draft_id, group_ids_json, started_at,
                      finished_at, attempted, sent, failed)
hw_send_recipients   (send_id, subscriber_id, status: pending|sent|failed,
                      attempts, last_error, sent_at)
                     UNIQUE(send_id, subscriber_id)
```

> Open: confirm `hw_` prefix vs. a slug-aligned `hum_`. See
> `02-design-notes.md` §Open items.

## Out of scope (v1)

- Open/click tracking — no pixel, no link rewriting.
- Bounce processing — schema reserves `bounced` for a future job.
- Drip campaigns, automations, A/B, scheduling-for-later.
- Multi-site / multi-tenant.
- Built-in SMTP — `wp_mail()` is the only sending surface.
- CAPTCHA on unsubscribe — the token is sufficient.
