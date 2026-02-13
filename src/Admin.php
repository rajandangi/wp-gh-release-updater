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
	 * Constructor
	 *
	 * @param Config    $config Configuration instance.
	 * @param GitHubAPI $github_api GitHub API instance.
	 * @param Updater   $updater Updater instance.
	 */
	public function __construct( /**
  * Config instance
  */
 private $config, /**
  * GitHub API instance
  */
 private $github_api, /**
  * Updater instance
  */
 private $updater ) {
		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks(): void {
		add_action( 'admin_menu', $this->addAdminMenu(...) );
		add_action( 'admin_init', $this->registerSettings(...) );
		add_action( 'admin_enqueue_scripts', $this->enqueueScripts(...) );
		add_action( 'admin_notices', $this->showAdminNotices(...) );

		// Plugin action links on plugins page
		$plugin_basename = $this->config->getPluginBasename();
		add_filter( 'plugin_action_links_' . $plugin_basename, $this->addPluginActionLinks(...) );
		add_filter( 'network_admin_plugin_action_links_' . $plugin_basename, $this->addPluginActionLinks(...) );

		// AJAX handlers
		add_action( 'wp_ajax_' . $this->config->getPluginSlug() . '_check_updates_quick', $this->ajaxQuickCheckForUpdates(...) );
		add_action( 'wp_ajax_' . $this->config->getAjaxTestRepoAction(), $this->ajaxTestRepository(...) );
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
			$this->displaySettingsPage(...)
		);
	}

	/**
	 * Register plugin settings
	 */
	public function registerSettings(): void {
		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'repository_url' ),
			['type'              => 'string', 'sanitize_callback' => $this->sanitizeRepositoryUrl(...), 'default'           => '']
		);

		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'access_token' ),
			['type'              => 'string', 'sanitize_callback' => $this->sanitizeAccessToken(...), 'default'           => '']
		);

		// Add settings sections
		add_settings_section(
			$this->config->getSettingsSection(),
			'Repository Configuration',
			$this->settingsSectionCallback(...),
			$this->config->getSettingsPageSlug()
		);

		// Repository URL field
		add_settings_field(
			'repository_url',
			'Repository URL',
			$this->repositoryUrlFieldCallback(...),
			$this->config->getSettingsPageSlug(),
			$this->config->getSettingsSection()
		);

		// Access token field
		add_settings_field(
			'access_token',
			'Access Token',
			$this->accessTokenFieldCallback(...),
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
			$script_handle = $this->config->getPluginSlug() . '-plugins-page';
			wp_enqueue_script(
				$script_handle,
				$this->config->getUpdaterUrl() . 'admin/js/plugins-page.js',
				[],
				$this->config->getPluginVersion(),
				true
			);

			// Pass plugin slug to JavaScript
			wp_localize_script(
				$script_handle,
				'pluginUpdaterConfig',
				['slug' => $this->config->getPluginSlug()]
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
			[],
			$this->config->getPluginVersion()
		);

		$script_handle = $this->config->getScriptHandle();
		$script_url    = $this->config->getUpdaterUrl() . 'admin/js/admin.js';

		wp_enqueue_script(
			$script_handle,
			$script_url,
			[],
			$this->config->getPluginVersion(),
			true
		);

		// Localize script for AJAX
		// Use plugin-specific variable name to avoid conflicts when multiple plugins use this updater
		$js_var_name = str_replace( '-', '_', $this->config->getPluginSlug() ) . '_GitHubUpdater';
		wp_localize_script(
			$this->config->getScriptHandle(),
			$js_var_name,
			['ajaxUrl'    => admin_url( 'admin-ajax.php' ), 'nonce'      => wp_create_nonce( $this->config->getNonceName() ), 'actions'    => ['testRepo' => $this->config->getAjaxTestRepoAction()], 'strings'    => ['checking'       => 'Checking for updates...', 'updating'       => 'Updating plugin...', 'testing'        => 'Testing repository access...', 'error'          => 'An error occurred. Please try again.', 'confirm_update' => 'Are you sure you want to update the plugin? This action cannot be undone.', 'success'        => 'Operation completed successfully.'], 'pluginSlug' => $this->config->getPluginSlug(), 'varName'    => $js_var_name]
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
		if ( in_array(trim( $token ), ['', '0'], true) ) {
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
			Logger::log(
				$this->config,
				'WARN',
				'Admin',
				'AJAX test-repository request failed security checks.',
				['user_id'    => get_current_user_id(), 'has_nonce'  => '' !== $nonce ? 'yes' : 'no', 'has_access' => current_user_can( $this->config->getCapability() ) ? 'yes' : 'no']
			);

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

		if ( ! $test_api->setRepositoryFromUrl( $repository_url, $access_token ) ) {
			wp_send_json(
				['success' => false, 'message' => 'Invalid repository URL format.']
			);
			return;
		}

		$test_result = $test_api->testRepositoryAccess();

		if ( is_wp_error( $test_result ) ) {
			wp_send_json(
				['success' => false, 'message' => $test_result->get_error_message()]
			);
		} else {
			wp_send_json(
				['success' => true, 'message' => 'Repository access successful!']
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
	 * Add "Check for Updates" action link on plugins page
	 *
	 * @param array $links Existing plugin action links
	 * @return array Modified links
	 */
	public function addPluginActionLinks( $links ): array {
		$check_updates_link = sprintf(
			'<a href="#" class="%s-check-updates" data-plugin="%s" data-nonce="%s">%s</a>',
			esc_attr( $this->config->getPluginSlug() ),
			esc_attr( $this->config->getPluginBasename() ),
			esc_attr( wp_create_nonce( $this->config->getPluginSlug() . '_check_updates_quick' ) ),
			esc_html__( 'Check for Updates', 'default' )
		);

		// Add as first link (before Deactivate)
		return array_merge( ['check_updates' => $check_updates_link], $links );
	}

	/**
  * Handle quick update check from plugins page (AJAX)
  */
	public function ajaxQuickCheckForUpdates(): void {
		// Verify nonce
		check_ajax_referer( $this->config->getPluginSlug() . '_check_updates_quick', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'update_plugins' ) ) {
			Logger::log(
				$this->config,
				'WARN',
				'Admin',
				'AJAX quick-check denied due to insufficient permissions.',
				['user_id' => get_current_user_id()]
			);

			wp_send_json_error( ['message' => 'Insufficient permissions.'] );
		}

		// Perform update check using shared workflow
		$result = $this->updater->checkForUpdatesFresh();

		if ( $result['success'] ) {
			wp_send_json_success(
				['message'          => $result['message'], 'update_available' => $result['update_available'], 'latest_version'   => $result['latest_version']]
			);
		} else {
			Logger::log(
				$this->config,
				'WARN',
				'Admin',
				'AJAX quick-check returned updater failure.',
				['message' => $result['message']]
			);

			wp_send_json_error( ['message' => $result['message']] );
		}
	}
}
