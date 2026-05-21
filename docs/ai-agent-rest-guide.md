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
   account (must have the `manage_options` capability).
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
| `rest_forbidden`              | 401  | Missing / wrong / revoked credentials, or user lacks `manage_options`. |
| `hum_draft_invalid_subject`   | 400  | Subject missing or empty after sanitisation.                           |
| `hum_draft_subject_too_long`  | 400  | Subject longer than 200 characters.                                    |
| `hum_draft_invalid_html_body` | 400  | `html_body` empty after stripping tags.                                |
| `hum_draft_unknown_groups`    | 400  | One or more slugs in `suggested_groups` don't exist. See `data.unknown`. |
| `hum_draft_not_found`         | 404  | `GET /drafts/{id}` against an unknown id.                              |
| `hum_draft_insert_failed`     | 400  | Database write failed. Retry or escalate.                              |

## Workflow

1. `POST /drafts` — submit the draft. Capture the returned `id`.
2. Optional: `GET /drafts/{id}` later to confirm edits or check
   status. Status transitions to `sent` (after a human triggers
   the send) or `cancelled`.
3. The agent does **not** trigger sends. Sending is a deliberate
   human action in the WP admin.

## Conventions

- All timestamps are stored and returned in UTC, in the form
  `Y-m-d H:i:s UTC`.
- Field names are lower-snake-case.
- `id`s are positive integers; treat them as opaque.
- The API is single-tenant, single-version. There is no pagination
  yet — only single-draft `GET` is exposed.
