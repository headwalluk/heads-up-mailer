<?php
/**
 * REST API controller.
 *
 * @package Heads_Up_Mailer
 * @since 0.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Registers the `heads-up-mailer/v1` REST namespace.
 *
 * Routes are authenticated via WordPress application passwords —
 * `permission_callback` checks the custom `hum_create_drafts`
 * capability, granted to Administrator and Editor on activation
 * (see `hum_ensure_caps()`). This lets an AI-agent user post drafts
 * with the Editor role rather than needing Administrator.
 *
 * @since 0.3.0
 */
class REST_Controller {

	/**
	 * Register hooks.
	 *
	 * @since 0.3.0
	 */
	public function run(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes for the namespace.
	 *
	 * @since 0.3.0
	 */
	public function register_routes(): void {
		register_rest_route(
			REST_NAMESPACE,
			'/drafts',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_draft' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->draft_args(),
				),
			)
		);

		register_rest_route(
			REST_NAMESPACE,
			'/drafts/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_draft' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ): bool {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Capability gate for all routes in this namespace.
	 *
	 * @since 0.3.0
	 */
	public function check_permission(): bool {
		return current_user_can( CAP_CREATE_DRAFTS );
	}

	/**
	 * `args` schema shared by POST /drafts.
	 *
	 * Sanitisation and the hard validation live in the controller.
	 * These rules only catch type mistakes before they reach the DB.
	 *
	 * @since 0.3.0
	 * @return array<string,array<string,mixed>>
	 */
	private function draft_args(): array {
		return array(
			'subject'          => array(
				'type'     => 'string',
				'required' => true,
			),
			'html_body'        => array(
				'type'     => 'string',
				'required' => true,
			),
			'suggested_groups' => array(
				'type'     => 'array',
				'required' => false,
				'default'  => array(),
				'items'    => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * POST /drafts — create a draft.
	 *
	 * @since 0.3.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function create_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Drafts_Controller();

		$data = array(
			'subject'          => (string) $request->get_param( 'subject' ),
			'html_body'        => (string) $request->get_param( 'html_body' ),
			'suggested_groups' => (array) $request->get_param( 'suggested_groups' ),
		);

		$id = $controller->create( $data );

		if ( is_wp_error( $id ) ) {
			$result = $this->wp_error_to_rest( $id );
		} else {
			$draft  = $controller->get( $id );
			$result = new \WP_REST_Response( $this->serialize( $draft ), 201 );
		}

		return $result;
	}

	/**
	 * GET /drafts/{id} — return one draft.
	 *
	 * @since 0.3.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function get_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Drafts_Controller();
		$id         = (int) $request->get_param( 'id' );
		$draft      = $controller->get( $id );

		if ( null === $draft ) {
			$result = new \WP_Error(
				'hum_draft_not_found',
				__( 'Draft not found.', 'heads-up-mailer' ),
				array( 'status' => 404 )
			);
		} else {
			$result = new \WP_REST_Response( $this->serialize( $draft ), 200 );
		}

		return $result;
	}

	/**
	 * Shape a draft row into the public REST payload.
	 *
	 * @since 0.3.0
	 * @param object $draft Draft row.
	 * @return array<string,mixed>
	 */
	private function serialize( object $draft ): array {
		$controller = new Drafts_Controller();

		return array(
			'id'               => (int) $draft->id,
			'subject'          => (string) $draft->subject,
			'html_body'        => (string) $draft->html_body,
			'suggested_groups' => $controller->suggested_groups( $draft ),
			'created_by'       => (int) $draft->created_by,
			'created_at'       => (string) $draft->created_at,
			'status'           => (string) $draft->status,
		);
	}

	/**
	 * Attach an HTTP status to a `WP_Error` so REST returns 4xx instead
	 * of the default 500.
	 *
	 * @since 0.3.0
	 * @param \WP_Error $error Controller error.
	 */
	private function wp_error_to_rest( \WP_Error $error ): \WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['status'] = 400;
		$error->add_data( $data );

		return $error;
	}
}
