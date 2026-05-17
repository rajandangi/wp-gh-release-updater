<?php
/**
 * Centralized server logger for WP GitHub Release Updater.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger utility.
 */
class Logger {

	/**
	 * Redact tokens, Authorization headers, and signed download URL credentials.
	 *
	 * @param string $message Message to sanitize.
	 * @return string Sanitized message.
	 */
	public static function redact( string $message ): string {
		$original_message = $message;
		$message          = preg_replace_callback(
			'/(Authorization:\s*(?:token|Bearer))\s+\S+/i',
			static function ( array $matches ): string {
				$suffix     = '';
				$credential = $matches[0];

				if ( preg_match( '/[.,;]$/', $credential, $punctuation ) ) {
					$suffix = $punctuation[0];
				}

				return $matches[1] . ' ***' . $suffix;
			},
			$message
		);

		if ( ! is_string( $message ) ) {
			return $original_message;
		}

		$redacted = preg_replace(
			array(
				'/github_pat_[A-Za-z0-9_]+/',
				'/ghp_[A-Za-z0-9]+/',
				'/(X-Amz-Signature=)[^&\s]+/',
				'/(X-Amz-Credential=)[^&\s]+/',
				'/(access_token=)[^&\s]+/',
				'/(sig=)[^&\s]+/',
				'/(jwt=)[^&\s]+/',
			),
			array(
				'github_pat_***',
				'ghp_***',
				'$1***',
				'$1***',
				'$1***',
				'$1***',
				'$1***',
			),
			$message
		);

		return is_string( $redacted ) ? $redacted : $message;
	}

	/**
	 * Write warning/error entry to server logs.
	 *
	 * @param Config $config Config instance.
	 * @param string $level Log level (WARN|ERROR).
	 * @param string $scope Logging scope (e.g. Updater, GitHubAPI, Admin).
	 * @param string $message Main message.
	 * @param array  $context Optional key/value context.
	 * @return void
	 */
	public static function log( Config $config, string $level, string $scope, string $message, array $context = array() ): void {
		$level = strtoupper( trim( $level ) );

		if ( ! in_array( $level, array( 'WARN', 'ERROR' ), true ) ) {
			return;
		}

		$parts = array();
		foreach ( $context as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$normalized_key = strtolower( (string) $key );
			if ( false !== strpos( $normalized_key, 'token' ) || false !== strpos( $normalized_key, 'password' ) || false !== strpos( $normalized_key, 'secret' ) ) {
				$value = '[redacted]';
			}

			$parts[] = sprintf( '%s=%s', $key, $value );
		}

		$context_suffix = ! empty( $parts ) ? ' [' . implode( ' ', $parts ) . ']' : '';

		$log_message = sprintf(
			'[WP GitHub Updater][%s][%s][%s] %s%s',
			$config->getPluginSlug(),
			$level,
			$scope,
			self::redact( $message ),
			$context_suffix
		);

		$log_message = self::redact( $log_message );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational updater warnings/errors should appear in server logs.
		error_log( $log_message );
	}
}
