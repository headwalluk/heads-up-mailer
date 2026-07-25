<?php
/**
 * Admin: Standalone iframe preview of a draft's HTML body.
 *
 * Served via `admin-post.php?action=hum_preview_draft` so no admin
 * chrome is emitted. Email HTML is typically a full document
 * (MJML output, etc.), so the body is echoed as-is — adding our
 * own `<html>`/`<body>` wrapper would nest the document and break
 * `<style>` selectors targeting `body`.
 *
 * Variables expected from the caller (`Plugin::handle_preview_draft`):
 *
 * - `$draft` object Draft row.
 *
 * @package Heads_Up_Mailer
 * @since 0.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Frame-Options: SAMEORIGIN' );
header( 'X-Content-Type-Options: nosniff' );

// The body is echoed unescaped — raw HTML is the whole point of a
// preview, and `html_body` is deliberately stored unsanitised so MJML
// output and conditional comments survive.
//
// The `sandbox` CSP directive (empty value = deny everything: scripts,
// forms, plugins, same-origin DOM access, top-level navigation) is what
// makes that safe. It MUST be sent as a header rather than relying only
// on the parent's `sandbox=""` iframe attribute: an iframe attribute is
// a property of the embedding context, so it does nothing when this URL
// is loaded as a top-level document — which any admin can do via the
// browser's "Open Frame in New Tab". The header travels with the
// response and therefore applies in both cases.
//
// Images and inline `<style>` are unaffected, so the preview still
// renders faithfully.
header( 'Content-Security-Policy: sandbox' );

echo $draft->html_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Neutralised by the `Content-Security-Policy: sandbox` header above; raw HTML is the point of the preview.
