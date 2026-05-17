<?php
declare(strict_types=1);

/**
 * GitHub API cache registry tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;

/**
 * Tests for object-cache-safe GitHub API transient invalidation.
 */
class GitHubAPICacheRegistryTest extends TestCase {

	private string $plugin_dir;
	private string $plugin_file;
	private Config $config;

	protected function setUp(): void {
		parent::setUp();

		$this->resetWordPressStubState();

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-cache-registry-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/cache-registry-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: Cache Registry Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'Cache Registry Test Updater',
				'page_title'  => 'Cache Registry Test Settings',
				'cli_command' => 'cache-registry-test',
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
	 * Writing a successful response records the exact transient key with autoload disabled.
	 */
	#[Test]
	public function set_cached_response_registers_cache_key_with_autoload_false(): void {
		$this->config->updateOption( 'repository_url', 'owner/repo' );
		$this->stubHttpResponse( $this->releasePayload( 'v1.0.0' ) );

		$this->makeGitHubApi()->getLatestRelease();

		$registry = $this->cacheRegistry();

		$this->assertCount( 1, $registry );
		$this->assertSame( [ true ], array_values( $registry ) );
		$this->assertSame( false, $GLOBALS['wp_gh_updater_test_option_autoload'][ $this->registryOptionName() ] ?? null );
		$this->assertArrayHasKey( array_key_first( $registry ), $GLOBALS['wp_gh_updater_test_transients'] );
	}

	/**
	 * Clearing all cache entries should call delete_transient() for every registered key.
	 */
	#[Test]
	public function clear_cache_without_endpoint_deletes_each_registered_transient(): void {
		$key_one = $this->cacheKeyForEndpoint( 'https://api.github.com/repos/owner/repo-a/releases/latest' );
		$key_two = $this->cacheKeyForEndpoint( 'https://api.github.com/repos/owner/repo-b/releases/latest' );
		$this->seedRegistry( [ $key_one, $key_two ] );

		$this->makeGitHubApi()->clearCache();

		$this->assertSame( [ $key_one, $key_two ], $GLOBALS['wp_gh_updater_test_deleted_transients'] );
		$this->assertFalse( get_option( $this->registryOptionName(), false ) );
		$this->assertSame( [], $GLOBALS['wp_gh_updater_test_transients'] );
	}

	/**
	 * Clearing one endpoint should remove only that exact transient key.
	 */
	#[Test]
	public function clear_cache_with_endpoint_removes_single_registered_entry(): void {
		$endpoint_one = 'https://api.github.com/repos/owner/repo-a/releases/latest';
		$endpoint_two = 'https://api.github.com/repos/owner/repo-b/releases/latest';
		$key_one      = $this->cacheKeyForEndpoint( $endpoint_one );
		$key_two      = $this->cacheKeyForEndpoint( $endpoint_two );
		$this->seedRegistry( [ $key_one, $key_two ] );

		$this->makeGitHubApi()->clearCache( $endpoint_one );

		$this->assertSame( [ $key_one ], $GLOBALS['wp_gh_updater_test_deleted_transients'] );
		$this->assertSame( [ $key_two => true ], $this->cacheRegistry() );
		$this->assertArrayNotHasKey( $key_one, $GLOBALS['wp_gh_updater_test_transients'] );
		$this->assertArrayHasKey( $key_two, $GLOBALS['wp_gh_updater_test_transients'] );
		$this->assertSame( false, $GLOBALS['wp_gh_updater_test_option_autoload'][ $this->registryOptionName() ] ?? null );
	}

	/**
	 * hasCachedData() should reflect the registry, not direct options-table SQL.
	 */
	#[Test]
	public function has_cached_data_reads_registry(): void {
		$api = $this->makeGitHubApi();

		$this->assertFalse( $api->hasCachedData() );

		$this->seedRegistry( [ $this->cacheKeyForEndpoint( 'https://api.github.com/repos/owner/repo/releases/latest' ) ] );

		$this->assertTrue( $api->hasCachedData() );
	}

	private function makeGitHubApi(): GitHubAPI {
		return new GitHubAPI( $this->config );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function releasePayload( string $tag_name ): array {
		return [
			'tag_name' => $tag_name,
			'name'     => 'registry release',
			'assets'   => [],
		];
	}

	/**
	 * @param array<string, mixed> $payload Response body payload.
	 */
	private function stubHttpResponse( array $payload ): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = [
			'body'     => json_encode( $payload ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [],
		];
	}

	private function registryOptionName(): string {
		return $this->config->getOptionName( 'cache_registry' );
	}

	/**
	 * @return array<string, true>
	 */
	private function cacheRegistry(): array {
		$registry = get_option( $this->registryOptionName(), [] );

		return is_array( $registry ) ? $registry : [];
	}

	private function cacheKeyForEndpoint( string $endpoint ): string {
		return $this->config->getCachePrefix() . md5( $endpoint . '|public' );
	}

	/**
	 * @param string[] $cache_keys Cache keys to seed.
	 */
	private function seedRegistry( array $cache_keys ): void {
		$registry = [];

		foreach ( $cache_keys as $cache_key ) {
			$registry[ $cache_key ]                             = true;
			$GLOBALS['wp_gh_updater_test_transients'][ $cache_key ] = [ 'cached' => $cache_key ];
		}

		update_option( $this->registryOptionName(), $registry, false );
	}

	private function resetWordPressStubState(): void {
		$GLOBALS['wp_gh_updater_test_options']             = [];
		$GLOBALS['wp_gh_updater_test_option_autoload']     = [];
		$GLOBALS['wp_gh_updater_test_transients']          = [];
		$GLOBALS['wp_gh_updater_test_deleted_transients']  = [];
		$GLOBALS['wp_gh_updater_test_http_response']       = null;
		$GLOBALS['wp_gh_updater_test_get_plugin_data']     = null;

		Config::clearAllInstances();
	}
}
