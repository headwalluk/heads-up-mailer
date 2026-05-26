<?php
/**
 * Send-queue worker.
 *
 * @package Heads_Up_Mailer
 * @since 0.4.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * WP-Cron-driven worker that drains the `hum_send_recipients` queue.
 *
 * Each tick:
 *
 *   1. Acquire `TRANSIENT_DRAIN_LOCK` so two ticks can't overlap.
 *   2. Pull up to `OPTION_BATCH_SIZE` rows with `status=pending`,
 *      ordered by `id` ASC.
 *   3. For each row: claim atomically (pending → processing),
 *      build headers + footer-injected body + plain-text alt, scope
 *      `wp_mail_from` / `wp_mail_from_name` to this send only, call
 *      `wp_mail()`, write the outcome (sent / failed) back to the
 *      row.
 *   4. Wall-clock budget of `~25s` — bail with rows still pending
 *      rather than risk a half-finished tick blowing past PHP's
 *      `max_execution_time`.
 *   5. Finalise: any send whose recipient rows have all reached
 *      sent or failed gets `finished_at` stamped, the counters
 *      populated, and the parent draft flipped from `sending` to
 *      `sent`.
 *
 * @since 0.4.0
 */
class Worker {

	/**
	 * Per-tick wall-clock budget, in seconds. Leaves headroom under
	 * the default `max_execution_time` of 30s.
	 *
	 * @since 0.4.0
	 */
	private const TICK_BUDGET_SECONDS = 25;

	/**
	 * Lock TTL — long enough to outlive the worst-case tick, short
	 * enough that a crashed worker won't block the queue for hours.
	 *
	 * @since 0.4.0
	 */
	private const LOCK_TTL_SECONDS = 90;

	/**
	 * Register hooks.
	 *
	 * @since 0.4.0
	 */
	public function run(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval value is dynamic (clamped 1..60 min from settings); sniff can't statically infer it.
		add_filter( 'cron_schedules', array( $this, 'register_interval' ) );
		add_action( CRON_DRAIN_QUEUE, array( $this, 'drain' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		// Tick-interval changes re-schedule the recurring event so the
		// new cadence takes effect without an activation cycle.
		add_action( 'update_option_' . OPTION_TICK_MINUTES, array( $this, 'reschedule' ), 10, 2 );
	}

	/**
	 * Inject our custom cron interval driven by `OPTION_TICK_MINUTES`.
	 *
	 * @since 0.4.0
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_interval( array $schedules ): array {
		$minutes = (int) get_option( OPTION_TICK_MINUTES, DEF_TICK_MINUTES );
		$minutes = max( 1, min( 60, $minutes ) );

		$schedules[ CRON_INTERVAL_TICK ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => __( 'Heads Up Mailer tick', 'heads-up-mailer' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring drain event if not already scheduled.
	 *
	 * @since 0.4.0
	 */
	public function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( CRON_DRAIN_QUEUE ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, CRON_INTERVAL_TICK, CRON_DRAIN_QUEUE );
		}
	}

	/**
	 * Re-schedule after the tick interval changes.
	 *
	 * @since 0.4.0
	 * @param mixed $old_value Previous minutes value.
	 * @param mixed $new_value New minutes value.
	 */
	public function reschedule( $old_value, $new_value ): void {
		if ( (int) $old_value !== (int) $new_value ) {
			wp_clear_scheduled_hook( CRON_DRAIN_QUEUE );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, CRON_INTERVAL_TICK, CRON_DRAIN_QUEUE );
		}
	}

	/**
	 * Cron entry point. Acquire lock, drain a batch, finalise.
	 *
	 * @since 0.4.0
	 */
	public function drain(): void {
		// Best-effort overlap guard. WP transients are not strictly
		// atomic against a racing tick, but cron ticks fire seconds
		// apart on quiet sites and the per-row claim below is the
		// real safety net.
		if ( false !== get_transient( TRANSIENT_DRAIN_LOCK ) ) {
			return;
		}

		set_transient( TRANSIENT_DRAIN_LOCK, time(), self::LOCK_TTL_SECONDS );

		try {
			$this->drain_batch();
			$this->finalize_completed_sends();
		} finally {
			delete_transient( TRANSIENT_DRAIN_LOCK );
		}
	}

	/**
	 * Pull up to OPTION_BATCH_SIZE pending rows and process each.
	 *
	 * @since 0.4.0
	 */
	private function drain_batch(): void {
		global $wpdb;

		$batch_size = (int) get_option( OPTION_BATCH_SIZE, DEF_BATCH_SIZE );
		$batch_size = max( 1, min( 100, $batch_size ) );
		$deadline   = time() + self::TICK_BUDGET_SECONDS;

		$recipients_table = $wpdb->prefix . TABLE_SEND_RECIPIENTS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from prefix; placeholders prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, send_id, subscriber_id, attempts FROM {$recipients_table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				SEND_STATUS_PENDING,
				$batch_size
			)
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		// Per-tick caches so we don't re-fetch the draft + sender +
		// footer template once per recipient when many share a send.
		$drafts_by_send = array();
		$sender         = array(
			'from_email' => (string) get_option( OPTION_FROM_EMAIL, '' ),
			'from_name'  => (string) get_option( OPTION_FROM_NAME, '' ),
		);

		foreach ( $rows as $row ) {
			if ( time() >= $deadline ) {
				break;
			}

			$send_id = (int) $row->send_id;

			if ( ! isset( $drafts_by_send[ $send_id ] ) ) {
				$drafts_by_send[ $send_id ] = $this->load_draft_for_send( $send_id );
			}

			$draft = $drafts_by_send[ $send_id ];

			$this->process_one( $row, $draft, $sender );
		}
	}

	/**
	 * Fetch the draft attached to a send_id, cached by the caller.
	 *
	 * @since 0.4.0
	 * @param int $send_id Send ID.
	 * @return ?object Draft row, or null when the send / draft is missing.
	 */
	private function load_draft_for_send( int $send_id ): ?object {
		$sends_controller  = new Sends_Controller();
		$drafts_controller = new Drafts_Controller();

		$send = $sends_controller->get( $send_id );

		if ( null === $send ) {
			return null;
		}

		return $drafts_controller->get( (int) $send->draft_id );
	}

	/**
	 * Process one recipient row.
	 *
	 * Atomic claim (`pending` → `processing`) so concurrent ticks
	 * never double-send the same row even if the transient lock
	 * fails to hold them apart.
	 *
	 * @since 0.4.0
	 * @param object  $row    Recipient row (id, send_id, subscriber_id, attempts).
	 * @param ?object $draft  Draft row, or null on lookup failure.
	 * @param array   $sender ['from_email' => ..., 'from_name' => ...].
	 */
	private function process_one( object $row, ?object $draft, array $sender ): void {
		global $wpdb;
		$recipients_table = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Optimistic claim.
		$claimed = $wpdb->update(
			$recipients_table,
			array( 'status' => SEND_STATUS_PROCESSING ),
			array(
				'id'     => (int) $row->id,
				'status' => SEND_STATUS_PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $claimed ) {
			return; // raced; another tick owns it.
		}

		$attempts = (int) $row->attempts + 1;

		// Lookup failures: mark failed and move on. The send won't
		// finalise until every row has a terminal status, so dropping
		// these rows on the floor would hang the parent draft.
		if ( null === $draft ) {
			$this->mark_failed( (int) $row->id, $attempts, 'draft missing' );
			return;
		}

		$subscriber = ( new Subscribers_Controller() )->get( (int) $row->subscriber_id );

		if ( null === $subscriber ) {
			$this->mark_failed( (int) $row->id, $attempts, 'subscriber missing' );
			return;
		}

		if ( STATUS_SUBSCRIBED !== (string) $subscriber->status ) {
			$this->mark_failed( (int) $row->id, $attempts, 'subscriber not subscribed' );
			return;
		}

		if ( '' === $sender['from_email'] || ! is_email( $sender['from_email'] ) ) {
			$this->mark_failed( (int) $row->id, $attempts, 'from_email missing or invalid' );
			return;
		}

		$token = ( new Tokens() )->generate( (int) $subscriber->id );

		if ( '' === $token ) {
			$this->mark_failed( (int) $row->id, $attempts, 'token generation failed' );
			return;
		}

		$ok = $this->send_with_filters( $draft, $subscriber, $token, $sender );

		if ( $ok ) {
			$this->mark_sent( (int) $row->id, $attempts );
		} else {
			$this->mark_failed( (int) $row->id, $attempts, 'wp_mail returned false' );
		}
	}

	/**
	 * Build the message and dispatch via `wp_mail()` with filters
	 * scoped to this single call.
	 *
	 * @since 0.4.0
	 * @param object $draft      Draft row.
	 * @param object $subscriber Subscriber row.
	 * @param string $token      Pre-generated bearer token.
	 * @param array  $sender     ['from_email' => ..., 'from_name' => ...].
	 */
	private function send_with_filters( object $draft, object $subscriber, string $token, array $sender ): bool {
		$unsub_url    = $this->build_unsubscribe_url( $token );
		$unsub_mailto = $this->build_unsubscribe_mailto( $token );
		$list_id      = $this->build_list_id();

		$headers = array(
			'List-Unsubscribe: <' . $unsub_mailto . '>, <' . $unsub_url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			'List-ID: <' . $list_id . '>',
			'Precedence: bulk',
			'Content-Type: text/html; charset=UTF-8',
		);

		$html_body = $this->inject_footer( (string) $draft->html_body, $unsub_url );
		$alt_body  = $this->build_plain_text( $html_body );

		$from_email = $sender['from_email'];
		$from_name  = $sender['from_name'];

		$from_filter = static function () use ( $from_email ) {
			return $from_email;
		};
		$name_filter = static function () use ( $from_name ) {
			return $from_name;
		};
		$alt_action  = static function ( $phpmailer ) use ( $alt_body ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer exposes `AltBody`; not our property name.
			$phpmailer->AltBody = $alt_body;
		};

		add_filter( 'wp_mail_from', $from_filter, 100 );
		add_filter( 'wp_mail_from_name', $name_filter, 100 );
		add_action( 'phpmailer_init', $alt_action );

		$ok = wp_mail( (string) $subscriber->email, (string) $draft->subject, $html_body, $headers );

		remove_filter( 'wp_mail_from', $from_filter, 100 );
		remove_filter( 'wp_mail_from_name', $name_filter, 100 );
		remove_action( 'phpmailer_init', $alt_action );

		return (bool) $ok;
	}

	/**
	 * Build the https unsubscribe URL.
	 *
	 * @since 0.4.0
	 * @param string $token Bearer token.
	 * @return string
	 */
	private function build_unsubscribe_url( string $token ): string {
		$slug = (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG );

		return add_query_arg(
			array(
				'token'  => $token,
				'action' => 'unsubscribe',
			),
			home_url( '/' . $slug . '/' )
		);
	}

	/**
	 * Build the mailto: unsubscribe target.
	 *
	 * The mailbox poller (M7) matches subjects against
	 * `^unsubscribe-([A-Za-z0-9._-]+)$` on this same address.
	 *
	 * @since 0.4.0
	 * @param string $token Bearer token.
	 * @return string Unsubscribe mailto URL (without surrounding `<>`).
	 */
	private function build_unsubscribe_mailto( string $token ): string {
		$mailbox = (string) get_option( OPTION_MAILBOX_USER, DEF_MAILBOX_USER );

		return 'mailto:' . $mailbox . '?subject=unsubscribe-' . rawurlencode( $token );
	}

	/**
	 * Build the List-ID identifier — `heads-up-mailer.<host>`.
	 *
	 * Derived from `home_url()` host so the identity stays stable
	 * across content edits but moves with site renames.
	 *
	 * @since 0.4.0
	 * @return string Bare List-ID (caller wraps in `<>`).
	 */
	private function build_list_id(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' === $host ? 'localhost' : $host;

		return 'heads-up-mailer.' . $host;
	}

	/**
	 * Inject the configured footer before the last `</body>` (or at
	 * the end of the body for fragment HTML).
	 *
	 * The footer template's `{{unsubscribe_url}}` placeholder is
	 * replaced with the recipient's URL. URL is `esc_url`-quoted so
	 * it's safe to drop into an `href` attribute.
	 *
	 * @since 0.4.0
	 * @param string $html      Draft HTML body.
	 * @param string $unsub_url Per-recipient unsubscribe URL.
	 * @return string
	 */
	private function inject_footer( string $html, string $unsub_url ): string {
		$template = (string) get_option( OPTION_FOOTER_HTML, DEF_FOOTER_HTML );
		$footer   = str_replace( '{{unsubscribe_url}}', esc_url( $unsub_url ), $template );

		$pos = strripos( $html, '</body>' );

		if ( false === $pos ) {
			$result = $html . $footer;
		} else {
			$result = substr( $html, 0, $pos ) . $footer . substr( $html, $pos );
		}

		return $result;
	}

	/**
	 * Build the plain-text alternative from the HTML body.
	 *
	 * `wp_strip_all_tags` removes `<script>` / `<style>` contents
	 * fully and then strips remaining tags. We collapse runs of
	 * blank lines and leading spaces to keep paragraph structure
	 * readable.
	 *
	 * @since 0.4.0
	 * @param string $html Footer-injected HTML body.
	 * @return string
	 */
	private function build_plain_text( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = (string) preg_replace( '/^[ \t]+/m', '', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Mark a recipient row as sent.
	 *
	 * @since 0.4.0
	 * @param int $row_id   Recipient row ID.
	 * @param int $attempts Updated attempts count.
	 */
	private function mark_sent( int $row_id, int $attempts ): void {
		global $wpdb;
		$recipients_table = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$wpdb->update(
			$recipients_table,
			array(
				'status'   => SEND_STATUS_SENT,
				'attempts' => $attempts,
				'sent_at'  => now_utc(),
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a recipient row as failed.
	 *
	 * @since 0.4.0
	 * @param int    $row_id   Recipient row ID.
	 * @param int    $attempts Updated attempts count.
	 * @param string $error    Short reason — surfaced in the sent log.
	 */
	private function mark_failed( int $row_id, int $attempts, string $error ): void {
		global $wpdb;
		$recipients_table = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$wpdb->update(
			$recipients_table,
			array(
				'status'     => SEND_STATUS_FAILED,
				'attempts'   => $attempts,
				'last_error' => $error,
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Finalise sends where every recipient row has reached a terminal
	 * status. Populates counters, stamps `finished_at`, and flips the
	 * parent draft from `sending` to `sent`.
	 *
	 * @since 0.4.0
	 */
	private function finalize_completed_sends(): void {
		global $wpdb;
		$sends_table      = $wpdb->prefix . TABLE_SENDS;
		$recipients_table = $wpdb->prefix . TABLE_SEND_RECIPIENTS;
		$drafts_table     = $wpdb->prefix . TABLE_DRAFTS;

		// Sends with no pending / processing rows, where finished_at is
		// still unset. The NOT EXISTS subquery is the cheap way to ask
		// "every child row is terminal" without grouping.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from prefix; placeholders prepared.
		$sends = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, draft_id FROM {$sends_table} s
				 WHERE s.finished_at = ''
				   AND NOT EXISTS (
				     SELECT 1 FROM {$recipients_table} r
				     WHERE r.send_id = s.id AND r.status IN (%s, %s)
				   )",
				SEND_STATUS_PENDING,
				SEND_STATUS_PROCESSING
			)
		);

		if ( ! is_array( $sends ) ) {
			return;
		}

		foreach ( $sends as $send ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation; placeholders prepared.
			$counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(*) AS attempted,
						SUM(status = %s) AS sent_count,
						SUM(status = %s) AS failed_count
					 FROM {$recipients_table}
					 WHERE send_id = %d",
					SEND_STATUS_SENT,
					SEND_STATUS_FAILED,
					(int) $send->id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
			$wpdb->update(
				$sends_table,
				array(
					'attempted'   => (int) ( $counts->attempted ?? 0 ),
					'sent'        => (int) ( $counts->sent_count ?? 0 ),
					'failed'      => (int) ( $counts->failed_count ?? 0 ),
					'finished_at' => now_utc(),
				),
				array( 'id' => (int) $send->id ),
				array( '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);

			// Conditional draft flip: only sending → sent. If an admin
			// manually changed status meanwhile, leave it alone.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional flip.
			$wpdb->update(
				$drafts_table,
				array( 'status' => DRAFT_STATUS_SENT ),
				array(
					'id'     => (int) $send->draft_id,
					'status' => DRAFT_STATUS_SENDING,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
	}
}
