<?php
/**
 * Main GitHub Updater Manager
 *
 * GitHub Release Updater UTILITY for WordPress plugins.
 * Automatically extracts plugin info - you only provide unique prefixes!
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater Manager Class
 *
 * This is the ONLY class you need to instantiate.
 * Plugin info (including slug) is extracted automatically from your plugin file!
 *
 * Minimal example:
 * new GitHubUpdaterManager([
 *     'plugin_file' => __FILE__,
 *     'menu_title'  => 'GitHub Updater',
 *     'page_title'  => 'My Plugin Updates',
 * ]);
 */
class GitHubUpdaterManager {

	/**
	 * Configuration instance
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * GitHub API instance
	 *
	 * @var GitHubAPI|null
	 */
	private $github_api;

	/**
	 * Updater instance
	 *
	 * @var Updater|null
	 */
	private $updater;

	/**
	 * Admin instance
	 *
	 * @var Admin|null
	 */
	private $admin;

	/**
	 * Whether the manager has been initialized
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * CLI instance.
	 *
	 * @var CLI|null
	 */
	private $cli;

	/**
	 * Constructor
	 *
	 * Plugin info (including slug) is automatically extracted from your plugin file!
	 *
	 * @param array $config_options Configuration options
	 *   Required:
	 *     - plugin_file: string - Your plugin file path (__FILE__)
	 *     - menu_title: string - Menu title (e.g., 'GitHub Updater')
	 *     - page_title: string - Page title (e.g., 'My Plugin Updates')
	 *
	 *   Optional:
	 *     - menu_parent: string (default: 'tools.php')
	 *     - capability: string (default: 'manage_options')
	 *     - cli_command: string (default: '<plugin-directory>')
	 */
	public function __construct( $config_options = array() ) {
		// Extract plugin file
		$plugin_file = $config_options['plugin_file'] ?? null;

		if ( ! $plugin_file ) {
			wp_die( 'GitHubUpdaterManager requires plugin_file parameter' );
		}

		// Create config instance - plugin info extracted automatically!
		$this->config = Config::getInstance( $plugin_file, $config_options );

		// Register hooks
		$this->registerHooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function registerHooks(): void {
		// Activation/deactivation hooks must be registered in main plugin file
		// So we provide public methods for those

		// Initialize on plugins_loaded
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize the updater components
	 *
	 * Called automatically on plugins_loaded hook
	 */
	public function init(): void {
		// Only initialize once
		if ( $this->initialized ) {
			return;
		}

		// Load in admin area or WP-CLI context
		if ( ! is_admin() && ! $this->isCliContext() ) {
			return;
		}

		// Load dependencies
		$this->loadDependencies();

		// Initialize components
		$this->initializeComponents();

		$this->initialized = true;

		// Allow others to hook after initialization
		do_action( 'github_updater_initialized', $this );
	}

	/**
	 * Load required files
	 *
	 * Note: With Composer autoloading, classes are loaded automatically.
	 * This method is kept for backward compatibility but does nothing.
	 */
	private function loadDependencies(): void {
		// Classes are autoloaded by Composer PSR-4
	}

	/**
	 * Initialize all components
	 */
	private function initializeComponents(): void {
		// Initialize GitHub API
		$this->github_api = new GitHubAPI( $this->config );

		// Initialize Updater
		$this->updater = new Updater( $this->config, $this->github_api );

		// Initialize Admin interface
		if ( is_admin() ) {
			$this->admin = new Admin( $this->config, $this->github_api, $this->updater );
		}

		// Register WP-CLI commands
		if ( $this->isCliContext() ) {
			$this->cli = new CLI( $this->config, $this->updater );
			$this->cli->register();
		}
	}

	/**
	 * Check whether execution context is WP-CLI.
	 *
	 * @return bool
	 */
	private function isCliContext(): bool {
		return defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' );
	}

	/**
	 * Activation callback
	 *
	 * Call this from register_activation_hook in your main plugin file
	 */
	public function activate(): void {
		// Create default options
		$default_options = $this->config->getDefaultOptions();

		foreach ( $default_options as $key => $value ) {
			if ( $this->config->getOption( $key ) === false ) {
				$this->config->addOption( $key, $value );
			}
		}

		// Flush rewrite rules if needed
		flush_rewrite_rules();

		// Allow others to hook after activation
		do_action( 'github_updater_activated', $this );
	}

	/**
	 * Deactivation callback
	 *
	 * Call this from register_deactivation_hook in your main plugin file
	 */
	public function deactivate(): void {
		// Clean up temporary files if any
		$upload_dir = wp_upload_dir();
		$temp_files = glob( $upload_dir['basedir'] . '/wp-github-updater-temp-*' );

		if ( $temp_files ) {
			foreach ( $temp_files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		// Allow others to hook after deactivation
		do_action( 'github_updater_deactivated', $this );
	}

	/**
	 * Uninstall callback
	 *
	 * Call this from uninstall hook to completely remove all data
	 */
	public function uninstall(): void {
		// Delete all plugin options
		$option_keys = array_keys( $this->config->getDefaultOptions() );

		foreach ( $option_keys as $key ) {
			$this->config->deleteOption( $key );
		}

		// Clean up temporary files
		$upload_dir = wp_upload_dir();
		$temp_files = glob( $upload_dir['basedir'] . '/wp-github-updater-temp-*' );

		if ( $temp_files ) {
			foreach ( $temp_files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		// Allow others to hook after uninstall
		do_action( 'github_updater_uninstalled', $this );
	}

	/**
	 * Get Config instance
	 *
	 * @return Config
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * Get GitHub API instance
	 *
	 * @return GitHubAPI|null
	 */
	public function getGitHubAPI() {
		return $this->github_api;
	}

	/**
	 * Get Updater instance
	 *
	 * @return Updater|null
	 */
	public function getUpdater() {
		return $this->updater;
	}

	/**
	 * Get Admin instance
	 *
	 * @return Admin|null
	 */
	public function getAdmin() {
		return $this->admin;
	}

	/**
	 * Get CLI instance.
	 *
	 * @return CLI|null
	 */
	public function getCLI() {
		return $this->cli;
	}

	/**
	 * Check if the manager has been initialized
	 *
	 * @return bool
	 */
	public function isInitialized() {
		return $this->initialized;
	}
}
