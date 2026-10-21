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
	 * Plugin integrations registry, populated during `run()` so
	 * the Settings tab + admin pages can read it back via
	 * `get_integrations()`.
	 *
	 * @since 0.9.0
	 * @var ?Integrations
	 */
	private ?Integrations $integrations = null;

	/**
	 * Integrations registry accessor — used by the Integrations
	 * settings tab template and any admin page that needs to
	 * surface integration state.
	 *
	 * @since 0.9.0
	 */
	public function get_integrations(): Integrations {
		if ( null === $this->integrations ) {
			$this->integrations = new Integrations();
		}

		return $this->integrations;
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public function run(): void {
		$settings = new Settings();
		$settings->run();

		$rest = new REST_Controller();
		$rest->run();

		$worker = new Worker();
		$worker->run();

		$poller = new Mailbox_Poller();
		$poller->run();

		$public = new Public_Controller();
		$public->run();

		// Run integrations on `plugins_loaded` priority 20 so
		// parent plugins (CF7, WooCommerce) have finished loading
		// and `is_active()` checks return reliable answers.
		add_action( 'plugins_loaded', array( $this, 'run_integrations' ), 20 );

		// Updater only matters in admin / cron contexts — the
		// `update_plugins` transient is read on those code paths.
		// Skipping init on front-end requests saves a small but
		// real amount of per-request work.
		if ( is_admin() || wp_doing_cron() ) {
			$updater = new Github_Updater();
			$updater->run();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'check_first_run' ), 1 );

		// admin-post.php and admin-ajax.php requests are is_admin() too.
		if ( is_admin() ) {
			$admin = new Admin( new Admin_Groups(), new Admin_Subscribers(), new Admin_Drafts() );
			$admin->run();
		}
	}

	/**
	 * `plugins_loaded` (priority 20) — apply the
	 * `hum_integrations` filter and bind hooks for active
	 * integrations. Priority 20 leaves room for parent plugins
	 * loading at priority 10 / 15.
	 *
	 * @since 0.9.0
	 */
	public function run_integrations(): void {
		$this->get_integrations()->run();
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

			$groups = new Groups_Controller();
			$groups->seed_defaults();

			hum_ensure_caps();

			add_option( OPTION_VERSION, HUM_VERSION, '', 'yes' );
			add_option( OPTION_DB_VERSION, DB_VERSION, '', 'yes' );
		} elseif ( HUM_VERSION !== $stored_version ) {
			// Backfill any new-in-this-version option defaults
			// that don't exist yet. `add_option()` is a no-op for
			// existing keys, so this is safe to run on every
			// upgrade without clobbering admin-edited values.
			foreach ( get_default_settings() as $key => $value ) {
				if ( false === get_option( $key ) ) {
					add_option( $key, $value, '', 'yes' );
				}
			}

			hum_ensure_caps();

			update_option( OPTION_VERSION, HUM_VERSION );
		} else {
			// no action: already installed at this version.
		}

		$stored_db_version = (int) get_option( OPTION_DB_VERSION, 0 );

		if ( $stored_db_version < DB_VERSION ) {
			$database = new Database();
			$database->create_tables();
			update_option( OPTION_DB_VERSION, DB_VERSION );
		}
	}
}
