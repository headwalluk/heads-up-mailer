# Planning: autonomous draft → send (daily security email)

**Status:** 📋 Planning — soak test starting; build not yet scoped.
**Created:** 2026-06-17
**Next step:** 2026-06-18 — turn the Phase 1 design below into a
numbered milestone in `00-project-tracker.md` with concrete tasks.

---

## Goal

Today an AI agent researches a topic, renders an HTML email from a
JSON doc + a `.mjml` template, and POSTs it to the site as a **Draft**
(attached to the right groups) via REST. A human reviews / edits the
HTML and presses **Send**.

A separate agent builds a **daily security email**. The goal is to let
that one run end-to-end **autonomously** — format it, POST it to the
correct group, and **send it to all subscribers** without a human in
the loop — because it would be useful to other hosting providers, not
just internally.

This is a deliberate, gated, multi-phase change. Autonomous sending to
the whole list is irreversible and outward-facing, so it gets gates,
control knobs, and an audit trail before it goes live.

---

## Current state (facts, as of 2026-06-17)

What the plugin supports today:

- **REST API** — two routes, both gated on the `hum_create_drafts`
  capability:
  - `POST /heads-up-mailer/v1/drafts` — create a draft (with groups).
  - `GET /heads-up-mailer/v1/drafts/{id}` — read it back.
- **The agent account** is an Editor with `hum_create_drafts` (since
  1.1.0). It can draft and read; it cannot send.
- **Sending is admin-only**: the Send button →
  `admin_post_hum_send_draft` → `Sends_Controller::queue( draft_id )`.
- **`Sends_Controller::queue()` pre-flight** (already solid): draft
  exists; draft is **not** already `sending`; a valid From: email is
  configured; the draft's groups resolve to real groups; at least one
  **subscribed** recipient exists. On pass, it writes the send +
  recipient rows atomically (status `pending`); the WP-Cron worker
  drains in batches.
- **Idempotency today**: `UNIQUE(send_id, subscriber_id)` stops a
  single send double-firing to the same recipient. But `queue()` will
  happily **re-send a draft whose status is already `sent`** — the
  admin UI guards that with a human "Send AGAIN to N recipients?"
  confirmation. There is no machine-level guard.

### The gap for autonomy

1. **No send route.** There is no REST endpoint to trigger a send.
2. **No send capability.** `hum_create_drafts` is the only custom cap;
   "can draft" and "can blast the list" are not separable.
3. **No autonomous idempotency.** Without the human "Send AGAIN?"
   gate, a retry / timeout-retry / agent bug could double-send to the
   whole list.

---

## Phased rollout

### Phase 0 — Soak test (now, **zero new code**)

The agent autonomously **drafts** the daily security email into the
correct group; a human reviews and sends manually. This is exactly
the existing flow, so it needs nothing new.

Purpose: build confidence in the parts that must be right before any
hands-off send — content quality, correct group attribution,
MJML→HTML rendering, link integrity, and deliverability.

**Suggested exit criteria** (decide the exact bar on 2026-06-18):
- N consecutive days (e.g. 5–7) of agent drafts that needed **no**
  human edits, or only trivial ones.
- No wrong-group attribution, no broken render, no deliverability
  flags.

### Phase 1 — Autonomous send (the build)

Only after Phase 0 passes. Add a gated REST send route and flip the
daily-security agent to draft → send. Design below.

---

## Phase 1 design considerations (to scope into a milestone)

### REST route

- Shape: `POST /heads-up-mailer/v1/drafts/{id}/send` (action on a
  draft) — thin wrapper around `Sends_Controller::queue()`, which
  already does all the pre-flight. Returns the new `send_id`, or a
  structured error.
- Alternative shape to weigh: a `POST /sends` collection endpoint
  taking a `draft_id`. Decide which on 2026-06-18.

### Gates

- **New capability** `hum_send_newsletters`, separate from
  `hum_create_drafts`. Granted deliberately. Open question: grant it
  to the agent's Editor role, mint a dedicated role/app-password user
  for the autonomous sender, or keep it admin-only and use a
  dedicated service account. Lean: dedicated service account so the
  send right is isolated and easy to revoke.
- **Autonomous idempotency**: the REST route must refuse a draft that
  is already `sent` or `sending` (no machine "Send AGAIN"). Consider
  also accepting an agent-supplied **idempotency key** so retries are
  safe even within the `draft` window.

### Control knobs

- **Master kill switch** — a setting that disables REST sending
  entirely, independent of the capability. Instant revoke.
- **Group allowlist** — restrict autonomous send to specific groups
  (e.g. only the daily-security group), so a bug can't blast a
  different audience.
- **Recipient ceiling** — refuse an autonomous send above N
  recipients, as a blast-radius guard.
- **Daily cap** — max autonomous sends per day (reuse / mirror the
  `/manage-comms/` rate-limiter pattern).
- **Send-window enforcement** — optionally require autonomous sends to
  fall inside the existing send-window (e.g. 08:00–18:00 site time).
- **Hold / delay** — optional "soft send": queue but hold for a short
  abort window, or a held state a human can cancel, during early
  autonomy.

### Audit

- **Log every autonomous trigger**: app-password user / token, time,
  draft_id, group_ids, recipient count, idempotency key, result
  (queued / refused + reason). Storage TBD — extend `hum_events` with
  a new event type, or a dedicated audit log.
- **Flag agent-triggered sends** distinctly in the Sent log (vs
  human-pressed-Send), so the history is unambiguous.
- **Notify the human** on each autonomous send during the early
  autonomous phase ("the agent just sent X to N recipients"), as a
  trust-building heads-up that can be dialled down later.

### Safety / rollback

- The kill switch and revocable capability give two independent ways
  to stop autonomy instantly.
- Phase 0 (draft-only) remains the always-available fallback config.

---

## Open questions for 2026-06-18 (milestone scoping)

1. Capability model: agent Editor + new cap, dedicated service
   account, or admin-only?
2. Idempotency: status-based refusal only, or also an idempotency key?
3. Which control knobs are v1 vs later (group allowlist + kill switch
   feel like v1; send-window + hold may be later)?
4. Audit storage: `hum_events` extension vs dedicated audit log; and
   whether per-send human notification ships in v1.
5. Route shape: `/drafts/{id}/send` vs `/sends`.
6. Schema impact (audit columns / table) → DB_VERSION bump? Versioned
   as a minor release.
7. Phase 0 exit criteria — the exact "clean days" bar.

---

## Non-goals

- Scheduling-for-later / drip automation (still out of scope per
  `01-requirements.md`).
- Open / click tracking (still out of scope).
- Letting the agent send to *arbitrary* groups — autonomy is scoped to
  the daily-security flow via the group allowlist.
