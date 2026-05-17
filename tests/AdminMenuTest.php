<?php
/**
 * Admin menu placement tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WPGitHubReleaseUpdater\Admin;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\Updater;

/**
 * Tests for admin settings page menu registration.
 */
class AdminMenuTest extends TestCase {

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

		$this->resetWordPressGlobals();
		Config::clearAllInstances();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-admin-menu-test-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/admin-menu-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Admin Menu Test Plugin\nVersion: 1.0.0\n*/\n"
		);
	}

	/**
	 * Tear down fixtures.
	 */
	protected function tearDown(): void {
		Config::clearAllInstances();
		$this->resetWordPressGlobals();

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}

		if ( is_dir( $this->plugin_dir ) ) {
			rmdir( $this->plugin_dir );
		}

		parent::tearDown();
	}

	/**
	 * Default menu placement remains under Tools and stores the returned hook suffix.
	 */
	public function test_default_tools_path_uses_management_page_and_stores_hook_suffix(): void {
		$GLOBALS['wp_gh_updater_test_next_management_hook'] = 'tools_page_admin-menu-hook';

		[$admin, $config] = $this->makeAdmin();

		$admin->addAdminMenu();

		$this->assertSame( 'tools_page_admin-menu-hook', $this->settingsHook( $admin ) );
		$this->assertCount( 1, $GLOBALS['wp_gh_updater_test_management_pages'] );
		$this->assertSame( $config->getSettingsPageSlug(), $GLOBALS['wp_gh_updater_test_management_pages'][0]['menu_slug'] );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_submenu_pages'] );
	}

	/**
	 * Custom menu parents use a submenu under the configured parent.
	 */
	public function test_custom_parent_uses_submenu_page_and_stores_hook_suffix(): void {
		$GLOBALS['wp_gh_updater_test_next_submenu_hook'] = 'settings_page_admin-menu-hook';

		[$admin, $config] = $this->makeAdmin(
			[
				'menu_parent' => 'options-general.php',
			]
		);

		$admin->addAdminMenu();

		$this->assertSame( 'settings_page_admin-menu-hook', $this->settingsHook( $admin ) );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_management_pages'] );
		$this->assertCount( 1, $GLOBALS['wp_gh_updater_test_submenu_pages'] );
		$this->assertSame( 'options-general.php', $GLOBALS['wp_gh_updater_test_submenu_pages'][0]['parent_slug'] );
		$this->assertSame( $config->getSettingsPageSlug(), $GLOBALS['wp_gh_updater_test_submenu_pages'][0]['menu_slug'] );
		$this->assertSame( $config->getCapability(), $GLOBALS['wp_gh_updater_test_submenu_pages'][0]['capability'] );
	}

	/**
	 * Settings assets load only when WordPress reports the stored settings hook.
	 */
	public function test_settings_assets_load_only_for_stored_settings_hook(): void {
		$GLOBALS['wp_gh_updater_test_next_management_hook'] = 'tools_page_admin-menu-hook';

		[$admin, $config] = $this->makeAdmin();

		$admin->addAdminMenu();
		$admin->enqueueScripts( $this->settingsHook( $admin ) );

		$this->assertSame( $config->getStyleHandle(), $GLOBALS['wp_gh_updater_test_enqueued_styles'][0]['handle'] );
		$this->assertSame( $config->getScriptHandle(), $GLOBALS['wp_gh_updater_test_enqueued_scripts'][0]['handle'] );
		$this->assertSame( $config->getScriptHandle(), $GLOBALS['wp_gh_updater_test_localized_scripts'][0]['handle'] );

		$GLOBALS['wp_gh_updater_test_enqueued_scripts']  = [];
		$GLOBALS['wp_gh_updater_test_enqueued_styles']   = [];
		$GLOBALS['wp_gh_updater_test_localized_scripts'] = [];

		$admin->enqueueScripts( 'some-other-hook' );

		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_enqueued_styles'] );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_enqueued_scripts'] );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_localized_scripts'] );
	}

	/**
	 * Settings notices render only when the current screen matches the stored settings hook.
	 */
	public function test_settings_notices_render_only_for_stored_settings_hook(): void {
		$GLOBALS['wp_gh_updater_test_next_management_hook'] = 'tools_page_admin-menu-hook';

		[$admin] = $this->makeAdmin();

		$admin->addAdminMenu();
		$GLOBALS['wp_gh_updater_test_current_screen'] = $this->settingsHook( $admin );

		ob_start();
		$admin->showAdminNotices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Please configure your repository URL to enable updates.', $output );

		$GLOBALS['wp_gh_updater_test_current_screen'] = 'dashboard';

		ob_start();
		$admin->showAdminNotices();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Build an Admin instance for the temporary plugin.
	 *
	 * @param array<string, mixed> $options Additional config options.
	 * @return array{0: Admin, 1: Config}
	 */
	private function makeAdmin( array $options = [] ): array {
		$config = Config::getInstance(
			$this->plugin_file,
			array_merge(
				[
					'menu_title'  => 'Admin Menu Test Updater',
					'page_title'  => 'Admin Menu Test Settings',
					'cli_command' => 'admin-menu-test',
				],
				$options
			)
		);

		$github_api = new GitHubAPI( $config );
		$updater    = new Updater( $config, $github_api );

		return [new Admin( $config, $github_api, $updater ), $config];
	}

	/**
	 * Read the stored settings hook suffix.
	 */
	private function settingsHook( Admin $admin ): ?string {
		$property = new ReflectionProperty( Admin::class, 'settings_hook' );
		$property->setAccessible( true );

		return $property->getValue( $admin );
	}

	/**
	 * Reset mutable WordPress stub state.
	 */
	private function resetWordPressGlobals(): void {
		$GLOBALS['wp_gh_updater_test_options']           = [];
		$GLOBALS['wp_gh_updater_test_transients']        = [];
		$GLOBALS['wp_gh_updater_test_http_response']     = null;
		$GLOBALS['wp_gh_updater_test_localized_scripts'] = [];
		$GLOBALS['wp_gh_updater_test_enqueued_scripts']  = [];
		$GLOBALS['wp_gh_updater_test_enqueued_styles']   = [];
		$GLOBALS['wp_gh_updater_test_management_pages']  = [];
		$GLOBALS['wp_gh_updater_test_submenu_pages']     = [];
		$GLOBALS['wp_gh_updater_test_current_screen']    = null;
		unset( $GLOBALS['wp_gh_updater_test_next_management_hook'], $GLOBALS['wp_gh_updater_test_next_submenu_hook'] );
	}
}
