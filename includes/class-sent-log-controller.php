<?php
/**
 * Sent log — read-only views over hum_sends / hum_send_recipients.
 *
 * @package Heads_Up_Mailer
 * @since 0.7.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Query helpers for the sent-log admin pages.
 *
 * Two views:
 *
 *   - `get_sends_with_counts()` — every send row joined to its
 *     parent draft and decorated with live recipient-status
 *     counters. Live counts are used (rather than the cached
 *     `attempted` / `sent` / `failed` columns on `hum_sends`) so
 *     in-flight sends show accurate numbers before
 *     `Worker::finalize_completed_sends()` populates the cache.
 *   - `get_recipients_for_send()` — single send's recipient rows,
 *     joined to the subscriber so the email column doesn't need
 *     a second lookup. Optional `$status` filter.
 *
 * Both methods return plain stdClass rows so the templates can
 * stay code-first.
 *
 * @since 0.7.0
 */
class Sent_Log_Controller {

	/**
	 * Every send with per-status recipient counters.
	 *
	 * @since 0.7.0
	 * @return array<int, object>
	 */
	public function get_sends_with_counts(): array {
		global $wpdb;

		$sends      = $wpdb->prefix . TABLE_SENDS;
		$drafts     = $wpdb->prefix . TABLE_DRAFTS;
		$recipients = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; placeholders prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.id,
					s.draft_id,
					s.is_automated,
					s.started_at,
					s.finished_at,
					d.subject AS draft_subject,
					d.status  AS draft_status,
					COUNT(r.id) AS recipients_total,
					SUM(r.status = %s) AS recipients_sent,
					SUM(r.status = %s) AS recipients_failed,
					SUM(r.status = %s) AS recipients_pending,
					SUM(r.status = %s) AS recipients_processing
				 FROM {$sends} s
				 LEFT JOIN {$drafts} d ON d.id = s.draft_id
				 LEFT JOIN {$recipients} r ON r.send_id = s.id
				 GROUP BY s.id
				 ORDER BY s.id DESC",
				SEND_STATUS_SENT,
				SEND_STATUS_FAILED,
				SEND_STATUS_PENDING,
				SEND_STATUS_PROCESSING
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Single send row with the same counter columns as the list
	 * query, joined to its parent draft.
	 *
	 * @since 0.7.0
	 * @param int $send_id Send ID.
	 * @return ?object
	 */
	public function get_send_with_counts( int $send_id ): ?object {
		global $wpdb;

		$sends      = $wpdb->prefix . TABLE_SENDS;
		$drafts     = $wpdb->prefix . TABLE_DRAFTS;
		$recipients = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; placeholders prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					s.id,
					s.draft_id,
					s.is_automated,
					s.started_at,
					s.finished_at,
					d.subject AS draft_subject,
					d.status  AS draft_status,
					COUNT(r.id) AS recipients_total,
					SUM(r.status = %s) AS recipients_sent,
					SUM(r.status = %s) AS recipients_failed,
					SUM(r.status = %s) AS recipients_pending,
					SUM(r.status = %s) AS recipients_processing
				 FROM {$sends} s
				 LEFT JOIN {$drafts} d ON d.id = s.draft_id
				 LEFT JOIN {$recipients} r ON r.send_id = s.id
				 WHERE s.id = %d
				 GROUP BY s.id",
				SEND_STATUS_SENT,
				SEND_STATUS_FAILED,
				SEND_STATUS_PENDING,
				SEND_STATUS_PROCESSING,
				$send_id
			)
		);

		return ( $row instanceof \stdClass ) ? $row : null;
	}

	/**
	 * Recipient rows for one send, optionally narrowed by status.
	 *
	 * @since 0.7.0
	 * @param int    $send_id Send ID.
	 * @param string $status  Optional `SEND_STATUS_*`. Empty = all statuses.
	 * @return array<int, object>
	 */
	public function get_recipients_for_send( int $send_id, string $status = '' ): array {
		global $wpdb;

		$recipients  = $wpdb->prefix . TABLE_SEND_RECIPIENTS;
		$subscribers = $wpdb->prefix . TABLE_SUBSCRIBERS;

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; placeholders prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						r.id,
						r.subscriber_id,
						r.status,
						r.attempts,
						r.sent_at,
						r.last_error,
						s.email
					 FROM {$recipients} r
					 LEFT JOIN {$subscribers} s ON s.id = r.subscriber_id
					 WHERE r.send_id = %d
					 ORDER BY r.id ASC",
					$send_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; placeholders prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						r.id,
						r.subscriber_id,
						r.status,
						r.attempts,
						r.sent_at,
						r.last_error,
						s.email
					 FROM {$recipients} r
					 LEFT JOIN {$subscribers} s ON s.id = r.subscriber_id
					 WHERE r.send_id = %d AND r.status = %s
					 ORDER BY r.id ASC",
					$send_id,
					$status
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
	}
}
