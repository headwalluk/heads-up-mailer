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
 * Routes are authenticated via WordPress application passwords. Each
 * route's `permission_callback` checks one of the plugin's custom
 * capabilities, granted in `hum_ensure_caps()` — so an AI-agent user
 * operates at Editor level rather than needing Administrator, and any
 * single right can be revoked without touching the others:
 *
 * - `hum_create_drafts`    — `POST /drafts`, `GET /drafts/{id}`
 * - `hum_send_newsletters` — `POST /drafts/{id}/send`
 * - `hum_read_groups`      — `GET /groups`, `GET /groups/{id}`
 * - `hum_manage_groups`    — `POST` / `PATCH` / `DELETE` on groups
 *                            (Administrator-only by default)
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
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			REST_NAMESPACE,
			'/drafts/(?P<id>\d+)/send',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_draft' ),
					'permission_callback' => array( $this, 'check_send_permission' ),
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			REST_NAMESPACE,
			'/groups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_groups' ),
					'permission_callback' => array( $this, 'check_read_groups_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_group' ),
					'permission_callback' => array( $this, 'check_manage_groups_permission' ),
					'args'                => $this->group_args( true ),
				),
			)
		);

		register_rest_route(
			REST_NAMESPACE,
			'/groups/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group' ),
					'permission_callback' => array( $this, 'check_read_groups_permission' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => array( $this, 'check_manage_groups_permission' ),
					'args'                => array_merge( $this->id_arg(), $this->group_args( false ) ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => array( $this, 'check_manage_groups_permission' ),
					'args'                => $this->id_arg(),
				),
			)
		);
	}

	/**
	 * Shared `args` schema for the `{id}` path segment.
	 *
	 * @since 1.5.0
	 * @return array<string,array<string,mixed>>
	 */
	private function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
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
	 * Capability gate for the trigger-send route.
	 *
	 * Separate from `check_permission()` so the send right
	 * (`hum_send_newsletters`) is independent of the draft right
	 * (`hum_create_drafts`).
	 *
	 * @since 1.5.0
	 */
	public function check_send_permission(): bool {
		return current_user_can( CAP_SEND_NEWSLETTERS );
	}

	/**
	 * Capability gate for the read-only groups routes.
	 *
	 * @since 1.6.0
	 */
	public function check_read_groups_permission(): bool {
		return current_user_can( CAP_READ_GROUPS );
	}

	/**
	 * Capability gate for the groups write routes.
	 *
	 * Separate from `check_read_groups_permission()` so enumerating
	 * segments and mutating them are independently grantable.
	 * `hum_manage_groups` is Administrator-only by default.
	 *
	 * @since 1.6.0
	 */
	public function check_manage_groups_permission(): bool {
		return current_user_can( CAP_MANAGE_GROUPS );
	}

	/**
	 * Writable-field schema for the groups create / update routes.
	 *
	 * **Security:** `allow_automated_send` is deliberately absent and
	 * must stay absent. That column is the per-group gate for
	 * autonomous sending (see `Sends_Controller::autonomous_gate()`).
	 * If it were writable here, an identity holding
	 * `hum_manage_groups` + `hum_send_newsletters` could flip the flag
	 * on any group and then autonomously mail it — collapsing a
	 * two-key control into a single-actor privilege escalation. The
	 * flag is set by a human in the admin UI only. Do not add it.
	 *
	 * `is_private` is writable: it only controls visibility on
	 * `/manage-comms/` and carries no send authority.
	 *
	 * @since 1.6.0
	 * @param bool $is_create Whether this is the create route (fields required).
	 * @return array<string,array<string,mixed>>
	 */
	private function group_args( bool $is_create ): array {
		return array(
			'slug'        => array(
				'type'     => 'string',
				'required' => $is_create,
			),
			'name'        => array(
				'type'     => 'string',
				'required' => $is_create,
			),
			'description' => array(
				'type'     => 'string',
				'required' => false,
			),
			'is_private'  => array(
				'type'     => 'boolean',
				'required' => false,
			),
		);
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
	 * POST /drafts/{id}/send — trigger an autonomous send.
	 *
	 * Thin wrapper: the autonomy gates (master switch, idempotency,
	 * per-group allowlist) live in `Sends_Controller::autonomous_gate()`,
	 * and the shared pre-flight + transactional insert live in
	 * `queue()`. Both refusals and successes are audited. The send row
	 * is flagged `is_automated` so the Sent log can tell agent-triggered
	 * sends apart from human ones.
	 *
	 * @since 1.5.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function send_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$draft_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$sends    = new Sends_Controller();

		$gate = $sends->autonomous_gate( $draft_id );

		if ( is_wp_error( $gate ) ) {
			audit_autonomous_send( 'refused', $user_id, $draft_id, 'reason=' . $gate->get_error_code() );
			$result = $gate;
		} else {
			$queued = $sends->queue( $draft_id, true );

			if ( is_wp_error( $queued ) ) {
				audit_autonomous_send( 'refused', $user_id, $draft_id, 'reason=' . $queued->get_error_code() );
				$result = $this->send_error_to_rest( $queued );
			} else {
				audit_autonomous_send( 'queued', $user_id, $draft_id, 'send_id=' . (int) $queued );
				$result = new \WP_REST_Response(
					array(
						'send_id'  => (int) $queued,
						'draft_id' => $draft_id,
						'status'   => 'queued',
					),
					200
				);
			}
		}

		return $result;
	}

	/**
	 * GET /groups — list every group.
	 *
	 * Includes private groups: this route is capability-gated to
	 * trusted internal identities, and an agent needs the full slug
	 * list to target a draft. Private only hides a group from the
	 * public `/manage-comms/` page.
	 *
	 * No pagination — groups are a handful of rows.
	 *
	 * @since 1.6.0
	 */
	public function get_groups(): \WP_REST_Response {
		$controller = new Groups_Controller();
		$counts     = $controller->member_counts();
		$payload    = array();

		foreach ( $controller->get_all() as $group ) {
			$payload[] = $this->serialize_group( $group, $counts );
		}

		return new \WP_REST_Response( array( 'groups' => $payload ), 200 );
	}

	/**
	 * GET /groups/{id} — return one group.
	 *
	 * @since 1.6.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function get_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Groups_Controller();
		$group      = $controller->get( (int) $request->get_param( 'id' ) );

		if ( null === $group ) {
			$result = $this->group_not_found();
		} else {
			$result = new \WP_REST_Response(
				$this->serialize_group( $group, $controller->member_counts() ),
				200
			);
		}

		return $result;
	}

	/**
	 * POST /groups — create a group.
	 *
	 * Only the allowlisted fields in `group_args()` reach the
	 * controller. `allow_automated_send` is never accepted, so a
	 * REST-created group always starts with autonomous sending off
	 * (`validate()` defaults it to 0).
	 *
	 * @since 1.6.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function create_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Groups_Controller();

		$id = $controller->create(
			array(
				'slug'        => (string) $request->get_param( 'slug' ),
				'name'        => (string) $request->get_param( 'name' ),
				'description' => (string) $request->get_param( 'description' ),
				'is_private'  => (bool) $request->get_param( 'is_private' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			$result = $this->group_error_to_rest( $id );
		} else {
			$group  = $controller->get( (int) $id );
			$result = new \WP_REST_Response(
				$this->serialize_group( $group, $controller->member_counts() ),
				201
			);
		}

		return $result;
	}

	/**
	 * PATCH /groups/{id} — partial update.
	 *
	 * `Groups_Controller::update()` full-replaces every column, so
	 * omitted fields are merged from the existing row first.
	 * `allow_automated_send` is always carried over from the stored
	 * row and never read from the request — without that merge a PATCH
	 * omitting it would silently clear a human-set automation flag.
	 *
	 * @since 1.6.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function update_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Groups_Controller();
		$id         = (int) $request->get_param( 'id' );
		$existing   = $controller->get( $id );

		if ( null === $existing ) {
			return $this->group_not_found();
		}

		$data = array(
			'slug'                 => (string) $existing->slug,
			'name'                 => (string) $existing->name,
			'description'          => (string) $existing->description,
			'is_private'           => ! empty( $existing->is_private ),
			'allow_automated_send' => ! empty( $existing->allow_automated_send ),
		);

		foreach ( array( 'slug', 'name', 'description', 'is_private' ) as $field ) {
			$supplied = $request->get_param( $field );

			if ( null !== $supplied ) {
				$data[ $field ] = ( 'is_private' === $field ) ? (bool) $supplied : (string) $supplied;
			}
		}

		$updated = $controller->update( $id, $data );

		if ( is_wp_error( $updated ) ) {
			$result = $this->group_error_to_rest( $updated );
		} else {
			$result = new \WP_REST_Response(
				$this->serialize_group( $controller->get( $id ), $controller->member_counts() ),
				200
			);
		}

		return $result;
	}

	/**
	 * DELETE /groups/{id} — delete an **empty** group.
	 *
	 * Refuses with 409 while any membership row references the group,
	 * regardless of those subscribers' status. The guard lives here
	 * rather than in `Groups_Controller::delete()` because that method
	 * intentionally cascades and backs the admin bulk-delete; moving
	 * the check down would change admin behaviour.
	 *
	 * @since 1.6.0
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function delete_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$controller = new Groups_Controller();
		$id         = (int) $request->get_param( 'id' );
		$group      = $controller->get( $id );

		if ( null === $group ) {
			return $this->group_not_found();
		}

		$member_count = $controller->count_members( $id );

		if ( $member_count > 0 ) {
			$result = new \WP_Error(
				'hum_group_not_empty',
				sprintf(
					/* translators: %d is the number of subscribers still in the group */
					_n(
						'Group still has %d member and cannot be deleted. Remove its members first.',
						'Group still has %d members and cannot be deleted. Remove its members first.',
						$member_count,
						'heads-up-mailer'
					),
					$member_count
				),
				array(
					'status'       => 409,
					'member_count' => $member_count,
				)
			);
		} else {
			$deleted = $controller->delete( $id );

			if ( is_wp_error( $deleted ) ) {
				$result = $this->group_error_to_rest( $deleted );
			} else {
				$result = new \WP_REST_Response(
					array(
						'deleted' => true,
						'id'      => $id,
					),
					200
				);
			}
		}

		return $result;
	}

	/**
	 * Shape a group row into the public REST payload.
	 *
	 * `allow_automated_send` is exposed **read-only** so an agent can
	 * see why an autonomous send was refused, without being able to
	 * change it.
	 *
	 * @since 1.6.0
	 * @param object                                         $group  Group row.
	 * @param array<int, array{members:int, subscribed:int}> $counts Batched membership counts.
	 * @return array<string,mixed>
	 */
	private function serialize_group( object $group, array $counts ): array {
		$id    = (int) $group->id;
		$count = isset( $counts[ $id ] ) ? $counts[ $id ] : array(
			'members'    => 0,
			'subscribed' => 0,
		);

		return array(
			'id'                   => $id,
			'slug'                 => (string) $group->slug,
			'name'                 => (string) $group->name,
			'description'          => (string) $group->description,
			'is_private'           => ! empty( $group->is_private ),
			'allow_automated_send' => ! empty( $group->allow_automated_send ),
			'member_count'         => $count['members'],
			'subscribed_count'     => $count['subscribed'],
		);
	}

	/**
	 * Shared 404 for the group routes.
	 *
	 * @since 1.6.0
	 */
	private function group_not_found(): \WP_Error {
		return new \WP_Error(
			'hum_group_not_found',
			__( 'Group not found.', 'heads-up-mailer' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Map a `Groups_Controller` `WP_Error` onto an HTTP status.
	 *
	 * Validation failures are client errors (400), a slug clash is a
	 * conflict (409), and the three `$wpdb` write failures are server
	 * faults (500). A status already set upstream is left untouched.
	 *
	 * @since 1.6.0
	 * @param \WP_Error $error Controller error.
	 */
	private function group_error_to_rest( \WP_Error $error ): \WP_Error {
		$status_by_code = array(
			'hum_group_invalid_slug'         => 400,
			'hum_group_invalid_name'         => 400,
			'hum_group_slug_too_long'        => 400,
			'hum_group_name_too_long'        => 400,
			'hum_group_description_too_long' => 400,
			'hum_group_exists'               => 409,
			'hum_group_not_found'            => 404,
			'hum_group_insert_failed'        => 500,
			'hum_group_update_failed'        => 500,
			'hum_group_delete_failed'        => 500,
		);

		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( empty( $data['status'] ) ) {
			$code           = $error->get_error_code();
			$data['status'] = isset( $status_by_code[ $code ] ) ? $status_by_code[ $code ] : 400;
		}

		$error->add_data( $data );

		return $error;
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

	/**
	 * Attach an HTTP status to a `queue()` `WP_Error` for the send route.
	 *
	 * `queue()` pre-flight failures (missing From: identity, no groups,
	 * no recipients) are well-formed-but-unprocessable → 422. The two
	 * insert/update failures are server faults → 500. A status already
	 * set upstream is left untouched.
	 *
	 * @since 1.5.0
	 * @param \WP_Error $error Error from `Sends_Controller::queue()`.
	 */
	private function send_error_to_rest( \WP_Error $error ): \WP_Error {
		$server_fault_codes = array( 'hum_send_insert_failed', 'hum_send_update_failed' );

		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( empty( $data['status'] ) ) {
			$data['status'] = in_array( $error->get_error_code(), $server_fault_codes, true ) ? 500 : 422;
		}

		$error->add_data( $data );

		return $error;
	}
}
