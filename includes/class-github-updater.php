<?php
/**
 * GitHub Updater.
 *
 * Hooks into the WordPress plugin update system to check the
 * configured GitHub repository for new releases and serve them
 * as standard plugin updates.
 *
 * Pattern lifted from quick-2fa; the GitHub Actions workflow at
 * `.github/workflows/release.yml` builds the release zips that
 * this class fetches.
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
 * Three hooks:
 *   - `pre_set_site_transient_update_plugins`: inject our
 *     plugin's available-update row when the GitHub release
 *     is newer than `HUM_VERSION`.
 *   - `plugins_api`: serve the "View details" modal contents.
 *   - `upgrader_process_complete`: clear our cache once an
 *     update finishes, so the next WP check picks up the new
 *     baseline.
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
	 * Constructor — capture the plugin basename / slug once so
	 * the hook callbacks don't re-derive them on every call.
	 *
	 * @since 0.10.0
	 */
	public function __construct() {
		$this->plugin_basename = HUM_BASENAME;
		$this->plugin_slug     = dirname( $this->plugin_basename );
	}

	/**
	 * Register hooks.
	 *
	 * Called from `Plugin::run()` only in admin / cron contexts —
	 * front-end requests never need the update transient and
	 * skipping init there avoids an unnecessary class load on
	 * every page.
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
	 * Site owners can disable via the `hum_updater_enabled`
	 * filter — handy for staging environments or pinning to the
	 * current version during a known-bad release window.
	 *
	 * @since 0.10.0
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
		 * @param bool $enabled Whether auto-updates are enabled. Default true.
		 */
		return (bool) apply_filters( 'hum_updater_enabled', true );
	}

	/**
	 * Check GitHub for a newer release and inject into the
	 * update_plugins transient.
	 *
	 * @since 0.10.0
	 * @param object $transient The update_plugins transient object.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		$checked = is_object( $transient ) && property_exists( $transient, 'checked' ) ? $transient->checked : false;

		if ( empty( $checked ) ) {
			// no action: WordPress hasn't populated the checked
			// list yet; bail and pick this up on the next pass.
			$this->log( 'check_for_update: transient has no checked list, skipping.' );
		} elseif ( ! $this->is_enabled() ) {
			$this->log( 'check_for_update: updates disabled via filter, skipping.' );
		} else {
			$release = $this->get_latest_release();

			if ( ! is_array( $release ) ) {
				$this->log( 'check_for_update: no release data returned from GitHub.' );
			} elseif ( version_compare( HUM_VERSION, $release['version'], '>=' ) ) {
				$this->log( 'check_for_update: current version ' . HUM_VERSION . ' is up to date (latest: ' . $release['version'] . ').' );
			} else {
				$this->log( 'check_for_update: update available ' . HUM_VERSION . ' → ' . $release['version'] . '.' );
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
	 * Provide plugin information for the "View details" modal.
	 *
	 * @since 0.10.0
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		$slug = isset( $args->slug ) ? (string) $args->slug : '';

		if ( 'plugin_information' !== $action || $slug !== $this->plugin_slug || ! $this->is_enabled() ) {
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
	 * Clear the cached release data after a plugin update
	 * completes.
	 *
	 * @since 0.10.0
	 * @param \WP_Upgrader $upgrader The upgrader instance.
	 * @param array        $options  Update details.
	 */
	public function clear_cache( $upgrader, $options ): void {
		if (
			'update' === ( $options['action'] ?? '' ) &&
			'plugin' === ( $options['type'] ?? '' ) &&
			! empty( $options['plugins'] ) &&
			in_array( $this->plugin_basename, $options['plugins'], true )
		) {
			delete_transient( UPDATER_CACHE_KEY );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release from GitHub, with transient
	 * caching.
	 *
	 * @since 0.10.0
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$release = null;

		$cached = get_transient( UPDATER_CACHE_KEY );

		if ( is_array( $cached ) ) {
			$this->log( 'get_latest_release: using cached release data.' );
			$release = $cached;
		} else {
			$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', UPDATER_GITHUB_REPO );
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/vnd.github.v3+json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log_error( 'get_latest_release: HTTP request to ' . $url . ' failed — ' . $response->get_error_message() );
			} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$this->log_error( 'get_latest_release: GitHub returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for ' . $url . '.' );
			} else {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
					$this->log_error( 'get_latest_release: response JSON from ' . $url . ' missing tag_name.' );
				} else {
					$zip_url = $this->find_zip_asset( $body );

					if ( '' === $zip_url ) {
						$this->log_error( 'get_latest_release: no matching .zip asset for tag ' . $body['tag_name'] . '.' );
					} else {
						$this->log( 'get_latest_release: found release ' . $body['tag_name'] . '.' );

						$release = array(
							'version'      => ltrim( $body['tag_name'], 'v' ),
							'zip_url'      => $zip_url,
							'html_url'     => $body['html_url'] ?? '',
							'body'         => $body['body'] ?? '',
							'published_at' => $body['published_at'] ?? '',
						);

						set_transient( UPDATER_CACHE_KEY, $release, UPDATER_CACHE_TTL );
					}
				}
			}
		}

		return $release;
	}

	/**
	 * Find the plugin ZIP asset from a GitHub release.
	 *
	 * Prefers `{slug}.zip` (the generic filename emitted by the
	 * release workflow) so the in-plugin updater always grabs a
	 * stable URL. Falls back to any `.zip` whose name starts
	 * with the plugin slug — covers the versioned alias
	 * (`{slug}-{version}.zip`) the workflow uploads alongside.
	 *
	 * @since 0.10.0
	 * @param array $release_data Decoded GitHub release API response.
	 * @return string Download URL, or empty string if no suitable asset found.
	 */
	private function find_zip_asset( array $release_data ): string {
		$zip_url = '';

		if ( ! empty( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			$stable_name = $this->plugin_slug . '.zip';

			foreach ( $release_data['assets'] as $asset ) {
				$name = $asset['name'] ?? '';

				if ( $stable_name === $name ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}

				// Accept any zip starting with the plugin slug
				// as a fallback. Don't break — we'd rather find
				// the stable name later in the loop if it shows
				// up after a versioned alias.
				if ( '' === $zip_url && str_starts_with( $name, $this->plugin_slug ) && str_ends_with( $name, '.zip' ) ) {
					$zip_url = $asset['browser_download_url'] ?? '';
				}
			}
		}

		return $zip_url;
	}

	/**
	 * Log a debug message to the PHP error log when WP_DEBUG is
	 * on.
	 *
	 * For routine flow tracing — cache hits, version
	 * comparisons, "up to date" results. Use `log_error()` for
	 * actual failures that warrant investigation.
	 *
	 * @since 0.10.0
	 * @param string $message The message to log.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
			error_log( 'Heads Up Mailer Github_Updater: ' . $message );
		}
	}

	/**
	 * Log an error message to the PHP error log unconditionally.
	 *
	 * Used for genuine failure conditions (HTTP errors,
	 * malformed responses, missing release assets) that should
	 * always be visible to a sysadmin diagnosing why updates
	 * aren't flowing — without requiring `WP_DEBUG`.
	 *
	 * @since 0.10.0
	 * @param string $message The message to log.
	 */
	private function log_error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for updater failures.
		error_log( 'Heads Up Mailer Github_Updater [error]: ' . $message );
	}
}
