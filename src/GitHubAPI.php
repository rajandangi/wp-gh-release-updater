<?php
/**
 * GitHub API client for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub API client class
 */
class GitHubAPI {

	/**
	 * GitHub API base URL
	 */
	private const API_BASE_URL = 'https://api.github.com';

	/**
	 * Cache discriminator for unauthenticated GitHub API requests.
	 */
	private const PUBLIC_CACHE_DISCRIMINATOR = 'public';

	/**
	 * Config instance
	 *
	 * @var Config|null
	 */
	private $config;

	/**
	 * Repository owner
	 *
	 * @var string|null
	 */
	private $owner;

	/**
	 * Repository name
	 *
	 * @var string|null
	 */
	private $repo;

	/**
	 * Access token for private repositories
	 *
	 * @var string|null
	 */
	private $access_token;

	/**
	 * Constructor
	 *
	 * @param Config $config Configuration instance
	 */
	public function __construct( $config ) {
		$this->config = $config;
		$this->loadConfiguration();
	}

	/**
	 * Load configuration from WordPress options
	 */
	private function loadConfiguration(): void {
		$repository_url = $this->config->getOption( 'repository_url', '' );
		// Use Config's decryption method to get access token
		$this->access_token = $this->config->getAccessToken();

		if ( ! empty( $repository_url ) ) {
			$this->parseRepositoryUrl( $repository_url );
		}
	}

	/**
	 * Parse repository URL to extract owner and repo
	 *
	 * @param string $url Repository URL
	 * @return bool Success status
	 */
	private function parseRepositoryUrl( $url ) {
		// Support both owner/repo and full GitHub URLs
		$patterns = array(
			'/^([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+)$/', // owner/repo format
			'/github\.com\/([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git)?(?:\/)?$/', // Full URL format
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, trim( $url ), $matches ) ) {
				$this->owner = $matches[1];
				// Remove .git suffix if present
				$repo = $matches[2];
				if ( substr( $repo, -4 ) === '.git' ) {
					$repo = substr( $repo, 0, -4 );
				}
				$this->repo = $repo;
				return true;
			}
		}

		return false;
	}

	/**
	 * Set repository details
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $token Access token (optional)
	 */
	public function setRepository( $owner, $repo, $token = '' ): void {
		$this->owner        = $owner;
		$this->repo         = $repo;
		$this->access_token = $token;
	}

	/**
	 * Report whether this API instance is configured with an access token.
	 *
	 * Used by the snapshot writer so the persisted release_snapshot records
	 * the auth mode that was in force when the snapshot was captured, rather
	 * than re-evaluating the token at download time (which can flip mid-cycle
	 * if the user clears the token after a check).
	 *
	 * @return bool True when a non-empty token is configured.
	 */
	public function hasAccessToken(): bool {
		return '' !== trim( (string) $this->access_token );
	}

	/**
	 * Configure repository and token from repository URL input.
	 *
	 * Supports:
	 * - owner/repo
	 * - https://github.com/owner/repo
	 *
	 * @param string $repository_url Repository URL or owner/repo.
	 * @param string $token Optional access token.
	 * @return bool True when repository format is valid.
	 */
	public function setRepositoryFromUrl( string $repository_url, string $token = '' ): bool {
		if ( ! $this->parseRepositoryUrl( $repository_url ) ) {
			return false;
		}

		$this->access_token = trim( $token );
		return true;
	}

	/**
	 * Get latest release from GitHub
	 *
	 * @return array|WP_Error Release data or error
	 */
	public function getLatestRelease() {
		if ( empty( $this->owner ) || empty( $this->repo ) ) {
			return new \WP_Error( 'invalid_repo', 'Repository owner and name must be configured' );
		}

		$url = self::API_BASE_URL . "/repos/{$this->owner}/{$this->repo}/releases/latest";

		return $this->makeRequest( $url );
	}

	/**
	 * Test repository access
	 *
	 * @return bool|WP_Error Success status or error
	 */
	public function testRepositoryAccess() {
		if ( empty( $this->owner ) || empty( $this->repo ) ) {
			return new \WP_Error( 'invalid_repo', 'Repository owner and name must be configured' );
		}

		$url    = self::API_BASE_URL . "/repos/{$this->owner}/{$this->repo}";
		$result = $this->makeRequest( $url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Make HTTP request to GitHub API
	 *
	 * @param string $url API endpoint URL
	 * @param array  $args Additional request arguments
	 * @return array|WP_Error Response data or error
	 */
	private function makeRequest( $url, $args = array() ) {
		// Generate cache key based on URL and auth status
		$cache_key = $this->getCacheKey( $url );

		// Try to get cached response
		$cached_response = $this->getCachedResponse( $cache_key );
		if ( false !== $cached_response ) {
			return $cached_response;
		}

		$default_args = array(
			'timeout'    => 30,
			'user-agent' => $this->config->getPluginName() . '/' . $this->config->getPluginVersion(),
			'headers'    => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
		);

		// Add authorization header if token is available
		if ( ! empty( $this->access_token ) ) {
			$default_args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$args = wp_parse_args( $args, $default_args );

		// Apply filters to modify request arguments
		$args = apply_filters( $this->config->getPluginSlug() . '_request_args', $args, $url );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::log(
				$this->config,
				'ERROR',
				'GitHubAPI',
				'HTTP transport error while calling GitHub API.',
				array(
					'url'   => $url,
					'code'  => $response->get_error_code(),
					'error' => $response->get_error_message(),
				)
			);

			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		// Handle different response codes
		switch ( $response_code ) {
			case 200:
				$data = json_decode( $body, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					Logger::log(
						$this->config,
						'ERROR',
						'GitHubAPI',
						'GitHub API returned invalid JSON.',
						array(
							'url'        => $url,
							'status'     => $response_code,
							'request_id' => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
						)
					);

					return new \WP_Error( 'json_error', 'Invalid JSON response from GitHub API' );
				}

				// Cache successful response
				$this->setCachedResponse( $cache_key, $data );

				return $data;

			case 401:
				Logger::log(
					$this->config,
					'WARN',
					'GitHubAPI',
					'GitHub API authentication failed.',
					array(
						'url'        => $url,
						'status'     => $response_code,
						'request_id' => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
					)
				);

				return new \WP_Error( 'unauthorized', 'GitHub API authentication failed. Check your access token.' );

			case 403:
				$rate_limit_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				if ( $rate_limit_remaining === '0' ) {
					Logger::log(
						$this->config,
						'WARN',
						'GitHubAPI',
						'GitHub API rate limit exceeded.',
						array(
							'url'                  => $url,
							'status'               => $response_code,
							'rate_limit_remaining' => $rate_limit_remaining,
							'request_id'           => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
						)
					);

					$error = new \WP_Error( 'rate_limit', 'GitHub API rate limit exceeded. Try again later.' );
					// Cache rate limit errors for 1 hour
					$this->setCachedResponse( $cache_key, $error, 3600 );
					return $error;
				}

				Logger::log(
					$this->config,
					'WARN',
					'GitHubAPI',
					'GitHub API access forbidden.',
					array(
						'url'                  => $url,
						'status'               => $response_code,
						'rate_limit_remaining' => $rate_limit_remaining,
						'request_id'           => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
					)
				);

				return new \WP_Error( 'forbidden', 'Access to GitHub repository is forbidden.' );

			case 404:
				Logger::log(
					$this->config,
					'WARN',
					'GitHubAPI',
					'GitHub repository or release not found.',
					array(
						'url'        => $url,
						'status'     => $response_code,
						'request_id' => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
					)
				);

				return new \WP_Error( 'not_found', 'GitHub repository or release not found.' );

			default:
				Logger::log(
					$this->config,
					$response_code >= 500 ? 'ERROR' : 'WARN',
					'GitHubAPI',
					'Unexpected GitHub API response status.',
					array(
						'url'        => $url,
						'status'     => $response_code,
						'request_id' => wp_remote_retrieve_header( $response, 'x-github-request-id' ),
					)
				);

				return new \WP_Error(
					'api_error',
					sprintf( 'GitHub API request failed with status code: %d', $response_code )
				);
		}
	}

	/**
	 * Generate cache key for a URL
	 *
	 * @param string $url API endpoint URL
	 * @return string Cache key
	 */
	private function getCacheKey( $url ) {
		$auth_discriminator = self::PUBLIC_CACHE_DISCRIMINATOR;
		if ( ! empty( $this->access_token ) ) {
			// Token identity separates authenticated caches without exposing raw token material.
			$auth_discriminator = 'authed_' . hash( 'sha256', $this->access_token );
		}

		$key_parts = array(
			$url,
			$auth_discriminator,
		);

		$hash = md5( implode( '|', $key_parts ) );
		return $this->config->getCachePrefix() . $hash;
	}

	/**
	 * Get cached response
	 *
	 * @param string $cache_key Cache key
	 * @return mixed|false Cached data or false if not found
	 */
	private function getCachedResponse( $cache_key ) {
		return get_transient( $cache_key );
	}

	/**
	 * Set cached response
	 *
	 * @param string $cache_key Cache key
	 * @param mixed  $data Data to cache
	 * @param int    $duration Cache duration in seconds (optional, uses config default)
	 * @return bool Success status
	 */
	private function setCachedResponse( $cache_key, $data, $duration = null ) {
		if ( null === $duration ) {
			$duration = $this->config->getCacheDuration();
		}

		$stored = set_transient( $cache_key, $data, $duration );

		if ( $stored ) {
			$this->registerCacheKey( $cache_key );
		}

		return $stored;
	}

	/**
	 * Check if any cache exists
	 *
	 * @return bool True if cache exists
	 */
	public function hasCachedData() {
		$registry = $this->getCacheRegistry();

		return ! empty( $registry );
	}

	/**
	 * Clear all GitHub API cache
	 *
	 * @param string $endpoint Optional specific endpoint to clear
	 * @return void
	 */
	public function clearCache( $endpoint = null ) {
		if ( null === $endpoint ) {
			foreach ( array_keys( $this->getCacheRegistry() ) as $cache_key ) {
				delete_transient( $cache_key );
			}

			delete_option( $this->getCacheRegistryOptionName() );
		} else {
			// Clear specific endpoint cache
			$cache_key = $this->getCacheKey( $endpoint );
			delete_transient( $cache_key );
			$this->unregisterCacheKey( $cache_key );
		}
	}

	/**
	 * Get the option name used to track exact transient keys created by this API instance.
	 *
	 * @return string Registry option name.
	 */
	private function getCacheRegistryOptionName(): string {
		return $this->config->getOptionName( 'cache_registry' );
	}

	/**
	 * Return cache key registry as a hash set.
	 *
	 * @return array<string, true>
	 */
	private function getCacheRegistry(): array {
		$registry = get_option( $this->getCacheRegistryOptionName(), array() );

		return is_array( $registry ) ? $registry : array();
	}

	/**
	 * Register an exact transient key so clearCache() can use delete_transient().
	 *
	 * @param string $cache_key Transient key.
	 */
	private function registerCacheKey( string $cache_key ): void {
		$registry = $this->getCacheRegistry();

		if ( isset( $registry[ $cache_key ] ) ) {
			return;
		}

		$registry[ $cache_key ] = true;

		update_option( $this->getCacheRegistryOptionName(), $registry, false );
	}

	/**
	 * Remove an exact transient key from the registry.
	 *
	 * @param string $cache_key Transient key.
	 */
	private function unregisterCacheKey( string $cache_key ): void {
		$registry = $this->getCacheRegistry();

		if ( ! isset( $registry[ $cache_key ] ) ) {
			return;
		}

		unset( $registry[ $cache_key ] );

		if ( empty( $registry ) ) {
			delete_option( $this->getCacheRegistryOptionName() );
			return;
		}

		update_option( $this->getCacheRegistryOptionName(), $registry, false );
	}
}
