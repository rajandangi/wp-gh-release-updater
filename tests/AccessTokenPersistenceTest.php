<?php
/**
 * Access token persistence tests.
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
 * Access token storage behavior tests.
 */
class AccessTokenPersistenceTest extends TestCase {

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

		$GLOBALS['wp_gh_updater_test_options'] = [];
		Config::clearAllInstances();

		$this->plugin_file = sys_get_temp_dir() . '/wp-gh-updater-test-plugin-' . uniqid( '', true ) . '.php';
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Test Updater Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'plugin_file' => $this->plugin_file,
				'menu_title'  => 'Test Updater',
				'page_title'  => 'Test Updater Settings',
				'cli_command' => 'test-updater',
			]
		);

		$github_api = new GitHubAPI( $this->config );
		$updater    = new Updater( $this->config, $github_api );
		$this->admin = new Admin( $this->config, $github_api, $updater );
	}

	/**
	 * Tear down fixtures.
	 */
	protected function tearDown(): void {
		Config::clearAllInstances();
		$GLOBALS['wp_gh_updater_test_options'] = [];

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}

		parent::tearDown();
	}

	/**
	 * Ensure sanitization does not re-encrypt encrypted token values.
	 */
	public function test_sanitize_access_token_is_idempotent_for_encrypted_values(): void {
		$plain_token      = 'ghp_test_token_123456';
		$encrypted_once   = $this->admin->sanitizeAccessToken( $plain_token );
		$encrypted_twice  = $this->admin->sanitizeAccessToken( $encrypted_once );
		$decrypted_twice  = $this->config->decrypt( $encrypted_twice );

		$this->assertNotSame( $plain_token, $encrypted_once );
		$this->assertSame( $encrypted_once, $encrypted_twice );
		$this->assertSame( $plain_token, $decrypted_twice );
	}

	/**
	 * Ensure masked values keep the stored encrypted token unchanged.
	 */
	public function test_sanitize_access_token_keeps_existing_value_for_masked_input(): void {
		$existing_token     = 'ghp_existing_token_654321';
		$existing_encrypted = $this->config->encrypt( $existing_token );
		$this->config->updateOption( 'access_token', $existing_encrypted );

		$sanitized = $this->admin->sanitizeAccessToken( '********' );

		$this->assertSame( $existing_encrypted, $sanitized );
		$this->assertSame( $existing_token, $this->config->decrypt( $sanitized ) );
	}

	/**
	 * Ensure saveAccessToken stores encrypted input without re-encrypting.
	 */
	public function test_save_access_token_accepts_existing_encrypted_value(): void {
		$plain_token = 'ghp_programmatic_token_123';
		$encrypted   = $this->config->encrypt( $plain_token );

		$this->assertTrue( $this->config->saveAccessToken( $encrypted ) );
		$this->assertSame( $encrypted, $this->config->getOption( 'access_token', '' ) );
		$this->assertSame( $plain_token, $this->config->getAccessToken() );
	}

	/**
	 * Ensure saveAccessToken clears storage for empty values.
	 */
	public function test_save_access_token_deletes_value_when_empty(): void {
		$this->assertTrue( $this->config->saveAccessToken( 'ghp_clear_me_123' ) );
		$this->assertNotSame( '', $this->config->getOption( 'access_token', '' ) );

		$this->assertTrue( $this->config->saveAccessToken( '' ) );
		$this->assertSame( '', $this->config->getOption( 'access_token', '' ) );
		$this->assertSame( '', $this->config->getAccessToken() );
	}
}
