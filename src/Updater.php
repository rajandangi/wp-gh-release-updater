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
	 * Priority for the temporary GitHub auth request filter.
	 *
	 * High priority helps prevent other plugins from overriding
	 * Authorization headers during update downloads.
	 */
	private const AUTH_FILTER_PRIORITY = 999;

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

		// Clear update cache after successful plugin upgrade (priority 5 for better compatibility)
		add_action( 'upgrader_process_complete', array( $this, 'clearCacheAfterUpdate' ), 5, 2 );
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
				Logger::log( $this->config, 'ERROR', 'Updater', $result['message'], array( 'action' => 'Check' ) );
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
				return $result;
			}

			$latest_version = $this->extractVersionFromTag( $release_data['tag_name'] );

			if ( empty( $latest_version ) ) {
				$result['message'] = 'Could not extract version from release tag: ' . $release_data['tag_name'];
				Logger::log( $this->config, 'ERROR', 'Updater', $result['message'], array( 'action' => 'Check' ) );
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

		} catch ( \Exception $e ) {
			$result['message'] = 'Error checking for updates: ' . $e->getMessage();
			Logger::log( $this->config, 'ERROR', 'Updater', $result['message'], array( 'action' => 'Check' ) );
		}

		return $result;
	}

	/**
	 * Check for updates after clearing updater cache.
	 *
	 * This is used by admin quick check and CLI so both entry points
	 * execute the same check workflow.
	 *
	 * @return array Update check result
	 */
	public function checkForUpdatesFresh() {
		$this->github_api->clearCache();
		delete_site_transient( 'update_plugins' );

		return $this->checkForUpdates();
	}

	/**
	 * Inject update information into WordPress update transient
	 * This allows WordPress to see the update and enable the "Update now" button
	 *
	 * Manual check approach: Only injects data that was manually checked by user
	 * But ensures it's ALWAYS available when WordPress checks the transient
	 *
	 * @param object|false $transient Update plugins transient
	 * @return object|false Modified transient
	 */
	public function injectUpdateInfo( $transient ) {
		// If transient is not an object, return as-is
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Ensure response array exists
		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}

		// Ensure no_update array exists
		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$plugin_basename = $this->config->getPluginBasename();

		// Get stored update information (from manual check)
		$latest_version   = $this->config->getOption( 'latest_version', '' );
		$update_available = $this->config->getOption( 'update_available', false );
		$release_snapshot = $this->config->getOption( 'release_snapshot', array() );

		// CRITICAL: Only inject if we have data AND it's valid
		// This ensures we don't inject stale or incorrect data
		if ( ! empty( $latest_version ) && ! empty( $release_snapshot ) ) {
			// Verify the version is actually newer than current
			if ( $update_available && $this->isUpdateAvailable( $this->current_version, $latest_version ) ) {
				// Get the download URL from the release snapshot
				$package_url = $this->findDownloadAsset( $release_snapshot );

				if ( ! empty( $package_url ) ) {
					$repo_url = $this->config->getOption( 'repository_url', 'https://github.com' );

					// Inject into response array - this shows the update notification
					$transient->response[ $plugin_basename ] = (object) array(
						'slug'         => $this->config->getPluginSlug(),
						'plugin'       => $plugin_basename,
						'new_version'  => $latest_version,
						'package'      => $package_url,
						'url'          => $repo_url,
						'tested'       => get_bloginfo( 'version' ),
						'requires'     => '6.0',
						'requires_php' => '7.4',
						'icons'        => array(),
						'banners'      => array(),
					);

					// Remove from no_update if it exists there
					if ( isset( $transient->no_update[ $plugin_basename ] ) ) {
						unset( $transient->no_update[ $plugin_basename ] );
					}
				}
			} else {
				// No update available OR current version >= latest version
				// Add to no_update array to prevent "compatibility unknown" warning
				$transient->no_update[ $plugin_basename ] = (object) array(
					'slug'         => $this->config->getPluginSlug(),
					'plugin'       => $plugin_basename,
					'new_version'  => $this->current_version,
					'url'          => $this->config->getOption( 'repository_url', 'https://github.com' ),
					'package'      => '',
					'tested'       => get_bloginfo( 'version' ),
					'requires'     => '6.0',
					'requires_php' => '7.4',
				);

				// Remove from response if it exists there (in case of version rollback)
				if ( isset( $transient->response[ $plugin_basename ] ) ) {
					unset( $transient->response[ $plugin_basename ] );
				}
			}
		}

		return $transient;
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
			$this->enableGitHubAuthFilter();
		}

		// Also check if we're in an update process (admin page)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read-only check for upgrade action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'upgrade-plugin' ) {
			$this->enableGitHubAuthFilter();
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
			$this->enableGitHubAuthFilter();
		}

		return $reply;
	}

	/**
	 * Enable temporary GitHub authorization request filter.
	 *
	 * @return void
	 */
	private function enableGitHubAuthFilter(): void {
		if ( false === has_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ) ) ) {
			add_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), self::AUTH_FILTER_PRIORITY, 2 );
		}
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
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return $args;
		}

		$host             = strtolower( $host );
		$is_asset_request = false !== strpos( (string) $url, '/releases/assets/' );

		// Strict host validation - ONLY exact GitHub domains
		// This prevents token leaks to domains like "my-github.com"
		$valid_github_hosts = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
			'githubusercontent.com',
			'raw.githubusercontent.com',
		);

		if ( ! in_array( $host, $valid_github_hosts, true ) ) {
			if ( $is_asset_request ) {
				Logger::log( $this->config, 'WARN', 'Updater', 'Authorization header not added for asset download. Host not in allowlist.', array( 'action' => 'Auth', 'host' => $host ) );
			}

			return $args;
		}

		// Get decrypted token using Config's method
		$token = trim( (string) $this->config->getAccessToken() );
		if ( '' === $token ) {
			if ( $is_asset_request ) {
				Logger::log( $this->config, 'WARN', 'Updater', 'Authorization header not added for asset download. Access token is empty.', array( 'action' => 'Auth' ) );
			}

			return $args;
		}

		// Reject obviously malformed token values while supporting
		// all current GitHub token prefixes.
		if ( preg_match( '/\s/', $token ) ) {
			if ( $is_asset_request ) {
				Logger::log( $this->config, 'WARN', 'Updater', 'Authorization header not added for asset download. Access token contains whitespace.', array( 'action' => 'Auth' ) );
			}

			return $args;
		}

		// Add authorization header
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;

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
			Logger::log( $this->config, 'ERROR', 'Updater', 'No assets found in release. Please upload a proper build ZIP file.', array( 'action' => 'Download' ) );
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

		Logger::log(
			$this->config,
			'ERROR',
			'Updater',
			sprintf(
				'No matching asset found. Expected: %s.zip. Available: %s',
				$prefix,
				implode( ', ', $available_assets )
			),
			array( 'action' => 'Download' )
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
	 * Only clears cache, relies on WordPress for reactivation
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
			remove_filter( 'http_request_args', array( $this, 'httpAuthForGitHub' ), self::AUTH_FILTER_PRIORITY );

			// Clear the update cache after successful update
			$this->clearUpdateCache();

			// Clear GitHub API cache after successful update
			$this->github_api->clearCache();

			// WordPress handles reactivation automatically.
		}
	}
}
