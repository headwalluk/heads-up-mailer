# Autonomous send — admin setup guide

*Since 1.5.0.* By default, sending a newsletter is always a human
action: an agent posts a draft over REST, an admin reviews it and
clicks **Send**. Autonomous send lets a trusted agent trigger the
send itself, over REST, with **no human in the loop** — but only for
audiences you have explicitly, deliberately opted in.

This is off by default and fail-safe by design. Read this before you
turn it on.

## The safety model — two independent gates

An autonomous send succeeds **only when both** of these agree.
Either one alone blocks it.

1. **Master switch** — *Settings → Sending → "Allow trigger-send via
   REST API"*. A single site-wide on/off. **Ships OFF.** Turning it
   off revokes all REST sending instantly, without touching any user
   roles.
2. **Per-group flag** — *Heads Up Mailer → Groups →* edit a group →
   *"Allow autonomous send"*. **Off for every group by default.** The
   send route refuses unless **every** group the draft targets has
   this ticked. One un-flagged group in the set blocks the whole
   send.

Because the per-group flag is data, not config, the agent can never
autonomously email a group you have not opted in — even a bug or a
mis-addressed draft simply gets refused. The general customer list
stays unreachable to automation as long as its group stays
un-flagged.

There is also a capability gate: the agent's WordPress user needs
`hum_send_newsletters` (granted to Administrator and Editor on
upgrade). Remove that cap to stop the agent sending while still
letting it draft.

## Turning it on

1. **Pick the audience.** Autonomous send is meant for a small,
   managed group — e.g. a daily security email — not a broad list.
   Create or choose that group.
2. **Flag the group.** *Heads Up Mailer → Groups*, edit the group,
   tick **"Allow autonomous send"**, save. The groups list shows an
   `· Auto-send` marker next to flagged groups.
3. **Flip the master switch.** *Settings → Sending*, tick **"Allow
   trigger-send via REST API"**, save.
4. **Confirm the agent's account** has the `hum_send_newsletters`
   capability (an Editor- or Administrator-role account has it
   automatically since 1.5.0).

The agent can now `POST /drafts/{id}/send` for drafts whose groups
are all flagged. See the
[AI agent REST guide](ai-agent-rest-guide.md) for the endpoint.

## What a refused send looks like

Refusals are loud and specific — the agent receives an HTTP error
naming the reason:

| Situation | HTTP | Code |
| --- | --- | --- |
| Master switch is off | 403 | `hum_autonomous_disabled` |
| A target group is not flagged | 403 | `hum_group_not_automated` (names the groups) |
| Draft already sent / sending | 409 | `hum_send_already_done` |
| No From: identity, no groups, or no recipients | 422 | `hum_send_*` |

## Auditing autonomous sends

- The **Sent log** (*Heads Up Mailer → Sent log*) shows a **Trigger**
  column: `Auto` for agent-triggered sends, `Manual` for
  human-pressed ones.
- Every trigger — queued **or** refused — is written to the PHP error
  log (`audit_autonomous_send`), recording the acting user, the
  draft, and the outcome.

## Turning it off / rolling back

Any one of these stops autonomy immediately:

- Untick the **master switch** (fastest; revokes all REST sending).
- Untick **"Allow autonomous send"** on the group(s).
- Remove the `hum_send_newsletters` capability from the agent's user.

Drafting is unaffected throughout — the agent can always post drafts
for human review, which is the always-available fallback.
