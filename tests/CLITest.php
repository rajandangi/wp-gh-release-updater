<?php
/**
 * WP-CLI command handler tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\CLI;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\Updater;

/**
 * Tests for the CLI command handler.
 *
 * Uses a WP_CLI stub (defined in constants.php) to capture output
 * and simulate WP_CLI::error() halting execution via exception.
 */
class CLITest extends TestCase {

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
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_gh_updater_test_options']    = [];
		$GLOBALS['wp_gh_updater_test_transients'] = [];
		$GLOBALS['wp_gh_updater_test_http_response'] = null;
		Config::clearAllInstances();
		\WP_CLI::reset();

		$this->plugin_file = sys_get_temp_dir() . '/wp-gh-updater-cli-test-' . uniqid( '', true ) . '.php';
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: CLI Test Plugin\nVersion: 2.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'CLI Test Updater',
				'page_title'  => 'CLI Test Settings',
				'cli_command' => 'cli-test',
			]
		);
	}

	/**
	 * Tear down fixtures.
	 */
	protected function tearDown(): void {
		Config::clearAllInstances();
		\WP_CLI::reset();
		$GLOBALS['wp_gh_updater_test_options']    = [];
		$GLOBALS['wp_gh_updater_test_transients'] = [];
		$GLOBALS['wp_gh_updater_test_http_response'] = null;

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}

		parent::tearDown();
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Build a CLI instance with a mocked Updater.
	 *
	 * @param array|null $readiness_result Return value for validateUpdateReadiness().
	 *                                     Null uses a real Updater.
	 * @return CLI
	 */
	private function makeCli( ?array $readiness_result = null ): CLI {
		if ( null !== $readiness_result ) {
			$updater = $this->createMock( Updater::class );
			$updater->method( 'validateUpdateReadiness' )->willReturn( $readiness_result );
		} else {
			$github_api = new GitHubAPI( $this->config );
			$updater    = new Updater( $this->config, $github_api );
		}

		return new CLI( $this->config, $updater );
	}

	/**
	 * Build a successful HTTP response array for the WP HTTP API stub.
	 *
	 * @param array $body JSON-encodable response body.
	 * @param int   $code HTTP status code.
	 * @return array
	 */
	private function httpResponse( array $body, int $code = 200 ): array {
		return [
			'body'     => json_encode( $body ),
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'headers'  => [],
		];
	}

	/**
	 * Return only captured entries matching the given method.
	 *
	 * @param string $method e.g. 'success', 'error', 'log'.
	 * @return string[] Array of captured messages.
	 */
	private function capturedMessages( string $method ): array {
		return array_values(
			array_map(
				static fn( $entry ) => $entry['message'],
				array_filter( \WP_CLI::$captured, static fn( $entry ) => $entry['method'] === $method )
			)
		);
	}

	// ── test-repo ───────────────────────────────────────────────────

	/**
	 * test-repo errors when no repository URL is configured and none is passed.
	 */
	public function test_test_repo_errors_on_empty_repository_url(): void {
		$cli = $this->makeCli();

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'Repository URL is empty' );

		$cli->test_repo( [], [] );
	}

	/**
	 * test-repo errors on an invalid repository URL format.
	 */
	public function test_test_repo_errors_on_invalid_url_format(): void {
		$cli = $this->makeCli();

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'Invalid repository URL format' );

		$cli->test_repo( [], [ 'repository-url' => 'not-a-valid-format!!!' ] );
	}

	/**
	 * test-repo succeeds when GitHub API returns a valid response
	 * and pipeline validation passes.
	 */
	public function test_test_repo_succeeds_with_valid_repository(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = $this->httpResponse(
			[ 'id' => 123, 'full_name' => 'owner/repo' ]
		);

		$readiness = [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => 'https://objects.githubusercontent.com/package.zip?token=abc',
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		];

		$cli = $this->makeCli( $readiness );
		$cli->test_repo( [], [ 'repository-url' => 'owner/repo' ] );

		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Repository access successful', $successes[0] );

		$logs = $this->capturedMessages( 'log' );
		$this->assertNotEmpty( $logs );
		// Pipeline validation outputs version and download URL info.
		$this->assertTrue(
			in_array( true, array_map( static fn( $m ) => str_contains( $m, 'Download URL' ), $logs ), true ),
			'Expected pipeline validation to output Download URL.'
		);
	}

	/**
	 * test-repo rejects the --dry flag.
	 */
	public function test_test_repo_rejects_dry_flag(): void {
		$cli = $this->makeCli();

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( '--dry is only supported for the update command' );

		$cli->test_repo( [], [ 'dry' => true ] );
	}

	/**
	 * test-repo uses the --repository-url override instead of saved config.
	 */
	public function test_test_repo_uses_repository_url_override(): void {
		// Save a different URL in config.
		$this->config->updateOption( 'repository_url', 'saved/repo' );
		$requested_url = '';

		$GLOBALS['wp_gh_updater_test_http_response'] = static function ( string $url, array $args ) use ( &$requested_url ): array {
			unset( $args );
			$requested_url = $url;

			return [
				'body'     => json_encode( [ 'id' => 456, 'full_name' => 'override/repo' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
			];
		};

		$readiness = [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '2.0.0',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'No update available. You have the latest version installed.',
		];

		$cli = $this->makeCli( $readiness );
		$cli->test_repo( [], [ 'repository-url' => 'override/repo' ] );

		$this->assertStringContainsString( '/repos/override/repo', $requested_url );

		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Repository access successful', $successes[0] );
	}

	/**
	 * test-repo uses the --access-token override instead of saved config.
	 */
	public function test_test_repo_uses_access_token_override(): void {
		// Save a token in config.
		$this->config->saveAccessToken( 'ghp_saved_token' );

		// Capture the URL and headers to verify the override token is used.
		$captured_args = null;
		$GLOBALS['wp_gh_updater_test_http_response'] = function ( string $url, array $args ) use ( &$captured_args ) {
			$captured_args = $args;
			return [
				'body'     => json_encode( [ 'id' => 789, 'full_name' => 'owner/repo' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
			];
		};

		$readiness = [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '2.0.0',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'No update available. You have the latest version installed.',
		];

		$cli = $this->makeCli( $readiness );
		$cli->test_repo( [], [ 'repository-url' => 'owner/repo', 'access-token' => 'ghp_override_token' ] );

		// Verify the override token was sent in the Authorization header.
		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertStringContainsString( 'ghp_override_token', $captured_args['headers']['Authorization'] );
	}

	/**
	 * test-repo reports API error when repository access fails.
	 */
	public function test_test_repo_reports_api_error(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = [
			'body'     => json_encode( [ 'message' => 'Not Found' ] ),
			'response' => [ 'code' => 404, 'message' => 'Not Found' ],
			'headers'  => [],
		];

		$cli = $this->makeCli();
		try {
			$cli->test_repo( [], [ 'repository-url' => 'owner/nonexistent' ] );
			$this->fail( 'Expected WPCLITestException to be thrown.' );
		} catch ( \WPCLITestException $exception ) {
			$this->assertStringContainsString( 'not found', strtolower( $exception->getMessage() ) );
		}

		$errors = $this->capturedMessages( 'error' );
		$this->assertNotEmpty( $errors );
	}

	// ── test-repo pipeline validation ───────────────────────────────

	/**
	 * test-repo reports pipeline failure when validateUpdateReadiness fails.
	 */
	public function test_test_repo_pipeline_reports_failure(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = $this->httpResponse(
			[ 'id' => 123, 'full_name' => 'owner/repo' ]
		);

		$readiness = [
			'success'          => false,
			'current_version'  => '2.0.0',
			'latest_version'   => '',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'GitHub API rate limit exceeded.',
		];

		$cli = $this->makeCli( $readiness );

		try {
			$cli->test_repo( [], [ 'repository-url' => 'owner/repo' ] );
			$this->fail( 'Expected WPCLITestException to be thrown.' );
		} catch ( \WPCLITestException $exception ) {
			$this->assertStringContainsString( 'rate limit', strtolower( $exception->getMessage() ) );
		}

		// Repo access succeeded, but pipeline failed.
		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Repository access successful', $successes[0] );
	}

	/**
	 * test-repo reports no update available through pipeline validation.
	 */
	public function test_test_repo_pipeline_reports_no_update(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = $this->httpResponse(
			[ 'id' => 123, 'full_name' => 'owner/repo' ]
		);

		$readiness = [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '2.0.0',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'No update available. You have the latest version installed.',
		];

		$cli = $this->makeCli( $readiness );
		$cli->test_repo( [], [ 'repository-url' => 'owner/repo' ] );

		$successes = $this->capturedMessages( 'success' );
		$this->assertGreaterThanOrEqual( 2, count( $successes ) );
		$this->assertStringContainsString( 'Repository access successful', $successes[0] );
		$this->assertStringContainsString( 'No update available', $successes[1] );
	}

	/**
	 * test-repo reports download URL when update is available.
	 */
	public function test_test_repo_pipeline_shows_download_url(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = $this->httpResponse(
			[ 'id' => 123, 'full_name' => 'owner/repo' ]
		);

		$download_url = 'https://objects.githubusercontent.com/package.zip?token=abc';
		$readiness = [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => $download_url,
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		];

		$cli = $this->makeCli( $readiness );
		$cli->test_repo( [], [ 'repository-url' => 'owner/repo' ] );

		$logs = $this->capturedMessages( 'log' );
		$found_download = false;
		foreach ( $logs as $log ) {
			if ( str_contains( $log, $download_url ) ) {
				$found_download = true;
				break;
			}
		}
		$this->assertTrue( $found_download, 'Expected download URL in log output.' );
	}

	// ── update ──────────────────────────────────────────────────────

	/**
	 * update reports no update when already on latest version.
	 */
	public function test_update_reports_no_update_available(): void {
		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '2.0.0',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'No update available. You have the latest version installed.',
		] );

		$cli->update( [], [] );

		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'latest version', $successes[0] );
	}

	/**
	 * update --dry reports no update with dry-run wording.
	 */
	public function test_update_dry_run_reports_no_update(): void {
		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '2.0.0',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'No update available. You have the latest version installed.',
		] );

		$cli->update( [], [ 'dry' => true ] );

		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Dry run', $successes[0] );
	}

	/**
	 * update runs WP_CLI::runcommand('plugin update ...') when update is available.
	 */
	public function test_update_runs_plugin_update_command(): void {
		\WP_CLI::$runcommand_result = (object) [
			'stdout'      => 'Updated 1 of 1 plugins.',
			'stderr'      => '',
			'return_code' => 0,
		];

		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => 'https://objects.githubusercontent.com/package.zip',
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		] );

		$cli->update( [], [] );

		// Verify runcommand was called.
		$runcommands = $this->capturedMessages( 'runcommand' );
		$this->assertNotEmpty( $runcommands );
		$this->assertStringContainsString( 'plugin update', $runcommands[0] );
		$this->assertStringNotContainsString( '--dry-run', $runcommands[0] );

		// Verify success message.
		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Plugin update completed', $successes[0] );
	}

	/**
	 * update --dry reports validated pipeline info without calling runcommand.
	 */
	public function test_update_dry_run_reports_pipeline_info(): void {
		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => 'https://objects.githubusercontent.com/package.zip?token=abc',
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		] );

		$cli->update( [], [ 'dry' => true ] );

		// Should NOT call runcommand in dry-run mode.
		$runcommands = $this->capturedMessages( 'runcommand' );
		$this->assertEmpty( $runcommands, 'Dry run should not call runcommand.' );

		// Should output version and download URL info.
		$logs = $this->capturedMessages( 'log' );
		$this->assertNotEmpty( $logs );

		$log_text = implode( "\n", $logs );
		$this->assertStringContainsString( '2.0.0', $log_text );
		$this->assertStringContainsString( '3.0.0', $log_text );
		$this->assertStringContainsString( 'Download URL', $log_text );

		$successes = $this->capturedMessages( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Dry run completed', $successes[0] );
	}

	/**
	 * update errors when runcommand returns a non-zero exit code.
	 */
	public function test_update_errors_on_failed_runcommand(): void {
		\WP_CLI::$runcommand_result = (object) [
			'stdout'      => '',
			'stderr'      => 'Error: Could not download update package.',
			'return_code' => 1,
		];

		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => 'https://objects.githubusercontent.com/package.zip',
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		] );

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'Could not download update package' );

		$cli->update( [], [] );
	}

	/**
	 * update errors when runcommand returns a non-object result.
	 */
	public function test_update_errors_on_null_runcommand_result(): void {
		\WP_CLI::$runcommand_result = null;

		$cli = $this->makeCli( [
			'success'          => true,
			'current_version'  => '2.0.0',
			'latest_version'   => '3.0.0',
			'update_available' => true,
			'download_url'     => 'https://objects.githubusercontent.com/package.zip',
			'message'          => 'Update available: 2.0.0 → 3.0.0. Download URL resolved successfully.',
		] );

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'Plugin update command failed' );

		$cli->update( [], [] );
	}

	/**
	 * update errors when updater check itself fails.
	 */
	public function test_update_errors_on_updater_failure(): void {
		$cli = $this->makeCli( [
			'success'          => false,
			'current_version'  => '2.0.0',
			'latest_version'   => '',
			'update_available' => false,
			'download_url'     => '',
			'message'          => 'Repository owner and name must be configured',
		] );

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'Repository owner and name must be configured' );

		$cli->update( [], [] );
	}

	// ── decrypt-token ───────────────────────────────────────────────

	/**
	 * decrypt-token errors when no access token is stored.
	 */
	public function test_decrypt_token_errors_when_no_token_is_stored(): void {
		$cli = $this->makeCli();

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'No access token is currently stored' );

		$cli->decrypt_token( [], [] );
	}

	/**
	 * decrypt-token errors when stored value cannot be decrypted.
	 */
	public function test_decrypt_token_errors_when_token_is_corrupted(): void {
		$this->config->updateOption( 'access_token', 'not-valid-encrypted-data' );

		$cli = $this->makeCli();

		$this->expectException( \WPCLITestException::class );
		$this->expectExceptionMessage( 'could not be decrypted' );

		$cli->decrypt_token( [], [] );
	}

	/**
	 * decrypt-token prints warning, masked token, and decrypted token.
	 */
	public function test_decrypt_token_outputs_warning_masked_and_raw_token(): void {
		$token = 'ghp_test_token_1234567890';
		$this->config->saveAccessToken( $token );

		$cli = $this->makeCli();
		$cli->decrypt_token( [], [] );

		$warnings = $this->capturedMessages( 'warning' );
		$logs     = $this->capturedMessages( 'log' );
		$successes = $this->capturedMessages( 'success' );

		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'Displaying decrypted access token', $warnings[0] );

		$this->assertCount( 2, $logs );
		$this->assertStringContainsString( 'Token (masked):', $logs[0] );
		$this->assertStringNotContainsString( $token, $logs[0] );
		$this->assertStringContainsString( 'Token (decrypted): ' . $token, $logs[1] );

		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString( 'Access token decrypted', $successes[0] );
	}

	/**
	 * decrypt-token --raw prints only the raw token.
	 */
	public function test_decrypt_token_raw_outputs_only_raw_token(): void {
		$token = 'ghp_raw_mode_token_123456';
		$this->config->saveAccessToken( $token );

		$cli = $this->makeCli();
		$cli->decrypt_token( [], [ 'raw' => true ] );

		$this->assertSame( [ $token ], $this->capturedMessages( 'line' ) );
		$this->assertSame( [], $this->capturedMessages( 'warning' ) );
		$this->assertSame( [], $this->capturedMessages( 'log' ) );
		$this->assertSame( [], $this->capturedMessages( 'success' ) );
	}

	// ── register ────────────────────────────────────────────────────

	/**
	 * register() calls WP_CLI::add_command() with the configured command path.
	 */
	public function test_register_registers_cli_command(): void {
		$cli = $this->makeCli();
		$result = $cli->register();

		$this->assertTrue( $result );

		$add_commands = $this->capturedMessages( 'add_command' );
		$this->assertNotEmpty( $add_commands );
		$this->assertSame( 'cli-test', $add_commands[0] );
	}

	/**
	 * register() is idempotent — calling it twice only registers once.
	 */
	public function test_register_is_idempotent(): void {
		$cli = $this->makeCli();

		$this->assertTrue( $cli->register() );
		$this->assertTrue( $cli->register() );

		$add_commands = $this->capturedMessages( 'add_command' );
		$this->assertCount( 1, $add_commands );
	}
}
