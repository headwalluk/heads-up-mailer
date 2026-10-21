<?php
/**
 * Drafts and Sent log admin pages and the draft form handlers.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Drafts and Sent log admin pages and the draft form handlers.
 *
 * @since 1.8.0
 */
class Admin_Drafts {

	/**
	 * Register hooks.
	 *
	 * @since 1.8.0
	 */
	public function run(): void {
		add_action( 'admin_post_hum_save_draft', array( $this, 'handle_save_draft' ) );
		add_action( 'admin_post_hum_delete_draft', array( $this, 'handle_delete_draft' ) );
		add_action( 'admin_post_hum_preview_draft', array( $this, 'handle_preview_draft' ) );
		add_action( 'admin_post_hum_send_draft', array( $this, 'handle_send_draft' ) );
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
}
