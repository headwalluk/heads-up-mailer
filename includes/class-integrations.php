<?php
/**
 * Integrations registry.
 *
 * @package Heads_Up_Mailer
 * @since 0.9.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Holds the list of registered `Integration` instances and
 * applies the `hum_integrations` filter so built-ins and
 * third-parties register through a single hook.
 *
 * Wiring:
 *
 *   1. `Plugin::run()` instantiates `Integrations`.
 *   2. Each integration file (`integrations/<slug>/class-<slug>.php`)
 *      attaches an `add_filter( 'hum_integrations', ... )` callback
 *      at file-load time. The callback receives the registry
 *      instance, calls `register()`, and returns it.
 *   3. `Integrations::run()` applies the filter (collecting every
 *      registered integration), then loops the active ones and
 *      calls `register_hooks()` on each.
 *   4. The Settings page reads back from the same registry to
 *      render either active sections or the "no integrations
 *      available" card with the available-integrations list.
 *
 * @since 0.9.0
 */
class Integrations {

	/**
	 * Every registered integration, in registration order.
	 *
	 * @since 0.9.0
	 * @var array<int, Integration>
	 */
	private array $integrations = array();

	/**
	 * Whether `run()` has been called (filter applied + hooks
	 * bound). Idempotency guard so a second call doesn't double-
	 * bind hooks.
	 *
	 * @since 0.9.0
	 * @var bool
	 */
	private bool $has_run = false;

	/**
	 * Register an integration. Called from `hum_integrations`
	 * filter callbacks; safe to call before or after `run()` —
	 * post-run registrations just don't get their hooks bound
	 * automatically and would need a manual `register_hooks()`
	 * call by the caller.
	 *
	 * @since 0.9.0
	 * @param Integration $integration Integration to register.
	 */
	public function register( Integration $integration ): void {
		$this->integrations[] = $integration;
	}

	/**
	 * Apply the `hum_integrations` filter to collect every
	 * registered integration, then bind hooks for the active
	 * ones.
	 *
	 * @since 0.9.0
	 */
	public function run(): void {
		if ( $this->has_run ) {
			return;
		}

		$this->has_run = true;

		// Filter passes the registry instance so callbacks can
		// `$registry->register( new ... )` and return the same
		// reference. Returning a non-Integrations value is
		// treated as a no-op.
		$result = apply_filters( 'hum_integrations', $this );

		if ( ! ( $result instanceof self ) ) {
			return;
		}

		foreach ( $this->get_active() as $integration ) {
			$integration->register_hooks();
		}
	}

	/**
	 * Every registered integration (active or not).
	 *
	 * @since 0.9.0
	 * @return array<int, Integration>
	 */
	public function get_all(): array {
		return $this->integrations;
	}

	/**
	 * Integrations whose parent plugin / theme is currently
	 * loaded.
	 *
	 * @since 0.9.0
	 * @return array<int, Integration>
	 */
	public function get_active(): array {
		$result = array();

		foreach ( $this->integrations as $integration ) {
			if ( $integration->is_active() ) {
				$result[] = $integration;
			}
		}

		return $result;
	}

	/**
	 * Integrations whose parent plugin / theme is NOT loaded.
	 * Used by the "Available integrations" card.
	 *
	 * @since 0.9.0
	 * @return array<int, Integration>
	 */
	public function get_available(): array {
		$result = array();

		foreach ( $this->integrations as $integration ) {
			if ( ! $integration->is_active() ) {
				$result[] = $integration;
			}
		}

		return $result;
	}
}
