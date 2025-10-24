<?php
/**
 * Updater class for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updater class
 */
class Updater {

	/**
	 * Config instance
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * GitHub API instance
	 *
	 * @var GitHubAPI|null
	 */
	private $github_api;

	/**
	 * Current plugin version
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Constructor
	 *
	 * @param Config    $config Configuration instance
	 * @param GitHubAPI $github_api GitHub API instance
	 */
	public function __construct( $config, $github_api ) {
		$this->config          = $config;
		$this->github_api      = $github_api;
		$this->current_version = $config->getPluginVersion();

		// Hook into WordPress update transient to inject update information
		add_filter( 'site_transient_update_plugins', array( $this, 'injectUpdateInfo' ), 10, 1 );

		// Hook into WordPress update transient to inject auth when needed
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'enableAuthForUpdate' ), 10, 1 );

		// Hook into upgrader pre-download to ensure auth is active
		add_filter( 'upgrader_pre_download', array( $this, 'enableAuthForDownload' ), 10, 3 );

		// Clear update cache after successful plugin upgrade
		add_action( 'upgrader_process_complete', array( $this, 'clearCacheAfterUpdate' ), 10, 2 );
	}

	/**
	 * Check for updates
	 *
	 * @return array Update check result
	 */
	public function checkForUpdates() {
		$result = array(
			'success'          => false,
			'current_version'  => $this->current_version,
			'latest_version'   => '',
			'update_available' => false,
			'message'          => '',
			'release_data'     => null,
		);

		try {
			// Get latest release from GitHub
			$release_data = $this->github_api->getLatestRelease();

			if ( is_wp_error( $release_data ) ) {
				$result['message'] = $release_data->get_error_message();
				$this->logAction( 'Check', 'Failure', $result['message'] );
				return $result;
			}

			// Check if this is a pre-release
			$include_prereleases = $this->config->getOption( 'include_prereleases', false );
			$is_prerelease       = $this->isPreRelease( $release_data['tag_name'] );

			if ( $is_prerelease && ! $include_prereleases ) {
				$result['message'] = sprintf(
					'Latest release (%s) is a pre-release. Enable "Include Pre-releases" to use it.',
					$release_data['tag_name']
				);
				$this->logAction( 'Check', 'Success', $result['message'] );
				return $result;
			}

			$latest_version = $this->extractVersionFromTag( $release_data['tag_name'] );

			if ( empty( $latest_version ) ) {
				$result['message'] = 'Could not extract version from release tag: ' . $release_data['tag_name'];
				$this->logAction( 'Check', 'Failure', $result['message'] );
				return $result;
			}

			$result['latest_version']   = $latest_version;
			$result['release_data']     = $release_data;
			$result['update_available'] = $this->isUpdateAvailable( $this->current_version, $latest_version );
			$result['success']          = true;

			if ( $result['update_available'] ) {
				$result['message'] = sprintf(
					'Update available: %s → %s',
					$this->current_version,
					$latest_version
				);
			} else {
				$result['message'] = 'You have the latest version installed.';
			}

			// Update WordPress options with release snapshot
			$this->config->updateOption( 'latest_version', $latest_version );
			$this->config->updateOption( 'update_available', $result['update_available'] );
			$this->config->updateOption( 'last_checked', time() );
			// Store release data snapshot to prevent race conditions
			$release_snapshot = array(
				'version'      => $latest_version,
				'tag_name'     => $release_data['tag_name'],
				'published_at' => $release_data['published_at'] ?? '',
				'assets'       => $release_data['assets'] ?? array(),
				'html_url'     => $release_data['html_url'] ?? '',
			);
			$this->config->updateOption( 'release_snapshot', $release_snapshot );
			$this->logAction( 'Check', 'Success', $result['message'] );

		} catch ( \Exception $e ) {
			$result['message'] = 'Error checking for updates: ' . $e->getMessage();
			$this->logAction( 'Check', 'Failure', $result['message'] );
		}

		return $result;
	}

	/**
	 * Inject update information into WordPress update transient
	 * This allows WordPress to see the update and enable the "Update now" button
	 *
	 * @param object|false $transient Update plugins transient
	 * @return object|false Modified transient
	 */
	public function injectUpdateInfo( $transient ) {
		// If transient is not an object, return as-is
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Check if we have update information stored
		$latest_version   = $this->config->getOption( 'latest_version', '' );
		$update_available = $this->config->getOption( 'update_available', false );
		$release_snapshot = $this->config->getOption( 'release_snapshot', array() );

		// Only inject if we have a valid update available
		if ( ! $update_available || empty( $latest_version ) || empty( $release_snapshot ) ) {
			return $transient;
		}

		// Verify the version is actually newer
		if ( ! $this->isUpdateAvailable( $this->current_version, $latest_version ) ) {
			return $transient;
		}

		// Get the download URL from the release snapshot
		$package_url = $this->findDownloadAsset( $release_snapshot );
		if ( empty( $package_url ) ) {
			return $transient;
		}

		// Ensure response array exists
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		// Inject update information
		$plugin_basename = $this->config->getPluginBasename();
		$repo_url        = $this->config->getOption( 'repository_url', 'https://github.com' );

		$transient->response[ $plugin_basename ] = (object) array(
			'slug'        => $this->config->getPluginSlug(),
			'plugin'      => $plugin_basename,
			'new_version' => $latest_version,
			'package'     => $package_url,
			'url'         => $repo_url,
		);

		return $transient;
	}

	/**
	 * Perform plugin update
	 *
	 * @return array Update result
	 */
	public function performUpdate() {
		$result = array(
			'success'      => false,
			'message'      => '',
			'redirect_url' => '',
		);

		try {
			// Check for concurrent update attempts (locking mechanism)
			$lock_key = 'update_in_progress_' . md5( $this->config->getPluginBasename() );
			if ( get_transient( $lock_key ) ) {
				$result['message'] = 'Update already in progress. Please wait for the current update to complete.';
				return $result;
			}

			// Set lock (1 minute expiration)
			set_transient( $lock_key, time(), 60 );

			// Check if update is available
			$latest_version   = $this->config->getOption( 'latest_version', '' );
			$update_available = $this->config->getOption( 'update_available', false );
			$release_snapshot = $this->config->getOption( 'release_snapshot', array() );

			if ( ! $update_available || empty( $latest_version ) ) {
				delete_transient( $lock_key );
				$result['message'] = 'No update available. Please check for updates first.';
				return $result;
			}

			// Verify we have a valid release snapshot
			if ( empty( $release_snapshot ) || empty( $release_snapshot['version'] ) ) {
				delete_transient( $lock_key );
				$result['message'] = 'Invalid release data. Please check for updates again.';
				return $result;
			}

			// Use snapshot data directly - no need to fetch fresh data
			// The snapshot was already validated during check
			$release_data = $release_snapshot;

			// Resolve package URL from GitHub assets
			$package_url = $this->findDownloadAsset( $release_data );           if ( empty( $package_url ) ) {
				delete_transient( $lock_key );
				$result['message'] = 'No suitable download asset found in the release.';
				$this->logAction( 'Download', 'Failure', $result['message'] );
				return $result;
			}

			// No need to manually register update - it's now injected via the injectUpdateInfo filter
			// This ensures the update is always available when WordPress checks the transient

			// Build the WordPress native update URL with fresh nonce
			$plugin_basename = $this->config->getPluginBasename();
			$update_url      = add_query_arg(
				array(
					'action'   => 'upgrade-plugin',
					'plugin'   => $plugin_basename,
					'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . $plugin_basename ),
				),
				self_admin_url( 'update.php' )
			);

			// Lock will be released after update completes via clearCacheAfterUpdate hook
			// or automatically expires after 1 minute

			$result['success']      = true;
			$result['redirect_url'] = $update_url;
			$result['message']      = 'Redirecting to WordPress update screen...';
			$this->logAction( 'Update', 'Initiated', 'Redirecting to WordPress update screen for version ' . $latest_version );

		} catch ( \Exception $e ) {
			// Release lock on error
			$lock_key = 'update_in_progress_' . md5( $this->config->getPluginBasename() );
			delete_transient( $lock_key );

			$result['message'] = 'Update failed: ' . $e->getMessage();
			$this->logAction( 'Update', 'Failure', $result['message'] );
		}

		return $result;
	}

	/**
	 * Enable auth filter when update transient is being set.
	 * This ensures authentication is available during the entire update process.
	 *
	 * @param mixed $transient Update plugins transient
	 * @return mixed Unmodified transient
	 */
	public function enableAuthForUpdate( $transient ) {
		// Check if our plugin has an update available
		$plugin_basename = $this->config->getPluginBasename();

		if ( is_object( $transient ) && isset( $transient->response[ $plugin_basename ] ) ) {
			// Our plugin has an update - enable auth filter
			if ( ! has_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ) ) ) {
				add_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), 10, 2 );
			}
		}

		// Also check if we're in an update process (admin page)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read-only check for upgrade action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'upgrade-plugin' ) {
			if ( ! has_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ) ) ) {
				add_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), 10, 2 );
			}
		}

		return $transient;
	}

	/**
	 * Enable auth filter before WordPress downloads the update package.
	 *
	 * @param bool         $reply   Whether to bail without returning the package (default false)
	 * @param string       $package The package file name or URL
	 * @param \WP_Upgrader $upgrader The WP_Upgrader instance
	 * @return bool Unmodified reply
	 */
	public function enableAuthForDownload( $reply, $package, $upgrader ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Check if this is a GitHub URL
		if ( is_string( $package ) && strpos( $package, 'github' ) !== false ) {
			// Enable auth filter for this download
			if ( ! has_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ) ) ) {
				add_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), 10, 2 );
			}
		}

		return $reply;
	}

	/**
	 * Register/update the core plugin update transient so WordPress knows
	 * about the new version and package URL. This allows the default updater
	 * to handle the installation just like an official update.
	 *
	 * @param string $new_version New version string
	 * @param string $package_url Download URL for the zip package
	 * @return void
	 */
	private function registerCoreUpdate( $new_version, $package_url ) {
		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		// Best-effort URL to the repo for details
		$repo_url = $this->config->getOption( 'repository_url', 'https://github.com' );

		$plugin_basename = $this->config->getPluginBasename();

		$transient->response[ $plugin_basename ] = (object) array(
			'slug'        => $this->config->getPluginSlug(),
			'plugin'      => $plugin_basename,
			'new_version' => $new_version,
			'package'     => $package_url,
			'url'         => $repo_url,
		);

		$transient->last_checked = time();
		set_site_transient( 'update_plugins', $transient );
	}

	/**
	 * Add Authorization header for GitHub package downloads if access token is set.
	 * Applied temporarily during upgrade only to prevent token leaks.
	 *
	 * @param array  $args Request args
	 * @param string $url  Request URL
	 * @return array Modified args
	 */
	public function httpAuthForGitHub( $args, $url ) {
		// Get decrypted token using Config's method
		$token = $this->config->getAccessToken();
		if ( empty( $token ) ) {
			return $args;
		}

		// Validate token format (basic check)
		if ( ! $this->isValidGitHubToken( $token ) ) {
			return $args;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return $args;
		}

		// Strict host validation - ONLY exact GitHub domains
		// This prevents token leaks to domains like "my-github.com"
		$valid_github_hosts = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'githubusercontent.com',
			'raw.githubusercontent.com',
		);

		if ( ! in_array( strtolower( $host ), $valid_github_hosts, true ) ) {
			return $args;
		}

		// Add authorization header
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'token ' . $token;

		// For GitHub API asset downloads, force the octet-stream Accept header
		if ( strpos( $url, 'api.github.com' ) !== false && strpos( $url, '/releases/assets/' ) !== false ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		// Allow larger files
		$args['timeout'] = max( $args['timeout'] ?? 30, 300 );

		// Ensure redirects are followed (GitHub API redirects to actual download URL)
		$args['redirection'] = 5;

		return $args;
	}

	/**
	 * Validate GitHub token format
	 *
	 * @param string $token GitHub token
	 * @return bool True if valid format
	 */
	private function isValidGitHubToken( $token ) {
		// GitHub tokens are alphanumeric with underscores, typically 40+ chars
		// Classic tokens: 40 chars, Fine-grained: starts with 'github_pat_'
		if ( strlen( $token ) < 20 ) {
			return false;
		}

		// Check for valid characters only
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $token ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract version number from GitHub tag
	 *
	 * Handles various version formats:
	 * - v1.2.3, version-1.2.3, release_1.2.3
	 * - 1.2.3-beta.1, 1.2.3+build.123
	 * - 1.2 (two-part versions)
	 * - 1.2.3.4 (four-part versions)
	 *
	 * @param string $tag Git tag name
	 * @return string Version number (empty string if invalid)
	 */
	private function extractVersionFromTag( $tag ) {
		// Remove common prefixes like 'v', 'version', 'release'
		$version = preg_replace( '/^(v|version|release)[-_]?/i', '', $tag );

		// Validate semantic version format (supports 2-4 part versions)
		// Pattern: X.Y or X.Y.Z or X.Y.Z.W with optional pre-release/build metadata
		if ( preg_match( '/^(\d+\.\d+(?:\.\d+)?(?:\.\d+)?)(?:[-+].*)?$/', $version, $matches ) ) {
			// Return the base version without pre-release/build metadata
			return $matches[1];
		}

		return '';
	}

	/**
	 * Check if version is a pre-release
	 *
	 * @param string $tag Git tag name
	 * @return bool True if pre-release
	 */
	private function isPreRelease( $tag ) {
		// Remove common prefixes
		$version = preg_replace( '/^(v|version|release)[-_]?/i', '', $tag );

		// Check for pre-release identifiers
		return (bool) preg_match( '/[-](alpha|beta|rc|pre|dev|snapshot)/i', $version );
	}

	/**
	 * Compare versions to determine if update is available
	 *
	 * @param string $current Current version
	 * @param string $latest Latest version
	 * @return bool True if update is available
	 */
	private function isUpdateAvailable( $current, $latest ) {
		return version_compare( $latest, $current, '>' );
	}

	/**
	 * Find suitable download asset from release
	 *
	 * Looks for ZIP file matching pattern: {prefix}.zip
	 * Does NOT fall back to zipball if assets exist (to avoid bloat)
	 *
	 * @param array $release_data GitHub release data
	 * @return string Download URL or empty string
	 */
	private function findDownloadAsset( $release_data ) {
		if ( empty( $release_data['assets'] ) ) {
			// No assets uploaded - this is an error
			// We don't use zipball as it includes source code, tests, etc.
			$this->logAction( 'Download', 'Failure', 'No assets found in release. Please upload a proper build ZIP file.' );
			return '';
		}

		// Get prefix from config
		$prefix = $this->config->getAssetPrefix();
		$prefix = rtrim( $prefix, '-' ); // Remove trailing hyphen if exists

		// Build list of acceptable filename patterns (more flexible matching)
		$patterns = array(
			strtolower( $prefix . '.zip' ),           // exact: prefix.zip
			strtolower( str_replace( '_', '-', $prefix ) . '.zip' ), // underscores to hyphens
			strtolower( str_replace( '-', '_', $prefix ) . '.zip' ), // hyphens to underscores
		);

		// Look for matching ZIP file
		foreach ( $release_data['assets'] as $asset ) {
			if ( ! $this->isZipFile( $asset ) ) {
				continue;
			}

			$asset_name = strtolower( $asset['name'] );

			// Check against all patterns
			foreach ( $patterns as $pattern ) {
				if ( $asset_name === $pattern ) {
					// Use the API URL provided by GitHub for authenticated downloads
					// This is already in the correct format: /repos/{owner}/{repo}/releases/assets/{id}
					$download_url = $asset['url'];

					$this->logAction(
						'Download',
						'Success',
						sprintf( 'Found matching asset: %s (using GitHub API URL)', $asset['name'] )
					);

					return apply_filters(
						$this->config->getPluginSlug() . '_download_url',
						$download_url,
						$asset,
						$release_data
					);
				}
			}
		}

		// No matching asset found - log which assets we found
		$available_assets = array_map(
			function ( $asset ) {
				return $asset['name'];
			},
			$release_data['assets']
		);

		$this->logAction(
			'Download',
			'Failure',
			sprintf(
				'No matching asset found. Expected: %s.zip. Available: %s',
				$prefix,
				implode( ', ', $available_assets )
			)
		);

		// Do NOT fall back to zipball - return error instead
		return '';
	}

	/**
	 * Check if asset is a ZIP file
	 *
	 * @param array $asset Asset data
	 * @return bool
	 */
	private function isZipFile( $asset ) {
		return 'application/zip' === $asset['content_type'] ||
				'zip' === pathinfo( $asset['name'], PATHINFO_EXTENSION );
	}

	/**
	 * Log action with timestamp
	 *
	 * @param string $action Action name (Check, Download, Install)
	 * @param string $result Result (Success, Failure)
	 * @param string $message Message
	 */
	private function logAction( $action, $result, $message ): void {
		$log_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'action'    => $action,
			'result'    => $result,
			'message'   => $message,
		);

		// Store only the most recent log entry
		$this->config->updateOption( 'last_log', $log_entry );
	}

	/**
	 * Get the last log entry
	 *
	 * @return array Log entry
	 */
	public function getLastLog() {
		return $this->config->getOption( 'last_log', array() );
	}

	/**
	 * Clear update cache and transients
	 *
	 * @return void
	 */
	private function clearUpdateCache() {
		// Clear stored update data
		$this->config->updateOption( 'latest_version', '' );
		$this->config->updateOption( 'update_available', false );
		$this->config->updateOption( 'release_snapshot', array() );

		// Clear WordPress plugin update transient
		$plugin_basename = $this->config->getPluginBasename();
		$transient       = get_site_transient( 'update_plugins' );

		if ( is_object( $transient ) && isset( $transient->response[ $plugin_basename ] ) ) {
			unset( $transient->response[ $plugin_basename ] );
			set_site_transient( 'update_plugins', $transient );
		}
	}

	/**
	 * Clear cache after WordPress completes plugin update
	 *
	 * @param \WP_Upgrader $upgrader WordPress upgrader instance
	 * @param array        $options Update options
	 * @return void
	 */
	public function clearCacheAfterUpdate( $upgrader, $options ) {
		// Only proceed if this is a plugin update
		if ( 'update' !== $options['action'] || 'plugin' !== $options['type'] ) {
			return;
		}

		// Check if our plugin was updated
		$plugin_basename = $this->config->getPluginBasename();
		$plugins_updated = $options['plugins'] ?? array();

		if ( in_array( $plugin_basename, $plugins_updated, true ) ) {
			// Release update lock
			$lock_key = 'update_in_progress_' . md5( $plugin_basename );
			delete_transient( $lock_key );

			// Remove auth filter after update completes
			remove_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), 10 );

			// Clear the update cache after successful update
			$this->clearUpdateCache();

			// Clear GitHub API cache after successful update
			$this->github_api->clearCache();

			$this->logAction( 'Update', 'Success', 'Plugin updated successfully. Cache cleared.' );
		}
	}
}
