<?php
/**
 * CSV importer for subscribers.
 *
 * Accepts the MailerLite export format and the spec-defined
 * `email,name,groups,consent_at,consent_source` shape. Column
 * names are matched case-sensitively against a small candidate
 * list per logical field.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Reads a CSV file row by row and update-or-creates subscribers.
 *
 * Group names are resolved to slugs via `sanitize_title()`, which
 * lets us accept either the human-readable name ("Hosting
 * customers") or the slug ("hosting-customers"). Unknown names
 * are reported per-row but do not stop the import.
 *
 * @since 0.1.0
 */
class CSV_Importer {

	/**
	 * Subscriber CRUD.
	 *
	 * @since 0.1.0
	 * @var Subscribers_Controller
	 */
	private Subscribers_Controller $subscribers;

	/**
	 * Group lookup.
	 *
	 * @since 0.1.0
	 * @var Groups_Controller
	 */
	private Groups_Controller $groups;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->subscribers = new Subscribers_Controller();
		$this->groups      = new Groups_Controller();
	}

	/**
	 * Import a CSV file from a local path.
	 *
	 * @since 0.1.0
	 * @param string $path Absolute path to the CSV file (typically a tmp upload).
	 * @return array<string,mixed> Per-row report plus aggregate counts.
	 */
	public function import_from_file( string $path ): array {
		$report = array(
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'rows'     => array(),
		);

		if ( ! is_readable( $path ) ) {
			return $this->fatal( $report, 0, __( 'CSV file is not readable.', 'heads-up-mailer' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local tmp upload, not a remote URL.
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return $this->fatal( $report, 0, __( 'Failed to open the CSV file.', 'heads-up-mailer' ) );
		}

		$headers = fgetcsv( $handle );

		if ( false === $headers || empty( $headers ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.
			fclose( $handle );
			return $this->fatal( $report, 1, __( 'CSV header row is empty.', 'heads-up-mailer' ) );
		}

		$email_idx = $this->find_column( $headers, array( 'Subscriber', 'Email', 'email' ) );

		if ( null === $email_idx ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.
			fclose( $handle );
			return $this->fatal( $report, 1, __( 'No "Subscriber" or "email" column found in CSV.', 'heads-up-mailer' ) );
		}

		$col_indexes = array(
			'email'      => $email_idx,
			'first_name' => $this->find_column( $headers, array( 'Name', 'First name', 'First Name' ) ),
			'last_name'  => $this->find_column( $headers, array( 'Last name', 'Last Name', 'last_name' ) ),
			'name'       => $this->find_column( $headers, array( 'name' ) ),
			'consent_at' => $this->find_column( $headers, array( 'Subscribed', 'consent_at' ) ),
			'groups'     => $this->find_column( $headers, array( 'Groups', 'groups' ) ),
		);

		$groups_lookup = $this->build_groups_lookup();
		$line_num      = 1;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard fgetcsv loop idiom.
		while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
			++$line_num;
			$result                       = $this->import_row( $raw, $col_indexes, $groups_lookup, $line_num );
			$report['rows'][]             = $result;
			$report[ $result['status'] ] += 1;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.
		fclose( $handle );

		return $report;
	}

	/**
	 * Process a single data row.
	 *
	 * @since 0.1.0
	 * @param array<int,string>  $raw           Raw row values from `fgetcsv()`.
	 * @param array<string,?int> $col_indexes   Logical field → column index map.
	 * @param array<string,int>  $groups_lookup Slug → group ID lookup.
	 * @param int                $line          Source line number for reporting.
	 * @return array<string,mixed>   Per-row result entry.
	 */
	private function import_row( array $raw, array $col_indexes, array $groups_lookup, int $line ): array {
		$email = strtolower( trim( $this->column_value( $raw, $col_indexes['email'] ) ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'line'    => $line,
				'email'   => $email,
				'status'  => 'errors',
				'message' => __( 'Invalid or missing email.', 'heads-up-mailer' ),
			);
		}

		// Name: combine first + last when both columns exist, else use a unified "name" column.
		$name = '';

		if ( null !== $col_indexes['first_name'] || null !== $col_indexes['last_name'] ) {
			$first = $this->column_value( $raw, $col_indexes['first_name'] );
			$last  = $this->column_value( $raw, $col_indexes['last_name'] );
			$name  = trim( $first . ' ' . $last );
		} elseif ( null !== $col_indexes['name'] ) {
			$name = $this->column_value( $raw, $col_indexes['name'] );
		}

		$groups_raw = $this->column_value( $raw, $col_indexes['groups'] );
		$resolved   = $this->resolve_groups( $groups_raw, $groups_lookup );

		$consent_at_raw = $this->column_value( $raw, $col_indexes['consent_at'] );
		$consent_at     = $this->format_consent_at( $consent_at_raw );

		$data = array(
			'email'          => $email,
			'name'           => $name,
			'consent_at'     => $consent_at,
			'consent_source' => 'mailerlite-import',
			'groups'         => $resolved['group_ids'],
		);

		$existing = $this->subscribers->get_by_email( $email );

		if ( null !== $existing ) {
			$op_result = $this->subscribers->update( (int) $existing->id, $data );
			$status    = is_wp_error( $op_result ) ? 'errors' : 'updated';
		} else {
			$op_result = $this->subscribers->create( $data );
			$status    = is_wp_error( $op_result ) ? 'errors' : 'inserted';
		}

		$message_parts = array();

		if ( is_wp_error( $op_result ) ) {
			$message_parts[] = $op_result->get_error_message();
		}

		if ( ! empty( $resolved['unknown'] ) ) {
			$message_parts[] = sprintf(
				/* translators: %s: comma-separated list of unknown group names. */
				__( 'Unknown groups ignored: %s.', 'heads-up-mailer' ),
				implode( ', ', $resolved['unknown'] )
			);
		}

		return array(
			'line'    => $line,
			'email'   => $email,
			'status'  => $status,
			'message' => trim( implode( ' ', $message_parts ) ),
		);
	}

	/**
	 * Find the first matching column index for a candidate list.
	 *
	 * @since 0.1.0
	 * @param array<int,string> $headers    The header row.
	 * @param array<int,string> $candidates Candidate header names, in priority order.
	 * @return ?int Index of the first match, or null when none of them matches.
	 */
	private function find_column( array $headers, array $candidates ): ?int {
		$found = null;

		foreach ( $candidates as $candidate ) {
			$idx = array_search( $candidate, $headers, true );

			if ( false !== $idx ) {
				$found = (int) $idx;
				break;
			}
		}

		return $found;
	}

	/**
	 * Read a column value from a row by index, trimmed.
	 *
	 * @since 0.1.0
	 * @param array<int,string> $raw Row values.
	 * @param ?int              $idx Column index or null when the column is absent.
	 * @return string Trimmed value, or empty string when missing.
	 */
	private function column_value( array $raw, ?int $idx ): string {
		$value = '';

		if ( null !== $idx && isset( $raw[ $idx ] ) ) {
			$value = trim( (string) $raw[ $idx ] );
		}

		return $value;
	}

	/**
	 * Parse a raw "Groups" cell and resolve each part to a group ID.
	 *
	 * Splits on both `;` (MailerLite default) and `,` (the spec-defined
	 * format). Each part is passed through `sanitize_title()` so the
	 * importer accepts either a human-readable name or a slug.
	 *
	 * @since 0.1.0
	 * @param string            $raw    Raw cell value.
	 * @param array<string,int> $lookup Slug → group ID lookup.
	 * @return array{group_ids:array<int>,unknown:array<string>}
	 */
	private function resolve_groups( string $raw, array $lookup ): array {
		$result = array(
			'group_ids' => array(),
			'unknown'   => array(),
		);

		if ( '' === $raw ) {
			return $result;
		}

		$parts = array();

		foreach ( explode( ';', $raw ) as $semi_part ) {
			foreach ( explode( ',', $semi_part ) as $comma_part ) {
				$trimmed = trim( $comma_part );

				if ( '' !== $trimmed ) {
					$parts[] = $trimmed;
				}
			}
		}

		foreach ( $parts as $part ) {
			$slug = sanitize_title( $part );

			if ( isset( $lookup[ $slug ] ) ) {
				$result['group_ids'][] = $lookup[ $slug ];
			} else {
				$result['unknown'][] = $part;
			}
		}

		$result['group_ids'] = array_values( array_unique( $result['group_ids'] ) );
		$result['unknown']   = array_values( array_unique( $result['unknown'] ) );

		return $result;
	}

	/**
	 * Coerce a raw timestamp into our stored `Y-m-d H:i:s UTC` shape.
	 *
	 * MailerLite exports `Y-m-d H:i:s` without a timezone marker. We
	 * treat those as UTC and append the suffix. Values that already
	 * end with a 2-5 letter timezone abbreviation are left alone.
	 *
	 * @since 0.1.0
	 * @param string $raw Raw cell value.
	 * @return string Normalised timestamp, or empty string when blank.
	 */
	private function format_consent_at( string $raw ): string {
		$result = $raw;

		if ( '' === $raw ) {
			$result = '';
		} elseif ( ! preg_match( '/[A-Z]{2,5}$/', $raw ) ) {
			$result = $raw . ' UTC';
		}

		return $result;
	}

	/**
	 * Build a slug → group ID lookup from all current groups.
	 *
	 * @since 0.1.0
	 * @return array<string,int>
	 */
	private function build_groups_lookup(): array {
		$lookup = array();
		$rows   = $this->groups->get_all();

		foreach ( $rows as $group ) {
			$lookup[ $group->slug ] = (int) $group->id;
		}

		return $lookup;
	}

	/**
	 * Helper to record a fatal error and return the bail report.
	 *
	 * @since 0.1.0
	 * @param array<string,mixed> $report  In-progress report.
	 * @param int                 $line    Source line (0 if pre-read).
	 * @param string              $message Human-readable error.
	 * @return array<string,mixed>
	 */
	private function fatal( array $report, int $line, string $message ): array {
		$report['rows'][]  = array(
			'line'    => $line,
			'email'   => '',
			'status'  => 'errors',
			'message' => $message,
		);
		$report['errors'] += 1;

		return $report;
	}
}
