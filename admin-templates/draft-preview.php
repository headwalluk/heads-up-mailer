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

// XSS-safe because the parent iframe is rendered with `sandbox=""`
// (no allow-list) — scripts, forms, and same-origin DOM access are
// all disabled regardless of body content.
echo $draft->html_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sandboxed iframe; raw HTML is the point of the preview.
