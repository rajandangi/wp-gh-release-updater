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
			$message,
			$context_suffix
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational updater warnings/errors should appear in server logs.
		error_log( $log_message );
	}
}
