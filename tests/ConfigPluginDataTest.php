<?php
declare(strict_types=1);

/**
 * Config plugin data extraction tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;

/**
 * Tests that Config uses WordPress' plugin header parser.
 */
class ConfigPluginDataTest extends TestCase {

	private string $plugin_dir;
	private string $plugin_file;

	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-config-data-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/config-data-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Should Come From Stub\nVersion: 0.0.1\nText Domain: should-not-win\n*/\n"
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
	 * Config should call WordPress' get_plugin_data(), not maintain a regex clone.
	 */
	#[Test]
	public function extract_plugin_data_uses_wordpress_get_plugin_data(): void {
		$calls = [];

		$GLOBALS['wp_gh_updater_test_get_plugin_data'] = static function ( string $plugin_file, bool $markup, bool $translate ) use ( &$calls ): array {
			$calls[] = [
				'plugin_file' => $plugin_file,
				'markup'      => $markup,
				'translate'   => $translate,
			];

			return [
				'Name'       => 'WordPress Parsed Plugin',
				'Version'    => '9.8.7',
				'TextDomain' => 'wordpress-parsed-domain',
			];
		};

		$config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Config Data Test Updater',
				'page_title'  => 'Config Data Test Settings',
				'cli_command' => 'config-data-test',
			]
		);

		$this->assertSame(
			[
				[
					'plugin_file' => $this->plugin_file,
					'markup'      => false,
					'translate'   => false,
				],
			],
			$calls
		);
		$this->assertSame( 'WordPress Parsed Plugin', $config->getPluginName() );
		$this->assertSame( '9.8.7', $config->getPluginVersion() );
		$this->assertSame( 'wordpress-parsed-domain', $config->getTextDomain() );
		$this->assertSame( 'config-data-test-plugin', $config->getPluginSlug() );
	}

	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']         = [];
		$GLOBALS['wp_gh_updater_test_option_autoload'] = [];
		$GLOBALS['wp_gh_updater_test_get_plugin_data'] = null;

		Config::clearAllInstances();
	}
}
