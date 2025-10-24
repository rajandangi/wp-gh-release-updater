<?php

/**
 * Admin class for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class
 */
class Admin {

	/**
	 * Config instance
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * GitHub API instance
	 *
	 * @var GitHubAPI
	 */
	private $github_api;

	/**
	 * Updater instance
	 *
	 * @var Updater
	 */
	private $updater;

	/**
	 * Constructor
	 *
	 * @param Config    $config Configuration instance.
	 * @param GitHubAPI $github_api GitHub API instance.
	 * @param Updater   $updater Updater instance.
	 */
	public function __construct( $config, $github_api, $updater ) {
		$this->config     = $config;
		$this->github_api = $github_api;
		$this->updater    = $updater;

		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks(): void {
		add_action( 'admin_menu', array( $this, 'addAdminMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_action( 'admin_notices', array( $this, 'showAdminNotices' ) );

		// Plugin action links on plugins page
		$plugin_basename = $this->config->getPluginBasename();
		add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'addPluginActionLinks' ) );
		add_filter( 'network_admin_plugin_action_links_' . $plugin_basename, array( $this, 'addPluginActionLinks' ) );

		// AJAX handlers
		add_action( 'wp_ajax_' . $this->config->getPluginSlug() . '_check_updates_quick', array( $this, 'ajaxQuickCheckForUpdates' ) );
		add_action( 'wp_ajax_' . $this->config->getAjaxTestRepoAction(), array( $this, 'ajaxTestRepository' ) );
	}

	/**
	 * Add admin menu page
	 */
	public function addAdminMenu(): void {
		add_management_page(
			$this->config->getPageTitle(),
			$this->config->getMenuTitle(),
			$this->config->getCapability(),
			$this->config->getSettingsPageSlug(),
			array( $this, 'displaySettingsPage' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function registerSettings(): void {
		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'repository_url' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitizeRepositoryUrl' ),
				'default'           => '',
			)
		);

		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'access_token' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitizeAccessToken' ),
				'default'           => '',
			)
		);

		// Add settings sections
		add_settings_section(
			$this->config->getSettingsSection(),
			'Repository Configuration',
			array( $this, 'settingsSectionCallback' ),
			$this->config->getSettingsPageSlug()
		);

		// Repository URL field
		add_settings_field(
			'repository_url',
			'Repository URL',
			array( $this, 'repositoryUrlFieldCallback' ),
			$this->config->getSettingsPageSlug(),
			$this->config->getSettingsSection()
		);

		// Access token field
		add_settings_field(
			'access_token',
			'Access Token',
			array( $this, 'accessTokenFieldCallback' ),
			$this->config->getSettingsPageSlug(),
			$this->config->getSettingsSection()
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueueScripts( $hook ): void {
		// Enqueue plugins page script (for "Check for Updates" link)
		if ( 'plugins.php' === $hook ) {
			wp_enqueue_script(
				$this->config->getPluginSlug() . '-plugins-page',
				$this->config->getUpdaterUrl() . 'admin/js/plugins-page.js',
				array(),
				$this->config->getPluginVersion(),
				true
			);
			return;
		}

		// Enqueue settings page scripts
		$menu_parent_prefix = str_replace( '.php', '', $this->config->getMenuParent() );
		$expected_hook      = $menu_parent_prefix . '_page_' . $this->config->getSettingsPageSlug();

		if ( $hook !== $expected_hook ) {
			return;
		}

		wp_enqueue_style(
			$this->config->getStyleHandle(),
			$this->config->getUpdaterUrl() . 'admin/css/admin.css',
			array(),
			$this->config->getPluginVersion()
		);

		wp_enqueue_script(
			$this->config->getScriptHandle(),
			$this->config->getUpdaterUrl() . 'admin/js/admin.js',
			array(),
			$this->config->getPluginVersion(),
			true
		);

		// Localize script for AJAX
		wp_localize_script(
			$this->config->getScriptHandle(),
			'wpGitHubUpdater',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $this->config->getNonceName() ),
				'actions' => array(
					'testRepo' => $this->config->getAjaxTestRepoAction(),
				),
				'strings' => array(
					'checking'       => 'Checking for updates...',
					'updating'       => 'Updating plugin...',
					'testing'        => 'Testing repository access...',
					'error'          => 'An error occurred. Please try again.',
					'confirm_update' => 'Are you sure you want to update the plugin? This action cannot be undone.',
					'success'        => 'Operation completed successfully.',
				),
			)
		);
	}

	/**
	 * Display settings page
	 */
	public function displaySettingsPage(): void {
		if ( ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
		}

		include $this->config->getUpdaterDir() . 'admin/views/settings.php';
	}

	/**
	 * Settings section description callback
	 */
	public function settingsSectionCallback(): void {
		echo '<p>' . esc_html__( 'Configure your GitHub repository settings.', 'github-updater' ) . '</p>';
	}

	/**
	 * Repository URL field callback
	 */
	public function repositoryUrlFieldCallback(): void {
		$value = $this->config->getOption( 'repository_url', '' );
		echo '<input type="text" id="repository_url" name="' . esc_attr( $this->config->getOptionName( 'repository_url' ) ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="owner/repo or https://github.com/owner/repo" />';
		echo '<p class="description">Enter the GitHub repository URL or owner/repo format.</p>';
	}

	/**
	 * Access Token field callback
	 */
	public function accessTokenFieldCallback(): void {
		// Don't display the encrypted token value for security
		// Show masked value if token exists
		$has_token = ! empty( $this->config->getOption( 'access_token', '' ) );
		$value     = $has_token ? '****************************************' : '';

		echo '<input type="password" id="access_token" name="' . esc_attr( $this->config->getOptionName( 'access_token' ) ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off" />';
		echo '<p class="description"><strong>Required for private repositories.</strong> GitHub Personal Access Token with <code>repo</code> scope. <strong>Highly recommended for public repositories</strong> to avoid API rate limits (60 requests/hour without token, 5000 with token).</p>';
	}

	/**
	 * Sanitize repository URL
	 *
	 * @param string $url Repository URL
	 * @return string Sanitized URL
	 */
	public function sanitizeRepositoryUrl( $url ) {
		$sanitized_url = sanitize_text_field( $url );

		// Clear cache when repository URL changes
		$old_url = $this->config->getOption( 'repository_url', '' );
		if ( $old_url !== $sanitized_url ) {
			$this->github_api->clearCache();
		}

		return $sanitized_url;
	}

	/**
	 * Sanitize access token
	 *
	 * @param string $token Access token
	 * @return string Encrypted token
	 */
	public function sanitizeAccessToken( $token ) {
		// If token is masked (all asterisks), keep the existing encrypted token
		if ( preg_match( '/^\*+$/', $token ) ) {
			return $this->config->getOption( 'access_token', '' );
		}

		// If empty, delete the token
		if ( empty( trim( $token ) ) ) {
			return '';
		}

		// Sanitize then encrypt the new token
		$sanitized_token = sanitize_text_field( $token );

		// Return the encrypted token directly
		// WordPress will save this via update_option
		return $this->config->encrypt( $sanitized_token );
	}

	/**
	 * AJAX handler for testing repository access
	 */
	public function ajaxTestRepository(): void {
		// Verify nonce and permissions
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $this->config->getNonceName() ) || ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( 'Security check failed' );
		}

		// Get posted settings
		$repository_url = isset( $_POST['repository_url'] ) ? sanitize_text_field( wp_unslash( $_POST['repository_url'] ) ) : '';
		$access_token   = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';

		// If token is masked, get the existing decrypted one
		if ( preg_match( '/^\*+$/', $access_token ) ) {
			$access_token = $this->config->getAccessToken();
		}

		// Test with the provided settings
		$test_api = new GitHubAPI( $this->config );

		// Parse repository URL and remove .git suffix if present
		if ( preg_match( '/^([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+)$/', $repository_url, $matches ) ) {
			$test_api->setRepository( $matches[1], $matches[2], $access_token );
		} elseif ( preg_match( '/github\.com\/([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git)?(?:\/)?$/', $repository_url, $matches ) ) {
			$test_api->setRepository( $matches[1], $matches[2], $access_token );
		} else {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Invalid repository URL format.',
				)
			);
			return;
		}

		$test_result = $test_api->testRepositoryAccess();

		if ( is_wp_error( $test_result ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => $test_result->get_error_message(),
				)
			);
		} else {
			wp_send_json(
				array(
					'success' => true,
					'message' => 'Repository access successful!',
				)
			);
		}
	}

	/**
	 * Show admin notices
	 */
	public function showAdminNotices(): void {
		$screen = get_current_screen();

		// Determine the menu parent prefix for the page hook
		$menu_parent_prefix = str_replace( '.php', '', $this->config->getMenuParent() );
		$expected_screen_id = $menu_parent_prefix . '_page_' . $this->config->getSettingsPageSlug();

		if ( $screen->id !== $expected_screen_id ) {
			return;
		}

		// Show configuration reminder
		$repository_url = $this->config->getOption( 'repository_url', '' );

		if ( empty( $repository_url ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>' . esc_html( $this->config->getPluginName() ) . ':</strong> Please configure your repository URL to enable updates.';
			echo '</p></div>';
		}

		// Show settings saved notice.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter from settings page redirect.
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo 'Settings saved successfully.';
			echo '</p></div>';
		}
	}

	/**
	 * Get current plugin status for display
	 *
	 * @return array Status information
	 */
	public function getPluginStatus() {
		return array(
			'current_version'       => $this->config->getPluginVersion(),
			'latest_version'        => $this->config->getOption( 'latest_version', '' ),
			'update_available'      => $this->config->getOption( 'update_available', false ),
			'last_checked'          => $this->config->getOption( 'last_checked', 0 ),
			'repository_configured' => ! empty( $this->config->getOption( 'repository_url', '' ) ),
			'last_log'              => $this->updater->getLastLog(),
			'has_cached_data'       => $this->github_api->hasCachedData(),
		);
	}

	/**
	 * Format timestamp for display
	 *
	 * @param int $timestamp Unix timestamp
	 * @return string Formatted date
	 */
	public function formatTimestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return 'Never';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Get update status message
	 *
	 * @param array $status Plugin status
	 * @return string Status message
	 */
	public function getStatusMessage( $status ) {
		if ( ! $status['repository_configured'] ) {
			return 'Repository not configured';
		}

		if ( empty( $status['latest_version'] ) ) {
			return 'Update check required';
		}

		if ( $status['update_available'] ) {
			return sprintf(
				'Update available: %s → %s',
				$status['current_version'],
				$status['latest_version']
			);
		}

		return 'Plugin is up to date';
	}

	/**
	 * Get status badge CSS class
	 *
	 * @param array $status Plugin status
	 * @return string CSS class
	 */
	public function getStatusBadgeClass( $status ) {
		if ( ! $status['repository_configured'] || empty( $status['latest_version'] ) ) {
			return 'badge-warning';
		}

		if ( $status['update_available'] ) {
			return 'badge-info';
		}

		return 'badge-success';
	}

	/**
	 * Add "Check for Updates" action link on plugins page
	 *
	 * @param array $links Existing plugin action links
	 * @return array Modified links
	 */
	public function addPluginActionLinks( $links ) {
		$check_updates_link = sprintf(
			'<a href="#" class="%s-check-updates" data-plugin="%s" data-nonce="%s">%s</a>',
			esc_attr( $this->config->getPluginSlug() ),
			esc_attr( $this->config->getPluginBasename() ),
			esc_attr( wp_create_nonce( $this->config->getPluginSlug() . '_check_updates_quick' ) ),
			esc_html__( 'Check for Updates', 'default' )
		);

		// Add as first link (before Deactivate)
		return array_merge( array( 'check_updates' => $check_updates_link ), $links );
	}

	/**
	 * Handle quick update check from plugins page (AJAX)
	 *
	 * @return void
	 */
	public function ajaxQuickCheckForUpdates() {
		// Verify nonce
		check_ajax_referer( $this->config->getPluginSlug() . '_check_updates_quick', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		// Clear cache first
		$this->github_api->clearCache();
		delete_site_transient( 'update_plugins' );

		// Perform update check
		$result = $this->updater->checkForUpdates();

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message'          => $result['message'],
					'update_available' => $result['update_available'],
					'latest_version'   => $result['latest_version'],
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}
}
