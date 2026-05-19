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
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_hum_save_group', array( $this, 'handle_save_group' ) );
		add_action( 'admin_post_hum_delete_group', array( $this, 'handle_delete_group' ) );
		add_action( 'admin_post_hum_save_subscriber', array( $this, 'handle_save_subscriber' ) );
		add_action( 'admin_post_hum_delete_subscriber', array( $this, 'handle_delete_subscriber' ) );
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
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @since 0.1.0
	 */
	public function enqueue_admin_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read to scope asset loading.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$hum_pages = array( 'heads-up-mailer', 'heads-up-mailer-groups', 'heads-up-mailer-subscribers' );

		if ( ! in_array( $page, $hum_pages, true ) ) {
			return;
		}

		wp_enqueue_script(
			'heads-up-mailer-admin',
			HUM_URL . 'assets/admin/heads-up-mailer-admin.js',
			array(),
			HUM_VERSION,
			true
		);
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
			'slug'        => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
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
