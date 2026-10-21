<?php
/**
 * Admin menu, notices, assets, the Dashboard and Settings pages, and the mailbox AJAX endpoints.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Admin menu, notices, assets, the Dashboard and Settings pages, and the mailbox AJAX endpoints.
 *
 * @since 1.8.0
 */
class Admin {

	/**
	 * Groups page.
	 *
	 * @since 1.8.0
	 * @var Admin_Groups
	 */
	private Admin_Groups $groups_page;

	/**
	 * Subscribers page.
	 *
	 * @since 1.8.0
	 * @var Admin_Subscribers
	 */
	private Admin_Subscribers $subscribers_page;

	/**
	 * Drafts and Sent log pages.
	 *
	 * @since 1.8.0
	 * @var Admin_Drafts
	 */
	private Admin_Drafts $drafts_page;

	/**
	 * Take the page objects whose renderers the menu points at.
	 *
	 * @since 1.8.0
	 * @param Admin_Groups      $groups_page      Groups page.
	 * @param Admin_Subscribers $subscribers_page Subscribers page.
	 * @param Admin_Drafts      $drafts_page      Drafts and Sent log pages.
	 */
	public function __construct( Admin_Groups $groups_page, Admin_Subscribers $subscribers_page, Admin_Drafts $drafts_page ) {
		$this->groups_page      = $groups_page;
		$this->subscribers_page = $subscribers_page;
		$this->drafts_page      = $drafts_page;
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.8.0
	 */
	public function run(): void {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_hum_test_mailbox', array( $this, 'ajax_test_mailbox' ) );
		add_action( 'wp_ajax_hum_poll_mailbox', array( $this, 'ajax_poll_mailbox' ) );

		$this->groups_page->run();
		$this->subscribers_page->run();
		$this->drafts_page->run();
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
			array( $this->subscribers_page, 'render_subscribers' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Groups', 'heads-up-mailer' ),
			__( 'Groups', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-groups',
			array( $this->groups_page, 'render_groups' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Drafts', 'heads-up-mailer' ),
			__( 'Drafts', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-drafts',
			array( $this->drafts_page, 'render_drafts' )
		);

		add_submenu_page(
			'heads-up-mailer',
			__( 'Sent log', 'heads-up-mailer' ),
			__( 'Sent log', 'heads-up-mailer' ),
			'manage_options',
			'heads-up-mailer-sent-log',
			array( $this->drafts_page, 'render_sent_log' )
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
}
