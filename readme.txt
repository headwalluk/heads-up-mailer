=== Heads Up Mailer ===
Contributors: paulfaulkner
Tags: newsletter, email, subscribers, mailer, unsubscribe
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

In-house newsletter plugin: async send queue, RFC 8058 one-click unsubscribe, IMAP poll for mailto unsubscribes.

== Description ==

Heads Up Mailer is a lightweight in-house newsletter plugin built to replace MailerLite on a single WordPress site.

Drafts are authored by an AI agent via REST. The administrator reviews and edits them in the WordPress admin, then sends to one or more customer groups. Sends are queued in custom tables and drained by WP-Cron in configurable batches — never inline with the admin POST.

Privacy-positive by design:

* No open tracking pixels.
* No click tracking, no link rewriting.
* Unsubscribes work via RFC 8058 one-click POST and via a `mailto:` form that the plugin harvests by polling an IMAP mailbox.

Out of scope for v1: open / click tracking, drip campaigns, A / B testing, scheduled sends, multi-site, built-in SMTP. This plugin sends via `wp_mail()` — combine it with whatever SMTP plugin you already use.

== Installation ==

1. Drop the plugin folder into `wp-content/plugins/heads-up-mailer/`.
2. Activate it via the WordPress admin.
3. Visit *Heads Up Mailer → Settings* and configure the IMAP mailbox and queue.

== Frequently Asked Questions ==

= Does this support open or click tracking? =

No, by design. The plugin does not insert tracking pixels or rewrite links.

= Can I use my own SMTP server? =

Yes. Heads Up Mailer sends via `wp_mail()`, so whatever you configure as the WordPress mail transport — for example via WP Mail SMTP — is what it will use.

= Where can I read more? =

See the `docs/` directory in the plugin folder.

== Changelog ==

= 0.3.0 =
* Settings page (Queue + Mailbox tabs) with per-field sanitize callbacks. Numeric ranges clamped, booleans normalised, mailbox password encrypted at rest via libsodium `crypto_secretbox` keyed off `AUTH_KEY` (HKDF-SHA256).
* "Test connection" button for the IMAP mailbox tab: single-retry connect from the submitted form values, surfacing the last `imap_errors()` entry on failure. Blank password falls back to the stored encrypted value, decrypted in place.
* `OPTION_MAILBOX_VALIDATE_CERT` — opt-out of TLS chain validation for hosts whose c-client CA bundle disagrees with their cert chain (common with Let's Encrypt on Dovecot). TLS encryption stays on; only chain validation is skipped.
* Drafts: `heads-up-mailer/v1` REST namespace with `POST /drafts` and `GET /drafts/{id}`, authenticated with WordPress application passwords. AI agents post drafts; admins review, edit, and (in a future release) send.
* Drafts admin: list table, add/edit form with HTML body textarea and group multi-select, sandboxed iframe preview that renders MJML-style full-document HTML faithfully.
* `docs/ai-agent-rest-guide.md` — guide for the AI agent operator.

= 0.2.0 =
* Groups: full CRUD admin (list / add / edit / delete) with `hosting-customers` and `web-designers` seeded on activation.
* Subscribers: full CRUD admin with status chips, group chips, and per-row token salt generation.
* CSV import: MailerLite export shape supported (`Subscriber, ..., Subscribed, Name, Last name, ..., Groups`); update-or-create by lowercased email; existing consent timestamps preserved; unknown group names warned per-row without stopping the import.
* Top-level "Heads Up Mailer" admin menu with Dashboard, Subscribers, and Groups submenus.

= 0.1.0 =
* Initial pre-release scaffold. See `CHANGELOG.md` in the repository for details.
