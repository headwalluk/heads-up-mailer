<?php
/**
 * Drafts CRUD controller.
 *
 * @package Heads_Up_Mailer
 * @since 0.3.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Owns CRUD on the `hum_drafts` table. Validates subject length,
 * non-empty HTML body, and resolves `suggested_groups` slugs to known
 * group IDs. Stores the resolved slug list as JSON.
 *
 * @since 0.3.0
 */
class Drafts_Controller {

	/**
	 * Fully qualified table name.
	 *
	 * @since 0.3.0
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . TABLE_DRAFTS;
	}

	/**
	 * Get a single draft by ID.
	 *
	 * @since 0.3.0
	 * @param int $id Draft ID.
	 * @return ?object Draft row or null when missing.
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; table from prefix, value placeholder is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row;
	}

	/**
	 * Get all drafts, ordered by created_at descending.
	 *
	 * @since 0.3.0
	 * @return array<int, object>
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read; no user input in query.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decode the suggested_groups_json blob into an array of slugs.
	 *
	 * @since 0.3.0
	 * @param ?object $draft Draft row.
	 * @return array<int, string>
	 */
	public function suggested_groups( ?object $draft ): array {
		$result = array();

		if ( null !== $draft && '' !== (string) $draft->suggested_groups_json ) {
			$decoded = json_decode( (string) $draft->suggested_groups_json, true );

			if ( is_array( $decoded ) ) {
				$result = array_values( array_filter( array_map( 'strval', $decoded ) ) );
			}
		}

		return $result;
	}

	/**
	 * Create a draft.
	 *
	 * @since 0.3.0
	 * @param array<string,mixed> $data Draft fields (`subject`, `html_body`, `suggested_groups`).
	 * @return int|\WP_Error Inserted ID on success, `WP_Error` on failure.
	 */
	public function create( array $data ): int|\WP_Error {
		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$row               = $validated;
		$row['status']     = DRAFT_STATUS_DRAFT;
		$row['created_by'] = (int) get_current_user_id();
		$row['created_at'] = now_utc();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$inserted = $wpdb->insert(
			$this->table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$result = ( false === $inserted )
			? new \WP_Error( 'hum_draft_insert_failed', __( 'Failed to insert draft.', 'heads-up-mailer' ) )
			: (int) $wpdb->insert_id;

		return $result;
	}

	/**
	 * Update a draft.
	 *
	 * Status transitions to `sent` / `cancelled` happen elsewhere (M5
	 * queue handler). This method only edits the editable fields.
	 *
	 * @since 0.3.0
	 * @param int                 $id   Draft ID.
	 * @param array<string,mixed> $data Draft fields to update.
	 * @return true|\WP_Error `true` on success, `WP_Error` on failure.
	 */
	public function update( int $id, array $data ): true|\WP_Error {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'hum_draft_not_found',
				__( 'Draft not found.', 'heads-up-mailer' )
			);
		}

		if ( DRAFT_STATUS_SENDING === (string) $existing->status ) {
			return new \WP_Error(
				'hum_draft_locked_while_sending',
				__( 'Cannot edit a draft while it is sending.', 'heads-up-mailer' )
			);
		}

		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$updated = $wpdb->update(
			$this->table(),
			$validated,
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$result = ( false === $updated )
			? new \WP_Error( 'hum_draft_update_failed', __( 'Failed to update draft.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Delete a draft.
	 *
	 * @since 0.3.0
	 * @param int $id Draft ID.
	 * @return true|\WP_Error
	 */
	public function delete( int $id ): true|\WP_Error {
		if ( null === $this->get( $id ) ) {
			return new \WP_Error(
				'hum_draft_not_found',
				__( 'Draft not found.', 'heads-up-mailer' )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write.
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		$result = ( false === $deleted )
			? new \WP_Error( 'hum_draft_delete_failed', __( 'Failed to delete draft.', 'heads-up-mailer' ) )
			: true;

		return $result;
	}

	/**
	 * Validate and sanitise incoming draft data.
	 *
	 * Resolves `suggested_groups` (array of slugs) against the live
	 * groups table. Unknown slugs become a `WP_Error` carrying the
	 * offending list so callers can show a useful message.
	 *
	 * @since 0.3.0
	 * @param array<string,mixed> $data Raw input.
	 * @return array<string,string>|\WP_Error
	 */
	private function validate( array $data ): array|\WP_Error {
		$subject = isset( $data['subject'] ) ? sanitize_text_field( wp_unslash( $data['subject'] ) ) : '';
		// Email HTML is a full document (<html>, <head>, <style>, conditional
		// comments). wp_kses_post strips those and breaks MJML output. Stored
		// raw — the sender is authenticated via the `hum_create_drafts` cap
		// (Administrator/Editor only), the preview iframe is sandboxed against
		// script execution, and the email client is the final renderer at
		// send time.
		$html_body = isset( $data['html_body'] ) ? (string) wp_unslash( $data['html_body'] ) : '';
		$raw_slugs = isset( $data['suggested_groups'] ) && is_array( $data['suggested_groups'] )
			? array_map( 'sanitize_title', wp_unslash( $data['suggested_groups'] ) )
			: array();

		if ( '' === $subject ) {
			return new \WP_Error(
				'hum_draft_invalid_subject',
				__( 'Subject is required.', 'heads-up-mailer' )
			);
		}

		if ( mb_strlen( $subject ) > DEF_DRAFT_SUBJECT_MAX ) {
			return new \WP_Error(
				'hum_draft_subject_too_long',
				/* translators: %d: maximum subject length. */
				sprintf( __( 'Subject must be %d characters or fewer.', 'heads-up-mailer' ), DEF_DRAFT_SUBJECT_MAX )
			);
		}

		if ( '' === trim( wp_strip_all_tags( $html_body ) ) ) {
			return new \WP_Error(
				'hum_draft_invalid_html_body',
				__( 'HTML body cannot be empty.', 'heads-up-mailer' )
			);
		}

		$resolved_slugs = array();
		$unknown_slugs  = array();

		if ( ! empty( $raw_slugs ) ) {
			$groups_controller = new Groups_Controller();

			foreach ( $raw_slugs as $slug ) {
				if ( '' === $slug ) {
					continue;
				}

				if ( null === $groups_controller->get_by_slug( $slug ) ) {
					$unknown_slugs[] = $slug;
				} else {
					$resolved_slugs[] = $slug;
				}
			}
		}

		if ( ! empty( $unknown_slugs ) ) {
			return new \WP_Error(
				'hum_draft_unknown_groups',
				/* translators: %s: comma-separated list of unknown slugs. */
				sprintf( __( 'Unknown group slugs: %s.', 'heads-up-mailer' ), implode( ', ', $unknown_slugs ) ),
				array( 'unknown' => $unknown_slugs )
			);
		}

		return array(
			'subject'               => $subject,
			'html_body'             => $html_body,
			'suggested_groups_json' => (string) wp_json_encode( array_values( array_unique( $resolved_slugs ) ) ),
		);
	}
}
