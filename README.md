# Heads Up Mailer

![Status](https://img.shields.io/badge/status-pre--release-orange)
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

## Documentation

In-depth guides live in [`docs/`](docs/):

- [Installation and configuration](docs/installation.md)
- [Subscriber management and CSV import](docs/subscribers.md)
- [Composing and sending newsletters](docs/sending.md)
- [Self-service preferences and unsubscribe](docs/manage-comms.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Developer reference: REST API and hooks](docs/developer.md)

## Status

Pre-release (`0.4.0`). Milestones 1–5 of 9 are complete: schema,
subscribers + groups (with CSV import), settings (with encrypted
IMAP credentials), draft authoring (REST + admin UI with sandboxed
preview), and the send pipeline (WP-Cron worker with RFC 8058
one-click unsubscribe headers, sandboxed footer injection,
plain-text alternative, optimistic per-row claim). The public
preference endpoint, IMAP poller, and sent-log UI are still to
come. See
[`CHANGELOG.md`](CHANGELOG.md) for what has landed and
[`dev-notes/00-project-tracker.md`](dev-notes/00-project-tracker.md)
for the milestone roadmap.

## REST API

AI agents post drafts via the `heads-up-mailer/v1` namespace with
WordPress application-password auth. See
[`docs/ai-agent-rest-guide.md`](docs/ai-agent-rest-guide.md) for
endpoints, request / response shapes, and `curl` examples.

## License

GPL v2 or later, matching WordPress core. See the plugin header
in `heads-up-mailer.php`.
