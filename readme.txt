=== Heads Up Mailer ===
Contributors: paulfaulkner
Tags: newsletter, email, subscribers, mailer, unsubscribe
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

In-house newsletter plugin: async send queue, RFC 8058 one-click unsubscribe, IMAP poll for mailto unsubscribes, GDPR-flavoured never-contact, CF7 + WooCommerce integrations.

== Description ==

Heads Up Mailer is a lightweight in-house newsletter plugin built to replace MailerLite on a single WordPress site.

Drafts are authored by an AI agent via REST. The administrator reviews and edits them in the WordPress admin, then sends to one or more customer groups. Sends are queued in custom tables and drained by WP-Cron in configurable batches — never inline with the admin POST.

Sending stays a human action by default. Optionally (since 1.5.0), a trusted agent can trigger a send autonomously over REST — but only when a site-wide master switch is on AND every target group has been individually opted in. Both ship OFF, so automation can never reach a group an admin has not deliberately flagged.

Privacy-positive by design:

* No open tracking pixels.
* No click tracking, no link rewriting.
* Unsubscribes work via RFC 8058 one-click POST and via a `mailto:` form that the plugin harvests by polling an IMAP mailbox.
* "Never contact" is a sticky terminal state that future CSV re-imports refuse to overwrite — belt-and-braces for GDPR erasure requests.

The admin dashboard gives an at-a-glance, privacy-first overview of the last 30 days: send health with a failure-rate alert, active-audience size, per-group sign-ups and departures, and recent send failures — all derived from data the plugin already holds, with no tracking pixels or link rewriting.

Built-in plugin integrations (each only loads when its parent plugin is active):

* **Contact Form 7** — drop `[hum_signup group:slug "Sign me up"]` into any CF7 form to add a sign-up checkbox.
* **WooCommerce** — auto-enrol customers into a configurable group on checkout, plus per-group opt-in checkboxes with admin-defined labels. Classic checkout only in 1.0.0; Block checkout support is on the roadmap.

The repository ships with a GitHub Actions release workflow and an in-plugin auto-updater so site owners receive new versions through the standard WordPress plugin update UI.

Out of scope for v1: open / click tracking, drip campaigns, A / B testing, scheduled sends, multi-site, built-in SMTP. This plugin sends via `wp_mail()` — combine it with whatever SMTP plugin you already use.

== Installation ==

1. Drop the plugin folder into `wp-content/plugins/heads-up-mailer/`.
2. Activate it via the WordPress admin.
3. Visit *Heads Up Mailer → Settings* and configure the IMAP mailbox, sending identity, and (optionally) the integrations tab.

== Frequently Asked Questions ==

= Does this support open or click tracking? =

No, by design. The plugin does not insert tracking pixels or rewrite links.

= Can I use my own SMTP server? =

Yes. Heads Up Mailer sends via `wp_mail()`, so whatever you configure as the WordPress mail transport — for example via WP Mail SMTP — is what it will use.

= Does the WooCommerce integration work with Block checkout? =

Not in 1.0.0. The integration hooks `woocommerce_after_checkout_billing_form`, which only fires under classic / shortcode checkout. Block checkout support is tracked as a follow-up. The auto-enrol-customers-to-a-group flow IS Block-compatible (it hooks `woocommerce_checkout_order_processed`); only the per-group opt-in checkboxes need the additional Blocks code path.

= How do I disable auto-updates? =

Add `add_filter( 'hum_updater_enabled', '__return_false' );` to your site's `functions.php` or an mu-plugin. Useful for staging environments or pinning to a known-good version during a release window.

= Where can I read more? =

* `README.md` in the plugin folder — feature overview and pointers.
* `CHANGELOG.md` — full per-version release notes.
* [REST API reference for AI agents](https://github.com/headwalluk/heads-up-mailer/blob/master/docs/ai-agent-rest-guide.md) — lives in the GitHub repo, not in the installed zip.

== Changelog ==

= 1.5.0 =
Adds optional autonomous sending: a new REST route lets a trusted agent trigger a send without a human pressing Send. It is gated by two fail-safe controls that both default OFF — a site-wide master switch (Settings → Sending) and a per-group "Allow autonomous send" flag — so a send only proceeds when the switch is on and every target group is opted in. A separate `hum_send_newsletters` capability keeps send rights revocable independently of drafting; the Sent log gains an Auto/Manual "Trigger" column. Also promotes the bulk Delete action on the Subscribers list. Schema version 4 (auto-migrates; no behaviour change until you opt a group in). Bundled translations refreshed for all eight locales (machine-translated; a native-speaker polish of the short labels is still pending). See `CHANGELOG.md` for the full entry.

= 1.4.0 =
Adds a `checked` option to the Contact Form 7 `[hum_signup]` tag, so a sign-up checkbox can render pre-ticked — handy for a dedicated subscribe page where you want to suggest the most popular list. The visitor can still untick it, and only ticked boxes enrol. No schema change. See `CHANGELOG.md` for the full entry.

= 1.3.0 =
Turns the placeholder dashboard into a privacy-first overview of the last 30 days: send health with a failure-rate alert banner, audience size, a per-group breakdown of active members plus sign-ups and departures, and recent send failures. Adds a `hum_events` activity log (schema version 3, auto-migrates) to track per-group joins and leaves — these counts are forward-looking and start accumulating from the upgrade. Also polishes the group "add" screen: the slug auto-generates from the name with an opt-in manual override, the name field is full-width, and the description notes it's plain text. See `CHANGELOG.md` for the full entry.

= 1.2.2 =
Fixes machine-translation artifacts in the bundled catalogues. Short, context-free admin labels were mistranslated identically across all eight locales — "Sent" rendered in the "late/delayed" sense, "Folder" as "brochure", and the acronyms "TLS" and "ID" expanded into prose. Corrected and recompiled. No code or schema change. See `CHANGELOG.md` for the full entry.

= 1.2.1 =
Ships translation catalogues for eight locales (de_DE, el_GR, en_GB, es_ES, fr_FR, it_IT, nl_NL, pl_PL), machine-kickstarted and committed so they land in the release zip. Also fixes a latent plural bug: the .po files had no Plural-Forms header, so plural strings would not have pluralised. No code or schema change. See `CHANGELOG.md` for the full entry.

= 1.2.0 =
Admin polish. The Drafts list now has a Groups column showing each draft's selected groups as pills, and the draft editor's Subject field is full width. No schema migration, no behavioural change to sending. See `CHANGELOG.md` for the full entry.

= 1.1.0 =
New custom capability `hum_create_drafts`, granted to Administrator and Editor on upgrade. The REST endpoint that the AI agent posts drafts to now gates on this cap instead of `manage_options`, so the agent account can sit at the Editor role rather than Administrator. Existing admin REST callers keep working — Administrator gets the new cap automatically. No schema migration. See `CHANGELOG.md` for the full entry.

= 1.0.0 =
First stable release. Replaces MailerLite at headwall-hosting.com. Stack covers: drafts via REST → admin review → async send queue → RFC 8058 unsubscribe → public `/manage-comms/` page → IMAP poll for mailto unsubscribes → sent log → never-contact status → Contact Form 7 + WooCommerce integrations → in-plugin GitHub auto-updater. See `CHANGELOG.md` in the plugin folder for the detailed per-feature history (0.1.0 through 0.10.1).

== Upgrade Notice ==

= 1.5.0 =
Optional autonomous sending over REST, plus schema version 4 (migrates automatically on upgrade). Both new gates ship OFF, so nothing sends autonomously until you enable the master switch and opt a group in. No action required to upgrade.

= 1.4.0 =
Optional `checked` attribute for the `[hum_signup]` CF7 tag to pre-tick a sign-up checkbox. No action required.

= 1.3.0 =
New admin dashboard plus a `hum_events` table (schema version 3, migrates automatically on upgrade). Per-group sign-up / departure tracking starts from this release; existing memberships are not backfilled. No action required.

= 1.2.2 =
Corrects mistranslated admin labels (Sent, Folder, TLS, ID) in the bundled catalogues for all eight locales. No action required.

= 1.2.1 =
Adds bundled translations for eight locales and fixes plural-form handling in the catalogues. No action required.

= 1.2.0 =
Cosmetic admin update: Groups column on the Drafts list and a full-width Subject field in the editor. No action required.

= 1.1.0 =
Adds the `hum_create_drafts` capability and grants it to Administrator + Editor on first admin pageload after upgrade. Lets you demote the AI-agent user from Administrator to Editor.

= 1.0.0 =
First stable release. Existing 0.x deployments upgrade in place via the in-plugin GitHub updater. No schema migration required.
