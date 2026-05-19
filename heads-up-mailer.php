<?php
/**
 * Plugin Name: Heads Up Mailer
 * Plugin URI: https://headgit.net/headwall/heads-up-mailer
 * Description: In-house newsletter sender for headwall-hosting.com. Async send queue, RFC-8058 one-click unsubscribe, IMAP poll for mailto unsubscribes.
 * Version: 0.1.0
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

define( 'HUM_VERSION', '0.1.0' );
define( 'HUM_FILE', __FILE__ );
define( 'HUM_PATH', plugin_dir_path( __FILE__ ) );
define( 'HUM_URL', plugin_dir_url( __FILE__ ) );
define( 'HUM_BASENAME', plugin_basename( __FILE__ ) );

require_once HUM_PATH . 'constants.php';
require_once HUM_PATH . 'functions-private.php';

require_once HUM_PATH . 'includes/class-database.php';
require_once HUM_PATH . 'includes/class-plugin.php';

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

	add_option( Heads_Up_Mailer\OPTION_VERSION, HUM_VERSION, '', 'yes' );
	add_option( Heads_Up_Mailer\OPTION_DB_VERSION, Heads_Up_Mailer\DB_VERSION, '', 'yes' );
}
register_activation_hook( __FILE__, 'hum_activate' );

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
