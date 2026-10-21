<?php
/**
 * Groups admin page: list, edit form and their form handlers.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Groups admin page: list, edit form and their form handlers.
 *
 * @since 1.8.0
 */
class Admin_Groups {

	/**
	 * Register hooks.
	 *
	 * @since 1.8.0
	 */
	public function run(): void {
		add_action( 'admin_post_hum_save_group', array( $this, 'handle_save_group' ) );
		add_action( 'admin_post_hum_delete_group', array( $this, 'handle_delete_group' ) );
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
