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
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_hum_save_group', array( $this, 'handle_save_group' ) );
		add_action( 'admin_post_hum_delete_group', array( $this, 'handle_delete_group' ) );
		add_action( 'admin_post_hum_save_subscriber', array( $this, 'handle_save_subscriber' ) );
		add_action( 'admin_post_hum_delete_subscriber', array( $this, 'handle_delete_subscriber' ) );
		add_action( 'admin_post_hum_mark_never_contact', array( $this, 'handle_mark_never_contact' ) );
		add_action( 'admin_post_hum_bulk_subscribers', array( $this, 'handle_bulk_subscribers' ) );
		add_action( 'admin_post_hum_csv_import', array( $this, 'handle_csv_import' ) );
		add_action( 'admin_post_hum_save_draft', array( $this, 'handle_save_draft' ) );
		add_action( 'admin_post_hum_delete_draft', array( $this, 'handle_delete_draft' ) );
		add_action( 'admin_post_hum_preview_draft', array( $this, 'handle_preview_draft' ) );
		add_action( 'admin_post_hum_send_draft', array( $this, 'handle_send_draft' ) );
		add_action( 'wp_ajax_hum_test_mailbox', array( $this, 'ajax_test_mailbox' ) );
		add_action( 'wp_ajax_hum_poll_mailbox', array( $this, 'ajax_poll_mailbox' ) );
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

		// Credential encryption fails closed when AUTH_KEY is missing,
		// too short, or still the wp-config sample placeholder. Without
		// this notice the symptom would be a mailbox password that
		// silently refuses to save.
		if ( ! Crypto::has_usable_key_material() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Heads Up Mailer: AUTH_KEY is missing or insecure.', 'heads-up-mailer' ),
				esc_html__( 'Mailbox credentials cannot be encrypted, so they will not be saved. Set a unique AUTH_KEY in wp-config.php (use the WordPress salt generator), then re-enter the mailbox password.', 'heads-up-mailer' )
			);

			return;
		}

		if ( ! extension_loaded( 'imap' ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Heads Up Mailer: PHP imap extension missing.', 'heads-up-mailer' ),
				esc_html__( 'The mailbox poller used for mailto-form unsubscribes requires the PHP imap extension. Sending still works without it.', 'heads-up-mailer' )
			);

			return;
		}

		// Stale-poll warning. Only show when creds are configured
		// AND polling is enabled — otherwise the admin hasn't asked
		// us to poll yet (or has explicitly paused it) and a
		// "stale" notice would be noise.
		$poll_enabled = (bool) filter_var( get_option( OPTION_MAILBOX_POLL_ENABLED, DEF_MAILBOX_POLL_ENABLED ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $poll_enabled ) {
			return;
		}

		$host          = (string) get_option( OPTION_MAILBOX_HOST, '' );
		$user          = (string) get_option( OPTION_MAILBOX_USER, '' );
		$has_password  = '' !== (string) get_option( OPTION_MAILBOX_PASSWORD, '' );
		$creds_present = ( '' !== $host && '' !== $user && $has_password );

		if ( ! $creds_present ) {
			return;
		}

		$last_ok       = (int) get_option( OPTION_MAILBOX_LAST_OK, 0 );
		$is_stale      = ( $last_ok < ( time() - MAILBOX_STALE_THRESHOLD_SECONDS ) );
		$last_error    = (string) get_option( OPTION_MAILBOX_LAST_ERROR, '' );
		$last_error_at = (int) get_option( OPTION_MAILBOX_LAST_ERROR_AT, 0 );

		if ( ! $is_stale ) {
			return;
		}

		$detail = ( '' !== $last_error && $last_error_at > 0 )
			? sprintf(
				/* translators: 1: error message from imap_errors(), 2: relative time since the error. */
				__( 'Last attempt failed: %1$s (%2$s).', 'heads-up-mailer' ),
				$last_error,
				human_time_diff( $last_error_at )
			)
			: __( 'No successful poll on record yet. Check IMAP credentials and the cron schedule.', 'heads-up-mailer' );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Heads Up Mailer: mailbox poller is stale.', 'heads-up-mailer' ),
			esc_html( $detail )
		);
	}

	/**
	 * Register the top-level admin menu and its submenus.
	 *
	 * @since 0.1.0
	 */
	public function admin_menu(): void {
		add_menu_page(
			__( 'Heads Up Mailer', 'heads-up-mailer' ),
			__( 'Heads Up Mailer', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer',
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Dashboard', 'heads-up-mailer' ),
			__( 'Dashboard', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Subscribers', 'heads-up-mailer' ),
			__( 'Subscribers', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-subscribers',
			array( $this, 'render_subscribers' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Groups', 'heads-up-mailer' ),
			__( 'Groups', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-groups',
			array( $this, 'render_groups' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Drafts', 'heads-up-mailer' ),
			__( 'Drafts', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-drafts',
			array( $this, 'render_drafts' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Sent log', 'heads-up-mailer' ),
			__( 'Sent log', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-sent-log',
			array( $this, 'render_sent_log' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Settings', 'heads-up-mailer' ),
			__( 'Settings', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @since 0.1.0
	 */
	public function enqueue_admin_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read to scope asset loading.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$hum_pages = array(
			'heads-up-mailer',
			'heads-up-mailer-groups',
			'heads-up-mailer-subscribers',
			'heads-up-mailer-drafts',
			'heads-up-mailer-sent-log',
			'heads-up-mailer-settings',
		);

		if ( ! in_array( $page, $hum_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'heads-up-mailer-admin',
			HUM_URL . 'assets/admin/heads-up-mailer-admin.css',
			array(),
			HUM_VERSION
		);

		wp_enqueue_script(
			'heads-up-mailer-admin',
			HUM_URL . 'assets/admin/heads-up-mailer-admin.js',
			array(),
			HUM_VERSION,
			true
		);

		wp_localize_script(
			'heads-up-mailer-admin',
			'humAdminData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'testMailboxNonce' => wp_create_nonce( 'hum_test_mailbox' ),
				'pollMailboxNonce' => wp_create_nonce( 'hum_poll_mailbox' ),
			)
		);
	}

	/**
	 * Render the Settings page (tab wrapper + options.php form).
	 *
	 * @since 0.2.0
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		require HUM_PATH . 'admin-templates/settings-page.php';
	}

	/**
	 * Render the top-level dashboard page.
	 *
	 * @since 0.1.0
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		$overview = ( new Dashboard_Controller() )->get_overview();

		require HUM_PATH . 'admin-templates/dashboard.php';
	}

	/**
	 * Render the Groups submenu page. Dispatches between list,
	 * add, and edit views based on `$_GET['action']`.
	 *
	 * @since 0.1.0
	 */
	public function render_groups(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch; state changes happen via admin-post handlers.
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$controller = new Groups_Controller();

		if ( 'add' === $action || 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch.
			$group_id = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
			$group    = ( 'edit' === $action && $group_id > 0 ) ? $controller->get( $group_id ) : null;

			require HUM_PATH . 'admin-templates/group-edit.php';
		} else {
			$groups = $controller->get_all();

			require HUM_PATH . 'admin-templates/groups-list.php';
		}
	}

	/**
	 * Handle the "Save group" form submission (admin-post.php).
	 *
	 * @since 0.1.0
	 */
	public function handle_save_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		check_admin_referer( 'hum_save_group' );

		$group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;

		$data = array(
			'slug'                 => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'name'                 => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'description'          => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'is_private'           => ! empty( $_POST['is_private'] ),
			'allow_automated_send' => ! empty( $_POST['allow_automated_send'] ),
		);

		$controller = new Groups_Controller();

		$result = ( $group_id > 0 )
			? $controller->update( $group_id, $data )
			: $controller->create( $data );

		$redirect_args = array( 'page' => 'heads-up-mailer-groups' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error']  = $result->get_error_code();
			$redirect_args['action'] = ( $group_id > 0 ) ? 'edit' : 'add';

			if ( $group_id > 0 ) {
				$redirect_args['group_id'] = $group_id;
			}
		} else {
			$redirect_args['updated'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Subscribers submenu page. Dispatches between
	 * list, add, and edit views based on `$_GET['action']`.
	 *
	 * @since 0.1.0
	 */
	public function render_subscribers(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch; state changes via admin-post handlers.
		$action            = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$subs_controller   = new Subscribers_Controller();
		$groups_controller = new Groups_Controller();

		// Always available to forms and lists.
		$all_groups = $groups_controller->get_all();

		if ( 'add' === $action || 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch.
			$subscriber_id   = isset( $_GET['subscriber_id'] ) ? absint( $_GET['subscriber_id'] ) : 0;
			$subscriber      = ( 'edit' === $action && $subscriber_id > 0 ) ? $subs_controller->get( $subscriber_id ) : null;
			$attached_groups = ( null !== $subscriber ) ? $subs_controller->get_groups( (int) $subscriber->id ) : array();

			require HUM_PATH . 'admin-templates/subscriber-edit.php';
		} elseif ( 'import' === $action || 'imported' === $action ) {
			$report = null;

			if ( 'imported' === $action ) {
				$transient_key = 'hum_csv_report_' . get_current_user_id();
				$stored        = get_transient( $transient_key );

				if ( is_array( $stored ) ) {
					$report = $stored;
				}

				delete_transient( $transient_key );
			}

			require HUM_PATH . 'admin-templates/subscriber-import.php';
		} else {
			$subscribers  = $subs_controller->get_all();
			$groups_by_id = array();

			foreach ( $all_groups as $g ) {
				$groups_by_id[ (int) $g->id ] = $g;
			}

			$memberships = array();
			foreach ( $subscribers as $sub ) {
				$memberships[ (int) $sub->id ] = $subs_controller->get_groups( (int) $sub->id );
			}

			require HUM_PATH . 'admin-templates/subscribers-list.php';
		}
	}

	/**
	 * Handle the "Save subscriber" form submission.
	 *
	 * @since 0.1.0
	 */
	public function handle_save_subscriber(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		check_admin_referer( 'hum_save_subscriber' );

		$subscriber_id = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;

		$group_ids = ( isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) )
			? array_map( 'absint', wp_unslash( $_POST['groups'] ) )
			: array();

		$data = array(
			'email'          => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'status'         => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : STATUS_SUBSCRIBED,
			'consent_source' => isset( $_POST['consent_source'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_source'] ) ) : '',
			'consent_at'     => isset( $_POST['consent_at'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_at'] ) ) : '',
			'groups'         => $group_ids,
		);

		$controller = new Subscribers_Controller();

		$result = ( $subscriber_id > 0 )
			? $controller->update( $subscriber_id, $data )
			: $controller->create( $data );

		$redirect_args = array( 'page' => 'heads-up-mailer-subscribers' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error']  = $result->get_error_code();
			$redirect_args['action'] = ( $subscriber_id > 0 ) ? 'edit' : 'add';

			if ( $subscriber_id > 0 ) {
				$redirect_args['subscriber_id'] = $subscriber_id;
			}
		} else {
			$redirect_args['updated'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "Delete subscriber" action.
	 *
	 * @since 0.1.0
	 */
	public function handle_delete_subscriber(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( $_GET['subscriber_id'] ) : 0;
		check_admin_referer( 'hum_delete_subscriber_' . $subscriber_id );

		$controller = new Subscribers_Controller();
		$result     = $controller->delete( $subscriber_id );

		$redirect_args = array( 'page' => 'heads-up-mailer-subscribers' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['deleted'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "Mark as never contact" row action.
	 *
	 * Idempotent — re-running on an already-never-contact row
	 * succeeds silently (the controller method returns true).
	 *
	 * @since 0.8.0
	 */
	public function handle_mark_never_contact(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( $_GET['subscriber_id'] ) : 0;
		check_admin_referer( 'hum_mark_never_contact_' . $subscriber_id );

		$controller = new Subscribers_Controller();
		$result     = $controller->mark_never_contact( $subscriber_id );

		$redirect_args = array( 'page' => 'heads-up-mailer-subscribers' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['never_contact'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle bulk actions submitted from the subscribers list.
	 *
	 * Currently dispatches a single action — `never_contact` —
	 * flipping every selected subscriber via the same idempotent
	 * `mark_never_contact()` helper used by the row action and
	 * the public "unsubscribe me from everything" button.
	 *
	 * Extra actions can be added by extending the switch.
	 *
	 * @since 0.8.0
	 */
	public function handle_bulk_subscribers(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		check_admin_referer( 'hum_bulk_subscribers' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$raw_ids = isset( $_POST['subscriber_ids'] ) && is_array( $_POST['subscriber_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['subscriber_ids'] ) )
			: array();

		$ids = array_values( array_filter( $raw_ids, static fn( int $id ): bool => $id > 0 ) );

		$redirect_args = array( 'page' => 'heads-up-mailer-subscribers' );

		if ( '' === $action || empty( $ids ) ) {
			$redirect_args['error'] = 'hum_bulk_no_selection';
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$controller = new Subscribers_Controller();
		$count      = 0;

		if ( 'never_contact' === $action ) {
			foreach ( $ids as $id ) {
				$result = $controller->mark_never_contact( (int) $id );

				if ( true === $result ) {
					++$count;
				}
			}

			$redirect_args['bulk_never_contact'] = (string) $count;
		} elseif ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				$result = $controller->delete( (int) $id );

				if ( true === $result ) {
					++$count;
				}
			}

			$redirect_args['bulk_deleted'] = (string) $count;
		} else {
			$redirect_args['error'] = 'hum_bulk_unknown_action';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "Import CSV" form submission (admin-post.php).
	 *
	 * Stores the per-row report in a per-user transient and
	 * redirects to `?action=imported` for the result view.
	 *
	 * @since 0.1.0
	 */
	public function handle_csv_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		check_admin_referer( 'hum_csv_import' );

		$redirect_args = array(
			'page'   => 'heads-up-mailer-subscribers',
			'action' => 'imported',
		);

		$tmp   = '';
		$error = '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES has a fixed structure; values are validated via is_uploaded_file() below.
		$upload = isset( $_FILES['csv_file'] ) && is_array( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : null;

		if ( null === $upload ) {
			$error = __( 'No file was uploaded.', 'heads-up-mailer' );
		} else {
			$upl_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_OK !== $upl_error ) {
				$error = __( 'File upload failed.', 'heads-up-mailer' );
			} elseif ( ! isset( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
				$error = __( 'Uploaded file is not a valid upload.', 'heads-up-mailer' );
			} else {
				$tmp = (string) $upload['tmp_name'];
			}
		}

		if ( '' !== $error ) {
			$report = array(
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'errors'   => 1,
				'rows'     => array(
					array(
						'line'    => 0,
						'email'   => '',
						'status'  => 'errors',
						'message' => $error,
					),
				),
			);
		} else {
			$importer = new CSV_Importer();
			$report   = $importer->import_from_file( $tmp );
		}

		$transient_key = 'hum_csv_report_' . get_current_user_id();
		set_transient( $transient_key, $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX: test the IMAP mailbox credentials currently in the form.
	 *
	 * Submitted values are used directly; a blank password falls back
	 * to the stored encrypted value, decrypted in place. Nothing is
	 * persisted — this only exercises the connection.
	 *
	 * @since 0.2.0
	 */
	public function ajax_test_mailbox(): void {
		check_ajax_referer( 'hum_test_mailbox', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'heads-up-mailer' ) ) );
		}

		if ( ! extension_loaded( 'imap' ) ) {
			wp_send_json_error( array( 'message' => __( 'The PHP imap extension is not loaded on this host.', 'heads-up-mailer' ) ) );
		}

		$host          = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		$port          = isset( $_POST['port'] ) ? absint( $_POST['port'] ) : 0;
		$user          = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$folder        = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'INBOX';
		$tls           = isset( $_POST['tls'] ) && '1' === $_POST['tls'];
		$validate_cert = isset( $_POST['validate_cert'] ) && '1' === $_POST['validate_cert'];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must pass through verbatim; sanitize_text_field would strip valid characters.
		$pass_input = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		$pass = ( '' === $pass_input )
			? Crypto::decrypt( (string) get_option( OPTION_MAILBOX_PASSWORD, '' ) )
			: $pass_input;

		if ( '' === $host || $port < 1 || '' === $user || '' === $pass ) {
			wp_send_json_error(
				array(
					'message' => __( 'Host, port, username, and password are all required.', 'heads-up-mailer' ),
				)
			);
		}

		$protocol = $tls ? '/imap/ssl' : '/imap';

		if ( $tls && ! $validate_cert ) {
			$protocol .= '/novalidate-cert';
		}

		$mailbox = '{' . $host . ':' . $port . $protocol . '}' . $folder;

		// Clear any stale error stack from earlier requests.
		imap_errors();

		// Single retry to keep the AJAX timeout bounded. The `@` suppresses
		// the warning so we can surface a clean message from imap_errors().
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_open emits warnings that we surface via imap_errors().
		$conn = @imap_open( $mailbox, $user, $pass, 0, 1 );

		if ( false === $conn ) {
			$errors  = imap_errors();
			$last    = is_array( $errors ) && ! empty( $errors ) ? (string) end( $errors ) : '';
			$message = '' !== $last
				? $last
				: __( 'Failed to open IMAP connection.', 'heads-up-mailer' );

			wp_send_json_error( array( 'message' => $message ) );
		}

		imap_close( $conn );

		wp_send_json_success(
			array(
				/* translators: %s: folder name on the IMAP server. */
				'message' => sprintf( __( 'Connection successful — folder "%s" is reachable.', 'heads-up-mailer' ), $folder ),
			)
		);
	}

	/**
	 * AJAX: trigger a synchronous mailbox poll using the stored
	 * credentials.
	 *
	 * The same code path the WP-Cron tick runs, just invoked
	 * inline. Honours the poll lock so it can't overlap with a
	 * concurrent tick.
	 *
	 * @since 0.6.0
	 */
	public function ajax_poll_mailbox(): void {
		check_ajax_referer( 'hum_poll_mailbox', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'heads-up-mailer' ) ) );
		}

		$result = ( new Mailbox_Poller() )->poll_now();

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Render the Drafts submenu page. Dispatches between list, add,
	 * and edit views based on `$_GET['action']`.
	 *
	 * @since 0.3.0
	 */
	public function render_drafts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch; state changes via admin-post handlers.
		$action            = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$drafts_controller = new Drafts_Controller();
		$groups_controller = new Groups_Controller();

		if ( 'add' === $action || 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch.
			$draft_id        = isset( $_GET['draft_id'] ) ? absint( $_GET['draft_id'] ) : 0;
			$draft           = ( 'edit' === $action && $draft_id > 0 ) ? $drafts_controller->get( $draft_id ) : null;
			$all_groups      = $groups_controller->get_all();
			$suggested_slugs = $drafts_controller->suggested_groups( $draft );

			require HUM_PATH . 'admin-templates/draft-edit.php';
		} else {
			$drafts = $drafts_controller->get_all();

			$group_names = array();
			foreach ( $groups_controller->get_all() as $group ) {
				$group_names[ (string) $group->slug ] = (string) $group->name;
			}

			require HUM_PATH . 'admin-templates/drafts-list.php';
		}
	}

	/**
	 * Render the Sent-log submenu page.
	 *
	 * Dispatches between the list view and a per-send drill-down
	 * based on the `send_id` query param. The drill-down accepts an
	 * optional `status` filter (one of `SEND_STATUS_*`).
	 *
	 * @since 0.7.0
	 */
	public function render_sent_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'heads-up-mailer' ) );
		}

		$controller = new Sent_Log_Controller();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch; this surface is read-only.
		$send_id = isset( $_GET['send_id'] ) ? absint( $_GET['send_id'] ) : 0;

		if ( $send_id <= 0 ) {
			$sends = $controller->get_sends_with_counts();

			require HUM_PATH . 'admin-templates/sent-log-list.php';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch.
			$requested_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

			$allowed_statuses = array(
				SEND_STATUS_SENT,
				SEND_STATUS_FAILED,
				SEND_STATUS_PENDING,
				SEND_STATUS_PROCESSING,
			);

			$status_filter = in_array( $requested_status, $allowed_statuses, true ) ? $requested_status : '';

			$send       = $controller->get_send_with_counts( $send_id );
			$recipients = ( null === $send ) ? array() : $controller->get_recipients_for_send( $send_id, $status_filter );

			require HUM_PATH . 'admin-templates/sent-log-detail.php';
		}
	}

	/**
	 * Handle the "Save draft" form submission (admin-post.php).
	 *
	 * @since 0.3.0
	 */
	public function handle_save_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		check_admin_referer( 'hum_save_draft' );

		$draft_id = isset( $_POST['draft_id'] ) ? absint( $_POST['draft_id'] ) : 0;

		$suggested_groups = ( isset( $_POST['suggested_groups'] ) && is_array( $_POST['suggested_groups'] ) )
			? array_map( 'sanitize_title', wp_unslash( $_POST['suggested_groups'] ) )
			: array();

		$data = array(
			'subject'          => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTML body sanitised via wp_kses_post inside Drafts_Controller::validate().
			'html_body'        => isset( $_POST['html_body'] ) ? (string) wp_unslash( $_POST['html_body'] ) : '',
			'suggested_groups' => $suggested_groups,
		);

		$controller = new Drafts_Controller();

		$result = ( $draft_id > 0 )
			? $controller->update( $draft_id, $data )
			: $controller->create( $data );

		$redirect_args = array( 'page' => 'heads-up-mailer-drafts' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error']  = $result->get_error_code();
			$redirect_args['action'] = ( $draft_id > 0 ) ? 'edit' : 'add';

			if ( $draft_id > 0 ) {
				$redirect_args['draft_id'] = $draft_id;
			}
		} else {
			$new_id                    = ( $draft_id > 0 ) ? $draft_id : (int) $result;
			$redirect_args['action']   = 'edit';
			$redirect_args['draft_id'] = $new_id;
			$redirect_args['updated']  = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "Delete draft" action.
	 *
	 * @since 0.3.0
	 */
	public function handle_delete_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$draft_id = isset( $_GET['draft_id'] ) ? absint( $_GET['draft_id'] ) : 0;
		check_admin_referer( 'hum_delete_draft_' . $draft_id );

		$controller = new Drafts_Controller();
		$result     = $controller->delete( $draft_id );

		$redirect_args = array( 'page' => 'heads-up-mailer-drafts' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['deleted'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "Send" form submission (admin-post.php).
	 *
	 * Delegates to `Sends_Controller::queue()`, which runs pre-flight
	 * and performs the transactional insert. Redirects back to the
	 * drafts list on success (the draft is now `sending` and locked
	 * from edits until the worker finishes).
	 *
	 * @since 0.4.0
	 */
	public function handle_send_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$draft_id = isset( $_POST['draft_id'] ) ? absint( $_POST['draft_id'] ) : 0;
		check_admin_referer( 'hum_send_draft_' . $draft_id );

		$controller = new Sends_Controller();
		$result     = $controller->queue( $draft_id );

		$redirect_args = array( 'page' => 'heads-up-mailer-drafts' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error']    = $result->get_error_code();
			$redirect_args['action']   = 'edit';
			$redirect_args['draft_id'] = $draft_id;
		} else {
			$redirect_args['sent'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Serve the iframe preview of a draft's HTML body.
	 *
	 * Reached via `admin-post.php?action=hum_preview_draft` so no admin
	 * chrome is emitted around the body.
	 *
	 * @since 0.3.0
	 */
	public function handle_preview_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$draft_id = isset( $_GET['draft_id'] ) ? absint( $_GET['draft_id'] ) : 0;
		check_admin_referer( 'hum_preview_draft_' . $draft_id );

		$controller = new Drafts_Controller();
		$draft      = $controller->get( $draft_id );

		if ( null === $draft ) {
			wp_die( esc_html__( 'Draft not found.', 'heads-up-mailer' ), '', array( 'response' => 404 ) );
		}

		require HUM_PATH . 'admin-templates/draft-preview.php';
		exit;
	}

	/**
	 * Handle the "Delete group" action (admin-post.php).
	 *
	 * @since 0.1.0
	 */
	public function handle_delete_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'heads-up-mailer' ) );
		}

		$group_id = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
		check_admin_referer( 'hum_delete_group_' . $group_id );

		$controller = new Groups_Controller();
		$result     = $controller->delete( $group_id );

		$redirect_args = array( 'page' => 'heads-up-mailer-groups' );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['deleted'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
