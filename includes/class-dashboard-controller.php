<?php
/**
 * Dashboard overview view-model.
 *
 * @package Heads_Up_Mailer
 * @since 1.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Dashboard controller. Assembles every figure the dashboard
 * template renders into a single structured array, so the template
 * stays presentation-only.
 *
 * Three groups of numbers, all over a rolling `DASHBOARD_WINDOW_DAYS`
 * window where "recent" applies:
 *
 * - Send health — totals and success / failure rate from `hum_sends`,
 *   plus an alert flag when the failure rate crosses the threshold.
 * - Audience — active subscriber total, account unsubscribes in the
 *   window, and per-group active size with join / leave counts.
 * - Recent failures — the latest failed recipients with their error.
 *
 * No tracking pixels, no link rewriting — every figure is derived
 * from data the plugin already owns.
 *
 * @since 1.3.0
 */
class Dashboard_Controller {

	/**
	 * Build the full dashboard payload.
	 *
	 * @since 1.3.0
	 * @return array<string,mixed>
	 */
	public function get_overview(): array {
		$cutoff = $this->window_cutoff();

		return array(
			'window_days'     => DASHBOARD_WINDOW_DAYS,
			'send_health'     => $this->send_health( $cutoff ),
			'audience'        => $this->audience( $cutoff ),
			'groups'          => $this->groups_breakdown( $cutoff ),
			'recent_failures' => $this->recent_failures(),
		);
	}

	/**
	 * UTC lower-bound string for the rolling window.
	 *
	 * @since 1.3.0
	 * @return string `Y-m-d H:i:s UTC`.
	 */
	private function window_cutoff(): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( DASHBOARD_WINDOW_DAYS * DAY_IN_SECONDS ) ) . ' UTC';
	}

	/**
	 * Send totals and success / failure rate within the window.
	 *
	 * Rates are taken over *completed* attempts (sent + failed) so
	 * in-flight pending recipients never dilute them. `success_pct`
	 * and `failure_pct` are null when nothing has completed yet,
	 * which the template renders as an explicit "no sends" state
	 * rather than a misleading 0%.
	 *
	 * @since 1.3.0
	 * @param string $cutoff UTC lower-bound string.
	 * @return array<string,mixed>
	 */
	private function send_health( string $cutoff ): array {
		global $wpdb;
		$sends = $wpdb->prefix . TABLE_SENDS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from prefix; value prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS sends,
					COALESCE(SUM(attempted), 0) AS attempted,
					COALESCE(SUM(sent), 0) AS sent,
					COALESCE(SUM(failed), 0) AS failed
				 FROM {$sends}
				 WHERE started_at >= %s",
				$cutoff
			)
		);

		$sends_count = ( null !== $row ) ? (int) $row->sends : 0;
		$sent        = ( null !== $row ) ? (int) $row->sent : 0;
		$failed      = ( null !== $row ) ? (int) $row->failed : 0;
		$attempted   = ( null !== $row ) ? (int) $row->attempted : 0;
		$completed   = $sent + $failed;

		$success_pct = ( $completed > 0 ) ? round( ( $sent / $completed ) * 100, 1 ) : null;
		$failure_pct = ( $completed > 0 ) ? round( ( $failed / $completed ) * 100, 1 ) : null;
		$alert       = ( null !== $failure_pct && $failure_pct >= DASHBOARD_ALERT_FAIL_PCT );

		return array(
			'sends'       => $sends_count,
			'attempted'   => $attempted,
			'sent'        => $sent,
			'failed'      => $failed,
			'completed'   => $completed,
			'success_pct' => $success_pct,
			'failure_pct' => $failure_pct,
			'alert'       => $alert,
		);
	}

	/**
	 * Active subscriber total and account unsubscribes in the window.
	 *
	 * "Account unsubscribes" counts subscribers whose status moved to
	 * `unsubscribed` or `never_contact` with an `unsubscribed_at`
	 * inside the window — this is the signal that catches one-click
	 * and IMAP unsubscribes, which never touch group memberships.
	 *
	 * @since 1.3.0
	 * @param string $cutoff UTC lower-bound string.
	 * @return array<string,int>
	 */
	private function audience( string $cutoff ): array {
		global $wpdb;
		$subscribers = $wpdb->prefix . TABLE_SUBSCRIBERS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from prefix; value prepared.
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$subscribers} WHERE status = %s",
				STATUS_SUBSCRIBED
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from prefix; values prepared.
		$unsubscribes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$subscribers}
				 WHERE status IN ( %s, %s ) AND unsubscribed_at >= %s",
				STATUS_UNSUBSCRIBED,
				STATUS_NEVER_CONTACT,
				$cutoff
			)
		);

		return array(
			'active'              => $active,
			'unsubscribes_window' => $unsubscribes,
		);
	}

	/**
	 * Per-group active size plus join / leave counts in the window.
	 *
	 * Active size counts only `subscribed` members (the reachable
	 * audience). Joins and leaves come from the event log, so they
	 * read empty for groups with no membership churn since the
	 * feature shipped — they populate going forward.
	 *
	 * @since 1.3.0
	 * @param string $cutoff UTC lower-bound string.
	 * @return array<int,array<string,mixed>>
	 */
	private function groups_breakdown( string $cutoff ): array {
		global $wpdb;
		$groups            = $wpdb->prefix . TABLE_GROUPS;
		$subscriber_groups = $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;
		$subscribers       = $wpdb->prefix . TABLE_SUBSCRIBERS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; value prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.name,
					COUNT(sub.id) AS active_members
				 FROM {$groups} g
				 LEFT JOIN {$subscriber_groups} sg ON sg.group_id = g.id
				 LEFT JOIN {$subscribers} sub ON sub.id = sg.subscriber_id AND sub.status = %s
				 GROUP BY g.id
				 ORDER BY g.name ASC",
				STATUS_SUBSCRIBED
			)
		);

		$events = new Events_Controller();
		$joins  = $events->count_by_group_since( EVENT_GROUP_JOIN, $cutoff );
		$leaves = $events->count_by_group_since( EVENT_GROUP_LEAVE, $cutoff );

		$breakdown = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$group_id = (int) $row->id;

				$breakdown[] = array(
					'id'             => $group_id,
					'name'           => (string) $row->name,
					'active_members' => (int) $row->active_members,
					'joins'          => isset( $joins[ $group_id ] ) ? (int) $joins[ $group_id ] : 0,
					'leaves'         => isset( $leaves[ $group_id ] ) ? (int) $leaves[ $group_id ] : 0,
				);
			}
		}

		return $breakdown;
	}

	/**
	 * The most recent failed recipients with their error text.
	 *
	 * Ordered by row id (a proxy for recency) and capped at
	 * `DASHBOARD_RECENT_LIMIT`. Joined to the subscriber for the
	 * email; a since-removed subscriber yields an empty email, which
	 * the template labels.
	 *
	 * @since 1.3.0
	 * @return array<int,array<string,mixed>>
	 */
	private function recent_failures(): array {
		global $wpdb;
		$recipients  = $wpdb->prefix . TABLE_SEND_RECIPIENTS;
		$subscribers = $wpdb->prefix . TABLE_SUBSCRIBERS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.send_id, r.last_error, r.sent_at, sub.email
				 FROM {$recipients} r
				 LEFT JOIN {$subscribers} sub ON sub.id = r.subscriber_id
				 WHERE r.status = %s
				 ORDER BY r.id DESC
				 LIMIT %d",
				SEND_STATUS_FAILED,
				DASHBOARD_RECENT_LIMIT
			)
		);

		$failures = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$failures[] = array(
					'send_id'    => (int) $row->send_id,
					'email'      => (string) $row->email,
					'last_error' => (string) $row->last_error,
				);
			}
		}

		return $failures;
	}
}
