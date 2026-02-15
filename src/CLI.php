<?php
/**
 * WP-CLI integration for WP GitHub Release Updater.
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command handler.
 */
class CLI {

	/**
  * Whether commands were registered.
  */
 private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Config  $config Config instance.
	 * @param Updater $updater Updater instance.
	 */
	public function __construct( private readonly Config $config, private readonly Updater $updater ) {
	}

	/**
  * Register WP-CLI commands.
  */
 public function register(): bool {
		if ( ! $this->isCliContext() ) {
			return false;
		}

		if ( $this->registered ) {
			return true;
		}

		$base_command = trim( $this->config->getCliCommand() );
		if ( '' === $base_command ) {
			return false;
		}

		$registered = $this->wpCliCall(
			'add_command',
			[$base_command, $this, ['shortdesc' => sprintf( 'GitHub updater commands for %s.', $this->config->getPluginName() )]]
		);

		if ( true !== $registered ) {
			$this->wpCliCall( 'warning', [sprintf( 'Command "%s" could not be registered (possibly already registered).', $base_command )] );
			return false;
		}

		$this->registered = true;
		return true;
	}

	/**
  * Test repository access and validate the full update pipeline.
  *
  * Performs the same validation used by the "Test Repository Access"
  * button in the updater settings page, then runs the complete update
  * pipeline (check for updates, locate release asset, resolve download
  * URL) to surface problems before an actual upgrade.
  *
  * ## OPTIONS
  *
  * [--repository-url=<repository-url>]
  * : Repository in owner/repo or https://github.com/owner/repo format.
  *
  * [--access-token=<access-token>]
  * : GitHub token override. Uses saved token when omitted.
  *
  * ## EXAMPLES
  *
  *     wp my-plugin test-repo
  *     wp my-plugin test-repo --repository-url=owner/repo
  *
  * @subcommand test-repo
  *
  * @param array $args Positional args.
  * @param array $assoc_args Assoc args.
  */
 public function test_repo( array $args, array $assoc_args ): void {
		unset( $args );

		if ( array_key_exists( 'dry', $assoc_args ) ) {
			$this->wpCliCall( 'error', ['--dry is only supported for the update command.'] );
		}

		$repository_url = $this->resolveRepositoryUrl( $assoc_args );
		$access_token   = $this->resolveAccessToken( $assoc_args );

		if ( '' === $repository_url ) {
			$this->wpCliCall( 'error', ['Repository URL is empty. Save settings first or pass --repository-url.'] );
		}

		$test_api = new GitHubAPI( $this->config );

		if ( ! $test_api->setRepositoryFromUrl( $repository_url, $access_token ) ) {
			$this->wpCliCall( 'error', ['Invalid repository URL format. Use owner/repo or https://github.com/owner/repo'] );
		}

		$test_result = $test_api->testRepositoryAccess();

		if ( is_wp_error( $test_result ) ) {
			$this->wpCliCall( 'error', [$test_result->get_error_message()] );
		}

		$this->wpCliCall( 'success', ['Repository access successful!'] );

		// Run full update pipeline validation (asset + download URL resolution).
		$this->wpCliCall( 'log', ['Running full update pipeline validation...'] );
		// Use the same repository/token overrides for pipeline validation,
		// but do not persist any update state into WP options.
		$readiness = $this->updater->validateUpdateReadiness( $repository_url, $access_token, false );

		$this->wpCliCall( 'log', [sprintf( 'Current version: %s', $readiness['current_version'] )] );

		if ( ! empty( $readiness['latest_version'] ) ) {
			$this->wpCliCall( 'log', [sprintf( 'Latest version: %s', $readiness['latest_version'] )] );
		}

		if ( ! $readiness['success'] ) {
			$this->wpCliCall( 'error', [$readiness['message']] );
		}

		if ( ! empty( $readiness['download_url'] ) ) {
			$this->wpCliCall( 'log', [sprintf( 'Download URL: %s', $readiness['download_url'] )] );
		}

		$this->wpCliCall( 'success', [$readiness['message']] );
	}

	/**
	 * Check for updates from GitHub releases.
	 *
	 * ## EXAMPLES
	 *
	 *     wp my-plugin check-updates
	 *
	 * @subcommand check-updates
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function check_updates( array $args, array $assoc_args ): void {
		unset( $args );

		if ( array_key_exists( 'dry', $assoc_args ) ) {
			$this->wpCliCall( 'error', ['--dry is only supported for the update command.'] );
		}

		$result = $this->updater->checkForUpdatesFresh();

		if ( ! $result['success'] ) {
			$this->wpCliCall( 'error', [$result['message']] );
		}

		$this->wpCliCall( 'log', [sprintf( 'Current version: %s', $result['current_version'] )] );

		if ( ! empty( $result['latest_version'] ) ) {
			$this->wpCliCall( 'log', [sprintf( 'Latest version: %s', $result['latest_version'] )] );
		}

		if ( $result['update_available'] ) {
			$this->wpCliCall( 'success', [$result['message']] );
			return;
		}

		$this->wpCliCall( 'success', ['No update available. You have the latest version installed.'] );
	}

	/**
  * Update this plugin from GitHub release package.
  *
  * ## OPTIONS
  *
  * [--dry]
  * : Validate the full update pipeline (check for updates, locate
  * release asset, resolve download URL) but skip the actual upgrade.
  *
  * ## EXAMPLES
  *
  *     wp my-plugin update
  *     wp my-plugin update --dry
  *
  * @param array $args Positional args.
  * @param array $assoc_args Assoc args.
  */
	public function update( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = array_key_exists( 'dry', $assoc_args );

		// Always run the full preflight validation first.
		$readiness = $this->updater->validateUpdateReadiness();

		if ( ! $readiness['success'] ) {
			$this->wpCliCall( 'error', [$readiness['message']] );
		}

		if ( ! $readiness['update_available'] ) {
			$this->wpCliCall( 'success', [$dry_run ? 'Dry run: no update available. Plugin is already up to date.' : 'No update available. You have the latest version installed.'] );
			return;
		}

		// In dry-run mode, report the validated result and stop.
		if ( $dry_run ) {
			$this->wpCliCall( 'log', [sprintf( 'Current version: %s', $readiness['current_version'] )] );
			$this->wpCliCall( 'log', [sprintf( 'Latest version: %s', $readiness['latest_version'] )] );
			$this->wpCliCall( 'log', [sprintf( 'Download URL: %s', $readiness['download_url'] )] );
			$this->wpCliCall( 'success', ['Dry run completed. Update pipeline validated successfully.'] );
			return;
		}

		// Proceed with the actual update.
		$plugin_basename = $this->config->getPluginBasename();
		$command         = 'plugin update ' . $plugin_basename;

		$command_result = $this->wpCliCall(
			'runcommand',
			[$command, ['return'     => 'all', 'launch'     => false, 'exit_error' => false]]
		);

		if ( ! is_object( $command_result ) ) {
			$this->wpCliCall( 'error', ['Plugin update command failed.'] );
			return;
		}

		$stdout = trim( (string) $command_result->stdout );
		$stderr = trim( (string) $command_result->stderr );

		if ( 0 !== (int) $command_result->return_code ) {
			$this->wpCliCall( 'error', ['' !== $stderr ? $stderr : ( '' !== $stdout ? $stdout : 'Plugin update command failed.' )] );
		}

		if ( '' !== $stdout ) {
			$this->wpCliCall( 'log', [$stdout] );
		}

		$this->wpCliCall( 'success', ['Plugin update completed.'] );
	}

	/**
	 * Decrypt and print the currently stored access token.
	 *
	 * Use this command for debugging when you need to verify the saved token.
	 * Output is sensitive.
	 *
	 * ## OPTIONS
	 *
	 * [--raw]
	 * : Print only the decrypted token value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp my-plugin decrypt-token
	 *     wp my-plugin decrypt-token --raw
	 *
	 * @subcommand decrypt-token
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function decrypt_token( array $args, array $assoc_args ): void {
		unset( $args );

		$encrypted_token = trim( (string) $this->config->getOption( 'access_token', '' ) );

		if ( '' === $encrypted_token ) {
			$this->wpCliCall( 'error', ['No access token is currently stored.'] );
		}

		$decrypted_token = $this->config->decrypt( $encrypted_token );

		if ( '' === $decrypted_token ) {
			$this->wpCliCall( 'error', ['Stored access token could not be decrypted. It may be corrupted or encrypted with different salts.'] );
		}

		if ( array_key_exists( 'raw', $assoc_args ) ) {
			$this->wpCliCall( 'line', [$decrypted_token] );
			return;
		}

		$this->wpCliCall( 'warning', ['Displaying decrypted access token. Treat this output as sensitive.'] );
		$this->wpCliCall( 'log', [sprintf( 'Token (masked): %s', $this->maskToken( $decrypted_token ) )] );
		$this->wpCliCall( 'log', [sprintf( 'Token (decrypted): %s', $decrypted_token )] );
		$this->wpCliCall( 'success', ['Access token decrypted.'] );
	}

	/**
  * Resolve repository URL from CLI arguments or stored config.
  *
  * @param array $assoc_args Assoc CLI args.
  */
 private function resolveRepositoryUrl( array $assoc_args ): string {
		$repository_url = $this->config->getOption( 'repository_url', '' );

		if ( isset( $assoc_args['repository-url'] ) ) {
			$repository_url = sanitize_text_field( (string) $assoc_args['repository-url'] );
		}

		return trim( (string) $repository_url );
	}

	/**
  * Resolve access token from CLI arguments or stored config.
  *
  * @param array $assoc_args Assoc CLI args.
  */
	private function resolveAccessToken( array $assoc_args ): string {
		$access_token = $this->config->getAccessToken();

		if ( isset( $assoc_args['access-token'] ) ) {
			$access_token = sanitize_text_field( (string) $assoc_args['access-token'] );
		}

		return trim( (string) $access_token );
	}

	/**
  * Mask token for safer display.
  *
  * @param string $token Raw token value.
  */
 private function maskToken( string $token ): string {
		$length = strlen( $token );

		if ( $length <= 8 ) {
			return str_repeat( '*', $length );
		}

		return substr( $token, 0, 4 ) . str_repeat( '*', $length - 8 ) . substr( $token, -4 );
	}

	/**
	 * Call WP-CLI static methods safely.
	 *
	 * @param string $method WP-CLI method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function wpCliCall( string $method, array $arguments = [] ) {
		$cli_class = '\\WP_CLI';

		if ( ! class_exists( $cli_class ) ) {
			return null;
		}

		return $cli_class::$method( ...$arguments );
	}

	/**
  * Check whether execution context is WP-CLI.
  */
 private function isCliContext(): bool {
		return defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' );
	}
}
