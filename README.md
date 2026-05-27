# Heads Up Mailer

![Status](https://img.shields.io/badge/status-1.0.0-brightgreen)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv2%2B-blue)

In-house WordPress newsletter plugin built to replace MailerLite
for a single site. Drafts are authored by an AI agent over REST,
reviewed and edited in WP admin, then sent to segmented customer
groups via an asynchronous WP-Cron queue. Privacy-positive: no
open tracking, no click tracking, no link rewriting.

Unsubscribes work via RFC 8058 one-click POST and via a `mailto:`
form that the plugin harvests by polling an IMAP mailbox.

## Who this is for

- **Site administrators** running a low-to-medium volume
  newsletter on a single WordPress site, who want full control
  over the send pipeline.
- **Newsletter editors** who need to review and approve drafts
  before they go out.
- **Hosting providers** who want a plugin that uses standard
  WordPress patterns (`wp_mail()`, WP-Cron, custom tables) and
  integrates cleanly with an existing SMTP and IMAP setup.

This plugin is **not** a hosted service, a multi-site mailer, or a
marketing-automation suite.

## What's in 1.0.0

- **Drafts pipeline** — REST endpoint, sandboxed admin preview,
  configurable footer + sender identity.
- **Send queue + worker** — async drain on a configurable WP-Cron
  interval, per-recipient optimistic claim against
  `UNIQUE(send_id, subscriber_id)`, wall-clock budget per tick.
- **RFC 8058 unsubscribe** — `List-Unsubscribe` (mailto + https),
  `List-Unsubscribe-Post: One-Click`, `List-ID`, `Precedence:
  bulk`. Plain-text alternative auto-generated.
- **Public `/manage-comms/` page** — per-group preferences with
  resubscribe-via-tick, plus a distinct "Unsubscribe me from
  everything" button that flips the subscriber to a sticky
  `never_contact` status.
- **IMAP poller** — harvests `mailto:` unsubscribe replies on a
  separate cron tick. Failed messages move to an `Errors`
  folder; processed messages move to `Processed`. Master enable
  switch on the settings tab.
- **Sent log** — list view of every send with live status
  counters, per-send recipient drill-down with status filter.
- **Subscribers + groups admin** — full CRUD, MailerLite-export
  CSV import (idempotent, refuses to overwrite never-contact
  rows), row + bulk actions.
- **Integrations framework** — pluggable `Integration` base
  class + `hum_integrations` filter. Built-ins:
  - **Contact Form 7** — new `[hum_signup group:slug "Label"]`
    form tag, droppable into any CF7 form.
  - **WooCommerce** — auto-enrol customers into a configurable
    group on checkout (classic checkout); per-group opt-in
    checkboxes with admin-defined labels.
- **In-plugin GitHub auto-updater** — releases land via the
  standard WordPress plugin update flow.
- **Encryption** — IMAP password encrypted at rest with
  libsodium (`AUTH_KEY`-derived key).

## Documentation

End-user docs live under [`docs/`](docs/):

- [REST API for AI agents](docs/ai-agent-rest-guide.md) — how
  to POST drafts and read them back.

Additional admin / editor / host guides are tracked as a
follow-up; the in-admin tab descriptions cover day-to-day use.

## REST API

AI agents post drafts via the `heads-up-mailer/v1` namespace
with WordPress application-password auth. See
[`docs/ai-agent-rest-guide.md`](docs/ai-agent-rest-guide.md) for
endpoints, request / response shapes, and `curl` examples.

## Updates

`includes/class-github-updater.php` checks the
[GitHub releases page](https://github.com/headwalluk/heads-up-mailer/releases)
and surfaces new versions through the standard WordPress
"Plugins → Updates" UI. Site owners can pin / disable updates
via the `hum_updater_enabled` filter.

## License

GPL v2 or later, matching WordPress core. See the plugin header
in `heads-up-mailer.php`.
