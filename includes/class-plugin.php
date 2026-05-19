<?php
/**
 * Main Plugin Class
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Main plugin class. Hook registration is centralised here.
 *
 * WRONG: Accessed from anywhere via `global $hum_plugin;`.
 * RIGHT: Accessed from anywhere via get_plugin();
 *
 * @since 0.1.0
 */
class Plugin {

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'check_first_run' ), 1 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * Needed for bundled translations shipped in `languages/` (e.g. MU
	 * plugin installs, pre-approval distribution). WordPress
	 * auto-loads from wordpress.org language packs since 4.6.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'heads-up-mailer', false, dirname( HUM_BASENAME ) . '/languages' );
	}

	/**
	 * Install defaults and run schema migrations on first admin load.
	 *
	 * Mirrors `hum_activate()` for MU plugin installs, where activation
	 * hooks don't fire. Also runs the schema migration when
	 * `OPTION_DB_VERSION` is behind `DB_VERSION`.
	 *
	 * @since 0.1.0
	 */
	public function check_first_run(): void {
		$stored_version = get_option( OPTION_VERSION, false );

		if ( false === $stored_version ) {
			$database = new Database();
			$database->create_tables();

			$defaults = get_default_settings();

			foreach ( $defaults as $key => $value ) {
				if ( false === get_option( $key ) ) {
					add_option( $key, $value, '', 'yes' );
				}
			}

			add_option( OPTION_VERSION, HUM_VERSION, '', 'yes' );
			add_option( OPTION_DB_VERSION, DB_VERSION, '', 'yes' );
		} elseif ( HUM_VERSION !== $stored_version ) {
			// no action: version-bump migration handler will be added when needed.
			update_option( OPTION_VERSION, HUM_VERSION );
		}

		$stored_db_version = (int) get_option( OPTION_DB_VERSION, 0 );

		if ( $stored_db_version < DB_VERSION ) {
			$database = new Database();
			$database->create_tables();
			update_option( OPTION_DB_VERSION, DB_VERSION );
		}
	}

	/**
	 * Render admin notices.
	 *
	 * Currently warns when the PHP `imap` extension is missing —
	 * needed by the mailbox poller for mailto-form unsubscribes.
	 * Sending still works without it; only the poller is affected.
	 *
	 * @since 0.1.0
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( extension_loaded( 'imap' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Heads Up Mailer: PHP imap extension missing.', 'heads-up-mailer' ),
			esc_html__( 'The mailbox poller used for mailto-form unsubscribes requires the PHP imap extension. Sending still works without it.', 'heads-up-mailer' )
		);
	}
}
