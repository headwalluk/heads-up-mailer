<?php
/**
 * Mailbox poller for mailto: unsubscribe replies.
 *
 * @package Heads_Up_Mailer
 * @since 0.6.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * WP-Cron-driven IMAP poller that translates inbound
 * `unsubscribe-<token>` mails on `unsub@…` into status flips.
 *
 * Each tick:
 *
 *   1. Bail early if creds are unconfigured or `ext-imap` is
 *      missing — both states are recoverable by the admin and
 *      shouldn't spam the error log.
 *   2. Acquire `TRANSIENT_POLL_LOCK` so concurrent ticks can't
 *      both walk the mailbox.
 *   3. Decrypt the stored password via `Crypto` and open the
 *      IMAP connection with a single retry (mirrors the
 *      test-connection AJAX handler).
 *   4. Ensure the `Processed` / `Errors` folders exist —
 *      `imap_createmailbox` is idempotent in practice but we
 *      guard with `imap_list` first to avoid noisy errors on
 *      every tick.
 *   5. `imap_search` UNSEEN, walk results within a ~25s wall-
 *      clock budget. For each message: parse the subject,
 *      verify the token, flip the subscriber, and move the
 *      message to the right folder.
 *   6. Stamp `OPTION_MAILBOX_LAST_OK` so the stale-poll admin
 *      notice stays quiet.
 *
 * @since 0.6.0
 */
class Mailbox_Poller {

	/**
	 * Per-tick wall-clock budget, in seconds. Same headroom as
	 * the M5 worker.
	 *
	 * @since 0.6.0
	 */
	private const TICK_BUDGET_SECONDS = 25;

	/**
	 * Lock TTL — long enough to outlive a slow tick, short
	 * enough that a crashed poller doesn't block the cron for
	 * hours.
	 *
	 * @since 0.6.0
	 */
	private const LOCK_TTL_SECONDS = 300;

	/**
	 * Subject pattern produced by the M5 worker's mailto
	 * unsubscribe link.
	 *
	 * @since 0.6.0
	 */
	private const SUBJECT_PATTERN = '/^unsubscribe-([A-Za-z0-9._-]+)$/';

	/**
	 * Register hooks.
	 *
	 * @since 0.6.0
	 */
	public function run(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval value is dynamic (clamped 1..60 min from settings); sniff can't statically infer it.
		add_filter( 'cron_schedules', array( $this, 'register_interval' ) );
		add_action( CRON_POLL_MAILBOX, array( $this, 'poll' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		// Interval changes re-schedule the recurring event so the
		// new cadence takes effect without an activation cycle.
		add_action( 'update_option_' . OPTION_MAILBOX_INTERVAL, array( $this, 'reschedule' ), 10, 2 );
	}

	/**
	 * Inject the mailbox-poll cron interval driven by
	 * `OPTION_MAILBOX_INTERVAL`.
	 *
	 * @since 0.6.0
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_interval( array $schedules ): array {
		$minutes = (int) get_option( OPTION_MAILBOX_INTERVAL, DEF_MAILBOX_INTERVAL );
		$minutes = max( 1, min( 60, $minutes ) );

		$schedules[ CRON_INTERVAL_MAILBOX_TICK ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => __( 'Heads Up Mailer mailbox poll', 'heads-up-mailer' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring poll event if not already scheduled.
	 *
	 * @since 0.6.0
	 */
	public function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( CRON_POLL_MAILBOX ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, CRON_INTERVAL_MAILBOX_TICK, CRON_POLL_MAILBOX );
		}
	}

	/**
	 * Re-schedule after the poll interval changes.
	 *
	 * @since 0.6.0
	 * @param mixed $old_value Previous minutes value.
	 * @param mixed $new_value New minutes value.
	 */
	public function reschedule( $old_value, $new_value ): void {
		if ( (int) $old_value !== (int) $new_value ) {
			wp_clear_scheduled_hook( CRON_POLL_MAILBOX );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, CRON_INTERVAL_MAILBOX_TICK, CRON_POLL_MAILBOX );
		}
	}

	/**
	 * Cron entry point. Acquires the lock and runs `run_poll`.
	 *
	 * Honours the `OPTION_MAILBOX_POLL_ENABLED` master switch so
	 * the admin can pause polling without removing credentials.
	 * The "Poll now" button bypasses this check on purpose — it's
	 * an explicit manual action, the switch governs the recurring
	 * one.
	 *
	 * @since 0.6.0
	 */
	public function poll(): void {
		$enabled = (bool) filter_var( get_option( OPTION_MAILBOX_POLL_ENABLED, DEF_MAILBOX_POLL_ENABLED ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $enabled ) {
			return;
		}

		$config = $this->load_config();

		if ( null === $config ) {
			return;
		}

		if ( false !== get_transient( TRANSIENT_POLL_LOCK ) ) {
			return;
		}

		set_transient( TRANSIENT_POLL_LOCK, time(), self::LOCK_TTL_SECONDS );

		try {
			$this->run_poll( $config );
		} finally {
			delete_transient( TRANSIENT_POLL_LOCK );
		}
	}

	/**
	 * Synchronous variant — opens IMAP, processes messages,
	 * returns a structured result. Reused by the cron hook and
	 * the "Poll now" AJAX endpoint.
	 *
	 * Returns shape:
	 *   ok       => bool
	 *   message  => string  (translated, human-readable)
	 *   processed => int    (count of recipients flipped)
	 *   errored   => int    (count of unparseable messages moved to Errors)
	 *
	 * @since 0.6.0
	 * @return array{ok: bool, message: string, processed: int, errored: int}
	 */
	public function poll_now(): array {
		$config = $this->load_config();

		if ( null === $config ) {
			$reason = ! extension_loaded( 'imap' )
				? __( 'The PHP imap extension is not loaded on this host.', 'heads-up-mailer' )
				: __( 'Mailbox credentials are not fully configured.', 'heads-up-mailer' );

			$result = array(
				'ok'        => false,
				'message'   => $reason,
				'processed' => 0,
				'errored'   => 0,
			);
		} elseif ( false !== get_transient( TRANSIENT_POLL_LOCK ) ) {
			$result = array(
				'ok'        => false,
				'message'   => __( 'Another poll is already in progress. Try again in a moment.', 'heads-up-mailer' ),
				'processed' => 0,
				'errored'   => 0,
			);
		} else {
			set_transient( TRANSIENT_POLL_LOCK, time(), self::LOCK_TTL_SECONDS );

			try {
				$result = $this->run_poll( $config );
			} finally {
				delete_transient( TRANSIENT_POLL_LOCK );
			}
		}

		return $result;
	}

	/**
	 * Load mailbox config + decrypt password. Returns null if
	 * the poller can't run (missing creds or missing ext-imap),
	 * the two states the cron path silently skips.
	 *
	 * @since 0.6.0
	 * @return ?array{host: string, port: int, user: string, pass: string, folder: string, tls: bool, validate_cert: bool}
	 */
	private function load_config(): ?array {
		if ( ! extension_loaded( 'imap' ) ) {
			return null;
		}

		$host          = (string) get_option( OPTION_MAILBOX_HOST, '' );
		$port          = (int) get_option( OPTION_MAILBOX_PORT, DEF_MAILBOX_PORT );
		$user          = (string) get_option( OPTION_MAILBOX_USER, DEF_MAILBOX_USER );
		$folder        = (string) get_option( OPTION_MAILBOX_FOLDER, DEF_MAILBOX_FOLDER );
		$tls           = (bool) filter_var( get_option( OPTION_MAILBOX_TLS, DEF_MAILBOX_TLS ), FILTER_VALIDATE_BOOLEAN );
		$validate_cert = (bool) filter_var( get_option( OPTION_MAILBOX_VALIDATE_CERT, DEF_MAILBOX_VALIDATE_CERT ), FILTER_VALIDATE_BOOLEAN );
		$pass          = Crypto::decrypt( (string) get_option( OPTION_MAILBOX_PASSWORD, '' ) );

		if ( '' === $host || $port < 1 || '' === $user || '' === $pass ) {
			return null;
		}

		return array(
			'host'          => $host,
			'port'          => $port,
			'user'          => $user,
			'pass'          => $pass,
			'folder'        => '' === $folder ? DEF_MAILBOX_FOLDER : $folder,
			'tls'           => $tls,
			'validate_cert' => $validate_cert,
		);
	}

	/**
	 * Open IMAP, walk UNSEEN messages, dispatch each, stamp
	 * health-state. Caller is responsible for the lock.
	 *
	 * @since 0.6.0
	 * @param array{host: string, port: int, user: string, pass: string, folder: string, tls: bool, validate_cert: bool} $config Configured mailbox.
	 * @return array{ok: bool, message: string, processed: int, errored: int}
	 */
	private function run_poll( array $config ): array {
		$mailbox = $this->build_mailbox_string( $config );

		// Clear any stale error stack from earlier requests.
		imap_errors();

		// Single retry to keep wall-clock bounded — same flag the
		// test-connection handler uses.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_open emits warnings that we surface via imap_errors().
		$conn = @imap_open( $mailbox, $config['user'], $config['pass'], 0, 1 );

		if ( false === $conn ) {
			$errors = imap_errors();
			$last   = is_array( $errors ) && ! empty( $errors ) ? (string) end( $errors ) : '';
			$reason = '' !== $last ? $last : __( 'Failed to open IMAP connection.', 'heads-up-mailer' );

			$this->record_error( $reason );

			return array(
				'ok'        => false,
				'message'   => $reason,
				'processed' => 0,
				'errored'   => 0,
			);
		}

		$base_path = $this->build_mailbox_base( $config );
		$this->ensure_folder( $conn, $base_path, MAILBOX_FOLDER_PROCESSED );
		$this->ensure_folder( $conn, $base_path, MAILBOX_FOLDER_ERRORS );

		$processed = 0;
		$errored   = 0;
		$deadline  = time() + self::TICK_BUDGET_SECONDS;

		$message_ids = imap_search( $conn, 'UNSEEN' );

		if ( is_array( $message_ids ) ) {
			foreach ( $message_ids as $message_id ) {
				if ( time() >= $deadline ) {
					break;
				}

				if ( $this->process_message( $conn, (int) $message_id ) ) {
					++$processed;
				} else {
					++$errored;
				}
			}
		}

		// CL_EXPUNGE applies the queued moves before closing.
		imap_close( $conn, CL_EXPUNGE );

		$this->record_success();

		/* translators: 1: number of processed unsubscribes, 2: number of unparseable messages moved to Errors. */
		$message = sprintf( __( 'Poll OK — %1$d processed, %2$d errored.', 'heads-up-mailer' ), $processed, $errored );

		return array(
			'ok'        => true,
			'message'   => $message,
			'processed' => $processed,
			'errored'   => $errored,
		);
	}

	/**
	 * Process a single message. Returns true on a clean
	 * unsubscribe, false if the message was moved to Errors.
	 *
	 * @since 0.6.0
	 * @param resource|\IMAP\Connection $conn       Open IMAP connection.
	 * @param int                       $message_id IMAP sequence number.
	 * @return bool
	 */
	private function process_message( $conn, int $message_id ): bool {
		$ok      = false;
		$header  = imap_headerinfo( $conn, $message_id );
		$subject = ( is_object( $header ) && isset( $header->subject ) )
			? trim( imap_utf8( (string) $header->subject ) )
			: '';

		$matches = array();
		$matched = ( '' !== $subject ) && ( 1 === preg_match( self::SUBJECT_PATTERN, $subject, $matches ) );

		if ( $matched ) {
			$subscriber_id = ( new Tokens() )->verify( (string) $matches[1] );

			if ( null !== $subscriber_id ) {
				$result = ( new Subscribers_Controller() )->unsubscribe( $subscriber_id );

				if ( true === $result ) {
					$ok = true;
				}
			}
		}

		$destination = $ok ? MAILBOX_FOLDER_PROCESSED : MAILBOX_FOLDER_ERRORS;
		$this->move_message( $conn, $message_id, $destination );

		return $ok;
	}

	/**
	 * Move a message into a sibling folder. The IMAP move is
	 * deferred until `imap_close( ..., CL_EXPUNGE )` actually
	 * applies it.
	 *
	 * @since 0.6.0
	 * @param resource|\IMAP\Connection $conn       Open IMAP connection.
	 * @param int                       $message_id IMAP sequence number.
	 * @param string                    $folder     Plain folder name (e.g. `Processed`).
	 */
	private function move_message( $conn, int $message_id, string $folder ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_mail_move emits warnings on missing folders; we already pre-created them.
		@imap_mail_move( $conn, (string) $message_id, $folder );
	}

	/**
	 * Create a sibling folder if it doesn't already exist.
	 *
	 * @since 0.6.0
	 * @param resource|\IMAP\Connection $conn      Open IMAP connection.
	 * @param string                    $base_path Server portion of the IMAP path (e.g. `{host:993/imap/ssl}`).
	 * @param string                    $folder    Plain folder name (e.g. `Processed`).
	 */
	private function ensure_folder( $conn, string $base_path, string $folder ): void {
		$full = $base_path . $folder;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_list emits warnings against unconfigured mailboxes.
		$existing = @imap_list( $conn, $base_path, $folder );

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_createmailbox emits warnings on existing folders we already ruled out, but defensive against race conditions.
		@imap_createmailbox( $conn, $full );

		// Discard any warnings emitted by the speculative create.
		imap_errors();
	}

	/**
	 * Assemble the `{host:port/proto}folder` connection string
	 * the cron tick reuses.
	 *
	 * @since 0.6.0
	 * @param array{host: string, port: int, folder: string, tls: bool, validate_cert: bool} $config Mailbox config.
	 * @return string
	 */
	private function build_mailbox_string( array $config ): string {
		return $this->build_mailbox_base( $config ) . $config['folder'];
	}

	/**
	 * Server portion only — used as the prefix for sibling
	 * folder paths (`Processed`, `Errors`).
	 *
	 * @since 0.6.0
	 * @param array{host: string, port: int, tls: bool, validate_cert: bool} $config Mailbox config.
	 * @return string
	 */
	private function build_mailbox_base( array $config ): string {
		$protocol = $config['tls'] ? '/imap/ssl' : '/imap';

		if ( $config['tls'] && ! $config['validate_cert'] ) {
			$protocol .= '/novalidate-cert';
		}

		return '{' . $config['host'] . ':' . $config['port'] . $protocol . '}';
	}

	/**
	 * Stamp the successful-poll timestamp and clear any prior
	 * error so the admin notice goes away.
	 *
	 * @since 0.6.0
	 */
	private function record_success(): void {
		update_option( OPTION_MAILBOX_LAST_OK, time(), false );
		update_option( OPTION_MAILBOX_LAST_ERROR, '', false );
		update_option( OPTION_MAILBOX_LAST_ERROR_AT, 0, false );
	}

	/**
	 * Record an IMAP-open failure for the stale-poll notice.
	 *
	 * @since 0.6.0
	 * @param string $reason Last error from `imap_errors()`.
	 */
	private function record_error( string $reason ): void {
		update_option( OPTION_MAILBOX_LAST_ERROR, $reason, false );
		update_option( OPTION_MAILBOX_LAST_ERROR_AT, time(), false );
	}
}
