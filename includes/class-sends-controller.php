<?php
/**
 * Sends queueing controller.
 *
 * @package Heads_Up_Mailer
 * @since 0.4.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Owns reads / writes on the `hum_sends` table and the per-send
 * recipient queue in `hum_send_recipients`.
 *
 * `queue()` is the chunk-C entry point: it runs all pre-flight
 * validation, resolves the selected groups to a deduplicated
 * subscriber list, and writes the send row plus every recipient
 * row in a single transaction. The draft flips to
 * `DRAFT_STATUS_SENDING` in the same transaction so the admin
 * can't double-queue. The cron worker (chunk D) takes over from
 * there.
 *
 * @since 0.4.0
 */
class Sends_Controller {

	/**
	 * Fully qualified sends table name.
	 *
	 * @since 0.4.0
	 */
	private function sends_table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_SENDS;
	}

	/**
	 * Fully qualified recipients table name.
	 *
	 * @since 0.4.0
	 */
	private function recipients_table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_SEND_RECIPIENTS;
	}

	/**
	 * Get a send row by ID.
	 *
	 * @since 0.4.0
	 * @param int $id Send ID.
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		$table = $this->sends_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; placeholder is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row;
	}

	/**
	 * Resolve the union of selected groups to a deduplicated list of
	 * subscribed-status subscriber IDs.
	 *
	 * @since 0.4.0
	 * @param array<int, int> $group_ids Resolved (non-zero) group IDs.
	 * @return array<int, int> Subscriber IDs, ordered ascending.
	 */
	public function compute_recipient_ids( array $group_ids ): array {
		$result = array();

		if ( ! empty( $group_ids ) ) {
			global $wpdb;
			$subs_table   = $wpdb->prefix . TABLE_SUBSCRIBERS;
			$groups_table = $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;

			$ints         = array_values( array_map( 'intval', $group_ids ) );
			$placeholders = implode( ', ', array_fill( 0, count( $ints ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; group IDs and status are placeholders.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT s.id FROM {$subs_table} s
					 INNER JOIN {$groups_table} sg ON sg.subscriber_id = s.id
					 WHERE sg.group_id IN ({$placeholders}) AND s.status = %s
					 ORDER BY s.id ASC",
					array_merge( $ints, array( STATUS_SUBSCRIBED ) )
				)
			);

			$result = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}

		return $result;
	}

	/**
	 * Queue a draft for sending.
	 *
	 * Pre-flight (top-of-function guards):
	 *   - Draft exists.
	 *   - Draft is not currently `sending` (another queue is in flight).
	 *   - Sender identity (`OPTION_FROM_EMAIL`) is configured.
	 *   - At least one valid suggested group is attached.
	 *   - At least one subscribed recipient resolves from those groups.
	 *
	 * Atomic insert (transactional — InnoDB):
	 *   1. `hum_sends` row.
	 *   2. N `hum_send_recipients` rows, one per recipient.
	 *   3. `hum_drafts.status` flipped to `DRAFT_STATUS_SENDING`.
	 *
	 * Re-sending an already-sent draft (`status = sent`) is allowed
	 * and writes a fresh `send_id` so the
	 * `UNIQUE(send_id, subscriber_id)` constraint stays clean.
	 *
	 * @since 0.4.0
	 * @param int  $draft_id     Draft ID.
	 * @param bool $is_automated True when triggered autonomously via the
	 *                           REST send route (recorded on the send row
	 *                           so the Sent log can distinguish it from a
	 *                           human-pressed Send). Default false.
	 * @return int|\WP_Error New send ID on success, `WP_Error` on failure.
	 */
	public function queue( int $draft_id, bool $is_automated = false ): int|\WP_Error {
		$drafts_controller = new Drafts_Controller();
		$draft             = $drafts_controller->get( $draft_id );

		if ( null === $draft ) {
			return new \WP_Error( 'hum_send_draft_not_found', __( 'Draft not found.', 'heads-up-mailer' ) );
		}

		if ( DRAFT_STATUS_SENDING === (string) $draft->status ) {
			return new \WP_Error( 'hum_send_already_in_flight', __( 'This draft is already sending. Wait for it to finish.', 'heads-up-mailer' ) );
		}

		$from_email = (string) get_option( OPTION_FROM_EMAIL, '' );

		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return new \WP_Error( 'hum_send_from_email_missing', __( 'Configure a From: email on Settings → Sending before sending.', 'heads-up-mailer' ) );
		}

		$slugs = $drafts_controller->suggested_groups( $draft );

		if ( empty( $slugs ) ) {
			return new \WP_Error( 'hum_send_no_groups', __( 'Select at least one group on the draft before sending.', 'heads-up-mailer' ) );
		}

		$groups_controller = new Groups_Controller();
		$group_ids         = array();

		foreach ( $slugs as $slug ) {
			$group = $groups_controller->get_by_slug( (string) $slug );

			if ( null !== $group ) {
				$group_ids[] = (int) $group->id;
			}
		}

		if ( empty( $group_ids ) ) {
			return new \WP_Error( 'hum_send_no_groups', __( 'None of the selected groups exist any more.', 'heads-up-mailer' ) );
		}

		$recipient_ids = $this->compute_recipient_ids( $group_ids );

		if ( empty( $recipient_ids ) ) {
			return new \WP_Error( 'hum_send_no_recipients', __( 'No subscribed recipients in the selected groups.', 'heads-up-mailer' ) );
		}

		return $this->commit_queue( $draft_id, $group_ids, $recipient_ids, $is_automated );
	}

	/**
	 * Decide whether a draft may be sent autonomously via REST.
	 *
	 * Runs the autonomy-specific gates that the human Send path does
	 * not need — the master switch, machine-level idempotency, and the
	 * per-group automation allowlist — and returns a `WP_Error`
	 * carrying the HTTP status the REST route should surface. This is
	 * the only place those gates live, so the route stays a thin
	 * wrapper. On pass, the caller proceeds to `queue( $id, true )`,
	 * which re-runs the shared pre-flight (From: identity, real
	 * recipients).
	 *
	 * Guards mirror `queue()`'s validate-and-bail style: each failure
	 * returns immediately; the body converges to `return true` once
	 * every gate has passed.
	 *
	 * @since 1.5.0
	 * @param int $draft_id Draft ID.
	 * @return true|\WP_Error True when allowed, else a `WP_Error` whose
	 *                        data carries `status` (403 / 404 / 409 / 422).
	 */
	public function autonomous_gate( int $draft_id ): true|\WP_Error {
		$enabled = filter_var( get_option( OPTION_AUTONOMOUS_SEND_ENABLED, DEF_AUTONOMOUS_SEND_ENABLED ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $enabled ) {
			return new \WP_Error(
				'hum_autonomous_disabled',
				__( 'Autonomous sending is disabled. Enable "Allow trigger-send via REST API" on Settings → Sending.', 'heads-up-mailer' ),
				array( 'status' => 403 )
			);
		}

		$drafts_controller = new Drafts_Controller();
		$draft             = $drafts_controller->get( $draft_id );

		if ( null === $draft ) {
			return new \WP_Error(
				'hum_send_draft_not_found',
				__( 'Draft not found.', 'heads-up-mailer' ),
				array( 'status' => 404 )
			);
		}

		$status = (string) $draft->status;

		if ( DRAFT_STATUS_SENDING === $status || DRAFT_STATUS_SENT === $status ) {
			return new \WP_Error(
				'hum_send_already_done',
				__( 'This draft has already been sent or is currently sending. Autonomous re-sends are refused.', 'heads-up-mailer' ),
				array( 'status' => 409 )
			);
		}

		$slugs = $drafts_controller->suggested_groups( $draft );

		if ( empty( $slugs ) ) {
			return new \WP_Error(
				'hum_send_no_groups',
				__( 'The draft targets no groups, so there is nothing to authorise.', 'heads-up-mailer' ),
				array( 'status' => 422 )
			);
		}

		$groups_controller = new Groups_Controller();
		$blocked           = array();

		foreach ( $slugs as $slug ) {
			$group          = $groups_controller->get_by_slug( (string) $slug );
			$is_automatable = ( null !== $group ) && filter_var( $group->allow_automated_send ?? 0, FILTER_VALIDATE_BOOLEAN );

			if ( ! $is_automatable ) {
				$blocked[] = (string) $slug;
			}
		}

		if ( ! empty( $blocked ) ) {
			return new \WP_Error(
				'hum_group_not_automated',
				sprintf(
					/* translators: %s: comma-separated list of group slugs that are not automation-enabled. */
					__( 'Autonomous send refused: these target groups are not enabled for automated sending: %s. Tick "Allow autonomous send" on each group, or remove it from the draft.', 'heads-up-mailer' ),
					implode( ', ', $blocked )
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Wrap the queue insert in a single transaction.
	 *
	 * Extracted so the pre-flight in `queue()` can converge to a
	 * single `return $this->commit_queue(...)` once every guard has
	 * passed.
	 *
	 * @since 0.4.0
	 * @param int             $draft_id      Draft ID.
	 * @param array<int, int> $group_ids     Resolved group IDs.
	 * @param array<int, int> $recipient_ids Deduplicated subscriber IDs.
	 * @param bool            $is_automated  True for a REST-triggered send.
	 * @return int|\WP_Error New send ID, or `WP_Error` on failure.
	 */
	private function commit_queue( int $draft_id, array $group_ids, array $recipient_ids, bool $is_automated = false ): int|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundary.
		$wpdb->query( 'START TRANSACTION' );

		$result = $this->insert_send_atomic( $draft_id, $group_ids, $recipient_ids, $is_automated );

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundary.
			$wpdb->query( 'ROLLBACK' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundary.
			$wpdb->query( 'COMMIT' );
		}

		return $result;
	}

	/**
	 * Execute the three transactional writes.
	 *
	 * Step failures `return new WP_Error` mid-function so the caller
	 * can roll back without trying every subsequent step. The
	 * convergence-at-bottom rule bends here because the body is a
	 * linear chain of dependent writes, not a branching state
	 * machine.
	 *
	 * @since 0.4.0
	 * @param int             $draft_id      Draft ID.
	 * @param array<int, int> $group_ids     Resolved group IDs.
	 * @param array<int, int> $recipient_ids Subscriber IDs to enqueue.
	 * @param bool            $is_automated  True for a REST-triggered send.
	 * @return int|\WP_Error
	 */
	private function insert_send_atomic( int $draft_id, array $group_ids, array $recipient_ids, bool $is_automated = false ): int|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$inserted_send = $wpdb->insert(
			$this->sends_table(),
			array(
				'draft_id'       => $draft_id,
				'group_ids_json' => (string) wp_json_encode( array_values( $group_ids ) ),
				'is_automated'   => $is_automated ? 1 : 0,
				'started_at'     => now_utc(),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted_send ) {
			return new \WP_Error( 'hum_send_insert_failed', __( 'Failed to create send.', 'heads-up-mailer' ) );
		}

		$send_id = (int) $wpdb->insert_id;

		// Bulk-insert the recipient rows in one statement. Each row's
		// defaults (status=pending, attempts=0, last_error='', sent_at='')
		// come from the column definitions, so we only set send_id +
		// subscriber_id explicitly.
		$values       = array();
		$placeholders = array();

		foreach ( $recipient_ids as $sub_id ) {
			$values[]       = $send_id;
			$values[]       = (int) $sub_id;
			$placeholders[] = '(%d, %d)';
		}

		$recipients_table  = $this->recipients_table();
		$placeholders_join = implode( ', ', $placeholders );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders fanned out from integer subscriber IDs; values prepared.
		$inserted_recipients = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$recipients_table} (send_id, subscriber_id) VALUES {$placeholders_join}",
				$values
			)
		);
		// phpcs:enable

		if ( false === $inserted_recipients ) {
			return new \WP_Error( 'hum_send_insert_failed', __( 'Failed to insert recipients.', 'heads-up-mailer' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated_draft = $wpdb->update(
			$wpdb->prefix . TABLE_DRAFTS,
			array( 'status' => DRAFT_STATUS_SENDING ),
			array( 'id' => $draft_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated_draft ) {
			return new \WP_Error( 'hum_send_update_failed', __( 'Failed to mark draft as sending.', 'heads-up-mailer' ) );
		}

		return $send_id;
	}
}
