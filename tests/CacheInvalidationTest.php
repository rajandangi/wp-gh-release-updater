<?php
declare(strict_types=1);

/**
 * GitHub API cache correctness tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;

/**
 * Tests for transient-backed GitHub release cache separation.
 */
class CacheInvalidationTest extends TestCase {

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
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-cache-test-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/cache-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Cache Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Cache Test Updater',
				'page_title'  => 'Cache Test Settings',
				'cli_command' => 'cache-test',
			]
		);
	}

	/**
	 * Tear down fixtures.
	 */
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
	 * Repository URL changes must not reuse release payloads cached for the old repo.
	 */
	#[Test]
	public function repository_url_change_does_not_reuse_release_cache(): void {
		$this->config->updateOption( 'repository_url', 'owner/repo-a' );
		$repo_a_payload = $this->releasePayload( 'v1.0.0', 'repo-a release' );
		$this->stubHttpResponse( $repo_a_payload );

		$this->assertSame( $repo_a_payload, $this->makeGitHubApi()->getLatestRelease() );

		$this->config->updateOption( 'repository_url', 'owner/repo-b' );
		$repo_b_payload = $this->releasePayload( 'v2.0.0', 'repo-b release' );
		$this->stubHttpResponse( $repo_b_payload );

		$this->assertSame( $repo_b_payload, $this->makeGitHubApi()->getLatestRelease() );
	}

	/**
	 * Changing from one token to another must not reuse an authenticated response for the old token.
	 */
	#[Test]
	public function access_token_change_does_not_reuse_authenticated_cache(): void {
		$this->config->updateOption( 'repository_url', 'owner/private-repo' );
		$this->assertTrue( $this->config->saveAccessToken( 'ghp_token_one' ) );
		$token_one_payload = $this->releasePayload( 'v1.0.0', 'token-one release' );
		$this->stubHttpResponse( $token_one_payload );

		$this->assertSame( $token_one_payload, $this->makeGitHubApi()->getLatestRelease() );

		$this->assertTrue( $this->config->saveAccessToken( 'ghp_token_two' ) );
		$token_two_payload = $this->releasePayload( 'v2.0.0', 'token-two release' );
		$this->stubHttpResponse( $token_two_payload );

		$this->assertSame( $token_two_payload, $this->makeGitHubApi()->getLatestRelease() );
	}

	/**
	 * Removing a token must move subsequent requests back to the public cache path.
	 */
	#[Test]
	public function token_removal_does_not_reuse_authenticated_cache(): void {
		$this->config->updateOption( 'repository_url', 'owner/repo' );
		$this->assertTrue( $this->config->saveAccessToken( 'ghp_token_to_remove' ) );
		$authenticated_payload = $this->releasePayload( 'v1.0.0', 'authenticated release' );
		$this->stubHttpResponse( $authenticated_payload );

		$this->assertSame( $authenticated_payload, $this->makeGitHubApi()->getLatestRelease() );

		$this->assertTrue( $this->config->saveAccessToken( '' ) );
		$public_payload = $this->releasePayload( 'v2.0.0', 'public release' );
		$this->stubHttpResponse( $public_payload );

		$this->assertSame( $public_payload, $this->makeGitHubApi()->getLatestRelease() );
	}

	/**
	 * Public and authenticated requests for the same repository keep separate cache entries.
	 */
	#[Test]
	public function public_and_authenticated_release_cache_entries_stay_separate(): void {
		$this->config->updateOption( 'repository_url', 'owner/repo' );
		$call_count     = 0;
		$public_payload = $this->releasePayload( 'v1.0.0', 'public release' );
		$authed_payload = $this->releasePayload( 'v2.0.0', 'authenticated release' );

		$GLOBALS['wp_gh_updater_test_http_response'] = static function () use ( &$call_count, $public_payload, $authed_payload ): array {
			++$call_count;

			return $call_count === 1
				? self::httpResponse( $public_payload )
				: self::httpResponse( $authed_payload );
		};

		$this->assertSame( $public_payload, $this->makeGitHubApi()->getLatestRelease() );

		$this->assertTrue( $this->config->saveAccessToken( 'ghp_authenticated_token' ) );
		$this->assertSame( $authed_payload, $this->makeGitHubApi()->getLatestRelease() );

		$this->assertSame( 2, $call_count );
	}

	/**
	 * Cache keys must not expose raw token material in transient names.
	 */
	#[Test]
	public function cache_key_does_not_contain_raw_token_material(): void {
		$token = 'ghp_do_not_put_me_in_transient_name';

		$this->config->updateOption( 'repository_url', 'owner/private-repo' );
		$this->assertTrue( $this->config->saveAccessToken( $token ) );
		$this->stubHttpResponse( $this->releasePayload( 'v1.0.0', 'private release' ) );

		$this->makeGitHubApi()->getLatestRelease();

		$this->assertNotSame( [], $this->transientNames() );
		foreach ( $this->transientNames() as $transient_name ) {
			$this->assertStringNotContainsString( $token, $transient_name );
		}
	}

	/**
	 * Same token across requests must produce a stable key and hit the existing cache entry.
	 */
	#[Test]
	public function same_token_reuses_cached_release_across_requests(): void {
		$this->config->updateOption( 'repository_url', 'owner/private-repo' );
		$this->assertTrue( $this->config->saveAccessToken( 'ghp_stable_token' ) );
		$payload    = $this->releasePayload( 'v1.0.0', 'stable-token release' );
		$call_count = 0;

		$GLOBALS['wp_gh_updater_test_http_response'] = static function () use ( &$call_count, $payload ): array {
			++$call_count;

			return self::httpResponse( $payload );
		};

		$this->assertSame( $payload, $this->makeGitHubApi()->getLatestRelease() );
		$this->assertSame( $payload, $this->makeGitHubApi()->getLatestRelease() );
		$this->assertSame( 1, $call_count );
	}

	/**
	 * Build a GitHub API instance using the current persisted options.
	 */
	private function makeGitHubApi(): GitHubAPI {
		return new GitHubAPI( $this->config );
	}

	/**
	 * Build a successful GitHub release payload.
	 *
	 * @return array<string, mixed>
	 */
	private function releasePayload( string $tag_name, string $name ): array {
		return [
			'tag_name' => $tag_name,
			'name'     => $name,
			'assets'   => [],
		];
	}

	/**
	 * Configure the WP HTTP stub to return a successful JSON response.
	 *
	 * @param array<string, mixed> $payload Response body payload.
	 */
	private function stubHttpResponse( array $payload ): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = self::httpResponse( $payload );
	}

	/**
	 * Build a successful HTTP response array for the WP HTTP API stub.
	 *
	 * @param array<string, mixed> $body JSON-encodable response body.
	 * @return array{body: string|false, response: array{code: int, message: string}, headers: array<string, string>}
	 */
	private static function httpResponse( array $body ): array {
		return [
			'body'     => json_encode( $body ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [],
		];
	}

	/**
	 * Return transient names written by the WordPress test double.
	 *
	 * @return string[]
	 */
	private function transientNames(): array {
		return array_keys( $GLOBALS['wp_gh_updater_test_transients'] );
	}

	/**
	 * Reset mutable WordPress test doubles between cases.
	 */
	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']       = [];
		$GLOBALS['wp_gh_updater_test_transients']    = [];
		$GLOBALS['wp_gh_updater_test_http_response'] = null;

		Config::clearAllInstances();
	}
}
