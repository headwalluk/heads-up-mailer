<?php
/**
 * Database schema and migrations.
 *
 * @package Heads_Up_Mailer
 * @since 0.1.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Database class. Owns custom-table schema and the migration runner.
 *
 * All datetime columns are `VARCHAR(30)` holding `Y-m-d H:i:s UTC`
 * strings (always UTC, sortable lexically, human-readable in SQL).
 *
 * @since 0.1.0
 */
class Database {

	/**
	 * Create or update all plugin tables via `dbDelta`.
	 *
	 * Idempotent — safe to call on activation, first-run, and version
	 * bumps.
	 *
	 * @since 0.1.0
	 */
	public function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate         = $wpdb->get_charset_collate();
		$table_subscribers       = $wpdb->prefix . TABLE_SUBSCRIBERS;
		$table_groups            = $wpdb->prefix . TABLE_GROUPS;
		$table_subscriber_groups = $wpdb->prefix . TABLE_SUBSCRIBER_GROUPS;
		$table_drafts            = $wpdb->prefix . TABLE_DRAFTS;
		$table_sends             = $wpdb->prefix . TABLE_SENDS;
		$table_send_recipients   = $wpdb->prefix . TABLE_SEND_RECIPIENTS;

		$statements = array(
			"CREATE TABLE {$table_subscribers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(254) NOT NULL,
				name varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'subscribed',
				consent_source varchar(100) NOT NULL DEFAULT '',
				consent_at varchar(30) NOT NULL DEFAULT '',
				unsubscribed_at varchar(30) NOT NULL DEFAULT '',
				token_salt char(64) NOT NULL DEFAULT '',
				created_at varchar(30) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status)
			) {$charset_collate}",

			"CREATE TABLE {$table_groups} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(100) NOT NULL,
				name varchar(255) NOT NULL,
				description text NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset_collate}",

			"CREATE TABLE {$table_subscriber_groups} (
				subscriber_id bigint(20) unsigned NOT NULL,
				group_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (subscriber_id, group_id),
				KEY group_id (group_id)
			) {$charset_collate}",

			"CREATE TABLE {$table_drafts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subject varchar(255) NOT NULL DEFAULT '',
				html_body longtext NOT NULL,
				suggested_groups_json text NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at varchar(30) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'draft',
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset_collate}",

			"CREATE TABLE {$table_sends} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				draft_id bigint(20) unsigned NOT NULL,
				group_ids_json text NOT NULL,
				started_at varchar(30) NOT NULL DEFAULT '',
				finished_at varchar(30) NOT NULL DEFAULT '',
				attempted int(11) unsigned NOT NULL DEFAULT 0,
				sent int(11) unsigned NOT NULL DEFAULT 0,
				failed int(11) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY draft_id (draft_id)
			) {$charset_collate}",

			"CREATE TABLE {$table_send_recipients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				send_id bigint(20) unsigned NOT NULL,
				subscriber_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts int(11) unsigned NOT NULL DEFAULT 0,
				last_error text NOT NULL,
				sent_at varchar(30) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY send_subscriber (send_id, subscriber_id),
				KEY status (status)
			) {$charset_collate}",
		);

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
	}
}
