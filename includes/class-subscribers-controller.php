<?php
/**
 * Subscribers CRUD controller.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Owns CRUD on the `hum_subscribers` table, group membership writes
 * to `hum_subscriber_groups`, and `token_salt` generation on insert.
 *
 * @since 0.1.0
 */
class Subscribers_Controller {

	/**
	 * Fully qualified subscribers table name.
	 *
	 * @since 0.1.0
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_SUBSCRIBERS;
	}

	/**
	 * Fully qualified subscriber_groups (junction) table name.
	 *
	 * @since 0.1.0
	 */
	private function groups_table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;
	}

	/**
	 * Get a subscriber by ID.
	 *
	 * @since 0.1.0
	 * @param int $id Subscriber ID.
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row;
	}

	/**
	 * Get a subscriber by email (case-insensitive).
	 *
	 * @since 0.1.0
	 * @param string $email Email address (will be lowercased + trimmed).
	 */
	public function get_by_email( string $email ): ?object {
		global $wpdb;
		$table       = $this->table();
		$email_lower = strtolower( trim( $email ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email_lower ) );

		return $row;
	}

	/**
	 * Get all subscribers, ordered by created_at descending.
	 *
	 * @since 0.1.0
	 * @return array<int, object>
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read, no user input in query.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get the group IDs a subscriber belongs to.
	 *
	 * @since 0.1.0
	 * @param int $subscriber_id Subscriber ID.
	 * @return array<int, int>
	 */
	public function get_groups( int $subscriber_id ): array {
		global $wpdb;
		$table = $this->groups_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT group_id FROM {$table} WHERE subscriber_id = %d", $subscriber_id )
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Replace the subscriber's group memberships.
	 *
	 * Wipes existing rows and inserts fresh ones. The caller is
	 * expected to pass validated group IDs (e.g. by intersecting
	 * with `Groups_Controller::get_all()` first).
	 *
	 * @since 0.1.0
	 * @param int        $subscriber_id Subscriber ID.
	 * @param array<int> $group_ids     Group IDs to attach.
	 */
	public function set_groups( int $subscriber_id, array $group_ids ): void {
		global $wpdb;
		$table = $this->groups_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Membership reset before re-insert.
		$wpdb->delete( $table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );

		foreach ( $group_ids as $group_id ) {
			$group_id = (int) $group_id;

			if ( $group_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Junction-table insert.
			$wpdb->insert(
				$table,
				array(
					'subscriber_id' => $subscriber_id,
					'group_id'      => $group_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Create a subscriber.
	 *
	 * Generates a 32-byte `token_salt` (hex-encoded). Stamps
	 * `created_at` and, when status is `unsubscribed`,
	 * `unsubscribed_at`.
	 *
	 * @since 0.1.0
	 * @param array<string,mixed> $data Subscriber fields, plus optional `groups` array of group IDs.
	 * @return int|\WP_Error Inserted ID on success, `WP_Error` on failure.
	 */
	public function create( array $data ): int|\WP_Error {
		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( null !== $this->get_by_email( $validated['email'] ) ) {
			return new \WP_Error(
				'hum_subscriber_exists',
				__( 'A subscriber with that email already exists.', 'heads-up-mailer' )
			);
		}

		$now = now_utc();

		$row                    = $validated;
		$row['token_salt']      = bin2hex( random_bytes( 32 ) );
		$row['created_at']      = $now;
		$row['unsubscribed_at'] = in_array( $row['status'], array( STATUS_UNSUBSCRIBED, STATUS_NEVER_CONTACT ), true ) ? $now : '';

		if ( '' === $row['consent_at'] ) {
			$row['consent_at'] = $now;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$inserted = $wpdb->insert(
			$this->table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$result = new \WP_Error(
				'hum_subscriber_insert_failed',
				__( 'Failed to insert subscriber.', 'heads-up-mailer' )
			);
		} else {
			$subscriber_id = (int) $wpdb->insert_id;

			if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
				$this->set_groups( $subscriber_id, array_map( 'intval', $data['groups'] ) );
			}

			$result = $subscriber_id;
		}

		return $result;
	}

	/**
	 * Update a subscriber.
	 *
	 * Handles `unsubscribed_at` stamping/clearing on status
	 * transitions. The `groups` key (if present) replaces existing
	 * memberships; omit it to leave memberships untouched.
	 *
	 * @since 0.1.0
	 * @param int                 $id   Subscriber ID.
	 * @param array<string,mixed> $data Subscriber fields and optional `groups`.
	 * @return true|\WP_Error `true` on success, `WP_Error` on failure.
	 */
	public function update( int $id, array $data ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$email_conflict = false;
		if ( $validated['email'] !== $existing->email ) {
			$other          = $this->get_by_email( $validated['email'] );
			$email_conflict = ( null !== $other && (int) $other->id !== $id );
		}

		if ( $email_conflict ) {
			return new \WP_Error(
				'hum_subscriber_exists',
				__( 'A subscriber with that email already exists.', 'heads-up-mailer' )
			);
		}

		$row = $validated;

		// Status-transition logic for unsubscribed_at. "Stopped"
		// covers both `unsubscribed` and `never_contact` — entering
		// either state stamps the timestamp, leaving for any other
		// status clears it, and staying within the stopped bucket
		// preserves the original stamp.
		$stopped_statuses = array( STATUS_UNSUBSCRIBED, STATUS_NEVER_CONTACT );
		$was_stopped      = in_array( (string) $existing->status, $stopped_statuses, true );
		$is_stopped       = in_array( (string) $row['status'], $stopped_statuses, true );

		if ( $is_stopped && ! $was_stopped ) {
			$row['unsubscribed_at'] = now_utc();
		} elseif ( ! $is_stopped ) {
			$row['unsubscribed_at'] = '';
		} else {
			$row['unsubscribed_at'] = (string) $existing->unsubscribed_at;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			$row,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$result = new \WP_Error(
				'hum_subscriber_update_failed',
				__( 'Failed to update subscriber.', 'heads-up-mailer' )
			);
		} else {
			if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
				$this->set_groups( $id, array_map( 'intval', $data['groups'] ) );
			}

			$result = true;
		}

		return $result;
	}

	/**
	 * Delete a subscriber and any group memberships.
	 *
	 * @since 0.1.0
	 * @param int $id Subscriber ID.
	 */
	public function delete( int $id ): true|\WP_Error {
		if ( null === $this->get( $id ) ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean junction-table rows first.
		$wpdb->delete(
			$this->groups_table(),
			array( 'subscriber_id' => $id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		$result = ( false === $deleted )
			? new \WP_Error( 'hum_subscriber_delete_failed', __( 'Failed to delete subscriber.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Flip a subscriber to `unsubscribed` and stamp `unsubscribed_at`.
	 *
	 * Idempotent — already-unsubscribed rows are left untouched
	 * (existing `unsubscribed_at` preserved). The recipient never
	 * needs to know whether their click "worked" the first time.
	 *
	 * @since 0.5.0
	 * @param int $id Subscriber ID.
	 * @return true|\WP_Error
	 */
	public function unsubscribe( int $id ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		if ( STATUS_UNSUBSCRIBED === (string) $existing->status ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'          => STATUS_UNSUBSCRIBED,
				'unsubscribed_at' => now_utc(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_subscriber_update_failed', __( 'Failed to update subscriber.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Flip a subscriber back to `subscribed` and clear
	 * `unsubscribed_at`.
	 *
	 * Idempotent. Only acts on rows currently in `unsubscribed`
	 * status; `bounced` and `complained` are left alone — those
	 * states need admin intervention, not a token-bearing
	 * recipient re-ticking a box.
	 *
	 * @since 0.5.0
	 * @param int $id Subscriber ID.
	 * @return true|\WP_Error
	 */
	public function resubscribe( int $id ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		if ( STATUS_UNSUBSCRIBED !== (string) $existing->status ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'          => STATUS_SUBSCRIBED,
				'unsubscribed_at' => '',
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_subscriber_update_failed', __( 'Failed to update subscriber.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Ensure a subscriber exists for the given email and is a
	 * member of the given group.
	 *
	 * - Existing row: union the requested group with the current
	 *   memberships. Other fields (status, consent_at, name)
	 *   left untouched. Status changes are the integration's
	 *   responsibility — call `resubscribe()` separately if the
	 *   signup should overwrite an `unsubscribed` state.
	 * - Missing row: create with `status = subscribed`,
	 *   `consent_at = now`, the provided `consent_source`, and
	 *   the single-group membership.
	 * - Never-contact: refused with `hum_subscriber_never_contact`.
	 *   Integration callers should handle this silently — a stale
	 *   form submission shouldn't surface as a fatal error.
	 *
	 * Helper for the M11 integrations (CF7, WooCommerce). Returns
	 * the subscriber ID on success, or a `WP_Error` on validation
	 * / DB failure.
	 *
	 * @since 0.9.0
	 * @param string $email          Subscriber email.
	 * @param string $name           Subscriber name (empty for unknown).
	 * @param int    $group_id       Target group ID.
	 * @param string $consent_source Free-text consent provenance (`cf7-form`, `woocommerce-checkout`, …).
	 * @return int|\WP_Error
	 */
	public function ensure_in_group( string $email, string $name, int $group_id, string $consent_source ): int|\WP_Error {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'hum_subscriber_invalid_email',
				__( 'A valid email address is required.', 'heads-up-mailer' )
			);
		}

		$existing = $this->get_by_email( $email );

		if ( null !== $existing && STATUS_NEVER_CONTACT === (string) $existing->status ) {
			return new \WP_Error(
				'hum_subscriber_never_contact',
				__( 'Subscriber is flagged never-contact.', 'heads-up-mailer' )
			);
		}

		if ( null === $existing ) {
			$result = $this->create(
				array(
					'email'          => $email,
					'name'           => $name,
					'consent_source' => $consent_source,
					'consent_at'     => now_utc(),
					'groups'         => array( $group_id ),
				)
			);

			return $result;
		}

		$existing_groups = $this->get_groups( (int) $existing->id );
		$new_groups      = array_values( array_unique( array_merge( $existing_groups, array( $group_id ) ) ) );

		$this->set_groups( (int) $existing->id, $new_groups );

		return (int) $existing->id;
	}

	/**
	 * Flip a subscriber to `never_contact` and stamp `unsubscribed_at`.
	 *
	 * The harder cousin of `unsubscribe()`. Recorded as a deliberate
	 * GDPR-style "do not contact under any circumstances" flag —
	 * `resubscribe()` ignores rows in this state, and the CSV
	 * importer refuses to update them, so a stale MailerLite export
	 * can't resurrect them by accident. Admins can still flip the
	 * status back via the subscriber edit form if they really mean
	 * to — the warning notice on that form names the intent.
	 *
	 * Idempotent — already-never-contact rows are left alone
	 * (existing `unsubscribed_at` preserved). Used by the public
	 * "Unsubscribe from everything" button and the admin row /
	 * bulk action on the subscribers list.
	 *
	 * @since 0.8.0
	 * @param int $id Subscriber ID.
	 * @return true|\WP_Error
	 */
	public function mark_never_contact( int $id ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		if ( STATUS_NEVER_CONTACT === (string) $existing->status ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'          => STATUS_NEVER_CONTACT,
				'unsubscribed_at' => now_utc(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_subscriber_update_failed', __( 'Failed to update subscriber.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Generate a fresh `token_salt` for a subscriber.
	 *
	 * Invalidates every outstanding `{id}.{hmac}` token for the
	 * subscriber, including in-flight `List-Unsubscribe` links from
	 * already-sent newsletters. Use sparingly — typically only on an
	 * admin-driven "rotate token" action or when a leak is suspected.
	 *
	 * @since 0.4.0
	 * @param int $id Subscriber ID.
	 * @return true|\WP_Error
	 */
	public function regenerate_token_salt( int $id ): true|\WP_Error {
		if ( null === $this->get( $id ) ) {
			return new \WP_Error(
				'hum_subscriber_not_found',
				__( 'Subscriber not found.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			array( 'token_salt' => bin2hex( random_bytes( 32 ) ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_subscriber_update_failed', __( 'Failed to update subscriber.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Validate and sanitise incoming subscriber data.
	 *
	 * Excludes `token_salt` and `created_at` — those are managed by
	 * the controller itself. Excludes `groups` — that's handled
	 * separately by `set_groups()`.
	 *
	 * @since 0.1.0
	 * @param array<string,mixed> $data Raw input.
	 * @return array<string,string>|\WP_Error
	 */
	private function validate( array $data ): array|\WP_Error {
		$email          = isset( $data['email'] ) ? strtolower( sanitize_email( wp_unslash( $data['email'] ) ) ) : '';
		$name           = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$status         = isset( $data['status'] ) ? sanitize_key( wp_unslash( $data['status'] ) ) : STATUS_SUBSCRIBED;
		$consent_source = isset( $data['consent_source'] ) ? sanitize_text_field( wp_unslash( $data['consent_source'] ) ) : '';
		$consent_at     = isset( $data['consent_at'] ) ? sanitize_text_field( wp_unslash( $data['consent_at'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'hum_subscriber_invalid_email',
				__( 'A valid email address is required.', 'heads-up-mailer' )
			);
		}

		$allowed_statuses = array(
			STATUS_SUBSCRIBED,
			STATUS_UNSUBSCRIBED,
			STATUS_BOUNCED,
			STATUS_COMPLAINED,
			STATUS_NEVER_CONTACT,
		);

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new \WP_Error(
				'hum_subscriber_invalid_status',
				__( 'Invalid subscriber status.', 'heads-up-mailer' )
			);
		}

		return array(
			'email'          => $email,
			'name'           => $name,
			'status'         => $status,
			'consent_source' => $consent_source,
			'consent_at'     => $consent_at,
		);
	}
}
