<?php
declare(strict_types=1);

/**
 * Admin AJAX redaction tests.
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
 * Tests for admin AJAX response redaction.
 */
class AdminAjaxRedactionTest extends TestCase {

	/**
	 * Temporary plugin directory path.
	 */
	private string $plugin_dir;

	/**
	 * Temporary plugin file path.
	 */
	private string $plugin_file;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressGlobals();
		Config::clearAllInstances();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-admin-ajax-test-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/admin-ajax-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Admin AJAX Test Plugin\nVersion: 1.0.0\n*/\n"
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
	 * AJAX repository test errors do not expose tokens or signed URL credentials.
	 */
	public function test_test_repository_error_payload_is_redacted(): void {
		$token      = 'ghp_abcdefghijklmnopqrstuvwxyz1234567890';
		$signed_url = 'https://objects.githubusercontent.com/github-production-release-asset-2e65be/12345/plugin.zip?X-Amz-Credential=AKIA_TEST%2F20260516%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Signature=abcdef1234567890';

		$GLOBALS['wp_gh_updater_test_http_response'] = new \WP_Error(
			'http_request_failed',
			'GitHub rejected Authorization: token ' . $token . ' for ' . $signed_url
		);

		$_POST = [
			'nonce'          => 'valid',
			'repository_url' => 'acme/private',
			'access_token'   => $token,
		];

		$admin = $this->makeAdmin();

		try {
			$admin->ajaxTestRepository();
		} catch ( \WPJSONTestException ) {
			// Expected: the WordPress test stub captures the payload and halts.
		}

		$response = $GLOBALS['wp_gh_updater_test_json_response'];

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'GitHub rejected Authorization: token *** for https://objects.githubusercontent.com/github-production-release-asset-2e65be/12345/plugin.zip?X-Amz-Credential=***&X-Amz-Signature=***',
			$response['message']
		);

		$log_output = implode( "\n", $GLOBALS['wp_gh_updater_test_error_log'] );

		$this->assertStringNotContainsString( $token, $log_output );
		$this->assertStringNotContainsString( 'AKIA_TEST', $log_output );
		$this->assertStringContainsString( 'Authorization: token ***', $log_output );
		$this->assertStringContainsString( 'X-Amz-Credential=***', $log_output );
	}

	/**
	 * Build an Admin instance for the temporary plugin.
	 */
	private function makeAdmin(): Admin {
		$config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Admin AJAX Test Updater',
				'page_title'  => 'Admin AJAX Test Settings',
				'cli_command' => 'admin-ajax-test',
			]
		);

		$github_api = new GitHubAPI( $config );
		$updater    = new Updater( $config, $github_api );

		return new Admin( $config, $github_api, $updater );
	}

	/**
	 * Reset mutable WordPress stub state.
	 */
	private function resetWordPressGlobals(): void {
		$GLOBALS['wp_gh_updater_test_options']       = [];
		$GLOBALS['wp_gh_updater_test_transients']    = [];
		$GLOBALS['wp_gh_updater_test_http_response'] = null;
		$GLOBALS['wp_gh_updater_test_json_response'] = null;
		$GLOBALS['wp_gh_updater_test_error_log']     = [];
		$_POST                                      = [];
	}
}
