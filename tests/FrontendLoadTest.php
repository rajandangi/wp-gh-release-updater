<?php
declare(strict_types=1);

/**
 * Frontend load isolation tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Admin;
use WPGitHubReleaseUpdater\CLI;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\GitHubUpdaterManager;
use WPGitHubReleaseUpdater\Updater;

/**
 * Tests that frontend requests keep the updater object graph unloaded.
 */
class FrontendLoadTest extends TestCase {

	/**
	 * Temporary plugin directory path.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Temporary plugin file path.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-frontend-test-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/frontend-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Frontend Test Plugin\nVersion: 1.0.0\n*/\n"
		);
	}

	/**
	 * Tear down fixtures.
	 */
	protected function tearDown(): void {
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
	 * Frontend requests do not initialize admin/updater dependencies or hooks.
	 */
	#[Test]
	public function frontend_request_does_not_initialize_updater_graph_or_admin_hooks(): void {
		$GLOBALS['wp_gh_updater_test_is_admin'] = false;
		$GLOBALS['wp_gh_updater_test_wp_cli']   = false;

		$manager = $this->makeManager();
		$manager->init();

		$this->assertFalse( $manager->isInitialized() );
		$this->assertNull( $manager->getGitHubAPI() );
		$this->assertNull( $manager->getUpdater() );
		$this->assertNull( $manager->getAdmin() );
		$this->assertNull( $manager->getCLI() );

		$this->assertAdminAndUpdaterHooksAreMissing();
	}

	/**
	 * Admin requests still initialize the web updater graph and admin hooks.
	 */
	#[Test]
	public function admin_request_initializes_admin_graph_and_hooks(): void {
		$GLOBALS['wp_gh_updater_test_is_admin'] = true;
		$GLOBALS['wp_gh_updater_test_wp_cli']   = false;

		$manager = $this->makeManager();
		$manager->init();

		$this->assertTrue( $manager->isInitialized() );
		$this->assertInstanceOf( GitHubAPI::class, $manager->getGitHubAPI() );
		$this->assertInstanceOf( Updater::class, $manager->getUpdater() );
		$this->assertInstanceOf( Admin::class, $manager->getAdmin() );
		$this->assertNull( $manager->getCLI() );

		$hooks = $this->registeredHookNames();

		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_init', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_frontend-test-plugin_check_updates_quick', $hooks );
		$this->assertContains( 'wp_ajax_frontend-test-plugin_test_repo', $hooks );
		$this->assertContains( 'plugin_action_links_' . $manager->getConfig()->getPluginBasename(), $hooks );
		$this->assertContains( 'network_admin_plugin_action_links_' . $manager->getConfig()->getPluginBasename(), $hooks );
		$this->assertContains( 'site_transient_update_plugins', $hooks );
		$this->assertContains( 'upgrader_pre_download', $hooks );
		$this->assertContains( 'upgrader_source_selection', $hooks );
		$this->assertContains( 'upgrader_process_complete', $hooks );
	}

	/**
	 * WP-CLI requests initialize CLI support without the admin UI.
	 */
	#[Test]
	public function wp_cli_request_initializes_cli_without_admin_ui(): void {
		$GLOBALS['wp_gh_updater_test_is_admin'] = false;
		$GLOBALS['wp_gh_updater_test_wp_cli']   = true;

		$manager = $this->makeManager();
		$manager->init();

		$this->assertTrue( $manager->isInitialized() );
		$this->assertInstanceOf( GitHubAPI::class, $manager->getGitHubAPI() );
		$this->assertInstanceOf( Updater::class, $manager->getUpdater() );
		$this->assertNull( $manager->getAdmin() );
		$this->assertInstanceOf( CLI::class, $manager->getCLI() );
		$this->assertContains( 'frontend-cli', $this->capturedCliCommandNames() );

		$hooks = $this->registeredHookNames();

		$this->assertNotContains( 'admin_menu', $hooks );
		$this->assertNotContains( 'admin_init', $hooks );
		$this->assertNotContains( 'admin_enqueue_scripts', $hooks );
		$this->assertNotContains( 'admin_notices', $hooks );
		$this->assertFalse( $this->hasHookWithPrefix( 'wp_ajax_' ) );
	}

	/**
	 * Build a manager using the package's current public constructor contract.
	 *
	 * @return GitHubUpdaterManager
	 */
	private function makeManager(): GitHubUpdaterManager {
		return new GitHubUpdaterManager(
			[
				'plugin_file' => $this->plugin_file,
				'menu_title'  => 'Frontend Test Updater',
				'page_title'  => 'Frontend Test Settings',
				'cli_command' => 'frontend-cli',
			]
		);
	}

	/**
	 * Reset mutable WordPress test doubles between cases.
	 */
	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']           = [];
		$GLOBALS['wp_gh_updater_test_transients']        = [];
		$GLOBALS['wp_gh_updater_test_http_response']     = null;
		$GLOBALS['wp_gh_updater_test_localized_scripts'] = [];
		$GLOBALS['wp_gh_updater_test_actions']           = [];
		$GLOBALS['wp_gh_updater_test_filters']           = [];
		$GLOBALS['wp_gh_updater_test_is_admin']          = true;
		$GLOBALS['wp_gh_updater_test_wp_cli']            = true;

		Config::clearAllInstances();
		\WP_CLI::reset();
	}

	/**
	 * Assert frontend requests avoid admin-only hooks and updater filters.
	 */
	private function assertAdminAndUpdaterHooksAreMissing(): void {
		$hooks = $this->registeredHookNames();

		foreach ( $this->frontendForbiddenHooks() as $hook ) {
			$this->assertNotContains( $hook, $hooks );
		}

		$this->assertFalse( $this->hasHookWithPrefix( 'wp_ajax_' ) );
		$this->assertFalse( $this->hasHookWithPrefix( 'plugin_action_links_' ) );
		$this->assertFalse( $this->hasHookWithPrefix( 'network_admin_plugin_action_links_' ) );
	}

	/**
	 * Hooks that must not be registered during frontend requests.
	 *
	 * @return string[]
	 */
	private function frontendForbiddenHooks(): array {
		return [
			'admin_menu',
			'admin_init',
			'admin_enqueue_scripts',
			'admin_notices',
			'pre_set_site_transient_update_plugins',
			'site_transient_update_plugins',
			'upgrader_pre_download',
			'upgrader_source_selection',
			'upgrader_process_complete',
		];
	}

	/**
	 * Get all action/filter hook names registered by the test stubs.
	 *
	 * @return string[]
	 */
	private function registeredHookNames(): array {
		$action_hooks = array_map(
			static fn( array $entry ): string => (string) $entry['hook'],
			$GLOBALS['wp_gh_updater_test_actions']
		);
		$filter_hooks = array_map(
			static fn( array $entry ): string => (string) $entry['hook'],
			$GLOBALS['wp_gh_updater_test_filters']
		);

		return array_merge( $action_hooks, $filter_hooks );
	}

	/**
	 * Check whether any registered hook starts with the given prefix.
	 *
	 * @param string $prefix Hook prefix.
	 * @return bool
	 */
	private function hasHookWithPrefix( string $prefix ): bool {
		foreach ( $this->registeredHookNames() as $hook ) {
			if ( str_starts_with( $hook, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return WP-CLI command names captured by the stub.
	 *
	 * @return string[]
	 */
	private function capturedCliCommandNames(): array {
		return array_values(
			array_map(
				static fn( array $entry ): string => $entry['message'],
				array_filter( \WP_CLI::$captured, static fn( array $entry ): bool => 'add_command' === $entry['method'] )
			)
		);
	}
}
