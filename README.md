# WP GitHub Release Updater

A lightweight Composer library that enables manual GitHub release updates for WordPress plugins with automatic slug detection and zero configuration.

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-blue)](https://php.net)
[![CI](https://github.com/rajandangi/wp-gh-release-updater/actions/workflows/ci.yml/badge.svg)](https://github.com/rajandangi/wp-gh-release-updater/actions/workflows/ci.yml)
[![Release](https://github.com/rajandangi/wp-gh-release-updater/actions/workflows/release.yml/badge.svg)](https://github.com/rajandangi/wp-gh-release-updater/actions/workflows/release.yml)

## Features

- **One-Line Integration** - Single class instantiation with auto-detection
- **Automatic Slug Detection** - Extracts slug from plugin filename
- **Encrypted Token Storage** - Secure token encryption using WordPress salts (AES-256-CBC)
- **Data Isolation** - Each plugin gets its own options, AJAX actions, and asset handles
- **Namespace Scoping Ready** - Works with [PHP-Scoper](https://github.com/humbug/php-scoper) to prevent class collisions
- **Public & Private Repos** - Support for both repository types
- **Manual Updates Only** - No automatic background checks
- **Zero Dependencies** - Vanilla JavaScript, no jQuery

## Requirements

- **WordPress** 6.0+
- **PHP** 8.3+
- **Composer** 2.0+

## Installation

Install via Composer in your WordPress plugin directory:

```bash
composer require rajandangi/wp-gh-release-updater
```

## Quick Start

### Integration

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
use WPGitHubReleaseUpdater\GitHubUpdaterManager;

/**
 * Initialize GitHub Updater
 */
function my_plugin_github_updater() {
    static $updater = null;

    if (null === $updater) {
        $updater = new GitHubUpdaterManager([
            'plugin_file' => __FILE__,
            'menu_title'  => 'GitHub Updater',
            'page_title'  => 'My Plugin Updates',
        ]);
    }

    return $updater;
}

// Initialize updater
my_plugin_github_updater();

// Use activation/deactivation hooks
register_activation_hook(__FILE__, function() {
    my_plugin_github_updater()->activate();
});

register_deactivation_hook(__FILE__, function() {
    my_plugin_github_updater()->deactivate();
});
```

### Configuration Options

**Required Parameters:**
- `plugin_file` (string) - Path to main plugin file (`__FILE__`)
- `menu_title` (string) - Admin menu title
- `page_title` (string) - Settings page title

**Optional Parameters:**
- `menu_parent` (string) - Parent menu location (default: `'tools.php'`)
- `capability` (string) - Required capability (default: `'manage_options'`)
- `cli_command` (string) - Base WP-CLI command path (default: `<plugin-directory>`)

### How Auto-Detection Works

Plugin slug is extracted from filename:
- `my-awesome-plugin.php` → `my-awesome-plugin`
- `woo-extension.php` → `woo-extension`

This slug creates unique prefixes:
- **Database**: `wp_{slug}_repository_url`, `wp_{slug}_access_token`
- **AJAX**: `{slug}_check`, `{slug}_update`, `{slug}_test_repo`
- **Assets**: `{slug}-admin-js`, `{slug}-admin-css`
- **Nonces**: `{slug}_nonce`

This gives each plugin its own database entries, AJAX endpoints, and script handles.

> **Important:** Data isolation does not prevent PHP class collisions. If two plugins bundle this package at different versions without namespace scoping, only one version of each class will be loaded (whichever plugin loads first). See [Avoiding Class Collisions](#avoiding-class-collisions-with-php-scoper) below.

## Usage

### For End Users

1. Go to **Tools → GitHub Updater**
2. Enter GitHub repository (e.g., `owner/repo`)
3. (Optional) Add Personal Access Token for private repos
4. Click **Test Repository Access** to verify
5. Click **Check for Updates** to see available versions
6. Click **Update Now** to install

### WP-CLI

Each plugin instance registers a unique command path:

```bash
wp <plugin-directory> <command>
```

Available commands:

- `test-repo` - same behavior as **Test Repository Access** in wp-admin
- `check-updates` - checks GitHub for latest release and prints status
- `update` - performs plugin update using WordPress upgrader flow
- `decrypt-token` - decrypts and prints the currently stored access token (sensitive output)

Examples:

```bash
# Uses saved repository URL and saved token from plugin settings
wp mu-plugin-updater test-repo

# Check update status
wp mu-plugin-updater check-updates

# Perform update when available
wp mu-plugin-updater update

# Dry-run update flow (no upgrader execution)
wp mu-plugin-updater update --dry

# Decrypt and print the saved token (sensitive)
wp mu-plugin-updater decrypt-token

# Print only the raw decrypted token
wp mu-plugin-updater decrypt-token --raw

# Override repository URL for one-off testing
wp mu-plugin-updater test-repo --repository-url=owner/repo

# Override token for one-off testing
wp mu-plugin-updater test-repo --access-token=github_pat_xxx
```

`test-repo` performs the same repository access validation as **Test Repository Access** in the updater settings page.

`decrypt-token` is intended for debugging. Treat command output as sensitive and avoid sharing it in logs.

To customize the base command and avoid naming conflicts, pass `cli_command` when creating the manager:

```php
new GitHubUpdaterManager([
    'plugin_file' => __FILE__,
    'menu_title'  => 'GitHub Updater',
    'page_title'  => 'Plugin Updates',
    'cli_command' => 'my-plugin-updater',
]);
```

### For Developers

**Required Plugin Headers:**

```php
<?php
/**
 * Plugin Name: Your Plugin Name    ← Required
 * Version: 1.0.0                   ← Required
 * Plugin URI: https://example.com
 * Description: Plugin description
 * Author: Your Name
 * License: GPL v2 or later
 */
```

The updater reads `Plugin Name` and `Version` from these headers automatically. See [Release Process](#release-process) for automating builds with GitHub Actions.

## Avoiding Class Collisions with PHP-Scoper

### The Problem

WordPress loads all active plugins into a single PHP process. If two plugins both `require` this package, PHP will only load the first version of each class it encounters (e.g., `WPGitHubReleaseUpdater\GitHubUpdaterManager`). The second plugin silently gets the wrong version.

This causes real issues:

- **Plugin A** bundles updater **v1.4** (has `CLI.php`, `Logger.php`)
- **Plugin B** bundles updater **v1.2** (missing those files)
- If Plugin B loads first, Plugin A's WP-CLI commands and logging silently break
- No error is thrown — PHP simply uses the already-loaded class

This is a fundamental WordPress limitation affecting **every** Composer package shared across plugins (not just this one). The built-in data isolation (unique option names, AJAX actions, etc.) does **not** solve this — the PHP classes themselves still collide.

### The Solution: PHP-Scoper

[PHP-Scoper](https://github.com/humbug/php-scoper) rewrites the package's namespace at build time so each plugin gets its own copy of the classes. Plugin A uses `PluginAVendor\WPGitHubReleaseUpdater\*` and Plugin B uses `PluginBVendor\WPGitHubReleaseUpdater\*` — no collision possible.

#### Step 1: Install PHP-Scoper

```bash
composer require --dev humbug/php-scoper
```

#### Step 2: Create `scoper.inc.php`

```php
<?php

declare(strict_types=1);

$finder = 'Isolated\\Symfony\\Component\\Finder\\Finder';

return [
    'prefix' => 'MyPluginVendor', // Choose a unique prefix for your plugin

    'finders' => [
        $finder::create()
            ->files()
            ->ignoreVCS(true)
            ->in(__DIR__ . '/vendor/rajandangi/wp-gh-release-updater/src'),
    ],

    // Don't prefix WordPress global functions, classes, and constants
    'expose-global-constants' => true,
    'expose-global-classes'   => true,
    'expose-global-functions' => true,

    // Don't prefix WordPress-bundled namespaces
    'exclude-namespaces' => [
        'WP_CLI',
        'WpOrg',
        'PHPMailer',
        'Automattic',
    ],
];
```

#### Step 3: Add Composer scripts

```json
{
    "autoload": {
        "psr-4": {
            "MyPluginVendor\\WPGitHubReleaseUpdater\\": "vendor_prefixed/rajandangi/wp-gh-release-updater/"
        }
    },
    "scripts": {
        "scope-updater": "php -d memory_limit=512M ./vendor/bin/php-scoper add-prefix --config=scoper.inc.php --output-dir=vendor_prefixed/rajandangi/wp-gh-release-updater --force",
        "post-install-cmd": "@scope-updater",
        "post-update-cmd": "@scope-updater"
    }
}
```

#### Step 4: Update your import

```php
// Before (unscoped — vulnerable to collisions)
use WPGitHubReleaseUpdater\GitHubUpdaterManager;

// After (scoped — fully isolated)
use MyPluginVendor\WPGitHubReleaseUpdater\GitHubUpdaterManager;
```

#### Step 5: Run the scoper

```bash
composer run scope-updater
```

This generates `vendor_prefixed/` with all classes rewritten under your prefix. Commit this directory to your repository so it's available in production without requiring PHP-Scoper at runtime.

### Key Points

- The `expose-global-*` flags prevent PHP-Scoper from prefixing WordPress core symbols (`WP_Error`, `add_action`, `ABSPATH`, etc.)
- The `exclude-namespaces` list keeps WP-CLI and other WordPress-bundled namespaces intact
- Run `composer install --no-dev --no-scripts` in CI/CD to skip scoping (since `vendor_prefixed/` is already committed)
- Each plugin should use a **different prefix** (e.g., `AcmeVendor`, `MyPluginVendor`)

### Without PHP-Scoper

If you are certain no other active plugin on your site uses this package, you can skip scoping and use the classes directly. The data isolation (unique option names, AJAX actions, nonces) still works. Just be aware that adding another plugin with this package later will cause silent class collisions.

## Security

- **Token Encryption**: AES-256-CBC with WordPress salts
- **Strict Validation**: GitHub domains only, no token leaks
- **Permissions**: Capability checks, nonce verification, input sanitization

## Advanced Examples

### Custom Menu Location

```php
// Place under Settings menu
new GitHubUpdaterManager([
    'plugin_file' => __FILE__,
    'menu_title'  => 'Updates',
    'page_title'  => 'Plugin Updates',
    'menu_parent' => 'options-general.php', // Settings menu
]);

// Or under Plugins menu
// 'menu_parent' => 'plugins.php',
```

### Custom Capability

```php
// Restrict to specific capability
new GitHubUpdaterManager([
    'plugin_file' => __FILE__,
    'menu_title'  => 'GitHub Updater',
    'page_title'  => 'Plugin Updates',
    'capability'  => 'manage_plugin_updates', // Custom capability
]);
```

## Development

```bash
# Install dependencies
composer install
npm install

# Run quality checks
composer qa        # All checks (PHPCS, PHPStan, Rector, Biome)
npm run biome:check # JavaScript/CSS linting

# Auto-fix issues
composer fix       # Fix all auto-fixable issues
npm run biome:fix  # Fix JS/CSS issues
```

## Release Process

Automate your plugin's release with GitHub Actions. Push to the `release` branch and the workflow handles everything — version extraction, packaging, tagging, and publishing.

### Setup

1. Create `.github/workflows/release.yml` in your plugin repository
2. Update `FILE_FOR_VERSION` to your main plugin file (e.g., `my-plugin.php`)
3. Update `SLUG` to your plugin slug (e.g., `my-plugin`)
4. Push to `release` branch to trigger

### Workflow File

`.github/workflows/release.yml`:

```yaml
name: Build and Release Plugin

on:
    push:
        branches:
            - release

permissions:
    contents: write

jobs:
    build-release:
        name: Build and Create Release
        runs-on: ubuntu-latest

        steps:
            - name: Checkout repository
              uses: actions/checkout@v4

            - name: Set up PHP
              uses: shivammathur/setup-php@v2
              with:
                  php-version: "8.3"
                  tools: composer:v2

            - name: Install production dependencies
              run: composer install --no-dev --prefer-dist --optimize-autoloader

            - name: Check for npm project
              id: check_npm
              run: |
                  if [ -f "package.json" ] && ([ -f "package-lock.json" ]); then
                    echo "has_npm=true" >> "$GITHUB_OUTPUT"
                  else
                    echo "has_npm=false" >> "$GITHUB_OUTPUT"
                  fi

            - name: Setup Node.js
              if: steps.check_npm.outputs.has_npm == 'true'
              uses: actions/setup-node@v4
              with:
                  node-version: "lts/*"
                  cache: "npm"

            - name: Install npm dependencies
              if: steps.check_npm.outputs.has_npm == 'true'
              run: npm ci

            - name: Build assets
              if: steps.check_npm.outputs.has_npm == 'true'
              run: npm run build

            - name: Determine version and plugin slug
              id: vars
              run: |
                  # Update these variables for your plugin
                  FILE_FOR_VERSION="my-plugin.php"  # Your main plugin file
                  SLUG="my-plugin"                  # Your plugin slug

                  VERSION=$(grep -iE '^\s*\*?\s*Version:' "$FILE_FOR_VERSION" | head -n1 | sed -E 's/^.*Version:\s*//I' | tr -d '\r' | sed 's/\s*$//')

                  if [[ -z "$VERSION" ]]; then
                    echo "Error: Could not determine plugin version"
                    exit 1
                  fi

                  echo "version=${VERSION}" >> "$GITHUB_OUTPUT"
                  echo "slug=${SLUG}" >> "$GITHUB_OUTPUT"
                  echo "zip=${SLUG}.zip" >> "$GITHUB_OUTPUT"

            - name: Create plugin package
              run: |
                  mkdir -p "release-package/${{ steps.vars.outputs.slug }}"

                  rsync -a --delete \
                    --exclude '.git' \
                    --exclude '.github' \
                    --exclude 'node_modules' \
                    --exclude 'tests' \
                    --exclude '.gitignore' \
                    --exclude 'composer.json' \
                    --exclude 'composer.lock' \
                    --exclude 'phpcs.xml' \
                    --exclude 'phpstan.neon' \
                    --exclude 'rector.php' \
                    --exclude 'biome.json' \
                    --exclude 'package.json' \
                    --exclude 'package-lock.json' \
                    --exclude 'webpack.config.js' \
                    --exclude 'release-package' \
                    ./ "release-package/${{ steps.vars.outputs.slug }}/"

            - name: Create ZIP file
              working-directory: release-package/${{ steps.vars.outputs.slug }}
              run: zip -r "../${{ steps.vars.outputs.zip }}" .

            - name: Create and push tag
              run: |
                  git config user.name "github-actions[bot]"
                  git config user.email "github-actions[bot]@users.noreply.github.com"
                  git tag -a "v${{ steps.vars.outputs.version }}" -m "Release v${{ steps.vars.outputs.version }}"
                  git push origin "v${{ steps.vars.outputs.version }}"

            - name: Create GitHub Release
              uses: softprops/action-gh-release@v2
              with:
                  tag_name: v${{ steps.vars.outputs.version }}
                  name: v${{ steps.vars.outputs.version }}
                  body: "Release version ${{ steps.vars.outputs.version }}"
                  files: release-package/${{ steps.vars.outputs.zip }}
                  generate_release_notes: true
                  draft: false
                  prerelease: false
              env:
                  GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

### How to Release

```bash
# 1. Update version in your plugin header
# Version: 1.2.3  ← Update this

# 2. Commit and push to release branch
git add my-plugin.php
git commit -m "Bump version to 1.2.3"
git push origin main:release
```

The workflow will automatically:
- Extract the version from your plugin header
- Install production Composer dependencies
- Build frontend assets (if `package.json` exists)
- Create a clean ZIP package (excludes dev files)
- Tag the release and publish it on GitHub

### Download Options

**Via Composer:**
```bash
composer require rajandangi/wp-gh-release-updater
```

**Direct Download:**
Grab the production-ready ZIP from the [Releases page](https://github.com/rajandangi/wp-gh-release-updater/releases). It includes `vendor/` — ready to use.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run QA checks (`composer qa`)
4. Commit changes (`git commit -m 'Add amazing feature'`)
5. Push to branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## License

GPL v2 or later

## Support

- **Issues**: [GitHub Issues](https://github.com/rajandangi/wp-github-release-updater/issues)
- **Changelog**: See [CHANGELOG.md](CHANGELOG.md)
