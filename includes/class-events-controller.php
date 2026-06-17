<?php
/**
 * Activity event log (per-group membership transitions).
 *
 * @package Heads_Up_Mailer
 * @since 1.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Events controller. Writes and reads `hum_events` rows.
 *
 * Events are an append-only activity log. Each row records that a
 * subscriber joined or left a group at a point in time. They are
 * written from `Subscribers_Controller::set_groups()` whenever the
 * membership diff is non-empty, and read back by the dashboard to
 * show per-group sign-up and departure trends over a rolling window.
 *
 * @since 1.3.0
 */
class Events_Controller {

	/**
	 * Fully qualified events table name.
	 *
	 * @since 1.3.0
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_EVENTS;
	}

	/**
	 * Append one event row.
	 *
	 * Guard-and-bail on an unknown type or non-positive IDs so a bad
	 * caller can't pollute the log. Timestamp is UTC, matching every
	 * other datetime column in the plugin.
	 *
	 * @since 1.3.0
	 * @param string $event_type    One of the `EVENT_*` constants.
	 * @param int    $subscriber_id Subscriber the event is about.
	 * @param int    $group_id      Group the event is about.
	 */
	public function record( string $event_type, int $subscriber_id, int $group_id ): void {
		$known = array( EVENT_GROUP_JOIN, EVENT_GROUP_LEAVE );

		if ( ! in_array( $event_type, $known, true ) || $subscriber_id <= 0 || $group_id <= 0 ) {
			// no action: reject malformed events rather than store noise.
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activity-log append to a custom table.
		$wpdb->insert(
			$this->table(),
			array(
				'event_type'    => $event_type,
				'subscriber_id' => $subscriber_id,
				'group_id'      => $group_id,
				'created_at'    => now_utc(),
			),
			array( '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Record a membership change as join / leave events.
	 *
	 * Diffs the old and new group-ID sets and writes one event per
	 * added or removed group. A no-op change writes nothing.
	 *
	 * @since 1.3.0
	 * @param int        $subscriber_id Subscriber whose membership changed.
	 * @param array<int> $old_ids       Group IDs before the change.
	 * @param array<int> $new_ids       Group IDs after the change.
	 */
	public function record_membership_change( int $subscriber_id, array $old_ids, array $new_ids ): void {
		$old = array_map( 'intval', $old_ids );
		$new = array_map( 'intval', $new_ids );

		$joined = array_diff( $new, $old );
		$left   = array_diff( $old, $new );

		foreach ( $joined as $group_id ) {
			$this->record( EVENT_GROUP_JOIN, $subscriber_id, (int) $group_id );
		}

		foreach ( $left as $group_id ) {
			$this->record( EVENT_GROUP_LEAVE, $subscriber_id, (int) $group_id );
		}
	}

	/**
	 * Count events of a type per group since a UTC cut-off.
	 *
	 * @since 1.3.0
	 * @param string $event_type One of the `EVENT_*` constants.
	 * @param string $since_utc  Inclusive lower-bound `Y-m-d H:i:s UTC` string.
	 * @return array<int,int> Map of group_id => event count (groups with zero events are absent).
	 */
	public function count_by_group_since( string $event_type, string $since_utc ): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard aggregate over a custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT group_id, COUNT(*) AS total
				 FROM {$table}
				 WHERE event_type = %s AND created_at >= %s
				 GROUP BY group_id",
				$event_type,
				$since_utc
			)
		);

		$counts = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (int) $row->group_id ] = (int) $row->total;
			}
		}

		return $counts;
	}

	/**
	 * Count events of a type across all groups since a UTC cut-off.
	 *
	 * @since 1.3.0
	 * @param string $event_type One of the `EVENT_*` constants.
	 * @param string $since_utc  Inclusive lower-bound `Y-m-d H:i:s UTC` string.
	 * @return int
	 */
	public function count_since( string $event_type, string $since_utc ): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard aggregate over a custom table.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s",
				$event_type,
				$since_utc
			)
		);

		return (int) $total;
	}
}
