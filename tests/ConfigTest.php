<?php
/**
 * Basic Test
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Basic Test Class
 */
class BasicTest extends TestCase {

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
}
