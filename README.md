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
- **Multi-Plugin Safe** - Complete isolation between plugins
- **Public & Private Repos** - Support for both repository types
- **Manual Updates Only** - No automatic background checks
- **Zero Dependencies** - Vanilla JavaScript, no jQuery

## Requirements

- **WordPress** 6.0+
- **PHP** 8.3+
- **Composer** 2.0+
- **ZipArchive** PHP extension

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

Complete isolation between multiple plugins guaranteed!

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

Examples:

```bash
# Uses saved repository URL and saved token from plugin settings
wp uwu-extensions-development test-repo

# Check update status
wp uwu-extensions-development check-updates

# Perform update when available
wp uwu-extensions-development update

# Dry-run update flow (no upgrader execution)
wp uwu-extensions-development update --dry

# Override repository URL for one-off testing
wp uwu-extensions-development test-repo --repository-url=owner/repo

# Override token for one-off testing
wp uwu-extensions-development test-repo --access-token=github_pat_xxx
```

`test-repo` performs the same repository access validation as **Test Repository Access** in the updater settings page.

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

**1. Required Plugin Headers:**

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

**2. Automate Releases with GitHub Actions:**

Automate your release process by adding this workflow to your plugin repository:

**Setup:**
1. Create `.github/workflows/release.yml` in your plugin repository
2. Update `FILE_FOR_VERSION` to your main plugin file (e.g., `my-plugin.php`)
3. Update `SLUG` to your plugin slug (e.g., `my-plugin`)
4. Push to `release` branch to trigger automatic release

**Workflow file (`.github/workflows/release.yml`):**

```yaml
name: Build and Release Plugin

on:
    push:
        branches:
            - release # Trigger on push to release branch

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
              run: npm install

            - name: Build assets
              if: steps.check_npm.outputs.has_npm == 'true'
              run: npm run build

            - name: Determine version and plugin slug
              id: vars
              run: |
                  # Update these variables for your plugin
                  FILE_FOR_VERSION="my-plugin.php"  # Your main plugin file
                  SLUG="my-plugin"                  # Your plugin slug

                  # Extract version from plugin header
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

                  # Copy files, excluding dev dependencies
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

**Usage:**

```bash
# 1. Update version in your plugin header
# Plugin Name: My Plugin
# Version: 1.2.3  ← Update this

# 2. Commit changes
git add my-plugin.php
git commit -m "Bump version to 1.2.3"

# 3. Push to release branch (triggers automatic build)
git push origin main:release
```

**What the workflow does:**
- ✅ Extracts version from plugin header automatically
- ✅ Installs production Composer dependencies
- ✅ Creates clean ZIP package (excludes dev files)
- ✅ Creates git tag with version number
- ✅ Creates GitHub release with auto-generated notes
- ✅ Uploads plugin ZIP as release asset

**Benefits:**
- No manual ZIP building
- Consistent release packages
- Automated version tagging
- One-step release process

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

This package uses automated releases from the `release` branch:

1. **Update Version**: Edit the `VERSION` file with the new version number (e.g., `1.2.3`)
2. **Merge to Release Branch**: Merge or push your changes to the `release` branch
3. **Automatic Process**:
   - GitHub Actions will automatically:
     - Create a git tag
     - Install production dependencies (with `vendor/`)
     - Create a production-ready ZIP file
     - Create a GitHub release with the ZIP attached
     - Update Packagist

### Download Options

**For Composer Users:**
```bash
composer require rajandangi/wp-gh-release-updater
```

**For Direct Download:**
Download the production-ready ZIP file from the [Releases page](https://github.com/rajandangi/wp-gh-release-updater/releases). The ZIP includes all dependencies in the `vendor/` folder, ready to use.

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
