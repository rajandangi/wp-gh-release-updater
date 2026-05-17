<?php
declare(strict_types=1);

/**
 * GitHubUpdaterManager lifecycle cleanup tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubUpdaterManager;

/**
 * Tests that lifecycle hooks do not duplicate WordPress-owned work.
 */
class GitHubUpdaterManagerLifecycleTest extends TestCase {

	private string $plugin_dir;
	private string $plugin_file;
	private GitHubUpdaterManager $manager;

	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-lifecycle-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/lifecycle-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Lifecycle Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->manager = new GitHubUpdaterManager(
			[
				'plugin_file' => $this->plugin_file,
				'menu_title'  => 'Lifecycle Test Updater',
				'page_title'  => 'Lifecycle Test Settings',
				'cli_command' => 'lifecycle-test',
			]
		);
	}

	protected function tearDown(): void {
		Config::clearAllInstances();
		$this->resetWordPressStubState();

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}

		if ( is_dir( $this->plugin_dir ) ) {
			rmdir( $this->plugin_dir );
		}

		parent::tearDown();
	}

	/**
	 * Package activation must not flush rewrites when no rewrites are registered.
	 */
	#[Test]
	public function activate_does_not_flush_rewrite_rules(): void {
		$this->manager->activate();

		$this->assertSame( 0, $GLOBALS['wp_gh_updater_test_flush_rewrite_rules_count'] );
	}

	/**
	 * Package deactivation must not scan uploads for temp files it never creates.
	 */
	#[Test]
	public function deactivate_does_not_touch_upload_temp_files(): void {
		$this->manager->deactivate();

		$this->assertSame( 0, $GLOBALS['wp_gh_updater_test_wp_upload_dir_count'] );
		$this->assertSame( 0, $GLOBALS['wp_gh_updater_test_wp_delete_file_count'] );
	}

	/**
	 * Package uninstall deletes its options but does not own upload temp cleanup.
	 */
	#[Test]
	public function uninstall_does_not_touch_upload_temp_files(): void {
		$this->manager->uninstall();

		$this->assertSame( 0, $GLOBALS['wp_gh_updater_test_wp_upload_dir_count'] );
		$this->assertSame( 0, $GLOBALS['wp_gh_updater_test_wp_delete_file_count'] );
	}

	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']                   = [];
		$GLOBALS['wp_gh_updater_test_option_autoload']           = [];
		$GLOBALS['wp_gh_updater_test_actions']                   = [];
		$GLOBALS['wp_gh_updater_test_flush_rewrite_rules_count'] = 0;
		$GLOBALS['wp_gh_updater_test_wp_upload_dir_count']       = 0;
		$GLOBALS['wp_gh_updater_test_wp_delete_file_count']      = 0;

		Config::clearAllInstances();
	}
}
