<?php
/**
 * Plugins page admin tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Admin;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\Updater;

/**
 * Tests for the plugins.php action link behavior.
 */
class AdminPluginsPageTest extends TestCase {

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
	 * Config instance.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_gh_updater_test_options']           = [];
		$GLOBALS['wp_gh_updater_test_transients']        = [];
		$GLOBALS['wp_gh_updater_test_http_response']     = null;
		$GLOBALS['wp_gh_updater_test_localized_scripts'] = [];
		Config::clearAllInstances();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-admin-test-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/admin-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Admin Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Admin Test Updater',
				'page_title'  => 'Admin Test Settings',
				'cli_command' => 'admin-test',
			]
		);

		$github_api  = new GitHubAPI( $this->config );
		$updater     = new Updater( $this->config, $github_api );
		$this->admin = new Admin( $this->config, $github_api, $updater );
	}

	/**
	 * Tear down fixtures.
	 */
	protected function tearDown(): void {
		Config::clearAllInstances();
		$GLOBALS['wp_gh_updater_test_options']           = [];
		$GLOBALS['wp_gh_updater_test_transients']        = [];
		$GLOBALS['wp_gh_updater_test_http_response']     = null;
		$GLOBALS['wp_gh_updater_test_localized_scripts'] = [];

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}

		if ( is_dir( $this->plugin_dir ) ) {
			rmdir( $this->plugin_dir );
		}

		parent::tearDown();
	}

	/**
	 * Action link carries all AJAX data on the link itself.
	 */
	public function test_add_plugin_action_links_outputs_link_level_ajax_data(): void {
		$links = $this->admin->addPluginActionLinks(
			[
				'deactivate' => '<a href="#">Deactivate</a>',
			]
		);

		$link   = $links['check_updates'];
		$action = $this->config->getPluginSlug() . '_check_updates_quick';

		$this->assertStringContainsString( 'data-wp-gh-release-updater-check="1"', $link );
		$this->assertStringContainsString( 'data-plugin="' . $this->config->getPluginBasename() . '"', $link );
		$this->assertStringContainsString( 'data-action="' . $action . '"', $link );
		$this->assertStringContainsString( 'data-nonce="nonce"', $link );
		$this->assertStringContainsString( 'data-ajax-url="https://example.com/wp-admin/admin-ajax.php"', $link );
	}

	/**
	 * Action link no longer exposes the old slug-specific JS selector.
	 */
	public function test_add_plugin_action_links_omits_old_check_updates_class(): void {
		$links = $this->admin->addPluginActionLinks( [] );
		$link  = $links['check_updates'];

		$this->assertStringNotContainsString( 'class=', $link );
		$this->assertStringNotContainsString( $this->config->getPluginSlug() . '-check-updates', $link );
	}

	/**
	 * Plugins page script does not localize the shared pluginUpdaterConfig global.
	 */
	public function test_enqueue_scripts_plugins_page_does_not_localize_plugin_updater_config(): void {
		$this->admin->enqueueScripts( 'plugins.php' );

		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_localized_scripts'] );
	}
}
