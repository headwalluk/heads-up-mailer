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
	 * Register hooks.
	 *
	 * @since 1.8.0
	 */
	public function run(): void {
		add_action( 'admin_post_hum_save_subscriber', array( $this, 'handle_save_subscriber' ) );
		add_action( 'admin_post_hum_delete_subscriber', array( $this, 'handle_delete_subscriber' ) );
		add_action( 'admin_post_hum_mark_never_contact', array( $this, 'handle_mark_never_contact' ) );
		add_action( 'admin_post_hum_bulk_subscribers', array( $this, 'handle_bulk_subscribers' ) );
		add_action( 'admin_post_hum_csv_import', array( $this, 'handle_csv_import' ) );
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
			// Paginated list. Previously this rendered every subscriber
			// via get_all() and then issued one get_groups() query per
			// row — an N+1 that grew with the table. Now: one page of
			// rows, one query for their memberships, one for their
			// user-account linkage.
			$per_page = DEF_SUBSCRIBERS_PER_PAGE;
			$total    = $subs_controller->count_all();
			$pages    = max( 1, (int) ceil( $total / $per_page ) );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query-param read for view state; no state change.
			$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
			// Clamp, so deleting the last row on the final page (or a
			// hand-edited URL) can't land on an empty view.
			$paged = min( max( 1, $paged ), $pages );

			$subscribers  = $subs_controller->get_page( $per_page, ( $paged - 1 ) * $per_page );
			$groups_by_id = array();

			foreach ( $all_groups as $g ) {
				$groups_by_id[ (int) $g->id ] = $g;
			}

			$subscriber_ids = array();
			$emails         = array();

			foreach ( $subscribers as $sub ) {
				$subscriber_ids[] = (int) $sub->id;
				$emails[]         = (string) $sub->email;
			}

			$memberships  = $subs_controller->group_ids_for_subscribers( $subscriber_ids );
			$linked_users = $subs_controller->user_ids_for_emails( $emails );

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

		// Return to the page the admin was on. The list clamps an
		// out-of-range value, so deleting the last row on the final
		// page lands on the new final page rather than an empty view.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$paged = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 0;

		if ( $paged > 1 ) {
			$redirect_args['paged'] = $paged;
		}

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
}
