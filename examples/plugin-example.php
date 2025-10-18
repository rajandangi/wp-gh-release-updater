<?php
/**
 * Example Plugin Integration with WP GitHub Updater Manager
 *
 * This is a complete example of how to integrate the updater into your WordPress plugin.
 *
 * @package ExamplePlugin
 */

/**
 * Plugin Name: Example Plugin with GitHub Updates
 * Plugin URI: https://github.com/yourname/example-plugin
 * Description: An example plugin demonstrating GitHub Release Updater integration
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: example-plugin
 * Domain Path: /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Import the GitHub Updater Manager class
use WPGitHubReleaseUpdater\GitHubUpdaterManager;

/**
 * Initialize the GitHub Updater
 *
 * This function creates and returns a singleton instance of the updater.
 * Plugin info is automatically extracted from the file headers above!
 */
function example_plugin_github_updater() {
	static $updater = null;

	if ( null === $updater ) {
		// ULTRA-SIMPLE: Plugin info auto-extracted from file!
		$updater = new GitHubUpdaterManager(
			array(
				// ============================================================
				// REQUIRED PARAMETERS
				// ============================================================

				// Your main plugin file
				'plugin_file' => __FILE__,

				// Menu title shown in WordPress admin
				'menu_title'  => 'GitHub Updater',

				// Page title shown on the settings page
				'page_title'  => 'Example Plugin Updates',

				// ============================================================
				// OPTIONAL PARAMETERS (with defaults shown)
				// ============================================================

				// Parent menu where updater page appears
				// Options: 'tools.php', 'options-general.php', 'plugins.php', etc.
				// 'menu_parent' => 'tools.php',

				// Required capability to access updater
				// 'capability' => 'manage_options',
			)
		);
	}

	return $updater;
}

// ============================================================
// PLUGIN ACTIVATION
// ============================================================

/**
 * Plugin activation hook
 *
 * Sets up default options and initializes the updater.
 */
register_activation_hook(
	__FILE__,
	function () {
		example_plugin_github_updater()->activate();

		// Add your plugin-specific activation code here
		// e.g., create database tables, set default options, etc.
	}
);

// ============================================================
// PLUGIN DEACTIVATION
// ============================================================

/**
 * Plugin deactivation hook
 *
 * Cleans up temporary files.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		example_plugin_github_updater()->deactivate();

		// Add your plugin-specific deactivation code here
		// e.g., clear scheduled events, etc.
	}
);

// ============================================================
// INITIALIZE UPDATER
// ============================================================

/**
 * Initialize the updater on plugins_loaded
 *
 * The updater will:
 * 1. Extract plugin name and version from file headers
 * 2. Create database options with unique prefix
 * 3. Add admin page under Tools menu (or your custom location)
 * 4. Handle all update operations
 */
example_plugin_github_updater();

// ============================================================
// YOUR PLUGIN CODE STARTS HERE
// ============================================================

/**
 * Initialize the plugin
 */
function example_plugin_init() {
	// Your plugin initialization code
	// Register post types, taxonomies, hooks, etc.
}
add_action( 'init', 'example_plugin_init' );

/**
 * Enqueue plugin scripts and styles
 */
function example_plugin_enqueue_assets() {
	// Your plugin's CSS and JavaScript
	wp_enqueue_style(
		'example-plugin-styles',
		plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
		array(),
		'1.0.0'
	);

	wp_enqueue_script(
		'example-plugin-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'example_plugin_enqueue_assets' );

// ============================================================
// HOW THE SLUG IS USED
// ============================================================

/**
 * Understanding how the auto-detected slug creates all prefixes:
 *
 * Filename: example-plugin.php → Auto-detected slug: 'example-plugin'
 *
 * This slug is then used to create:
 *
 * - Database options:  wp_example_plugin_repository_url
 *                      wp_example_plugin_access_token
 *                      wp_example_plugin_last_check_time
 *
 * - AJAX actions:      example_plugin_check
 *                      example_plugin_update
 *                      example_plugin_test_repo
 *
 * - Asset handles:     example-plugin-admin-js
 *                      example-plugin-admin-css
 *
 * - Security nonce:    example_plugin_nonce
 *
 * This ensures complete isolation between different plugins using this updater!
 */

// ============================================================
// ADVANCED: CUSTOM MENU LOCATION
// ============================================================

/**
 * Place updater under a custom admin menu
 */
function example_plugin_github_updater_custom_menu() {
	static $updater = null;

	if ( null === $updater ) {
		$updater = new GitHubUpdaterManager(
			array(
				'plugin_file' => __FILE__,
				'menu_title'  => 'Updates',
				'page_title'  => 'Plugin Updates from GitHub',

				// Place under Settings menu
				'menu_parent' => 'options-general.php',

				// Or place under Plugins menu
				// 'menu_parent'  => 'plugins.php',

				// Or create your own top-level menu first, then use its slug
				// 'menu_parent'  => 'my-custom-menu',
			)
		);
	}

	return $updater;
}

// ============================================================
// ADVANCED: CUSTOM CAPABILITY
// ============================================================

/**
 * Restrict updater access to specific capability
 */
function example_plugin_github_updater_custom_capability() {
	static $updater = null;

	if ( null === $updater ) {
		$updater = new GitHubUpdaterManager(
			array(
				'plugin_file' => __FILE__,
				'menu_title'  => 'GitHub Updater',
				'page_title'  => 'Plugin Updates',

				// Only allow administrators to access updater
				'capability'  => 'manage_options',

				// Or create a custom capability
				// 'capability'  => 'manage_plugin_updates',
			)
		);
	}

	return $updater;
}

// ============================================================
// TESTING THE INTEGRATION
// ============================================================

/**
 * After activating your plugin:
 *
 * 1. Go to Tools → GitHub Updater (or your custom menu location)
 * 2. Enter your GitHub repository: "yourname/example-plugin"
 * 3. (Optional) Add Personal Access Token for private repos
 * 4. Click "Test Repository Access"
 * 5. Click "Check for Updates"
 * 6. If update available, click "Update Now"
 *
 * That's it! The updater handles everything else.
 */

// ============================================================
// RELEASING UPDATES ON GITHUB
// ============================================================

/**
 * To release an update:
 *
 * 1. Update the version in your plugin header (line 11 above)
 * 2. Commit and push your changes
 * 3. Create a new tag: git tag v1.0.1
 * 4. Push the tag: git push origin v1.0.1
 * 5. Create a GitHub release from the tag
 * 6. Build your plugin ZIP (with vendor/ directory included!)
 * 7. Name the ZIP file as your plugin slug: example-plugin.zip
 * 8. Upload the ZIP as a release asset
 *
 * Users will now see the update available in their WordPress admin!
 */

// ============================================================
// BUILD SCRIPT EXAMPLE (build.sh)
// ============================================================

/**
 * Create a build.sh file in your plugin root:
 *
 * #!/bin/bash
 *
 * VERSION="1.0.1"
 * PLUGIN_SLUG="example-plugin"
 *
 * # Install production dependencies
 * composer install --no-dev --optimize-autoloader
 *
 * # Create ZIP
 * zip -r "${PLUGIN_SLUG}.zip" . \
 *   -x "*.git*" \
 *   -x "*node_modules*" \
 *   -x "*.github*" \
 *   -x "*tests*" \
 *   -x "*build.sh*" \
 *   -x "*.phpcs.xml*"
 *
 * echo "✓ Built ${PLUGIN_SLUG}.zip for version ${VERSION}"
 *
 * Make it executable: chmod +x build.sh
 * Run it: ./build.sh
 */
