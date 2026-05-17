<?php
declare(strict_types=1);

/**
 * Namespaced WordPress function overrides for tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

/**
 * Test-controlled is_admin() override for package classes.
 */
function is_admin(): bool {
	return (bool) ( $GLOBALS['wp_gh_updater_test_is_admin'] ?? true );
}

/**
 * Test-controlled defined() override for package classes.
 *
 * @param string $constant_name Constant name.
 * @return bool
 */
function defined( string $constant_name ): bool {
	if ( 'WP_CLI' === $constant_name && array_key_exists( 'wp_gh_updater_test_wp_cli', $GLOBALS ) ) {
		return true;
	}

	return \defined( $constant_name );
}

/**
 * Test-controlled constant() override for package classes.
 *
 * @param string $constant_name Constant name.
 * @return mixed
 */
function constant( string $constant_name ): mixed {
	if ( 'WP_CLI' === $constant_name && array_key_exists( 'wp_gh_updater_test_wp_cli', $GLOBALS ) ) {
		return (bool) $GLOBALS['wp_gh_updater_test_wp_cli'];
	}

	return \constant( $constant_name );
}

/**
 * Test-controlled class_exists() override for package classes.
 *
 * @param string $class Class name.
 * @param bool   $autoload Whether to autoload.
 * @return bool
 */
function class_exists( string $class, bool $autoload = true ): bool {
	if ( in_array( ltrim( $class, '\\' ), ['WP_CLI'], true ) ) {
		return (bool) ( $GLOBALS['wp_gh_updater_test_wp_cli'] ?? true ) && \class_exists( $class, $autoload );
	}

	return \class_exists( $class, $autoload );
}

/**
 * Test-controlled error_log() override for package classes.
 *
 * @param string $message Log message.
 * @return bool
 */
function error_log( string $message ): bool {
	$GLOBALS['wp_gh_updater_test_error_log'][] = $message;

	return true;
}
