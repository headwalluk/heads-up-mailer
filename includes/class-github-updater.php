<?php
/**
 * GitHub Updater.
 *
 * Hooks into the WordPress plugin update system to check the
 * configured GitHub repository for new releases and serve them
 * as standard plugin updates. Release zips are built by
 * `.github/workflows/release.yml`.
 *
 * @package Heads_Up_Mailer
 * @since 0.10.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Checks GitHub Releases for plugin updates and hooks into the
 * WordPress plugin update system.
 *
 * @since 0.10.0
 */
class Github_Updater {

	/**
	 * Plugin basename (e.g. `heads-up-mailer/heads-up-mailer.php`).
	 *
	 * @since 0.10.0
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (directory name).
	 *
	 * @since 0.10.0
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Capture the plugin basename and slug.
	 *
	 * @since 0.10.0
	 */
	public function __construct() {
		$this->plugin_basename = HUM_BASENAME;
		$this->plugin_slug     = dirname( $this->plugin_basename );
	}

	/**
	 * Register hooks. Called from `Plugin::run()` in admin and cron contexts only.
	 *
	 * @since 0.10.0
	 */
	public function run(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Check whether GitHub auto-updates are enabled.
	 *
	 * @since 0.10.0
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		/**
		 * Filter whether GitHub auto-updates are enabled for
		 * Heads Up Mailer.
		 *
		 * Return false to disable update checks. Useful for
		 * staging environments, local development, or
		 * temporarily pinning the plugin to its current version.
		 *
		 * @since 0.10.0
		 *
		 * @param bool $enabled Whether auto-updates are enabled. Default true.
		 */
		return (bool) filter_var( apply_filters( 'hum_updater_enabled', true ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @since 0.10.0
	 *
	 * @param mixed $transient The update_plugins transient — an object once core
	 *                         has built it, but false or empty on early passes.
	 * @return mixed The transient, unchanged unless an update was injected.
	 */
	public function check_for_update( mixed $transient ): mixed {
		$checked = is_object( $transient ) && property_exists( $transient, 'checked' ) ? $transient->checked : false;

		if ( empty( $checked ) ) {
			// no action: WordPress hasn't populated the checked list yet.
			$this->log( 'check_for_update: transient has no checked list, skipping.' );
		} elseif ( ! $this->is_enabled() ) {
			$this->log( 'check_for_update: updates disabled via filter, skipping.' );
		} else {
			$installed_version = $this->get_installed_version( $checked );
			$release           = $this->get_latest_release();

			if ( ! is_array( $release ) ) {
				$this->log( 'check_for_update: no release data returned from GitHub.' );
			} elseif ( version_compare( $installed_version, $release['version'], '>=' ) ) {
				$this->log( 'check_for_update: current version ' . $installed_version . ' is up to date (latest: ' . $release['version'] . ').' );
			} else {
				$this->log( 'check_for_update: update available ' . $installed_version . ' → ' . $release['version'] . '.' );
				$transient->response[ $this->plugin_basename ] = (object) array(
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $release['version'],
					'url'         => $release['html_url'],
					'package'     => $release['zip_url'],
				);
			}
		}

		return $transient;
	}

	/**
	 * Resolve the installed version from the plugin header, as recorded in the update transient.
	 *
	 * Reads the header rather than HUM_VERSION because the header is what
	 * WordPress compares against `new_version` when deciding to show a notice.
	 *
	 * @since 1.8.0
	 *
	 * @param array $checked The transient's checked list, keyed by plugin basename.
	 * @return string Version string WordPress considers installed.
	 */
	private function get_installed_version( array $checked ): string {
		$installed_version = $checked[ $this->plugin_basename ] ?? '';

		if ( '' === $installed_version ) {
			$installed_version = HUM_VERSION;
			$this->log( 'get_installed_version: ' . $this->plugin_basename . ' absent from the checked list, falling back to HUM_VERSION ' . $installed_version . '.' );
		} elseif ( HUM_VERSION !== $installed_version ) {
			$this->log_error( 'get_installed_version: version drift — the plugin header reports ' . $installed_version . ' but HUM_VERSION is ' . HUM_VERSION . '. Both are set in heads-up-mailer.php and must be bumped together.' );
		} else {
			// no action: header and constant agree.
		}

		return $installed_version;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 *
	 * @since 0.10.0
	 *
	 * @param mixed $result The result object or array. Default false.
	 * @param mixed $action The API action being performed.
	 * @param mixed $args   Plugin API arguments.
	 * @return mixed Our plugin information, or $result unchanged for any other request.
	 */
	public function plugin_info( mixed $result, mixed $action, mixed $args ): mixed {
		$requested_slug = is_object( $args ) ? ( $args->slug ?? '' ) : '';

		if ( 'plugin_information' !== $action || $requested_slug !== $this->plugin_slug || ! $this->is_enabled() ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( is_array( $release ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_data = get_plugin_data( HUM_FILE, false, true );

			$result                = new \stdClass();
			$result->name          = $plugin_data['Name'] ?? $this->plugin_slug;
			$result->slug          = $this->plugin_slug;
			$result->version       = $release['version'];
			$result->author        = $plugin_data['AuthorName'] ?? '';
			$result->homepage      = $plugin_data['PluginURI'] ?? $release['html_url'];
			$result->requires      = $plugin_data['RequiresWP'] ?? '';
			$result->requires_php  = $plugin_data['RequiresPHP'] ?? '';
			$result->downloaded    = 0;
			$result->last_updated  = $release['published_at'] ?? '';
			$result->download_link = $release['zip_url'];

			if ( ! empty( $release['body'] ) ) {
				$result->sections = array(
					'description' => $plugin_data['Description'] ?? '',
					'changelog'   => wp_kses_post( wpautop( $release['body'] ) ),
				);
			}
		}

		return $result;
	}

	/**
	 * Clear the cached release data after a plugin update completes.
	 *
	 * @since 0.10.0
	 *
	 * @param mixed $upgrader The upgrader instance.
	 * @param mixed $options  Update details.
	 */
	public function clear_cache( mixed $upgrader, mixed $options ): void {
		if (
			is_array( $options ) &&
			'update' === ( $options['action'] ?? '' ) &&
			'plugin' === ( $options['type'] ?? '' ) &&
			! empty( $options['plugins'] ) &&
			in_array( $this->plugin_basename, $options['plugins'], true )
		) {
			delete_transient( UPDATER_CACHE_KEY );
			delete_transient( UPDATER_FAILURE_CACHE_KEY );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release from GitHub, caching successes and failures separately.
	 *
	 * @since 0.10.0
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$release = null;
		$cached  = get_transient( UPDATER_CACHE_KEY );

		if ( is_array( $cached ) ) {
			$this->log( 'get_latest_release: using cached release data.' );
			$release = $cached;
		} elseif ( false !== get_transient( UPDATER_FAILURE_CACHE_KEY ) ) {
			$this->log( 'get_latest_release: backing off, a recent lookup failed.' );
		} else {
			$body = $this->request_latest_release();

			if ( is_array( $body ) ) {
				$release = $this->build_release( $body );
			}

			if ( null === $release ) {
				set_transient( UPDATER_FAILURE_CACHE_KEY, time(), UPDATER_FAILURE_CACHE_TTL );
			} else {
				set_transient( UPDATER_CACHE_KEY, $release, UPDATER_CACHE_TTL );
				delete_transient( UPDATER_FAILURE_CACHE_KEY );
			}
		}

		return $release;
	}

	/**
	 * Request the latest release from the GitHub API, logging every failure path.
	 *
	 * @since 1.8.0
	 *
	 * @return array|null Decoded response body, or null if the request failed.
	 */
	private function request_latest_release(): ?array {
		$body = null;
		$url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', UPDATER_GITHUB_REPO );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => UPDATER_REQUEST_TIMEOUT,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'request_latest_release: HTTP request to ' . $url . ' failed — ' . $response->get_error_message() );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->log_error( 'request_latest_release: GitHub returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for ' . $url . '.' );
		} else {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $decoded ) || empty( $decoded['tag_name'] ) ) {
				$this->log_error( 'request_latest_release: response JSON from ' . $url . ' missing tag_name.' );
			} else {
				$body = $decoded;
			}
		}

		return $body;
	}

	/**
	 * Build the cached release array from a GitHub API response body.
	 *
	 * @since 1.8.0
	 *
	 * @param array $body Decoded GitHub release API response.
	 * @return array|null Release data, or null if it carries no usable ZIP asset.
	 */
	private function build_release( array $body ): ?array {
		$release = null;
		$zip_url = $this->find_zip_asset( $body );

		if ( '' === $zip_url ) {
			$this->log_error( 'build_release: no matching .zip asset for tag ' . $body['tag_name'] . '.' );
		} else {
			$this->log( 'build_release: found release ' . $body['tag_name'] . '.' );

			$release = array(
				'version'      => ltrim( (string) $body['tag_name'], 'v' ),
				'zip_url'      => $zip_url,
				'html_url'     => $body['html_url'] ?? '',
				'body'         => $body['body'] ?? '',
				'published_at' => $body['published_at'] ?? '',
			);
		}

		return $release;
	}

	/**
	 * Find the plugin ZIP asset, preferring `{slug}.zip` over `{slug}-{version}.zip`.
	 *
	 * @since 0.10.0
	 *
	 * @param array $release_data Decoded GitHub release API response.
	 * @return string Download URL, or empty string if no suitable asset found.
	 */
	private function find_zip_asset( array $release_data ): string {
		$zip_url = '';

		if ( ! empty( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			$stable_name = $this->plugin_slug . '.zip';

			// Requires a digit after the hyphen so an unrelated asset such as
			// `{slug}-docs.zip` is never offered to WordPress as the plugin.
			$versioned_pattern = '/^' . preg_quote( $this->plugin_slug, '/' ) . '-[0-9][0-9a-z.\-]*\.zip$/i';

			foreach ( $release_data['assets'] as $asset ) {
				$name = $asset['name'] ?? '';

				if ( $stable_name === $name ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}

				// Keep looping: the stable name may appear after a versioned match.
				if ( '' === $zip_url && 1 === preg_match( $versioned_pattern, $name ) ) {
					$zip_url = $asset['browser_download_url'] ?? '';
				}
			}
		}

		return $zip_url;
	}

	/**
	 * Log a debug message to the PHP error log when WP_DEBUG is on.
	 *
	 * @since 0.10.0
	 *
	 * @param string $message The message to log.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
			error_log( 'Heads Up Mailer Github_Updater: ' . $message );
		}
	}

	/**
	 * Log an updater failure to the PHP error log unconditionally.
	 *
	 * @since 0.10.0
	 *
	 * @param string $message The message to log.
	 */
	private function log_error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for updater failures.
		error_log( 'Heads Up Mailer Github_Updater [error]: ' . $message );
	}
}
