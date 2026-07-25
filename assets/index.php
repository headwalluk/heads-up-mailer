<?php
/**
 * Silence is golden.
 *
 * Directory-listing guard for the plugin's static assets (this directory stays web-readable by design). The canonical one-line
 * `// Silence is golden.` form trips WPCS's file-comment rule, so the
 * same sentiment is expressed as a docblock to keep `phpcs` clean.
 *
 * @package Heads_Up_Mailer
 * @since 1.6.0
 */

defined( 'ABSPATH' ) || die();
