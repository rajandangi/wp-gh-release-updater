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

		// Resolve authenticated download URL just-in-time before WordPress downloads the package.
		add_filter( 'upgrader_pre_download', array( $this, 'handlePreDownload' ), 10, 3 );

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
	 * Validate the full update pipeline without performing the update.
	 *
	 * Runs every step that `update` would execute — check for updates,
	 * locate the matching release asset, resolve the download URL (including
	 * the authenticated redirect for private repos) — and returns a
	 * structured result.  Use this from CLI commands (`test-repo`,
	 * `update --dry`) to surface problems before an actual upgrade.
	 *
	 * @return array{
	 *     success: bool,
	 *     current_version: string,
	 *     latest_version: string,
	 *     update_available: bool,
	 *     download_url: string,
	 *     message: string,
	 * }
	 */
	public function validateUpdateReadiness(): array {
		$result = array(
			'success'          => false,
			'current_version'  => $this->current_version,
			'latest_version'   => '',
			'update_available' => false,
			'download_url'     => '',
			'message'          => '',
		);

		// Step 1 — Check for updates (clears cache first).
		$check = $this->checkForUpdatesFresh();

		if ( ! $check['success'] ) {
			$result['message'] = $check['message'];
			return $result;
		}

		$result['current_version']  = $check['current_version'];
		$result['latest_version']   = $check['latest_version'];
		$result['update_available'] = $check['update_available'];

		if ( ! $check['update_available'] ) {
			$result['success'] = true;
			$result['message'] = 'No update available. You have the latest version installed.';
			return $result;
		}

		// Step 2 — Locate matching asset and resolve download URL.
		$release_data = $check['release_data'] ?? array();
		$download_url = $this->findDownloadAsset( $release_data );

		if ( '' === $download_url ) {
			$result['message'] = 'Update available but no downloadable asset found. Check release assets on GitHub.';
			return $result;
		}

		$result['download_url'] = $download_url;
		$result['success']      = true;
		$result['message']      = sprintf(
			'Update available: %s → %s. Download URL resolved successfully.',
			$check['current_version'],
			$check['latest_version']
		);

		return $result;
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
				// Get the package URL for the matching asset.
				// For public repos this is the browser_download_url (ready to use).
				// For private repos this is the API asset URL; the actual
				// pre-signed download URL is resolved just-in-time by
				// handlePreDownload() during the upgrade, avoiding an HTTP
				// call on every admin page load.
				$package_url = $this->getAssetPackageUrl( $release_snapshot );

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
	 * Find the matching ZIP release asset.
	 *
	 * Looks for a ZIP file whose name matches the configured asset prefix.
	 * Returns the raw asset array on success, null on failure.
	 * No HTTP calls — this is a pure data lookup.
	 *
	 * @param array $release_data GitHub release data (full or snapshot).
	 * @return array|null Matching asset entry or null.
	 */
	private function findMatchingAsset( array $release_data ): ?array {
		if ( empty( $release_data['assets'] ) ) {
			return null;
		}

		$prefix   = rtrim( $this->config->getAssetPrefix(), '-' );
		$patterns = array(
			strtolower( $prefix . '.zip' ),
			strtolower( str_replace( '_', '-', $prefix ) . '.zip' ),
			strtolower( str_replace( '-', '_', $prefix ) . '.zip' ),
		);

		foreach ( $release_data['assets'] as $asset ) {
			if ( ! $this->isZipFile( $asset ) ) {
				continue;
			}

			$asset_name = strtolower( $asset['name'] );

			foreach ( $patterns as $pattern ) {
				if ( $asset_name === $pattern ) {
					return $asset;
				}
			}
		}

		return null;
	}

	/**
	 * Find suitable download asset and fully resolve its download URL.
	 *
	 * Used by validateUpdateReadiness() to validate the entire download
	 * pipeline (including pre-signed URL resolution for private repos).
	 * This makes an HTTP call for private repos — do NOT use in filters
	 * that run on every page load.
	 *
	 * @param array $release_data GitHub release data.
	 * @return string Resolved download URL or empty string.
	 */
	private function findDownloadAsset( $release_data ) {
		if ( empty( $release_data['assets'] ) ) {
			Logger::log( $this->config, 'ERROR', 'Updater', 'No assets found in release. Please upload a proper build ZIP file.', array( 'action' => 'Download' ) );
			return '';
		}

		$asset = $this->findMatchingAsset( $release_data );

		if ( null === $asset ) {
			$prefix           = rtrim( $this->config->getAssetPrefix(), '-' );
			$available_assets = array_map(
				function ( $a ) {
					return $a['name'];
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
			return '';
		}

		$download_url = $this->resolveAssetDownloadUrl( $asset );

		if ( '' === $download_url ) {
			Logger::log( $this->config, 'ERROR', 'Updater', 'Could not resolve download URL for asset.', array( 'action' => 'Download', 'asset' => $asset['name'] ) );
			return '';
		}

		return apply_filters(
			$this->config->getPluginSlug() . '_download_url',
			$download_url,
			$asset,
			$release_data
		);
	}

	/**
	 * Get the package URL for the matching asset without resolving redirects.
	 *
	 * Used by injectUpdateInfo() where we need a URL to store in the transient
	 * but must NOT make HTTP calls (the filter runs on every admin page load).
	 *
	 * For public repos: returns browser_download_url (directly downloadable).
	 * For private repos: returns the GitHub API asset URL; the actual
	 *                    pre-signed URL is resolved just-in-time by
	 *                    handlePreDownload() when WordPress downloads.
	 *
	 * @param array $release_data GitHub release data (full or snapshot).
	 * @return string Package URL or empty string.
	 */
	private function getAssetPackageUrl( array $release_data ): string {
		$asset = $this->findMatchingAsset( $release_data );

		if ( null === $asset ) {
			return '';
		}

		$token = trim( (string) $this->config->getAccessToken() );

		// Public repo — browser_download_url works without auth.
		if ( '' === $token && ! empty( $asset['browser_download_url'] ) ) {
			return $asset['browser_download_url'];
		}

		// Private repo — return the API URL. handlePreDownload() will
		// resolve this to a pre-signed URL at download time.
		$url = $asset['url'] ?? '';
		return $url;
	}

	/**
	 * Resolve a GitHub release asset to a direct download URL.
	 *
	 * For private repositories the API asset URL returns a 302 redirect
	 * to a pre-signed objects.githubusercontent.com URL.  WordPress strips
	 * the Authorization header on cross-domain redirects, so we resolve
	 * the redirect ourselves and return the final signed URL which requires
	 * no further auth.
	 *
	 * For public repositories we use browser_download_url directly.
	 *
	 * @param array $asset Single asset entry from the GitHub release payload.
	 * @return string Direct download URL, or empty string on failure.
	 */
	private function resolveAssetDownloadUrl( array $asset ): string {
		$token = trim( (string) $this->config->getAccessToken() );

		// Public repo — browser_download_url works without auth.
		if ( '' === $token && ! empty( $asset['browser_download_url'] ) ) {
			return $asset['browser_download_url'];
		}

		// Private (or authenticated) repo — resolve the API redirect.
		$api_url  = $asset['url'] ?? '';
		if ( '' === $api_url ) {
			return $asset['browser_download_url'] ?? '';
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'     => 30,
				'redirection' => 0, // Do NOT follow redirects — we want the Location header.
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::log( $this->config, 'ERROR', 'Updater', 'Failed to resolve asset redirect.', array( 'action' => 'Download', 'error' => $response->get_error_message() ) );
			return '';
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );


		// 302/301 → the Location header contains the pre-signed URL.
		if ( in_array( $status, array( 301, 302 ), true ) && '' !== $location ) {
			return $location;
		}

		// If GitHub returned 200 directly (unlikely but possible), fall
		// back to browser_download_url.
		if ( 200 === $status && ! empty( $asset['browser_download_url'] ) ) {
			return $asset['browser_download_url'];
		}

		Logger::log( $this->config, 'WARN', 'Updater', sprintf( 'Unexpected status %d while resolving asset URL.', $status ), array( 'action' => 'Download', 'url' => $api_url ) );
		return '';
	}

	/**
	 * Resolve authenticated download URL just-in-time before WordPress
	 * downloads the update package.
	 *
	 * This filter fires in WP_Upgrader::download_package() BEFORE
	 * WordPress attempts to download the file.  For private repos the
	 * package URL in the transient is a GitHub API asset URL that
	 * requires authentication.  We download the file here with proper
	 * auth headers and return the local temp file path so WordPress
	 * never has to deal with Authorization headers itself.
	 *
	 * Uses the same asset matching logic as validateUpdateReadiness(),
	 * guaranteeing consistency between test-repo / update --dry and
	 * the actual update.
	 *
	 * @param bool|\WP_Error $reply   Whether to bail without returning the package (default false).
	 * @param string         $package The package file name or URL.
	 * @param \WP_Upgrader   $upgrader The WP_Upgrader instance.
	 * @return bool|string|\WP_Error Unmodified $reply, or local file path on success, or WP_Error.
	 */
	public function handlePreDownload( $reply, $package, $upgrader ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only intercept GitHub API asset URLs.
		if ( ! is_string( $package )
			|| strpos( $package, 'api.github.com' ) === false
			|| strpos( $package, '/releases/assets/' ) === false
		) {
			return $reply;
		}

		// Verify this package belongs to our release snapshot.
		$release_snapshot = $this->config->getOption( 'release_snapshot', array() );
		$matched_asset    = null;

		foreach ( $release_snapshot['assets'] ?? array() as $asset ) {
			if ( isset( $asset['url'] ) && $asset['url'] === $package ) {
				$matched_asset = $asset;
				break;
			}
		}

		if ( null === $matched_asset ) {
			// Not our plugin's asset — let WordPress handle it.
			return $reply;
		}

		$token = trim( (string) $this->config->getAccessToken() );

		if ( '' === $token ) {
			return $reply;
		}


		// Stream the file directly from the GitHub API asset URL with
		// proper auth headers. cURL will follow the 302 redirect to the
		// pre-signed objects.githubusercontent.com URL automatically,
		// dropping the Authorization header on the cross-domain redirect
		// (which is fine — the pre-signed URL has auth baked in).
		// This single-request approach avoids the race condition where a
		// separate resolve step gets a different HTTP status (200 vs 302)
		// depending on caching / transport behavior.
		$tmpfilename = wp_tempnam( $package );

		$response = wp_safe_remote_get(
			$package,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmpfilename,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the temp file.
			@unlink( $tmpfilename );
			Logger::log( $this->config, 'ERROR', 'Updater', 'handlePreDownload: Streaming download failed.', array( 'action' => 'Download', 'error' => $response->get_error_message() ) );
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the temp file.
			@unlink( $tmpfilename );
			$body = wp_remote_retrieve_body( $response );
			Logger::log( $this->config, 'ERROR', 'Updater', sprintf( 'handlePreDownload: GitHub returned HTTP %d.', $status ), array( 'action' => 'Download' ) );
			return new \WP_Error(
				'github_download_failed',
				sprintf( 'GitHub asset download failed with HTTP %d.', $status )
			);
		}

		// Verify the downloaded file is not empty.
		$filesize = filesize( $tmpfilename );

		if ( 0 === $filesize || false === $filesize ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the temp file.
			@unlink( $tmpfilename );
			Logger::log( $this->config, 'ERROR', 'Updater', 'handlePreDownload: Downloaded file is empty.', array( 'action' => 'Download' ) );
			return new \WP_Error(
				'github_download_empty',
				'Downloaded update package is empty.'
			);
		}

		return $tmpfilename;
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

			// Clear the update cache after successful update
			$this->clearUpdateCache();

			// Clear GitHub API cache after successful update
			$this->github_api->clearCache();

			// WordPress handles reactivation automatically.
		}
	}
}
