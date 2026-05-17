<?php
declare(strict_types=1);

/**
 * Updater cache cleanup tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\Updater;
use WP_Upgrader;

/**
 * Tests that updater cache cleanup uses WordPress invalidation APIs.
 */
class UpdaterClearCacheTest extends TestCase {

	private string $plugin_dir;
	private string $plugin_file;
	private Config $config;
	private Updater $updater;

	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-clear-cache-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/clear-cache-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Clear Cache Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Clear Cache Test Updater',
				'page_title'  => 'Clear Cache Test Settings',
				'cli_command' => 'clear-cache-test',
			]
		);

		$this->updater = new Updater( $this->config, new GitHubAPI( $this->config ) );
	}

	protected function tearDown(): void {
		Config::clearAllInstances();
		WP_Upgrader::reset();
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
	 * clearUpdateCache() should delete the whole update transient via WP API, not edit its shape.
	 */
	#[Test]
	public function clear_update_cache_calls_delete_site_transient_only(): void {
		$this->config->updateOption( 'latest_version', '1.1.0' );
		$this->config->updateOption( 'update_available', true );
		$this->config->updateOption( 'release_snapshot', [ 'tag_name' => 'v1.1.0' ] );

		$this->updater->clearCacheAfterUpdate(
			null,
			[
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => $this->config->getPluginBasename(),
			]
		);

		$this->assertSame( '', $this->config->getOption( 'latest_version', 'sentinel' ) );
		$this->assertFalse( $this->config->getOption( 'update_available', true ) );
		$this->assertSame( [], $this->config->getOption( 'release_snapshot', [ 'sentinel' ] ) );
		$this->assertSame( [ 'update_plugins' ], $GLOBALS['wp_gh_updater_test_deleted_site_transients'] );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_set_site_transients'] );
	}

	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']                 = [];
		$GLOBALS['wp_gh_updater_test_option_autoload']         = [];
		$GLOBALS['wp_gh_updater_test_transients']              = [];
		$GLOBALS['wp_gh_updater_test_deleted_transients']      = [];
		$GLOBALS['wp_gh_updater_test_deleted_site_transients'] = [];
		$GLOBALS['wp_gh_updater_test_set_site_transients']     = [];
		$GLOBALS['wp_gh_updater_test_actions']                 = [];
		$GLOBALS['wp_gh_updater_test_filters']                 = [];

		Config::clearAllInstances();
		WP_Upgrader::reset();
	}
}
