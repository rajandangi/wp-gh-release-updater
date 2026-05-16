<?php
/**
 * Config/bootstrap tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;

/**
 * Config/bootstrap test class.
 */
class ConfigTest extends TestCase {

	/**
	 * Test that autoloader is working
	 */
	public function test_autoloader_is_working(): void {
		$this->assertTrue( class_exists( 'WPGitHubReleaseUpdater\Config' ) );
		$this->assertTrue( class_exists( 'WPGitHubReleaseUpdater\GitHubAPI' ) );
		$this->assertTrue( class_exists( 'WPGitHubReleaseUpdater\Updater' ) );
		$this->assertTrue( class_exists( 'WPGitHubReleaseUpdater\Admin' ) );
		$this->assertTrue( class_exists( 'WPGitHubReleaseUpdater\GitHubUpdaterManager' ) );
	}

	/**
	 * Test that constants are defined
	 */
	public function test_wordpress_constants_are_defined(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertTrue( defined( 'WPINC' ) );
	}

	/**
	 * Test basic PHP functionality
	 */
	public function test_php_version(): void {
		$this->assertTrue( version_compare( PHP_VERSION, '8.3.0', '>=' ) );
	}

	/**
	 * Existing stored token envelopes remain readable by a fresh Config instance.
	 */
	public function test_access_token_round_trips_after_config_singleton_reset(): void {
		$GLOBALS['wp_gh_updater_test_options'] = [];
		Config::clearAllInstances();

		$plugin_file = sys_get_temp_dir() . '/wp-gh-updater-config-test-' . uniqid( '', true ) . '.php';
		file_put_contents(
			$plugin_file,
			"<?php\n/*\nPlugin Name: Config Token Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		try {
			$config = Config::getInstance(
				$plugin_file,
				[
					'menu_title'  => 'Config Token Test Updater',
					'page_title'  => 'Config Token Test Settings',
					'cli_command' => 'config-token-test',
				]
			);

			$token = 'ghp_current_codec_round_trip_1234567890';

			$this->assertTrue( $config->saveAccessToken( $token ) );

			$encrypted = $config->getOption( 'access_token', '' );
			$this->assertIsString( $encrypted );
			$this->assertNotSame( $token, $encrypted );
			$this->assertStringContainsString( '::', (string) base64_decode( $encrypted, true ) );

			Config::clearAllInstances();

			$fresh_config = Config::getInstance(
				$plugin_file,
				[
					'menu_title'  => 'Config Token Test Updater',
					'page_title'  => 'Config Token Test Settings',
					'cli_command' => 'config-token-test',
				]
			);

			$this->assertSame( $token, $fresh_config->getAccessToken() );
		} finally {
			Config::clearAllInstances();
			$GLOBALS['wp_gh_updater_test_options'] = [];

			if ( is_file( $plugin_file ) ) {
				unlink( $plugin_file );
			}
		}
	}
}
