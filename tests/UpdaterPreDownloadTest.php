<?php
declare(strict_types=1);

/**
 * handlePreDownload() reliability tests (P6 lock).
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Config;
use WPGitHubReleaseUpdater\GitHubAPI;
use WPGitHubReleaseUpdater\Updater;
use WP_Error;
use WP_Upgrader;

/**
 * Phase 2 reliability tests covering the upgrader_pre_download filter.
 */
class UpdaterPreDownloadTest extends TestCase {

	private string $plugin_dir;
	private string $plugin_file;
	private Config $config;
	private Updater $updater;
	private string $package_url;

	protected function setUp(): void {
		parent::setUp();

		Config::clearAllInstances();
		WP_Upgrader::reset();

		$GLOBALS['wp_gh_updater_test_options']     = [];
		$GLOBALS['wp_gh_updater_test_transients']  = [];
		$GLOBALS['wp_gh_updater_test_error_log']   = [];
		$GLOBALS['wp_gh_updater_test_actions']     = [];
		$GLOBALS['wp_gh_updater_test_filters']     = [];
		$GLOBALS['wp_gh_updater_test_http_response'] = null;
		$GLOBALS['wp_gh_updater_test_download_url'] = null;

		$this->plugin_dir  = sys_get_temp_dir() . '/wp-gh-updater-predownload-' . uniqid( '', true );
		$this->plugin_file = $this->plugin_dir . '/predownload-test-plugin.php';

		mkdir( $this->plugin_dir );
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: PreDownload Test Plugin\nVersion: 1.0.0\n*/\n"
		);

		$this->config = Config::getInstance(
			$this->plugin_file,
			[
				'menu_title'  => 'PreDownload Test Updater',
				'page_title'  => 'PreDownload Test Settings',
				'cli_command' => 'predownload-test',
			]
		);

		$this->assertTrue( $this->config->saveAccessToken( 'ghp_test_token_for_predownload_predownload_predownload' ) );

		$this->package_url = 'https://api.github.com/repos/owner/repo/releases/assets/12345';
		$this->config->updateOption(
			'release_snapshot',
			[
				'version'  => '1.1.0',
				'tag_name' => 'v1.1.0',
				'assets'   => [
					[
						'name'                 => $this->config->getAssetPrefix() . '.zip',
						'content_type'         => 'application/zip',
						'url'                  => $this->package_url,
						'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.1.0/plugin.zip',
					],
				],
			]
		);

		$github_api    = new GitHubAPI( $this->config );
		$this->updater = new Updater( $this->config, $github_api );
	}

	protected function tearDown(): void {
		Config::clearAllInstances();
		WP_Upgrader::reset();
		$GLOBALS['wp_gh_updater_test_download_url'] = null;

		if ( is_file( $this->plugin_file ) ) {
			unlink( $this->plugin_file );
		}
		if ( is_dir( $this->plugin_dir ) ) {
			rmdir( $this->plugin_dir );
		}

		parent::tearDown();
	}

	/**
	 * P6: Second concurrent update bounces cleanly with WP_Error('update_in_progress').
	 */
	#[Test]
	public function returns_wp_error_when_lock_held_by_another_request(): void {
		WP_Upgrader::$force_lock_failure = true;

		$result = $this->updater->handlePreDownload( false, $this->package_url, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'update_in_progress', $result->get_error_code() );
	}

	/**
	 * P6: First caller acquires the namespaced lock for this plugin slug.
	 */
	#[Test]
	public function acquires_slug_namespaced_lock_for_our_plugin(): void {
		$this->stubSuccessfulDownload();

		$this->updater->handlePreDownload( false, $this->package_url, null );

		$expected_name = 'wp_gh_' . $this->config->getPluginSlug() . '_update';
		$create_calls  = array_filter( WP_Upgrader::$lock_events, static fn( array $e ): bool => 'create' === $e['event'] );

		$this->assertNotEmpty( $create_calls );
		$first = array_values( $create_calls )[0];
		$this->assertSame( $expected_name, $first['name'] );
	}

	/**
	 * P6: Lock is HELD through extraction after a successful download.
	 *
	 * handlePreDownload returns the tmpfile but does NOT release the
	 * lock — extraction still has to run. clearCacheAfterUpdate
	 * releases the lock on success; WP core's TTL covers crash mid-extract.
	 */
	#[Test]
	public function holds_lock_through_extraction_after_successful_download(): void {
		$this->stubSuccessfulDownload();

		$result = $this->updater->handlePreDownload( false, $this->package_url, null );

		$this->assertIsString( $result, 'success path returns tmpfile path' );
		$lock_name = 'wp_gh_' . $this->config->getPluginSlug() . '_update';
		$this->assertArrayHasKey( $lock_name, WP_Upgrader::$active_locks, 'lock must stay held through extraction' );
	}

	/**
	 * P6: Lock releases on local download failure (no extraction will run).
	 */
	#[Test]
	public function releases_lock_when_download_returns_wp_error(): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = new WP_Error( 'http_request_failed', 'simulated network failure' );

		$result = $this->updater->handlePreDownload( false, $this->package_url, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [], WP_Upgrader::$active_locks, 'lock must release on local download failure' );
	}

	/**
	 * P6: Public-repo passthrough keeps the lock held — WP downloads + extracts
	 * the package itself, and clearCacheAfterUpdate / TTL handle release.
	 *
	 * Drives the test through the production browser_download_url shape that
	 * getAssetPackageUrl() writes into the transient for public repos. Using
	 * the API asset URL here would mask a regression where the lock gate
	 * filters out public-repo packages entirely.
	 */
	#[Test]
	public function holds_lock_through_public_repo_passthrough(): void {
		$this->assertTrue( $this->config->saveAccessToken( '' ) );

		$browser_url = 'https://github.com/owner/repo/releases/download/v1.1.0/plugin.zip';
		$result      = $this->updater->handlePreDownload( false, $browser_url, null );

		$this->assertFalse( $result, 'passthrough returns original reply' );
		$lock_name = 'wp_gh_' . $this->config->getPluginSlug() . '_update';
		$this->assertArrayHasKey( $lock_name, WP_Upgrader::$active_locks );
	}

	/**
	 * P6: clearCacheAfterUpdate releases the lock on successful update.
	 */
	#[Test]
	public function clear_cache_after_update_releases_lock(): void {
		$lock_name = 'wp_gh_' . $this->config->getPluginSlug() . '_update';
		\WP_Upgrader::create_lock( $lock_name, 300 );
		$this->assertArrayHasKey( $lock_name, WP_Upgrader::$active_locks );

		$this->updater->clearCacheAfterUpdate(
			null,
			[
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => [ $this->config->getPluginBasename() ],
			]
		);

		$this->assertSame( [], WP_Upgrader::$active_locks, 'lock released after successful update' );
	}

	/**
	 * P6: Non-matching package short-circuits before lock acquisition.
	 */
	#[Test]
	public function does_not_acquire_lock_for_unrelated_package(): void {
		$result = $this->updater->handlePreDownload( false, 'https://example.com/foo.zip', null );

		$this->assertFalse( $result );
		$this->assertSame( [], WP_Upgrader::$lock_events );
	}

	/**
	 * P6: Single-plugin "Update now" passes $options['plugin'] (singular),
	 * not $options['plugins']. clearCacheAfterUpdate must release the lock
	 * for both shapes.
	 */
	#[Test]
	public function clear_cache_after_update_releases_lock_for_single_plugin_key(): void {
		$lock_name = 'wp_gh_' . $this->config->getPluginSlug() . '_update';
		\WP_Upgrader::create_lock( $lock_name, 300 );
		$this->assertArrayHasKey( $lock_name, WP_Upgrader::$active_locks );

		$this->updater->clearCacheAfterUpdate(
			null,
			[
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => $this->config->getPluginBasename(),
			]
		);

		$this->assertSame( [], WP_Upgrader::$active_locks, 'lock released for single-plugin upgrade path' );
	}

	/**
	 * Earlier upgrader_pre_download filter handled the download; our gate
	 * must pass the non-false reply through unchanged without acquiring a
	 * lock or touching the snapshot.
	 */
	#[Test]
	public function passes_through_non_false_reply_from_earlier_filter(): void {
		$prior_reply = '/tmp/already-handled.zip';

		$result = $this->updater->handlePreDownload( $prior_reply, $this->package_url, null );

		$this->assertSame( $prior_reply, $result );
		$this->assertSame( [], WP_Upgrader::$lock_events );
	}

	/**
	 * Snapshots captured while authenticated record `is_public => false`. If
	 * the user later clears their token, the package URL must still resolve
	 * to the API asset URL so handlePreDownload() surfaces
	 * `github_no_access_token` cleanly instead of leaking a private download
	 * through WP's standard downloader.
	 */
	#[Test]
	public function authenticated_snapshot_short_circuits_when_token_is_cleared(): void {
		$this->config->updateOption(
			'release_snapshot',
			[
				'version'   => '1.1.0',
				'tag_name'  => 'v1.1.0',
				'is_public' => false,
				'assets'    => [
					[
						'name'                 => $this->config->getAssetPrefix() . '.zip',
						'content_type'         => 'application/zip',
						'url'                  => $this->package_url,
						'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.1.0/plugin.zip',
					],
				],
			]
		);

		$this->assertTrue( $this->config->saveAccessToken( '' ) );

		$result = $this->updater->handlePreDownload( false, $this->package_url, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'github_no_access_token', $result->get_error_code() );
		$this->assertSame( [], WP_Upgrader::$active_locks, 'lock released after auth error' );
	}

	/**
	 * Stub the resolve-redirect HTTP call to point download_url() at $local_path.
	 */
	private function stubResolveRedirect( string $local_path ): void {
		$GLOBALS['wp_gh_updater_test_http_response'] = [
			'response' => [ 'code' => 302 ],
			'headers'  => [ 'location' => 'https://release-assets.example.com/signed-url' ],
			'body'     => '',
		];

		$GLOBALS['wp_gh_updater_test_download_url'] = $local_path;
	}

	/**
	 * Stub a complete successful download flow (resolve → download → file).
	 *
	 * handlePreDownload() no longer inspects ZIP contents (WP's unzip_file()
	 * validates downstream), so a plain temp file is enough. Avoids pulling
	 * in ext-zip as an implicit test-time dependency.
	 */
	private function stubSuccessfulDownload(): void {
		$this->stubResolveRedirect( $this->writeStubDownloadFixture() );
	}

	/**
	 * Write a plain-bytes temp file to stand in for the downloaded asset.
	 */
	private function writeStubDownloadFixture(): string {
		$path = tempnam( sys_get_temp_dir(), 'wp_gh_stub_dl_' );
		$this->assertNotFalse( $path );

		file_put_contents( $path, "stub plugin payload\n" );

		return $path;
	}
}
