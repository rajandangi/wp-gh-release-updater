<?php
declare(strict_types=1);

/**
 * Logger redaction tests.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater\Tests;

use PHPUnit\Framework\TestCase;
use WPGitHubReleaseUpdater\Logger;

/**
 * Tests for log/output secret redaction.
 */
class LoggerRedactionTest extends TestCase {

	/**
	 * GitHub classic PAT substrings are redacted.
	 */
	public function test_redacts_classic_github_pat(): void {
		$message = 'Using token ghp_abcdefghijklmnopqrstuvwxyz1234567890 for update.';

		$this->assertSame(
			'Using token ghp_*** for update.',
			Logger::redact( $message )
		);
	}

	/**
	 * GitHub fine-grained PAT substrings are redacted.
	 */
	public function test_redacts_fine_grained_github_pat(): void {
		$message = 'Using token github_pat_11AABBCC0_example_abcdefghijklmnopqrstuvwxyz1234567890.';

		$this->assertSame(
			'Using token github_pat_***.',
			Logger::redact( $message )
		);
	}

	/**
	 * Token Authorization headers preserve scheme but redact credentials.
	 */
	public function test_redacts_token_authorization_header(): void {
		$message = 'Request failed with Authorization: token ghp_abcdefghijklmnopqrstuvwxyz1234567890.';

		$this->assertSame(
			'Request failed with Authorization: token ***.',
			Logger::redact( $message )
		);
	}

	/**
	 * Bearer Authorization headers preserve scheme but redact credentials.
	 */
	public function test_redacts_bearer_authorization_header(): void {
		$message = 'Request failed with Authorization: Bearer github_pat_11AABBCC0_example_secret.';

		$this->assertSame(
			'Request failed with Authorization: Bearer ***.',
			Logger::redact( $message )
		);
	}

	/**
	 * GitHub API asset URL query credentials are redacted while the path remains visible.
	 */
	public function test_redacts_github_api_asset_url_query_auth(): void {
		$message = 'Resolved https://api.github.com/repos/acme/private/releases/assets/12345?access_token=ghp_abcdefghijklmnopqrstuvwxyz1234567890&name=plugin.zip.';

		$this->assertSame(
			'Resolved https://api.github.com/repos/acme/private/releases/assets/12345?access_token=***&name=plugin.zip.',
			Logger::redact( $message )
		);
	}

	/**
	 * Private asset redirects return objects.githubusercontent.com pre-signed URLs.
	 */
	public function test_redacts_objects_githubusercontent_signed_asset_url(): void {
		$message = 'Download failed for https://objects.githubusercontent.com/github-production-release-asset-2e65be/12345/plugin.zip?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIA_TEST%2F20260516%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20260516T010203Z&X-Amz-Expires=300&X-Amz-Signature=abcdef1234567890&response-content-disposition=attachment%3B%20filename%3Dplugin.zip.';

		$this->assertSame(
			'Download failed for https://objects.githubusercontent.com/github-production-release-asset-2e65be/12345/plugin.zip?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=***&X-Amz-Date=20260516T010203Z&X-Amz-Expires=300&X-Amz-Signature=***&response-content-disposition=attachment%3B%20filename%3Dplugin.zip.',
			Logger::redact( $message )
		);
	}

	/**
	 * GitHub release asset CDN URLs may use Azure-style signed query credentials.
	 */
	public function test_redacts_release_assets_githubusercontent_signed_asset_url(): void {
		$message = 'Download failed for https://release-assets.githubusercontent.com/github-production-release-asset/12345/plugin.zip?sp=r&sv=2018-11-09&sig=abcdef1234567890%3D&jwt=header.payload.signature&response-content-disposition=attachment%3B%20filename%3Dplugin.zip.';

		$this->assertSame(
			'Download failed for https://release-assets.githubusercontent.com/github-production-release-asset/12345/plugin.zip?sp=r&sv=2018-11-09&sig=***&jwt=***&response-content-disposition=attachment%3B%20filename%3Dplugin.zip.',
			Logger::redact( $message )
		);
	}

	/**
	 * Non-secret messages are unchanged.
	 */
	public function test_leaves_plain_message_unchanged(): void {
		$message = 'GitHub repository or release not found. status=404 request_id=ABC123';

		$this->assertSame( $message, Logger::redact( $message ) );
	}
}
