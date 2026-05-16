# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-contained Composer library (`rajandangi/wp-gh-release-updater`, PSR-4 namespace `WPGitHubReleaseUpdater\`) that WordPress plugins bundle to get manual GitHub release updates. **It is not itself a WordPress plugin** — it has no entry plugin file. Consumers instantiate `GitHubUpdaterManager` from their own plugin's main file. PHP `>=8.3` required.

## Commands

Composer scripts (defined in `composer.json`):

- `composer test` — PHPUnit (bootstrap: `tests/bootstrap.php`, suite scans `tests/`)
- `composer phpcs` / `composer phpcbf` — WPCS via `phpcs.xml` (PHP 8.3, scans `src/`)
- `composer phpstan` — level 6 against `src/`, uses `vendor/php-stubs/wordpress-stubs`
- `composer rector` (dry run) / `composer rector-fix` — `rector.php`, targets PHP 8.3, skips `src/admin/views/`
- `composer qa` — phpcs + phpstan + rector + biome:check
- `composer fix` — phpcbf + rector-fix + biome:fix

Frontend (JS/CSS lives under `src/admin/`):

- `npm run biome:check` / `npm run biome:fix` — Biome over `src/admin/js src/admin/css`

Single test: `vendor/bin/phpunit --filter <TestMethodName>` or `vendor/bin/phpunit tests/CLITest.php`.

`phpunit.xml` runs with `failOnRisky="true"` and `failOnWarning="true"` — risky/warning tests fail the suite.

## Local integration testing — Makefile

GitHub workflows exist under `.github/workflows/` (branch-scoped, not every push): `ci.yml` (lint/static-analysis/PHPUnit, runs on push to `main`/`develop`), `auto-fix.yml` (auto-formatter, runs on `fix/**` / `feature/**` plus manual dispatch), and `release.yml` (tag → packaged release, runs on the `release` branch). The Makefile is for the **end-to-end WordPress update flow** that CI cannot exercise — it drives a local WordPress site via Herd and WP-CLI to verify the package actually upgrades a plugin from a real GitHub release. The Makefile expects a sibling checkout at `/Users/rajandangi/Herd/wp-gh-release-updater-test` containing the test plugin `wp-github-release-updater`. Override with env vars (`WP_TEST_DIR`, `TEST_PLUGIN_SLUG`, …).

- `make sync-test-plugin` — rsync this package into the test plugin's `vendor/rajandangi/wp-gh-release-updater/`, then `composer dump-autoload` **only if the test plugin has a root `composer.json`** (release-installed plugin zips skip this step and rely on their packaged autoloader), then activate the plugin
- `make test-cli-commands` — smoke test: `--help`, `register-cli`, `test-repo`, `check-updates`, `update --dry`, `decrypt-token`. Does **not** run a real `update`
- `make test-release-update` — sync, lower the test plugin's `Version:` header, then run the full update flow end-to-end against a real GitHub release (this is the only target that exercises `update` for real)

If `LOCAL_TEST_CONTEXT.md` exists, read it before running local integration or browser smoke tests. It documents machine-local test fixtures that should not be committed.

WP-CLI commands the package registers (default base = plugin directory slug, override via `cli_command` in config):

- `wp <slug> test-repo` — validate repo access
- `wp <slug> check-updates` — query latest release
- `wp <slug> update [--dry]` — run WordPress upgrader flow
- `wp <slug> decrypt-token [--raw]` — debug only; prints decrypted token
- `wp <slug> register` — **incidentally exposed**. `CLI::register()` is `public` so WP-CLI picks it up as a subcommand even though its job is wiring `add_command` at boot. Not part of the documented API. Either treat it as internal-only or make it non-public in a follow-up.

## Architecture

`GitHubUpdaterManager` is the single public entry point and acts as a small DI container. Consumer code does only:

```php
new GitHubUpdaterManager(['plugin_file' => __FILE__, ...]);
```

Object graph built in `initializeComponents()` (`src/GitHubUpdaterManager.php`):

- **`Config`** (`src/Config.php`) — **Per-plugin singleton/registry**: private constructor, retrieved via `Config::getInstance($plugin_file, $options)`. Instance key is `realpath($plugin_file)`, so `clearInstance($plugin_file)` / `clearAllInstances()` exist as test-reset hooks — note the arg is the plugin **file path**, not the slug (see Tests section). Auto-derives plugin slug from the `plugin_file` basename and uses it to namespace **everything**: option names (`wp_{slug}_repository_url`, `wp_{slug}_access_token`), AJAX action handles, asset handles, nonce names, cache keys, default WP-CLI command, settings page slug. Also owns AES-256-CBC encrypt/decrypt of the GitHub token using WordPress salts (`AUTH_KEY`+`SECURE_AUTH_KEY`) — see `getEncryptionKey()`, `encrypt()`, `decrypt()`, `saveAccessToken()`, `getAccessToken()`. **Two plugins sharing this class cannot share state** because every key is slug-prefixed.
  - **Refactor target.** `Config` is doing a lot in one file: WP options I/O (`get_option`/`update_option`/`add_option`/`delete_option`), AES-256-CBC token crypto, slug derivation, CLI-command sanitization, and menu config. If you touch this area, that's the deepening opportunity. (If you run `graphify` locally — output gitignored under `graphify-out/` — it quantifies the smell: 52 edges, betweenness 0.436, cohesion 0.05.)
- **`GitHubAPI`** (`src/GitHubAPI.php`) — Owns HTTP to GitHub (`getLatestRelease`, `testRepositoryAccess`), URL parsing (`parseRepositoryUrl` accepts `owner/repo`, full URLs, `.git` suffix), and a transient-backed response cache (`getCachedResponse`/`setCachedResponse`, key derived from URL hash, duration from `Config::getCacheDuration()`).
- **`Updater`** (`src/Updater.php`) — Hooks into WordPress' update transient (`injectUpdateInfo` on `pre_set_site_transient_update_plugins`) and the upgrader pipeline (`handlePreDownload`, `fixSourceDirectory` rename the extracted dir back to the plugin slug, `clearCacheAfterUpdate`). Asset selection logic: `findMatchingAsset` → `findDownloadAsset` → `resolveAssetDownloadUrl` (handles private-repo asset URLs that need the `Authorization` header). Version comparison via `isUpdateAvailable` + `extractVersionFromTag` + `isPreRelease`.
- **`Admin`** (`src/Admin.php`) — Settings page, repository-URL/token fields, AJAX handlers (`ajaxTestRepository`, `ajaxQuickCheckForUpdates`), and the "Check for Updates" plugin-row action link. Skipped entirely in WP-CLI context.
- **`CLI`** (`src/CLI.php`) — Registered only when `defined('WP_CLI') && WP_CLI`. Methods named with snake_case (`test_repo`, `check_updates`, `update`, `decrypt_token`) because WP-CLI uses method names as subcommand names — do not rename to camelCase.
- **`Logger`** (`src/Logger.php`) — `error_log` wrapper gated on `WP_DEBUG`. It's the canonical observability seam: called from `Updater` (`checkForUpdatesWithApi`, `findDownloadAsset`, `resolveAssetDownloadUrl`, `handlePreDownload`, `fixSourceDirectory`), `Admin` (`ajaxTestRepository`, `ajaxQuickCheckForUpdates`), and `GitHubAPI::makeRequest`. Reach for `Logger::log()` rather than `error_log()` directly so debug output stays gated consistently.

`GitHubUpdaterManager::activate()` / `deactivate()` / `uninstall()` are the lifecycle hooks the consumer plugin must wire to `register_activation_hook`/etc. — the library cannot register them itself because it has no main plugin file.

## Class-collision constraint (read before refactoring public API)

Because every plugin that bundles this library autoloads the same `WPGitHubReleaseUpdater\*` classes, **the first plugin to load wins** — its version of each class is the one PHP uses everywhere. Consumers are expected to mitigate with PHP-Scoper (README §"Avoiding Class Collisions"), which rewrites the namespace to e.g. `MyPluginVendor\WPGitHubReleaseUpdater\`. Implications:

- Don't add singletons keyed by class name — they'd collide across plugins.
- Renames/removals of public classes or methods are silent breakages for older bundled copies. Treat the public surface (`GitHubUpdaterManager`, `Config`, `GitHubAPI`, `Updater`, `Admin`, `CLI`, `Logger`) as load-bearing.
- The data-isolation slug prefix is independent of scoping; both are needed.

## Tests

`tests/bootstrap.php` loads `vendor/autoload.php` then `tests/constants.php`, which defines a large set of WordPress function/class stubs (`add_action`, `wp_remote_get`, `WP_Error`, `WP_CLI`, options, transients) plus globals (`$GLOBALS['wp_gh_updater_test_transients']`, `$GLOBALS['wp_gh_updater_test_http_response']`). Tests mutate these globals to drive behavior — when writing a new test, reset the relevant global in `setUp`/`tearDown` rather than introducing real WP. `WP_CLI::error` is stubbed to throw `WPCLITestException` so CLI tests can assert on failure paths.

**Reset the `Config` singleton between tests.** Because `Config::getInstance()` caches one instance per plugin file path (`realpath($plugin_file)`), leaking state across cases causes flaky tests. `CLITest::setUp/tearDown` already call `Config::clearAllInstances()` (and `WP_CLI::reset()`); mirror that pattern in any new test that constructs a `Config`, `GitHubUpdaterManager`, or anything downstream.

## Coding standards (when writing code)

- `declare(strict_types=1);` at top of new PHP files.
- Use tabs for PHP indent (WPCS); JS/CSS uses tabs at width 4 (`biome.json`).
- Short array syntax is allowed; PSR-4 file naming (no `class-` prefix); camelCase methods are allowed except where WP-CLI requires snake_case subcommand names.
- PHPStan ignores listed in `phpstan.neon` exist to paper over WordPress stubs (`WP_Error`, `wp_send_json_*` unreachable-after-exit) — don't widen them blindly; fix the type instead.
- Rector is configured with `UP_TO_PHP_83` + `STRICT_BOOLEANS` + `PRIVATIZATION` sets. Expect it to push toward typed properties, early returns, and privatized members.
