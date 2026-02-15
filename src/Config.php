<?php
/**
 * Configuration class for GitHub Release Updater Utility
 *
 * This utility automatically extracts ALL plugin information from the host plugin,
 * including the slug from the filename. Zero configuration needed!
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration class
 *
 * Extracts plugin info (including slug) automatically from the consuming plugin.
 * Creates all necessary prefixes from the auto-detected slug.
 */
class Config {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Text domain for translations.
	 *
	 * @var string
	 */
	private $text_domain;

	/**
	 * Updater directory path.
	 *
	 * @var string
	 */
	private $updater_dir;

	/**
  * Updater URL.
  */
 private readonly string $updater_url;

	/**
  * Option prefix for database options.
  */
 private readonly string $option_prefix;

	/**
	 * Asset prefix for script/style handles.
	 *
	 * @var string
	 */
	private $asset_prefix;

	/**
  * Nonce name for security verification.
  */
 private readonly string $nonce_name;

	/**
	 * Parent menu slug for admin page.
	 *
	 * @var string
	 */
	private $menu_parent;

	/**
	 * Menu title for admin page.
	 *
	 * @var string
	 */
	private $menu_title;

	/**
	 * Page title for admin page.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * Capability required to access settings.
	 *
	 * @var string
	 */
	private $capability;

	/**
  * Base WP-CLI command path.
  *
  * Example: my-plugin
  */
 private string $cli_command;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $settings_page_slug;

	/**
  * Settings group name.
  */
 private readonly string $settings_group;

	/**
  * AJAX action name for checking updates.
  */
 private readonly string $ajax_check_action;

	/**
  * AJAX action name for performing updates.
  */
 private readonly string $ajax_update_action;

	/**
  * AJAX action name for testing repository connection.
  */
 private readonly string $ajax_test_repo_action;

	/**
  * AJAX action name for clearing cache.
  */
 private readonly string $ajax_clear_cache_action;

	/**
  * Script handle for enqueuing JavaScript.
  */
 private readonly string $script_handle;

	/**
  * Style handle for enqueuing CSS.
  */
 private readonly string $style_handle;

	/**
	 * Instance registry - keyed by plugin file path
	 * This prevents config collision when multiple plugins use this updater
	 *
	 * @var array<string, Config>
	 */
	private static array $instances = [];

	/**
	 * Get instance for a specific plugin
	 *
	 * @param string $plugin_file Main plugin file path
	 * @param array  $config Configuration options
	 * @return Config
	 */
	public static function getInstance( $plugin_file = null, $config = [] ) {
		if ( ! $plugin_file ) {
			wp_die( 'GitHub Updater: Plugin file is required to get Config instance' );
		}

		// Use realpath to normalize the path for consistent keying
		$key = realpath( $plugin_file );
		if ( false === $key ) {
			$key = $plugin_file; // Fallback if realpath fails
		}

		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $plugin_file, $config );
		}

		return self::$instances[ $key ];
	}

	/**
  * Clear instance for a specific plugin (useful for testing)
  *
  * @param string $plugin_file Main plugin file path
  */
 public static function clearInstance( $plugin_file ): void {
		$key = realpath( $plugin_file );
		if ( false === $key ) {
			$key = $plugin_file;
		}

		unset( self::$instances[ $key ] );
	}

	/**
  * Clear all instances (useful for testing)
  */
 public static function clearAllInstances(): void {
		self::$instances = [];
	}

	/**
	 * Constructor
	 *
	 * Automatically extracts plugin info from the plugin file headers.
	 * Plugin slug is auto-detected from filename.
	 *
	 * @param string $plugin_file Main plugin file path (__FILE__ from consuming plugin)
	 * @param array  $config Configuration options
	 *    Required:
	 *      - menu_title: string - Admin menu title (e.g., 'My Plugin Updates')
	 *      - page_title: string - Admin page title (e.g., 'My Plugin GitHub Updater')
	 *
	 *    Optional:
	 *      - menu_parent: string (default: 'tools.php')
	 *      - capability: string (default: 'manage_options')
	 *      - cli_command: string (default: '<plugin-directory>')
	 */
	private function __construct( $plugin_file = null, $config = [] ) {
		if ( ! $plugin_file || ! file_exists( $plugin_file ) ) {
			wp_die( 'GitHub Updater: Invalid plugin file provided' );
		}

		// Validate required configuration
		$this->validateConfig( $config );

		// Set plugin paths
		$this->plugin_url      = plugin_dir_url( $plugin_file );
		$this->plugin_basename = plugin_basename( $plugin_file );

		// Set updater manager paths (resolved from this file's location)
		$this->updater_dir = trailingslashit( __DIR__ );
		$this->updater_url = $this->resolveUpdaterUrl( $plugin_file );

		// Extract plugin data from file headers
		$plugin_data          = $this->extractPluginData( $plugin_file );
		$this->plugin_name    = $plugin_data['name'];
		$this->plugin_version = $plugin_data['version'];
		$this->text_domain    = $plugin_data['text_domain'];

		// Auto-detect slug from filename (no override option)
		$this->plugin_slug = $plugin_data['slug'];

		// Get WordPress database table prefix
		global $wpdb;
		$db_prefix = $wpdb->prefix;

		// Use plugin slug for all prefixes (guaranteed unique by WordPress)
		$option_suffix = $this->plugin_slug . '_';
		$ajax_prefix   = $this->plugin_slug . '_';
		$asset_prefix  = str_replace( '_', '-', $this->plugin_slug ) . '-';
		$nonce_name    = $this->plugin_slug . '_nonce';

		// Set prefixes (wpdb prefix + plugin slug is sufficient for uniqueness)
		$this->option_prefix = $db_prefix . $option_suffix;
		$this->asset_prefix  = $asset_prefix;
		$this->nonce_name    = $nonce_name;

		// AJAX action names (use local variable, no need to store as property)
		$this->ajax_check_action       = $ajax_prefix . 'check';
		$this->ajax_update_action      = $ajax_prefix . 'update';
		$this->ajax_test_repo_action   = $ajax_prefix . 'test_repo';
		$this->ajax_clear_cache_action = $ajax_prefix . 'clear_cache';

		// Asset handles - use 'updater' suffix to avoid conflicts with plugin's own admin scripts
		$this->script_handle = $asset_prefix . 'updater-admin';
		$this->style_handle  = $asset_prefix . 'updater-admin';

		// Admin menu settings (menu_title and page_title are required)
		$this->menu_parent = $config['menu_parent'] ?? 'tools.php';
		$this->menu_title  = $config['menu_title'];
		$this->page_title  = $config['page_title'];
		$this->capability  = $config['capability'] ?? 'manage_options';

		// WP-CLI command path
		$default_cli_command = $this->buildDefaultCliCommand();
		$requested_command   = $config['cli_command'] ?? $default_cli_command;
		$this->cli_command   = $this->sanitizeCliCommandPath( (string) $requested_command );

		if ( '' === $this->cli_command ) {
			$this->cli_command = $default_cli_command;
		}

		// Generate settings page slug and group from plugin slug
		$this->settings_page_slug = str_replace( '_', '-', $this->plugin_slug ) . '-updater-settings';
		$this->settings_group     = $this->plugin_slug . '_updater_settings';
	}

	/**
  * Build default WP-CLI command path.
  *
  * Uses plugin directory name when available to reduce conflicts between
  * environments like "my-plugin" and "my-plugin-development".
  */
 private function buildDefaultCliCommand(): string {
		$plugin_dir = dirname( $this->plugin_basename );

		if ( '.' === $plugin_dir || '' === $plugin_dir ) {
			$plugin_dir = $this->plugin_slug;
		}

		$plugin_dir = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $plugin_dir ) );
		$plugin_dir = trim( $plugin_dir, '-_' );

		if ( '' === $plugin_dir ) {
			return $this->plugin_slug;
		}

		return $plugin_dir;
	}

	/**
  * Sanitize WP-CLI command path.
  *
  * Allows multi-part command paths (space-separated).
  *
  * @param string $command WP-CLI command path.
  */
 private function sanitizeCliCommandPath( string $command ): string {
		$command = strtolower( trim( $command ) );

		if ( '' === $command ) {
			return '';
		}

		$segments           = preg_split( '/\s+/', $command );
		$sanitized_segments = [];

		foreach ( $segments as $segment ) {
			$segment = preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $segment );
			$segment = trim( (string) $segment, '-_' );

			if ( '' !== $segment ) {
				$sanitized_segments[] = $segment;
			}
		}

		if ( $sanitized_segments === [] ) {
			return '';
		}

		return implode( ' ', $sanitized_segments );
	}

	/**
	 * Validate required configuration
	 *
	 * @param array $config Configuration array
	 */
	private function validateConfig( $config ): void {
		// Slug is optional - will be auto-detected from filename if not provided

		// Check for required menu settings
		if ( empty( $config['menu_title'] ) ) {
			wp_die( 'GitHub Updater Configuration Error: "menu_title" is required' );
		}

		if ( empty( $config['page_title'] ) ) {
			wp_die( 'GitHub Updater Configuration Error: "page_title" is required' );
		}
	}

	/**
	 * Extract plugin data from plugin file headers
	 *
	 * @param string $plugin_file Plugin file path
	 * @return array Plugin data
	 */
	private function extractPluginData( $plugin_file ): array {
		// Get default plugin data
		if ( function_exists( 'get_plugin_data' ) ) {
			$plugin_data = get_plugin_data( $plugin_file, false, false );
		} else {
			// Fallback: parse headers manually
			$plugin_data = $this->parsePluginHeaders( $plugin_file );
		}

		// Extract slug from file name or sanitize plugin name
		$file_name = basename( $plugin_file, '.php' );
		$slug      = sanitize_title( $file_name );

		return ['name'        => $plugin_data['Name'] ?? 'Unknown Plugin', 'version'     => $plugin_data['Version'] ?? '1.0.0', 'text_domain' => $plugin_data['TextDomain'] ?? $slug, 'slug'        => $slug];
	}

	/**
	 * Parse plugin headers manually (fallback)
	 *
	 * @param string $plugin_file Plugin file path
	 * @return array Headers
	 */
	private function parsePluginHeaders( $plugin_file ): array {
		$headers = ['Name'       => 'Plugin Name', 'Version'    => 'Version', 'TextDomain' => 'Text Domain'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file headers, not remote URL
		$file_data   = file_get_contents( $plugin_file, false, null, 0, 8192 );
		$plugin_data = [];

		foreach ( $headers as $key => $value ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $value, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
				$plugin_data[ $key ] = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		return $plugin_data;
	}

	/**
	 * Get plugin slug
	 */
	public function getPluginSlug(): string {
		return $this->plugin_slug;
	}

	/**
	 * Get plugin name
	 */
	public function getPluginName(): string {
		return $this->plugin_name;
	}

	/**
	 * Get plugin version
	 */
	public function getPluginVersion(): string {
		return $this->plugin_version;
	}

	/**
	 * Get plugin URL
	 */
	public function getPluginUrl(): string {
		return $this->plugin_url;
	}

	/**
	 * Get updater manager directory
	 */
	public function getUpdaterDir(): string {
		return $this->updater_dir;
	}

	/**
	 * Get updater manager URL
	 */
	public function getUpdaterUrl(): string {
		return $this->updater_url;
	}

	/**
	 * Get plugin basename
	 */
	public function getPluginBasename(): string {
		return $this->plugin_basename;
	}

	/**
	 * Get option prefix
	 */
	public function getOptionPrefix(): string {
		return $this->option_prefix;
	}

	/**
	 * Get full option name
	 *
	 * @param string $option_name Option name without prefix
	 * @return string Full option name with prefix
	 */
	public function getOptionName( string $option_name ): string {
		return $this->option_prefix . $option_name;
	}

	/**
	 * Get menu parent
	 */
	public function getMenuParent(): string {
		return $this->menu_parent;
	}

	/**
	 * Get menu title
	 */
	public function getMenuTitle(): string {
		return $this->menu_title;
	}

	/**
	 * Get page title
	 */
	public function getPageTitle(): string {
		return $this->page_title;
	}

	/**
	 * Get capability required
	 */
	public function getCapability(): string {
		return $this->capability;
	}

	/**
  * Get base WP-CLI command path.
  */
 public function getCliCommand(): string {
		return $this->cli_command;
	}

	/**
	 * Get settings page slug
	 */
	public function getSettingsPageSlug(): string {
		return $this->settings_page_slug;
	}

	/**
	 * Get settings group
	 */
	public function getSettingsGroup(): string {
		return $this->settings_group;
	}

	/**
	 * Get settings section
	 */
	public function getSettingsSection(): string {
		return $this->plugin_slug . '_main';
	}

	/**
	 * Get AJAX check action
	 */
	public function getAjaxCheckAction(): string {
		return $this->ajax_check_action;
	}

	/**
	 * Get AJAX update action
	 */
	public function getAjaxUpdateAction(): string {
		return $this->ajax_update_action;
	}

	/**
	 * Get AJAX test repo action
	 */
	public function getAjaxTestRepoAction(): string {
		return $this->ajax_test_repo_action;
	}

	/**
	 * Get AJAX clear cache action
	 */
	public function getAjaxClearCacheAction(): string {
		return $this->ajax_clear_cache_action;
	}

	/**
	 * Get nonce name
	 */
	public function getNonceName(): string {
		return $this->nonce_name;
	}

	/**
	 * Get text domain
	 */
	public function getTextDomain(): string {
		return $this->text_domain;
	}

	/**
	 * Get script handle
	 */
	public function getScriptHandle(): string {
		return $this->script_handle;
	}

	/**
	 * Get style handle
	 */
	public function getStyleHandle(): string {
		return $this->style_handle;
	}

	/**
	 * Get asset prefix
	 */
	public function getAssetPrefix(): string {
		return $this->asset_prefix;
	}

	/**
	 * Get all default options
	 *
	 * @return array Default options
	 */
	public function getDefaultOptions(): array {
		return ['repository_url'   => '', 'access_token'     => '', 'last_checked'     => 0, 'latest_version'   => '', 'update_available' => false];
	}

	/**
	 * Get option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $default_value Default value
	 * @return mixed Option value
	 */
	public function getOption( string $option_name, mixed $default_value = false ) {
		return get_option( $this->getOptionName( $option_name ), $default_value );
	}

	/**
	 * Update option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $value Option value
	 * @return bool Success status
	 */
	public function updateOption( string $option_name, mixed $value ) {
		return update_option( $this->getOptionName( $option_name ), $value );
	}

	/**
	 * Add option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $value Option value
	 * @return bool Success status
	 */
	public function addOption( string $option_name, mixed $value ) {
		return add_option( $this->getOptionName( $option_name ), $value );
	}

	/**
	 * Delete option value
	 *
	 * @param string $option_name Option name without prefix
	 * @return bool Success status
	 */
	public function deleteOption( string $option_name ) {
		return delete_option( $this->getOptionName( $option_name ) );
	}

	/**
	 * Encrypt sensitive data using WordPress salts
	 *
	 * Uses AES-256-CBC encryption with WordPress authentication salts as key material.
	 *
	 * @param string $data Data to encrypt
	 * @return string Encrypted data (base64: encrypted::iv) or empty string on failure
	 */
	public function encrypt( $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$method    = 'AES-256-CBC';
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
		$encrypted = openssl_encrypt( $data, $method, $this->getEncryptionKey(), 0, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for encryption, not code obfuscation
		return $encrypted ? base64_encode( $encrypted . '::' . base64_encode( $iv ) ) : '';
	}

	/**
	 * Decrypt sensitive data
	 *
	 * @param string $encrypted_data Encrypted data (base64 encoded)
	 * @return string Decrypted data or empty string on failure
	 */
	public function decrypt( $encrypted_data ): string {
		if ( empty( $encrypted_data ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not code obfuscation
		$decoded = base64_decode( $encrypted_data, true );
		if ( $decoded === '' || $decoded === '0' || $decoded === false || !str_contains( $decoded, '::' ) ) {
			return '';
		}

		[$encrypted, $iv_encoded] = explode( '::', $decoded, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not code obfuscation
		$iv = base64_decode( $iv_encoded, true );

		if ( $iv === '' || $iv === '0' || $iv === false ) {
			return '';
		}

		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $this->getEncryptionKey(), 0, $iv );
		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Get encryption key from WordPress salts
	 *
	 * @return string Encryption key (32 bytes for AES-256)
	 */
	private function getEncryptionKey(): string {
		// Use WordPress authentication salts to create a unique key
		// This ensures the key is unique per WordPress installation
		$salt_keys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'];

		$key_material = '';
		foreach ( $salt_keys as $salt_key ) {
			if ( defined( $salt_key ) ) {
				$key_material .= constant( $salt_key );
			}
		}

		// If no salts defined, fall back to wp_salt
		if ( $key_material === '' || $key_material === '0' ) {
			$key_material = wp_salt( 'auth' );
		}

		// Hash to get consistent 32-byte key for AES-256
		return hash( 'sha256', $key_material, true );
	}

	/**
	 * Save encrypted access token
	 *
	 * @param string $token Access token to encrypt and save
	 * @return bool Success status
	 */
	public function saveAccessToken( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token || '0' === $token ) {
			// If token is empty, delete the option
			return $this->deleteOption( 'access_token' );
		}

		$existing_encrypted = (string) $this->getOption( 'access_token', '' );
		if ( '' !== $existing_encrypted && hash_equals( $existing_encrypted, $token ) ) {
			return true;
		}

		// If an encrypted value is passed in, store it directly.
		if ( '' !== $this->decrypt( $token ) ) {
			return $this->updateOption( 'access_token', $token );
		}

		$encrypted_token = $this->encrypt( $token );
		return $this->updateOption( 'access_token', $encrypted_token );
	}

	/**
	 * Get decrypted access token
	 *
	 * @return string Decrypted access token
	 */
	public function getAccessToken(): string {
		$encrypted_token = $this->getOption( 'access_token', '' );

		if ( empty( $encrypted_token ) ) {
			return '';
		}

		return $this->decrypt( $encrypted_token );
	}

	/**
	 * Get cache key prefix for GitHub API caching
	 *
	 * @return string Cache key prefix
	 */
	public function getCachePrefix(): string {
		return $this->option_prefix . 'github_cache_';
	}

	/**
	 * Get cache duration in seconds
	 * Default: 60 seconds (1 minute)
	 *
	 * @return int Cache duration in seconds
	 */
	public function getCacheDuration(): int {
		// Fixed 1-minute cache as per requirements
		return 60;
	}

	/**
  * Resolve the updater URL from __DIR__ relative to the plugin root.
  *
  * Works regardless of whether the package lives in vendor/ or vendor_prefixed/.
  *
  * @param string $plugin_file Main plugin file path.
  */
 private function resolveUpdaterUrl( string $plugin_file ): string {
		$plugin_dir_real = realpath( dirname( $plugin_file ) );
		$plugin_dir      = wp_normalize_path( false !== $plugin_dir_real ? $plugin_dir_real : dirname( $plugin_file ) );

		$updater_dir_real = realpath( __DIR__ );
		$updater_dir      = wp_normalize_path( false !== $updater_dir_real ? $updater_dir_real : __DIR__ );

		$plugin_dir_prefix = trailingslashit( untrailingslashit( $plugin_dir ) );

		// Get the relative path from the plugin root to this file's directory.
		if ( str_starts_with( trailingslashit( $updater_dir ), $plugin_dir_prefix ) ) {
			$relative = ltrim( substr( $updater_dir, strlen( untrailingslashit( $plugin_dir ) ) ), '/' );
			return trailingslashit( plugin_dir_url( $plugin_file ) . $relative );
		}

		// Fallback: resolve from this file path directly.
		return trailingslashit( plugin_dir_url( __FILE__ ) );
	}
}
