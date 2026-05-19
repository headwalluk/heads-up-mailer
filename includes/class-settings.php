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
}
