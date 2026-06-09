<?php
/**
 * Plugin Name: Heads Up Mailer
 * Plugin URI: https://headgit.net/headwall/heads-up-mailer
 * Description: In-house newsletter sender for headwall-hosting.com. Async send queue, RFC-8058 one-click unsubscribe, IMAP poll for mailto unsubscribes.
 * Version: 1.2.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Paul Faulkner
 * Author URI: https://headwall-hosting.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: heads-up-mailer
 * Domain Path: /languages
 *
 * @package Heads_Up_Mailer
 */

defined( 'ABSPATH' ) || die();

define( 'HUM_VERSION', '1.2.1' );
define( 'HUM_FILE', __FILE__ );
define( 'HUM_PATH', plugin_dir_path( __FILE__ ) );
define( 'HUM_URL', plugin_dir_url( __FILE__ ) );
define( 'HUM_BASENAME', plugin_basename( __FILE__ ) );

require_once HUM_PATH . 'constants.php';
require_once HUM_PATH . 'functions-private.php';

require_once HUM_PATH . 'includes/class-database.php';
require_once HUM_PATH . 'includes/class-groups-controller.php';
require_once HUM_PATH . 'includes/class-subscribers-controller.php';
require_once HUM_PATH . 'includes/class-drafts-controller.php';
require_once HUM_PATH . 'includes/class-tokens.php';
require_once HUM_PATH . 'includes/class-sends-controller.php';
require_once HUM_PATH . 'includes/class-sent-log-controller.php';
require_once HUM_PATH . 'includes/class-worker.php';
require_once HUM_PATH . 'includes/class-csv-importer.php';
require_once HUM_PATH . 'includes/class-crypto.php';
require_once HUM_PATH . 'includes/class-mailbox-poller.php';
require_once HUM_PATH . 'includes/class-settings.php';
require_once HUM_PATH . 'includes/class-rest-controller.php';
require_once HUM_PATH . 'includes/class-public-controller.php';
require_once HUM_PATH . 'includes/class-integration.php';
require_once HUM_PATH . 'includes/class-integrations.php';
require_once HUM_PATH . 'includes/class-github-updater.php';
require_once HUM_PATH . 'includes/class-plugin.php';

// Built-in integrations. Each file always loads; each integration
// runs its own active-check before binding hooks.
require_once HUM_PATH . 'integrations/contact-form-7/class-contact-form-7.php';
require_once HUM_PATH . 'integrations/woocommerce/class-woocommerce.php';

/**
 * Activation hook. Creates tables and installs default options.
 *
 * @since 0.1.0
 */
function hum_activate(): void {
	$database = new Heads_Up_Mailer\Database();
	$database->create_tables();

	$defaults = Heads_Up_Mailer\get_default_settings();

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value, '', 'yes' );
		}
	}

	$groups = new Heads_Up_Mailer\Groups_Controller();
	$groups->seed_defaults();

	hum_ensure_caps();

	add_option( Heads_Up_Mailer\OPTION_VERSION, HUM_VERSION, '', 'yes' );
	add_option( Heads_Up_Mailer\OPTION_DB_VERSION, Heads_Up_Mailer\DB_VERSION, '', 'yes' );

	( new Heads_Up_Mailer\Worker() )->ensure_scheduled();
	( new Heads_Up_Mailer\Mailbox_Poller() )->ensure_scheduled();

	// Register the manage-comms rewrite once before flushing, so the
	// flushed rule table includes it. The `init` hook in
	// Public_Controller hasn't fired during activation.
	( new Heads_Up_Mailer\Public_Controller() )->register_rewrite();
	flush_rewrite_rules( false );
}
register_activation_hook( __FILE__, 'hum_activate' );

/**
 * Grant the plugin's custom capabilities to the roles that need
 * them. Idempotent — `WP_Role::add_cap()` no-ops if the role
 * already has the cap.
 *
 * Granted to Administrator (so existing admin workflows keep
 * working when we move the REST gate off `manage_options`) and to
 * Editor (so a dedicated AI-agent user can operate at Editor level
 * without Administrator role inflation).
 *
 * @since 1.1.0
 */
function hum_ensure_caps(): void {
	$role_slugs = array( 'administrator', 'editor' );

	foreach ( $role_slugs as $role_slug ) {
		$role = get_role( $role_slug );

		if ( null !== $role && ! $role->has_cap( Heads_Up_Mailer\CAP_CREATE_DRAFTS ) ) {
			$role->add_cap( Heads_Up_Mailer\CAP_CREATE_DRAFTS );
		}
	}
}

/**
 * Deactivation hook. Clear the recurring drain event so a removed
 * plugin doesn't leave a ghost cron entry, and flush rewrite rules
 * so our `/manage-comms/` slug stops resolving.
 *
 * @since 0.4.0
 */
function hum_deactivate(): void {
	wp_clear_scheduled_hook( Heads_Up_Mailer\CRON_DRAIN_QUEUE );
	wp_clear_scheduled_hook( Heads_Up_Mailer\CRON_POLL_MAILBOX );
	flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'hum_deactivate' );

/**
 * Bootstrap. Sets the plugin global and runs the main class.
 *
 * @since 0.1.0
 */
function hum_plugin_run(): void {
	global $hum_plugin;

	$hum_plugin = new Heads_Up_Mailer\Plugin();
	$hum_plugin->run();
}
hum_plugin_run();
