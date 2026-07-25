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

   Since 1.6.0 there are two further caps for the group routes:

   | Capability | Grants | Granted on upgrade to |
   | --- | --- | --- |
   | `hum_read_groups` | `GET /groups`, `GET /groups/{id}` | Administrator + Editor |
   | `hum_manage_groups` | `POST` / `PATCH` / `DELETE` on groups | **Administrator only** |

   So an Editor-role agent account can *discover* groups out of the
   box, but cannot create or delete them. That is deliberate: reading
   the group list gives an agent no reach it did not already have,
   whereas a mistaken `DELETE` is destructive. If you want an agent
   maintaining groups, grant `hum_manage_groups` to that user
   explicitly (or to the Editor role, accepting that it applies to
   every Editor).

   All four caps are independent — revoke any one without disturbing
   the others.
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
| `html_body`        | string   | yes      | Newsletter HTML. Stored **verbatim** — see below.    |
| `suggested_groups` | string[] | no       | Array of group **slugs** (not IDs). Default `[]`.    |

`html_body` is deliberately **not** run through `wp_kses_post`: email
HTML is usually a full document, and kses strips the conditional
comments and wrapper markup that MJML-style output depends on. The
trade-off is that the agent's HTML is trusted content — the admin
preview renders it under a `Content-Security-Policy: sandbox` response
header so scripts in it cannot execute, but an agent credential is
still effectively "may store arbitrary HTML on this site". Treat that
credential accordingly.

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

### `GET /groups` — list every group

*Since 1.6.0. Requires `hum_read_groups`.*

Use this to discover valid `suggested_groups` slugs rather than
hard-coding them. Includes private groups — "private" only hides a
group from the public preferences page, and this route is
capability-gated.

**Response** — `200 OK`:

```json
{
  "groups": [
    {
      "id": 1,
      "slug": "hosting-customers",
      "name": "Hosting customers",
      "description": "Active hosting customers.",
      "is_private": false,
      "allow_automated_send": false,
      "member_count": 136,
      "subscribed_count": 135
    }
  ]
}
```

Two counts, because they differ and both matter:

- `member_count` — every membership row, whatever the subscriber's
  status. This is the number that governs whether the group can be
  deleted.
- `subscribed_count` — members a send would actually reach. Use this
  one for reporting audience size.

`allow_automated_send` is **read-only**. It tells you whether
`POST /drafts/{id}/send` can reach this group, so a refusal is
diagnosable — but the write routes ignore the field entirely. Only a
human can change it, in the admin UI.

No pagination — groups are a handful of rows, ordered by name.

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/groups
```

### `GET /groups/{id}` — read one group

*Requires `hum_read_groups`.* Same object shape as a list entry.
`404` if the id is unknown.

### `POST /groups` — create a group

*Requires `hum_manage_groups` (Administrator by default).*

| Field         | Type    | Required | Notes                                        |
| ------------- | ------- | -------- | -------------------------------------------- |
| `slug`        | string  | yes      | Passed through `sanitize_title()`. ≤ 100 bytes after sanitising. Must be unique. |
| `name`        | string  | yes      | ≤ 255 characters.                            |
| `description` | string  | no       | Plain text. ≤ 65535 bytes.                   |
| `is_private`  | boolean | no       | Hides the group from the public preferences page. Default `false`. |

Any other field is ignored — notably `allow_automated_send`, which
cannot be set over REST at any privilege level. A group created here
always starts with autonomous sending off.

Non-ASCII slugs are percent-encoded by `sanitize_title()`, as
elsewhere in WordPress, which can make the stored slug considerably
longer than what you sent. Prefer ASCII slugs.

**Response** — `201 Created`, returning the created group in the same
shape as `GET /groups/{id}`.

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     -H "Content-Type: application/json" \
     -X POST \
     -d '{"slug":"security-bulletin","name":"Security bulletin","description":"Daily security digest."}' \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/groups
```

### `PATCH /groups/{id}` — partial update

*Requires `hum_manage_groups`.*

Send only the fields you want changed; anything omitted keeps its
stored value. Accepts the same four fields as `POST /groups`.

```bash
curl -u "newsletter-agent:AbCd EfGh IjKl MnOp QrSt UvWx" \
     -H "Content-Type: application/json" \
     -X PATCH \
     -d '{"description":"Daily security digest for managed hosting."}' \
     https://devx.headwall.tech/wp-json/heads-up-mailer/v1/groups/5
```

### `DELETE /groups/{id}` — delete an empty group

*Requires `hum_manage_groups`.*

**Refuses any group that still has members**, with `409` and the count
in both the message and `data.member_count`:

```json
{
  "code": "hum_group_not_empty",
  "message": "Group still has 136 members and cannot be deleted. Remove its members first.",
  "data": { "status": 409, "member_count": 136 }
}
```

"Has members" means *any* membership row, including subscribers who
are unsubscribed or never-contact. Deleting a group also deletes its
membership rows, so this guard exists to stop that happening as an
invisible side effect of an agent tidying up. Clearing a populated
group is a deliberate human action in the admin UI.

**Response** — `200 OK` on success:

```json
{ "deleted": true, "id": 5 }
```

## Group slugs

`suggested_groups` accepts a list of slugs. Two are seeded on
install:

- `hosting-customers`
- `web-designers`

Since 1.6.0, call `GET /groups` to discover the current set rather
than assuming — admins can add, rename, and remove groups on the
**Heads Up Mailer → Groups** page.

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

### Group-route codes

| Code                             | HTTP | Meaning                                                             |
| -------------------------------- | ---- | ------------------------------------------------------------------- |
| `rest_forbidden`                 | 401/403 | Missing / wrong credentials, or the user lacks `hum_read_groups` (read routes) or `hum_manage_groups` (write routes). |
| `hum_group_not_found`            | 404  | No group with that id.                                              |
| `hum_group_exists`               | 409  | A group with that slug already exists.                              |
| `hum_group_not_empty`            | 409  | `DELETE` against a group that still has members. See `data.member_count`. |
| `hum_group_invalid_slug`         | 400  | Slug missing or empty after `sanitize_title()`.                     |
| `hum_group_invalid_name`         | 400  | Name missing or empty after sanitising.                             |
| `hum_group_slug_too_long`        | 400  | Slug over 100 bytes after sanitising.                               |
| `hum_group_name_too_long`        | 400  | Name over 255 characters.                                           |
| `hum_group_description_too_long` | 400  | Description over 65535 bytes.                                       |
| `hum_group_insert_failed`        | 500  | Database write failed. Retry or escalate.                           |
| `hum_group_update_failed`        | 500  | Database write failed. Retry or escalate.                           |
| `hum_group_delete_failed`        | 500  | Database write failed. Retry or escalate.                           |

One quirk inherited from WordPress core: a `POST` with **no body at
all** returns `400 rest_missing_callback_param` (naming the missing
fields) rather than `401`, because core checks required parameters
before the capability callback. Send valid credentials and a valid body
and the capability gate behaves normally.

## Workflow

The plugin supports two modes. Which one applies depends entirely on
admin configuration — the agent uses the same first two steps either
way.

0. Optional but recommended: `GET /groups` to resolve the slugs you
   intend to target. Cheap, and it turns a silent "draft targeting
   nothing" into a decision you make deliberately.
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
- The API is single-tenant, single-version. There is no pagination:
  drafts are fetched one at a time, and `GET /groups` returns the full
  (small) set.
- Group routes are addressed by numeric `id`, not slug, for
  consistency with the draft routes. `sanitize_title()` permits a
  purely numeric slug, which would be ambiguous in a path segment. Map
  slug → id via `GET /groups`.
