<?php
/**
 * Groups CRUD controller.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Owns CRUD on the `hum_groups` table and the seed of the default
 * groups on first install.
 *
 * @since 0.1.0
 */
class Groups_Controller {

	/**
	 * Fully qualified table name.
	 *
	 * @since 0.1.0
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_GROUPS;
	}

	/**
	 * Get a single group by ID.
	 *
	 * @since 0.1.0
	 * @param int $id Group ID.
	 * @return ?object Group row or null when missing.
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; table from prefix, value placeholder is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row;
	}

	/**
	 * Get a group by slug.
	 *
	 * @since 0.1.0
	 * @param string $slug Group slug.
	 * @return ?object Group row or null when missing.
	 */
	public function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; table from prefix, value placeholder is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );

		return $row;
	}

	/**
	 * Get all groups, ordered by name ascending.
	 *
	 * @since 0.1.0
	 * @return array<int, object> Group rows.
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name interpolated from prefix; no user input in query.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Visible-to-this-subscriber groups for the public
	 * `/manage-comms/` page.
	 *
	 * Returns every public group, plus any private group the
	 * subscriber is already a member of (so they can leave it).
	 * Private groups they're not in are hidden — no sign-up
	 * checkbox, no information leak about the group's existence.
	 *
	 * @since 1.0.0
	 * @param array<int, int> $member_group_ids Group IDs the subscriber currently belongs to.
	 * @return array<int, object>
	 */
	public function get_visible_for( array $member_group_ids ): array {
		$result     = array();
		$member_set = array_flip( array_map( 'intval', $member_group_ids ) );

		foreach ( $this->get_all() as $group ) {
			$is_private = ! empty( $group->is_private );
			$is_member  = isset( $member_set[ (int) $group->id ] );

			if ( ! $is_private || $is_member ) {
				$result[] = $group;
			}
		}

		return $result;
	}

	/**
	 * Total membership rows for one group, regardless of subscriber
	 * status.
	 *
	 * This is the "is the group empty?" test used by the REST delete
	 * guard. Deliberately status-blind: a group holding only
	 * unsubscribed or never-contact members is still **not** empty,
	 * because deleting it would silently destroy those membership
	 * rows. Clearing them is a human job in the admin UI.
	 *
	 * @since 1.6.0
	 * @param int $id Group ID.
	 * @return int Membership row count.
	 */
	public function count_members( int $id ): int {
		global $wpdb;
		$table = $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; table from prefix, value placeholder is prepared.
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE group_id = %d", $id ) );

		return (int) $total;
	}

	/**
	 * Membership counts for every group, keyed by group ID.
	 *
	 * Two counts per group, because they differ and callers need
	 * both:
	 *
	 * - `members` — every membership row (see `count_members()`).
	 * - `subscribed` — rows whose subscriber is `STATUS_SUBSCRIBED`,
	 *   i.e. what a send to this group would actually reach. Mirrors
	 *   the join in `Sends_Controller::compute_recipient_ids()`.
	 *
	 * Batched into one query so the REST list route doesn't fire N+1
	 * counts. Groups with no members are absent from the result —
	 * callers should default to zero.
	 *
	 * `LEFT JOIN`, not `INNER`, so `members` counts raw junction rows
	 * and therefore always agrees with `count_members()`. An inner
	 * join would hide an orphaned membership row, and a group could
	 * then report zero members here while the delete guard refused it.
	 *
	 * @since 1.6.0
	 * @return array<int, array{members:int, subscribed:int}>
	 */
	public function member_counts(): array {
		global $wpdb;
		$subs_table   = $wpdb->prefix . TABLE_SUBSCRIBERS;
		$groups_table = $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;
		$result       = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; status is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sg.group_id AS group_id,
				        COUNT(*) AS members,
				        SUM( CASE WHEN s.status = %s THEN 1 ELSE 0 END ) AS subscribed
				 FROM {$groups_table} sg
				 LEFT JOIN {$subs_table} s ON s.id = sg.subscriber_id
				 GROUP BY sg.group_id",
				STATUS_SUBSCRIBED
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row->group_id ] = array(
					'members'    => (int) $row->members,
					'subscribed' => (int) $row->subscribed,
				);
			}
		}

		return $result;
	}

	/**
	 * Create a group.
	 *
	 * @since 0.1.0
	 * @param array<string,mixed> $data Group fields (`slug`, `name`, `description`).
	 * @return int|\WP_Error Inserted ID on success, `WP_Error` on failure.
	 */
	public function create( array $data ): int|\WP_Error {
		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( null !== $this->get_by_slug( $validated['slug'] ) ) {
			return new \WP_Error(
				'hum_group_exists',
				__( 'A group with that slug already exists.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write via $wpdb->insert.
		$inserted = $wpdb->insert(
			$this->table(),
			$validated,
			array( '%s', '%s', '%s', '%d', '%d' )
		);

		$result = ( false === $inserted )
			? new \WP_Error( 'hum_group_insert_failed', __( 'Failed to insert group.', 'heads-up-mailer' ) )
			: (int) $wpdb->insert_id;

		return $result;
	}

	/**
	 * Update a group.
	 *
	 * @since 0.1.0
	 * @param int                 $id   Group ID.
	 * @param array<string,mixed> $data Group fields to update.
	 * @return true|\WP_Error `true` on success, `WP_Error` on failure.
	 */
	public function update( int $id, array $data ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_group_not_found',
				__( 'Group not found.', 'heads-up-mailer' )
			);
		}

		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$slug_conflict = false;
		if ( $validated['slug'] !== $existing->slug ) {
			$slug_conflict = ( null !== $this->get_by_slug( $validated['slug'] ) );
		}

		if ( $slug_conflict ) {
			return new \WP_Error(
				'hum_group_exists',
				__( 'A group with that slug already exists.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write via $wpdb->update.
		$updated = $wpdb->update(
			$this->table(),
			$validated,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_group_update_failed', __( 'Failed to update group.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Delete a group, plus any memberships referencing it.
	 *
	 * @since 0.1.0
	 * @param int $id Group ID.
	 * @return true|\WP_Error `true` on success, `WP_Error` on failure.
	 */
	public function delete( int $id ): true|\WP_Error {
		if ( null === $this->get( $id ) ) {
			return new \WP_Error(
				'hum_group_not_found',
				__( 'Group not found.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean up junction-table rows before deleting the group.
		$wpdb->delete(
			$wpdb->prefix . TABLE_SUBSCRIBER_GROUPS,
			array( 'group_id' => $id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write via $wpdb->delete.
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		$result = ( false === $deleted )
			? new \WP_Error( 'hum_group_delete_failed', __( 'Failed to delete group.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Insert the seed groups (`hosting-customers`, `web-designers`)
	 * if missing.
	 *
	 * Idempotent — safe to call on every activation. Existing groups
	 * are left untouched, including descriptions edited by an admin.
	 *
	 * @since 0.1.0
	 */
	public function seed_defaults(): void {
		$defaults = array(
			array(
				'slug'        => 'hosting-customers',
				'name'        => __( 'Hosting customers', 'heads-up-mailer' ),
				'description' => __( 'Active hosting customers.', 'heads-up-mailer' ),
			),
			array(
				'slug'        => 'web-designers',
				'name'        => __( 'Web designers', 'heads-up-mailer' ),
				'description' => __( 'Web designers and agencies who refer hosting customers.', 'heads-up-mailer' ),
			),
		);

		foreach ( $defaults as $default ) {
			if ( null === $this->get_by_slug( $default['slug'] ) ) {
				$this->create( $default );
			}
		}
	}

	/**
	 * Validate and sanitise incoming group data.
	 *
	 * @since 0.1.0
	 * @param array<string,mixed> $data Raw input.
	 * @return array<string,string>|\WP_Error Sanitised fields or error.
	 */
	private function validate( array $data ): array|\WP_Error {
		$slug                 = isset( $data['slug'] ) ? sanitize_title( wp_unslash( $data['slug'] ) ) : '';
		$name                 = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$description          = isset( $data['description'] ) ? sanitize_textarea_field( wp_unslash( $data['description'] ) ) : '';
		$is_private           = ! empty( $data['is_private'] ) ? 1 : 0;
		$allow_automated_send = ! empty( $data['allow_automated_send'] ) ? 1 : 0;

		if ( '' === $slug ) {
			return new \WP_Error(
				'hum_group_invalid_slug',
				__( 'A valid slug is required.', 'heads-up-mailer' )
			);
		}

		if ( '' === $name ) {
			return new \WP_Error(
				'hum_group_invalid_name',
				__( 'Group name is required.', 'heads-up-mailer' )
			);
		}

		// Length guards mirror the column widths. Checked after
		// sanitising, because sanitize_title() percent-encodes
		// non-ASCII and can grow the slug well past its input length.
		if ( strlen( $slug ) > MAX_GROUP_SLUG_LENGTH ) {
			return new \WP_Error(
				'hum_group_slug_too_long',
				sprintf(
					/* translators: %d is the maximum number of characters allowed in a group slug */
					__( 'Group slug is too long (maximum %d characters).', 'heads-up-mailer' ),
					MAX_GROUP_SLUG_LENGTH
				)
			);
		}

		if ( mb_strlen( $name ) > MAX_GROUP_NAME_LENGTH ) {
			return new \WP_Error(
				'hum_group_name_too_long',
				sprintf(
					/* translators: %d is the maximum number of characters allowed in a group name */
					__( 'Group name is too long (maximum %d characters).', 'heads-up-mailer' ),
					MAX_GROUP_NAME_LENGTH
				)
			);
		}

		if ( strlen( $description ) > MAX_GROUP_DESCRIPTION_LENGTH ) {
			return new \WP_Error(
				'hum_group_description_too_long',
				sprintf(
					/* translators: %d is the maximum number of characters allowed in a group description */
					__( 'Group description is too long (maximum %d characters).', 'heads-up-mailer' ),
					MAX_GROUP_DESCRIPTION_LENGTH
				)
			);
		}

		return array(
			'slug'                 => $slug,
			'name'                 => $name,
			'description'          => $description,
			'is_private'           => $is_private,
			'allow_automated_send' => $allow_automated_send,
		);
	}
}
