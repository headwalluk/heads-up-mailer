<?php
/**
 * Settings registration and sanitisation.
 *
 * All plugin settings share the `hum_settings` option group so a
 * single `options.php` form can persist any subset of them. Each
 * setting is registered as its own `wp_option`, with its own
 * sanitize callback.
 *
 * @package Heads_Up_Mailer
 * @since 0.2.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Settings API integration.
 *
 * Instantiated by `Plugin::run()`; registers all settings on
 * `admin_init` so the `options.php` save flow has them available.
 *
 * @since 0.2.0
 */
class Settings {

	/**
	 * Settings group name. Used by `settings_fields()` in the form.
	 *
	 * @since 0.2.0
	 */
	public const GROUP = 'hum_settings';

	/**
	 * Register hooks.
	 *
	 * @since 0.2.0
	 */
	public function run(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
		// Slug changes invalidate the cached rewrite rule table. Hooked
		// here so a flush happens once, on save, rather than on every
		// admin pageview. M6 owns the `/manage-comms/` rule itself.
		add_action( 'update_option_' . OPTION_MANAGE_SLUG, array( $this, 'flush_rewrites_on_slug_change' ), 10, 2 );
	}

	/**
	 * Register every plugin setting under the shared group.
	 *
	 * @since 0.2.0
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			OPTION_BATCH_SIZE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
				'default'           => DEF_BATCH_SIZE,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_TICK_MINUTES,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_minutes' ),
				'default'           => DEF_TICK_MINUTES,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_FROM_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'default'           => DEF_FROM_NAME,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_FROM_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_from_email' ),
				'default'           => DEF_FROM_EMAIL,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_FOOTER_HTML,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_footer_html' ),
				'default'           => DEF_FOOTER_HTML,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MANAGE_SLUG,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slug' ),
				'default'           => DEF_MANAGE_SLUG,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_AUTONOMOUS_SEND_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
				'default'           => DEF_AUTONOMOUS_SEND_ENABLED,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_POLL_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
				'default'           => DEF_MAILBOX_POLL_ENABLED,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_HOST,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_PORT,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_port' ),
				'default'           => DEF_MAILBOX_PORT,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_USER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'default'           => DEF_MAILBOX_USER,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_PASSWORD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_password' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_FOLDER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'default'           => DEF_MAILBOX_FOLDER,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_TLS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
				'default'           => DEF_MAILBOX_TLS,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_VALIDATE_CERT,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
				'default'           => DEF_MAILBOX_VALIDATE_CERT,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_MAILBOX_INTERVAL,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_minutes' ),
				'default'           => DEF_MAILBOX_INTERVAL,
			)
		);

		register_setting(
			self::GROUP,
			OPTION_WC_CUSTOMERS_GROUP_SLUG,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_optional_slug' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			OPTION_WC_CHECKOUT_INTRO,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			OPTION_WC_CHECKOUT_GROUPS_JSON,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_wc_checkout_groups_json' ),
				'default'           => '{}',
			)
		);
	}

	/**
	 * Clamp batch size to 1..100.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return int
	 */
	public function sanitize_batch_size( $value ): int {
		return max( 1, min( 100, (int) $value ) );
	}

	/**
	 * Clamp a minutes value to 1..60.
	 *
	 * Used by both the queue tick interval and the mailbox polling
	 * interval.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return int
	 */
	public function sanitize_minutes( $value ): int {
		return max( 1, min( 60, (int) $value ) );
	}

	/**
	 * Clamp a TCP port to 1..65535.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return int
	 */
	public function sanitize_port( $value ): int {
		return max( 1, min( 65535, (int) $value ) );
	}

	/**
	 * Standard text sanitisation.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_text( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Normalise a checkbox / boolean-ish value to "1" / "0".
	 *
	 * Stored as a string so `get_option()` returns something
	 * `filter_var( ..., FILTER_VALIDATE_BOOLEAN )` recognises.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_boolean( $value ): string {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
	}

	/**
	 * Sanitise the mailbox password field.
	 *
	 * Empty input → keep the existing stored value. Non-empty input
	 * → encrypt via `Crypto::encrypt()`. The form never round-trips
	 * a decrypted value back to the browser; only the encrypted
	 * envelope is ever stored.
	 *
	 * @since 0.2.0
	 * @param mixed $value Raw form value.
	 * @return string Encrypted envelope, or existing value when input is blank.
	 */
	public function sanitize_password( $value ): string {
		$trimmed = trim( (string) $value );

		$result = ( '' === $trimmed )
			? (string) get_option( OPTION_MAILBOX_PASSWORD, '' )
			: Crypto::encrypt( $trimmed );

		return $result;
	}

	/**
	 * Sanitise the From: email field.
	 *
	 * Empty input is allowed — the send-time check will refuse to
	 * queue without a value. A non-empty but malformed value is
	 * rejected with a settings error; the previously stored value
	 * is preserved.
	 *
	 * @since 0.4.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_from_email( $value ): string {
		$trimmed = trim( (string) $value );
		$clean   = sanitize_email( $trimmed );
		$valid   = ( '' !== $clean ) && (bool) is_email( $clean );

		if ( '' !== $trimmed && ! $valid ) {
			add_settings_error(
				OPTION_FROM_EMAIL,
				'hum_invalid_from_email',
				__( 'From email address is not valid. The previous value was kept.', 'heads-up-mailer' )
			);
		}

		if ( '' === $trimmed ) {
			$result = '';
		} elseif ( $valid ) {
			$result = $clean;
		} else {
			$result = (string) get_option( OPTION_FROM_EMAIL, '' );
		}

		return $result;
	}

	/**
	 * Sanitise the footer HTML.
	 *
	 * `wp_kses_post` allows the usual post-content tags and inline
	 * styling (enough for a small footer block) but blocks scripts
	 * and unsafe protocols. The `{{unsubscribe_url}}` placeholder is
	 * plain text and passes through unchanged.
	 *
	 * @since 0.4.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_footer_html( $value ): string {
		return wp_kses_post( (string) wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitise the public unsubscribe slug.
	 *
	 * Falls back to the default on empty input — a blank slug would
	 * collide with the site root.
	 *
	 * @since 0.4.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_slug( $value ): string {
		$clean = sanitize_title( (string) $value );

		return ( '' === $clean ) ? DEF_MANAGE_SLUG : $clean;
	}

	/**
	 * Sanitize a slug, allowing empty (= "not set / disabled").
	 *
	 * Used by the WC integration's customers-group dropdown,
	 * where "leave blank to disable customer auto-tracking" is a
	 * valid choice.
	 *
	 * @since 0.9.0
	 * @param mixed $value Raw form value.
	 * @return string
	 */
	public function sanitize_optional_slug( $value ): string {
		return sanitize_title( (string) $value );
	}

	/**
	 * Sanitize the WC checkout-groups JSON map.
	 *
	 * Expected shape: `{ "<slug>": { "at_checkout": bool, "label": string }, ... }`.
	 *
	 * Anything that doesn't decode to a JSON object is replaced
	 * with `{}`; entries that don't match the expected shape are
	 * dropped silently so a tampered POST can't poison the
	 * option.
	 *
	 * @since 0.9.0
	 * @param mixed $value Raw form value (may be a string or already an array).
	 * @return string Canonical JSON-encoded map (no pretty-printing).
	 */
	public function sanitize_wc_checkout_groups_json( $value ): string {
		$decoded = is_string( $value ) ? json_decode( (string) $value, true ) : $value;

		if ( ! is_array( $decoded ) ) {
			return '{}';
		}

		$clean = array();

		foreach ( $decoded as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) ) {
				continue;
			}

			$clean_slug = sanitize_title( $slug );

			if ( '' === $clean_slug ) {
				continue;
			}

			$at_checkout = ! empty( $entry['at_checkout'] );
			$label       = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';

			$clean[ $clean_slug ] = array(
				'at_checkout' => $at_checkout,
				'label'       => $label,
			);
		}

		return (string) wp_json_encode( $clean );
	}

	/**
	 * Flush rewrite rules after the manage-comms slug changes.
	 *
	 * No-op for now — M6 will register the `/manage-comms/` rewrite
	 * rule itself. Wiring the flush here means slug changes pick
	 * up the new pattern immediately once M6 lands, without an
	 * extra "save permalinks" step.
	 *
	 * @since 0.4.0
	 * @param mixed $old_value Previous slug.
	 * @param mixed $new_value New slug.
	 */
	public function flush_rewrites_on_slug_change( $old_value, $new_value ): void {
		if ( (string) $old_value !== (string) $new_value ) {
			flush_rewrite_rules( false );
		}
	}
}
