<?php
/**
 * Abstract base class for third-party plugin integrations.
 *
 * @package Heads_Up_Mailer
 * @since 0.9.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * One integration per parent plugin / theme. Subclasses live in
 * `integrations/<slug>/class-<slug>.php`, register themselves on
 * the `hum_integrations` filter, and Heads Up Mailer treats them
 * uniformly:
 *
 *   - `slug()` is a unique stable identifier used in option keys
 *     and admin URLs. Lowercase, kebab-case.
 *   - `label()` is the integration's display name (often matches
 *     the parent plugin).
 *   - `parent_label()` is the parent plugin / theme name used in
 *     the "available, but you need to install X" copy.
 *   - `is_active()` returns true only when the parent plugin is
 *     loaded. Cheap to call — typically `class_exists()` or
 *     `function_exists()`.
 *   - `register_hooks()` is only called when `is_active()` returns
 *     true. Bind the WordPress hooks the integration needs here.
 *   - `render_settings_section()` is only called when the
 *     integration is active. Renders the integration's section
 *     inside the Integrations settings tab. Code-first PHP,
 *     `printf` / `echo`.
 *
 * Concrete subclasses are free to add their own constants,
 * helpers, and option keys.
 *
 * @since 0.9.0
 */
abstract class Integration {

	/**
	 * Stable kebab-case identifier — used in option keys / URLs.
	 *
	 * @since 0.9.0
	 */
	abstract public function slug(): string;

	/**
	 * Integration display name (often matches the parent plugin).
	 *
	 * @since 0.9.0
	 */
	abstract public function label(): string;

	/**
	 * Parent plugin / theme name as the admin would recognise it.
	 * Used in the "available, but install X first" copy on the
	 * Integrations tab.
	 *
	 * @since 0.9.0
	 */
	abstract public function parent_label(): string;

	/**
	 * True when the parent plugin / theme is loaded and the
	 * integration can safely bind its hooks.
	 *
	 * @since 0.9.0
	 */
	abstract public function is_active(): bool;

	/**
	 * Bind WordPress hooks. Only called when `is_active()` is
	 * true — implementations don't need to guard internally.
	 *
	 * @since 0.9.0
	 */
	abstract public function register_hooks(): void;

	/**
	 * Render the integration's section inside the Integrations
	 * settings tab. Only called when active.
	 *
	 * Implementations should use `printf` / `echo` and escape on
	 * output (`esc_html`, `esc_attr`, `esc_url`).
	 *
	 * @since 0.9.0
	 */
	abstract public function render_settings_section(): void;
}
