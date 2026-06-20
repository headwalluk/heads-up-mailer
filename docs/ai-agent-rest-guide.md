# AI agent — REST API guide

Heads Up Mailer exposes a small REST surface so an external agent
can post newsletter drafts into WordPress for human review and
send. Everything below applies to the `heads-up-mailer/v1`
namespace.

## Base URL

```
https://devx.headwall.tech/wp-json/heads-up-mailer/v1
```

Production swaps the host. Otherwise the path is identical.

## Authentication

Every route uses **HTTP Basic auth** with a WordPress
[application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).

1. In WP admin, open **Users → Profile** for the dedicated agent
   account. The account must have the `hum_create_drafts`
   capability — granted by the plugin to the **Administrator** and
   **Editor** roles on activation, so an Editor-role agent account
   is sufficient (no Administrator role required). Plugins below
   1.1.0 instead required `manage_options` (Administrator only).
   To use the autonomous send route (below), the account also needs
   `hum_send_newsletters` — granted to the same two roles since
   1.5.0, so an Editor account has both. The two caps are separate
   so an admin can let the agent draft but not send, simply by
   removing the send cap.
2. Scroll to **Application Passwords**, enter a label (e.g.
   "newsletter-agent"), and click **Add new application password**.
3. WordPress shows a 24-character credential like
   `AbCd EfGh IjKl MnOp QrSt UvWx`. Save it — it is not shown
   again.

The agent sends that credential as Basic auth on every request:

```
Authorization: Basic base64(<wp-username>:<application-password>)
```

Spaces in the password may be kept or stripped — WordPress accepts
both. Avoid logging or rotating into git.

## Endpoints

### `POST /drafts` — create a draft

Submits a newsletter draft for an admin to review and send.

**Request body** (JSON):

| Field              | Type     | Required | Notes                                                |
| ------------------ | -------- | -------- | ---------------------------------------------------- |
| `subject`          | string   | yes      | ≤ 200 characters. Stripped of HTML.                  |
| `html_body`        | string   | yes      | Newsletter HTML. Sanitised via `wp_kses_post`.       |
| `suggested_groups` | string[] | no       | Array of group **slugs** (not IDs). Default `[]`.    |

**Response** — `201 Created`:

```json
{
  "id": 12,
  "subject": "May hosting update",
  "html_body": "<p>…</p>",
  "suggested_groups": ["hosting-customers"],
  "created_by": 7,
  "created_at": "2026-05-20 19:17:47 UTC",
  "status": "draft"
}
```

`status` is `draft` on insert. Sending or cancelling is an
admin-only action and changes it later.

**`curl` example:**

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     -H "Content-Type: application/json" \
     -X POST \
     -d @- \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/drafts <<'JSON'
{
  "subject": "May hosting update",
  "html_body": "<p>Hi! Here's what's new this month.</p>",
  "suggested_groups": ["hosting-customers"]
}
JSON
```

### `GET /drafts/{id}` — read a draft back

Use the `id` returned by `POST /drafts`. Returns the same shape as
above, including any edits an admin has made.

**Response codes:**

- `200 OK` — draft found.
- `404 Not Found` — no draft with that id.

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/drafts/12
```

### `POST /drafts/{id}/send` — trigger an autonomous send

Queues a draft for sending to every subscribed recipient in its
target groups — the same async pipeline a human triggers from the
admin **Send** button. Requires the `hum_send_newsletters`
capability. **This route is gated** (see below); a refused request
queues nothing and sends nothing.

**Both gates must pass, or the send is refused:**

1. The site-wide **"Allow trigger-send via REST API"** switch
   (*Settings → Sending*) must be ON. It is **off by default**.
2. **Every** group the draft targets must have **"Allow autonomous
   send"** ticked (*Heads Up Mailer → Groups →* edit the group). If
   even one targeted group is not enabled, the whole send is
   refused.

This is fail-safe by design: the agent can only ever reach groups an
admin has explicitly opted into automation, so it can never blast a
general or large list by mistake. Enabling autonomy is an admin
action — confirm the target group is flagged out-of-band.

**Request:** no body. The draft id is in the path.

**Response** — `200 OK`:

```json
{
  "send_id": 42,
  "draft_id": 12,
  "status": "queued"
}
```

This call only enqueues. The worker drains on its normal WP-Cron
schedule; poll `GET /drafts/{id}` to watch `status` move
`draft → sending → sent`.

**`curl` example:**

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     -X POST \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/drafts/12/send
```

**Idempotency:** a draft already `sending` or `sent` is refused with
`409`. There is no machine "send again" — re-sending a completed
draft is a deliberate human action in the admin. Post a fresh draft
for each send.

## Group slugs

`suggested_groups` accepts a list of slugs. Two are seeded on
install:

- `hosting-customers`
- `web-designers`

Admins can add more on the **Heads Up Mailer → Groups** page; the
slug is shown in the list. There is no REST endpoint for groups
yet — confirm new slugs with the admin out-of-band.

If you pass an unknown slug, the request fails with
`hum_draft_unknown_groups` (HTTP 400) and the body lists the
offending entries.

## Errors

All errors return a JSON envelope shaped like:

```json
{
  "code": "hum_draft_subject_too_long",
  "message": "Subject must be 200 characters or fewer.",
  "data": { "status": 400 }
}
```

### Possible codes

| Code                          | HTTP | Meaning                                                                |
| ----------------------------- | ---- | ---------------------------------------------------------------------- |
| `rest_forbidden`              | 401  | Missing / wrong / revoked credentials, or user lacks the `hum_create_drafts` capability (granted to Administrator + Editor). |
| `hum_draft_invalid_subject`   | 400  | Subject missing or empty after sanitisation.                           |
| `hum_draft_subject_too_long`  | 400  | Subject longer than 200 characters.                                    |
| `hum_draft_invalid_html_body` | 400  | `html_body` empty after stripping tags.                                |
| `hum_draft_unknown_groups`    | 400  | One or more slugs in `suggested_groups` don't exist. See `data.unknown`. |
| `hum_draft_not_found`         | 404  | `GET /drafts/{id}` against an unknown id.                              |
| `hum_draft_insert_failed`     | 400  | Database write failed. Retry or escalate.                              |

### Send-route codes (`POST /drafts/{id}/send`)

| Code                          | HTTP | Meaning                                                                |
| ----------------------------- | ---- | ---------------------------------------------------------------------- |
| `rest_forbidden`              | 401/403 | Missing / wrong credentials, or the user lacks `hum_send_newsletters`. |
| `hum_autonomous_disabled`     | 403  | The site-wide "Allow trigger-send via REST API" switch is OFF.         |
| `hum_group_not_automated`     | 403  | One or more target groups are not enabled for autonomous send. The message names the blocked slugs. |
| `hum_send_already_done`       | 409  | Draft is already `sending` or `sent`. No machine re-send.              |
| `hum_send_draft_not_found`    | 404  | No draft with that id.                                                 |
| `hum_send_no_groups`          | 422  | The draft targets no groups.                                           |
| `hum_send_no_recipients`      | 422  | No subscribed recipients in the target groups.                         |
| `hum_send_from_email_missing` | 422  | No From: identity configured (*Settings → Sending*).                   |

## Workflow

The plugin supports two modes. Which one applies depends entirely on
admin configuration — the agent uses the same first two steps either
way.

1. `POST /drafts` — submit the draft. Capture the returned `id`.
2. Optional: `GET /drafts/{id}` later to confirm edits or check
   status (`draft → sending → sent`, or `cancelled`).

**Default — human sends (no gates configured):**

3. The agent stops here. A human reviews the draft in WP admin and
   clicks **Send**. This is the right mode for anything going to a
   large or general audience.

**Autonomous — agent sends (gated, opt-in per group):**

3. `POST /drafts/{id}/send` — trigger the send yourself. This only
   succeeds when the master switch is on **and** every target group
   is automation-enabled (see the endpoint above); otherwise it is
   refused with a descriptive error and nothing is sent. Intended
   for a small, managed group an admin has deliberately opted in —
   e.g. a daily security email — not the general list.

## Conventions

- All timestamps are stored and returned in UTC, in the form
  `Y-m-d H:i:s UTC`.
- Field names are lower-snake-case.
- `id`s are positive integers; treat them as opaque.
- The API is single-tenant, single-version. There is no pagination
  yet — only single-draft `GET` is exposed.
