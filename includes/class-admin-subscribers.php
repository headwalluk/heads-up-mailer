<?php
/**
 * Subscribers admin page: list, edit form, CSV import and their form handlers.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Subscribers admin page: list, edit form, CSV import and their form handlers.
 *
 * @since 1.8.0
 */
class Admin_Subscribers {

	/**
	 * Views that render a form instead of the list table.
	 *
	 * @since 1.8.0
	 */
	private const FORM_VIEWS = array( 'add', 'edit', 'import', 'imported' );

	/**
	 * List table, built on `load-{hook}` so Screen Options can see its columns.
	 *
	 * @since 1.8.0
	 * @var ?Subscribers_List_Table
	 */
	private ?Subscribers_List_Table $list_table = null;

	/**
	 * Register hooks.
	 *
	 * @since 1.8.0
	 */
	public function run(): void {
		add_action( 'admin_post_hum_save_subscriber', array( $this, 'handle_save_subscriber' ) );
		add_action( 'admin_post_hum_delete_subscriber', array( $this, 'handle_delete_subscriber' ) );
		add_action( 'admin_post_hum_mark_never_contact', array( $this, 'handle_mark_never_contact' ) );
		add_action( 'admin_post_hum_csv_import', array( $this, 'handle_csv_import' ) );

		// Core saves Screen Options before `load-{hook}` fires, so this can't wait for it.
		add_filter( 'set_screen_option_' . META_SUBSCRIBERS_PER_PAGE, array( $this, 'save_per_page_option' ), 10, 3 );
	}

	/**
	 * Sanitised list state (search, filters, sort, page) from a query-string array.
	 *
	 * @since 1.8.0
	 * @param array<string, mixed> $query Raw query args, e.g. `$_GET`.
	 * @return array<string, string|int> Only the keys that are set and valid.
	 */
	public static function list_state( array $query ): array {
		$state = array();

		$search  = isset( $query['s'] ) && is_string( $query['s'] ) ? trim( sanitize_text_field( wp_unslash( $query['s'] ) ) ) : '';
		$status  = isset( $query['status'] ) && is_string( $query['status'] ) ? sanitize_key( $query['status'] ) : '';
		$group   = isset( $query['group'] ) && is_numeric( $query['group'] ) ? (int) $query['group'] : 0;
		$orderby = isset( $query['orderby'] ) && is_string( $query['orderby'] ) ? sanitize_key( $query['orderby'] ) : '';
		$order   = isset( $query['order'] ) && is_string( $query['order'] ) ? strtolower( $query['order'] ) : '';
		$paged   = isset( $query['paged'] ) && is_numeric( $query['paged'] ) ? (int) $query['paged'] : 0;

		if ( '' !== $search ) {
			$state['s'] = $search;
		}

		if ( in_array( $status, Subscribers_Controller::statuses(), true ) ) {
			$state['status'] = $status;
		}

		if ( $group > 0 || GROUP_FILTER_NONE === $group ) {
			$state['group'] = $group;
		}

		if ( isset( SUBSCRIBER_SORT_COLUMNS[ $orderby ] ) ) {
			$state['orderby'] = $orderby;
		}

		if ( 'asc' === $order || 'desc' === $order ) {
			$state['order'] = $order;
		}

		if ( $paged > 1 ) {
			$state['paged'] = $paged;
		}

		return $state;
	}

	/**
	 * Subscribers list URL carrying the given state and notice flags.
	 *
	 * @since 1.8.0
	 * @param array<string, string|int> $args State from `list_state()` plus any flags.
	 * @return string
	 */
	public static function list_url( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => SUBSCRIBERS_PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * List URL to return to after a single-row action: the referring list view if there was one.
	 *
	 * @since 1.8.0
	 * @param array<string, string> $flags Notice flags to add.
	 * @return string
	 */
	private static function return_url( array $flags ): string {
		$state   = array();
		$referer = wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			$referer_query = array();
			wp_parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $referer_query );

			if ( SUBSCRIBERS_PAGE_SLUG === ( $referer_query['page'] ?? '' ) && ! isset( $referer_query['action'] ) ) {
				$state = self::list_state( $referer_query );
			}
		}

		return self::list_url( array_merge( $state, $flags ) );
	}

	/**
	 * `load-{hook}` for the Subscribers page: Screen Options, then any bulk action, before output.
	 *
	 * @since 1.8.0
	 */
	public function load_list_screen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view dispatch.
		$view = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( ! in_array( $view, self::FORM_VIEWS, true ) ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Subscribers per page', 'heads-up-mailer' ),
					'default' => DEF_SUBSCRIBERS_PER_PAGE,
					'option'  => META_SUBSCRIBERS_PER_PAGE,
				)
			);
			add_filter( 'default_hidden_columns', array( $this, 'default_hidden_columns' ), 10, 2 );

			$list_table = $this->get_list_table();
			$this->process_list_request( $list_table );
			$list_table->prepare_items();
		}
	}

	/**
	 * Build the list table on first use.
	 *
	 * @since 1.8.0
	 * @return Subscribers_List_Table
	 */
	private function get_list_table(): Subscribers_List_Table {
		if ( null === $this->list_table ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once HUM_PATH . 'includes/class-subscribers-list-table.php';

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state; sanitised by list_state().
			$this->list_table = new Subscribers_List_Table( self::list_state( $_GET ) );
		}

		return $this->list_table;
	}

	/**
	 * Apply a submitted bulk action, or tidy the URL after a search/filter submit, then redirect.
	 *
	 * @since 1.8.0
	 * @param Subscribers_List_Table $list_table The list table.
	 */
	private function process_list_request( Subscribers_List_Table $list_table ): void {
		$bulk_action = $list_table->current_action();

		// Core only reads the top select; common.js mirrors the bottom one into it,
		// so without JS the bottom Apply would otherwise be ignored.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below before anything is applied.
		$bottom_action = isset( $_GET['action2'] ) && is_string( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';

		if ( false === $bulk_action && '' !== $bottom_action && '-1' !== $bottom_action && empty( $_GET['filter_action'] ) ) {
			$bulk_action = $bottom_action;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state; sanitised by list_state(). Bulk actions verify the nonce below.
		$state = self::list_state( $_GET );

		if ( false !== $bulk_action ) {
			check_admin_referer( 'bulk-subscribers' );

			$raw_ids = isset( $_GET['subscriber_ids'] ) && is_array( $_GET['subscriber_ids'] )
				? array_map( 'absint', wp_unslash( $_GET['subscriber_ids'] ) )
				: array();
			$ids     = array_values( array_filter( $raw_ids ) );

			wp_safe_redirect( self::list_url( array_merge( $state, $this->apply_bulk_action( (string) $bulk_action, $ids ) ) ) );
			exit;
		} elseif ( ! empty( $_GET['_wp_http_referer'] ) ) {
			// A search or filter submit carries the bulk nonce and empty bulk selects; drop them.
			wp_safe_redirect( self::list_url( $state ) );
			exit;
		} else {
			// no action: a plain list view.
		}
	}

	/**
	 * Run a bulk action over subscriber IDs.
	 *
	 * @since 1.8.0
	 * @param string          $bulk_action `never_contact` or `delete`.
	 * @param array<int, int> $ids         Subscriber IDs.
	 * @return array<string, string> Notice flags for the redirect.
	 */
	private function apply_bulk_action( string $bulk_action, array $ids ): array {
		$flags      = array();
		$controller = new Subscribers_Controller();
		$count      = 0;

		if ( empty( $ids ) ) {
			$flags['error'] = 'hum_bulk_no_selection';
		} elseif ( 'never_contact' === $bulk_action ) {
			foreach ( $ids as $id ) {
				if ( true === $controller->mark_never_contact( $id ) ) {
					++$count;
				}
			}

			$flags['bulk_never_contact'] = (string) $count;
		} elseif ( 'delete' === $bulk_action ) {
			foreach ( $ids as $id ) {
				if ( true === $controller->delete( $id ) ) {
					++$count;
				}
			}

			$flags['bulk_deleted'] = (string) $count;
		} else {
			$flags['error'] = 'hum_bulk_unknown_action';
		}

		return $flags;
	}

	/**
	 * `set_screen_option_{option}`: store the per-page value, clamped.
	 *
	 * @since 1.8.0
	 * @param mixed $screen_option Value to store; false skips saving.
	 * @param mixed $option        Option name.
	 * @param mixed $value         Submitted value.
	 * @return mixed
	 */
	public function save_per_page_option( mixed $screen_option, mixed $option, mixed $value ): mixed {
		if ( META_SUBSCRIBERS_PER_PAGE === $option && is_numeric( $value ) ) {
			$screen_option = min( DEF_SUBSCRIBERS_PER_PAGE_MAX, max( 1, (int) $value ) );
		}

		return $screen_option;
	}

	/**
	 * `default_hidden_columns`: hide "Added" until the user shows it.
	 *
	 * @since 1.8.0
	 * @param mixed $hidden Hidden column keys.
	 * @param mixed $screen Current screen.
	 * @return mixed
	 */
	public function default_hidden_columns( mixed $hidden, mixed $screen ): mixed {
		if ( is_array( $hidden ) && $screen instanceof \WP_Screen && null !== $this->list_table && $screen->id === $this->list_table->screen->id ) {
			$hidden[] = 'created_at';
		}

		return $hidden;
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
			$list_table = $this->get_list_table();

			// Prepared on `load-{hook}`; only a render without that hook gets here unprepared.
			if ( null === $list_table->items ) {
				$list_table->prepare_items();
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
		$flags      = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'deleted' => '1' );

		wp_safe_redirect( self::return_url( $flags ) );
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
		$flags      = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'never_contact' => '1' );

		wp_safe_redirect( self::return_url( $flags ) );
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
}
