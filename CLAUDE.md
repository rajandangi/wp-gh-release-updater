# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠ Scope Guard (READ FIRST — applies to every change you propose)

This package is a **thin bridge between GitHub Releases and the WordPress plugin updater**. It is not a replacement for any part of WordPress core. Before adding, accepting, or planning ANY code in this repo, apply this heuristic:

> If the failure mode is generic to "fetch a ZIP and replace plugin files safely," **WordPress already handles it**. Add code only when the failure mode is one of:
>
> 1. **GitHub-specific** (release semantics, tag parsing, asset auth, signed URLs)
> 2. **Auth-specific** (encrypted PAT storage, Authorization header injection, redaction)
> 3. **Coordination across multiple instances of this package** (browser-global collisions, update lock when WP doesn't lock per-plugin)

### Package legitimately owns

1. GitHub release discovery, version extraction from tag names, pre-release filtering.
2. Private-repo signed-asset download (Authorization header, asset URL resolution).
3. Encrypted GitHub PAT storage (AES-256-CBC envelope, WP salt-derived key).
4. Auth-aware HTTP cache (the wp.org transient assumes wp.org).
5. Sensitive-string redaction in logs / AJAX / CLI output (`Logger::redact()`).
6. `WP_Upgrader::create_lock()` around the package's own update path (WP does not call it for individual plugin updates by default).
7. Browser-side isolation between multiple scoped copies on the same `plugins.php`.

### WordPress (≥6.9) already handles — DO NOT REIMPLEMENT, DO NOT PRE-FLIGHT

1. **ZIP integrity / corrupt-archive detection.** `unzip_file()` returns `WP_Error` on a bad archive. Do not add `ZipArchive::CHECKCONS` or any other pre-extract validator.
2. **Automatic rollback on extraction failure.** `WP_Upgrader::install_package()` creates `wp-content/upgrade-temp-backup/plugins/{slug}/` and restores on failure. Do not write a parallel rollback layer.
3. **Filesystem writability checks.** `WP_Filesystem` runs before extraction. Do not pre-flight.
4. **Disk space.** Surfaces as a filesystem write failure. Do not call `disk_free_space()`.
5. **Opcache invalidation after update.** WP invalidates for the affected plugin dir automatically.
6. **Maintenance mode toggling during update.** WP toggles `.maintenance` for the duration.

**Any new code that duplicates the right column is overkill. Reject it at review or before opening the PR.**

### Worked example of what got caught

- **2026-05 ZIP integrity check (cut).** Pre-v1.8.0 WIP added `Updater::checkZipIntegrity()` (using `ZipArchive::CHECKCONS`) plus an `ext-zip` requirement in `composer.json`. Both duplicated work `unzip_file()` already does. The bits were dropped from the v1.8.0 history before tagging — see the unpushed-commit rewrite documented in `ARCHITECTURE_IMPROVEMENT_PLAN.md`. If you find yourself proposing a similar pre-flight validator, stop.

### Decision protocol when in doubt

1. Identify the failure mode in one sentence.
2. Ask: "Does WP core (6.9+) surface this through `WP_Error` already?" If yes, stop.
3. Ask: "Is this GitHub-specific, auth-specific, or multi-instance coordination?" If no, stop.
4. Only if both pass: write the code, and link this section in the PR description.

The plan file ([ARCHITECTURE_IMPROVEMENT_PLAN.md](ARCHITECTURE_IMPROVEMENT_PLAN.md)) has an "Already Shipped" table. **Do not re-propose anything in that table.** The plan also has a "Deferred Decisions" section. Do not act on those without explicit user re-approval.

## Wheel-Reinvention Guardrail (keep this package thin)

Use this section before adding update reliability, filesystem, cache, activation, or parsing code. The default answer should be: **WordPress probably already has this.**

### Current audit verdict

The v1.8.0 update path correctly delegates the dangerous part of updates to WordPress:

- `Plugin_Upgrader` / `WP_Upgrader` still own download orchestration, extraction, install, maintenance mode, and rollback.
- `download_url()` still owns normal public URL downloads.
- `unzip_file()` / `WP_Upgrader::install_package()` still own archive validation and extraction failure handling.
- `WP_Upgrader::create_lock()` is used, not replaced, for the concurrency lock.
- Settings pages use the Settings API; admin menus use `add_management_page()` / `add_submenu_page()`.

The cleanups below shipped in `7a3bd8f` for v1.8.1. They are documented here as a **do-not-reintroduce** list, not as pending work. If you find yourself proposing one of these again, stop and re-read the Scope Guard above.

### Completed cleanups - do not reintroduce

1. **`flush_rewrite_rules()` in `GitHubUpdaterManager::activate()` - removed.**
   - This package registers no rewrite tags, routes, CPTs, taxonomies, or rewrite rules.
   - Activation must not flush the whole site's rewrite cache.
   - If a consumer plugin needs a flush, that consumer owns it.

2. **`wp-github-updater-temp-*` upload-dir cleanup in `deactivate()` / `uninstall()` - removed.**
   - The package does not create that pattern in uploads; `download_url()` uses WordPress temp paths and the upgrader owns cleanup during updates.
   - Dead cleanup makes future agents think this package owns temp-file lifecycle. It does not.
   - Do not expand uninstall into plugin lifecycle housekeeping; this package is only an update bridge.

3. **Manual plugin-update transient surgery in `Updater::clearUpdateCache()` - replaced.**
   - The package now uses `delete_site_transient( 'update_plugins' )`, matching the local convention already used elsewhere in `Updater`.
   - Do not introduce `wp_clean_plugins_cache( true )` here unless the plugin file cache itself must also be cleared.
   - Do not hand-maintain the shape of WordPress' update transient unless there is a proven package-specific reason.

4. **Direct SQL transient clearing in `GitHubAPI::clearCache()` / `hasCachedData()` - replaced.**
   - Direct DB deletion bypasses persistent object caches, so Redis/Memcached sites could keep serving stale values after the database rows were deleted.
   - WordPress has no delete-by-prefix transient API, so the package now keeps a small `autoload=false` option registry of exact cache keys it creates.
   - `clearCache()` must call `delete_transient( $key )` for each registered key. `hasCachedData()` must read the registry instead of querying `$wpdb->options`.
   - Legacy pre-v1.8.1 transients are not registered and expire naturally under the normal cache TTL.

5. **`Config::parsePluginHeaders()` regex clone - deleted.**
   - `extractPluginData()` now uses WordPress' `get_plugin_data()` parser.
   - If `get_plugin_data()` is unavailable, load `ABSPATH . 'wp-admin/includes/plugin.php'` and call it. Fail loudly if real WordPress bootstrap is missing.
   - If only generic file headers are needed, use `get_file_data()` after loading the relevant core file.
   - Do not maintain a regex clone of WordPress' plugin header parser.

### Keep, but do not expand, package-specific updater hooks

These are legitimate because WordPress exposes hooks but cannot know GitHub/private-repo semantics:

1. **`Updater::handlePreDownload()`**
   - Keep only the GitHub private-asset auth behavior, public/private asset matching, and WP lock acquisition.
   - Do not add ZIP validation, filesystem preflights, disk checks, rollback, chmod/chown, or extraction checks here.

2. **Direct-200 asset fallback in `handlePreDownload()`**
   - Allowed only because GitHub's asset API can return either a redirect or a binary body for `Accept: application/octet-stream`.
   - Keep it tiny: `wp_tempnam()`, write body, return path, release lock on failure.
   - Do not grow this into a custom downloader. If it becomes complex, redesign around WordPress HTTP streaming APIs first.

3. **`Updater::fixSourceDirectory()`**
   - Allowed only to adapt GitHub release ZIP top-level folder names to the installed plugin slug via WordPress' `upgrader_source_selection` hook.
   - Do not add rollback, archive inspection, or recursive install logic. WordPress owns install/extract/rollback.

4. **`GitHubAPI` response handling**
   - Allowed because GitHub status codes, rate-limit headers, release JSON, asset names, and auth modes are package-specific.
   - Keep using `wp_remote_get()` / response helpers. Do not introduce another HTTP client.

5. **Token encryption and redaction**
   - Allowed because WordPress does not provide encrypted arbitrary secret storage or log redaction for plugin-owned GitHub PATs.
   - Keep the surface small: `Config::saveAccessToken()`, `Config::getAccessToken()`, `Logger::redact()`.

### Review checklist before accepting future update-path code

For every proposed change under `Updater`, `GitHubAPI`, `Config`, or lifecycle hooks:

1. Name the exact failure mode.
2. Search WordPress core first: `WP_Upgrader`, `Plugin_Upgrader`, `download_url()`, `unzip_file()`, `WP_Filesystem`, `wp_clean_plugins_cache()`, Transients API, Settings API.
3. If core already handles it, delete the proposed package code.
4. If core exposes a hook/filter for it, use the hook and keep the package code as a narrow adapter.
5. If the code touches filesystem, archives, rollback, temp files, maintenance mode, plugin cache, or plugin headers, assume it is suspicious until proven package-specific.
6. Add/keep tests only for package-owned behavior: GitHub auth, release/asset selection, token storage/redaction, cache key isolation, scoped multi-plugin isolation, and lock coordination.

### Things explicitly forbidden without new approval

- `ZipArchive`, `CHECKCONS`, custom archive integrity checks, or an `ext-zip` requirement.
- Custom rollback, backup directories, restore routines, or extraction verification.
- Custom filesystem writability/disk-space/opcache/maintenance-mode checks.
- Direct SQL against WordPress options for cache operations when exact-key Transients API calls can work.
- Manual plugin-header parsing when `get_plugin_data()` / `get_file_data()` can be loaded.
- Activation-time `flush_rewrite_rules()` from this package.
- Cleanup of temp-file patterns this package does not create.

## What this is

A self-contained Composer library (`rajandangi/wp-gh-release-updater`, PSR-4 namespace `WPGitHubReleaseUpdater\`) that WordPress plugins bundle to get manual GitHub release updates. **It is not itself a WordPress plugin** — it has no entry plugin file. Consumers instantiate `GitHubUpdaterManager` from their own plugin's main file. PHP `>=8.3` and WordPress `>=6.9` required.

WordPress 6.9+ is required so the package inherits `WP_Upgrader::install_package()` automatic rollback (added 6.3, hardened through 6.9): if an update fails after extraction, WP restores the previous plugin from `wp-content/upgrade-temp-backup/plugins/{slug}/`. Consumer plugin headers should declare `Requires at least: 6.9` so older sites block activation cleanly.

## Commands

Composer scripts (defined in `composer.json`):

- `composer test` — PHPUnit 12 (bootstrap: `tests/bootstrap.php`, suite scans `tests/`). Tests use PHP attributes (`#[Test]`, `#[DataProvider]`), not docblock annotations.
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
- `wp <slug> decrypt-token [--raw]` — debug only; default verifies decryption by printing token length plus a warning; `--raw` is required to print the plaintext token
- `wp <slug> register` — **incidentally exposed**. `CLI::register()` is `public` so WP-CLI picks it up as a subcommand even though its job is wiring `add_command` at boot. Not part of the documented API. Either treat it as internal-only or make it non-public in a follow-up.

## Architecture

`GitHubUpdaterManager` is the single public entry point and acts as a small DI container. Consumer code does only:

```php
new GitHubUpdaterManager(['plugin_file' => __FILE__, ...]);
```

Object graph built in `initializeComponents()` (`src/GitHubUpdaterManager.php`):

- **`Config`** (`src/Config.php`) — **Per-plugin singleton/registry**: private constructor, retrieved via `Config::getInstance($plugin_file, $options)`. Instance key is `realpath($plugin_file)`, so `clearInstance($plugin_file)` / `clearAllInstances()` exist as test-reset hooks — note the arg is the plugin **file path**, not the slug (see Tests section). Auto-derives plugin slug from the `plugin_file` basename and uses it to namespace **everything**: option names (`wp_{slug}_repository_url`, `wp_{slug}_access_token`), AJAX action handles, asset handles, nonce names, cache keys, default WP-CLI command, settings page slug. Also owns AES-256-CBC encrypt/decrypt of the GitHub token using WordPress salts (`AUTH_KEY`+`SECURE_AUTH_KEY`) — see `getEncryptionKey()`, `encrypt()`, `decrypt()`, `saveAccessToken()`, `getAccessToken()`. **Two plugins sharing this class cannot share state** because every key is slug-prefixed.
  - **Refactor target (#1 god node).** `Config` is doing nine jobs in one file: WP options I/O (`get_option`/`update_option`/`add_option`/`delete_option`), AES-256-CBC token crypto, slug derivation, CLI-command sanitization, menu config, per-plugin singleton registry, cache-duration getter, and AJAX/asset/nonce name derivation. If you touch this area, that's the deepening opportunity. Graphify run on 2026-05-17 (commit `72abc6ba`): **58 edges** (#1 god node), **betweenness 0.155** (highest in graph), Community 0 cohesion **0.05** (Config + WP options API + the test that touches them — graph literally says "Config IS the options layer"). Decomposition plan (P11–P14) sits in `ARCHITECTURE_IMPROVEMENT_PLAN.md` Phase 3.
- **`GitHubAPI`** (`src/GitHubAPI.php`) — Owns HTTP to GitHub (`getLatestRelease`, `testRepositoryAccess`), URL parsing (`parseRepositoryUrl` accepts `owner/repo`, full URLs, `.git` suffix), and a transient-backed response cache (`getCachedResponse`/`setCachedResponse`). **Cache key includes auth discriminator**: `md5( $url . '|' . ( $token ? 'authed_' . hash('sha256', $token) : PUBLIC_CACHE_DISCRIMINATOR ) )` — token change invalidates cache, public/authenticated entries stay separate, raw token never enters the transient name. TTL from `Config::getCacheDuration()`.
- **`Updater`** (`src/Updater.php`) — Hooks into WordPress' update transient (`injectUpdateInfo` on `pre_set_site_transient_update_plugins`) and the upgrader pipeline (`handlePreDownload`, `fixSourceDirectory` rename the extracted dir back to the plugin slug, `clearCacheAfterUpdate`). Asset selection logic: `findMatchingAsset` → `findDownloadAsset` (Authorization-header resolution is inlined here for private-repo asset URLs since the v1.8.0 collapse — there is no separate `resolveAssetDownloadUrl` method any more). Version comparison via `isUpdateAvailable` + `extractVersionFromTag` + `isPreRelease`.
- **`Admin`** (`src/Admin.php`) — Settings page, repository-URL/token fields, AJAX handlers (`ajaxTestRepository`, `ajaxQuickCheckForUpdates`), and the "Check for Updates" plugin-row action link. Skipped entirely in WP-CLI context. Several invariants set by the P1-P5 arc:
  - **Plugin row action link carries data attributes** (`data-wp-gh-release-updater-check`, `data-plugin`, `data-action`, `data-nonce`, `data-ajax-url`) instead of relying on a `window.pluginUpdaterConfig` global. `plugins-page.js` reads from clicked-link data only. This is what isolates multiple bundled copies of the package on the same `plugins.php`.
  - **Settings page hook suffix is stored** (`$this->settings_hook`) from the WordPress menu registration return value. `enqueueScripts()` and `showAdminNotices()` gate on it instead of reconstructing `'tools_page_…'` strings. Don't reintroduce string-concatenation hook checks.
  - **AJAX response payloads run through `Logger::redact()`** before `wp_send_json_*` to scrub token/header/signed-URL leakage from GitHub error bodies.
- **`CLI`** (`src/CLI.php`) — Registered only when `defined('WP_CLI') && WP_CLI`. Methods named with snake_case (`test_repo`, `check_updates`, `update`, `decrypt_token`) because WP-CLI uses method names as subcommand names — do not rename to camelCase. `decrypt-token` default prints redacted token length + a `--raw` warning; plaintext requires `--raw`. CLI also routes its `WP_CLI::log/error/success` strings through `Logger::redact()` so signed download URLs in `test-repo` / `update --dry` output stay safe.
- **`Logger`** (`src/Logger.php`) — `error_log` wrapper gated on `WP_DEBUG`. **`Logger::redact()` is the centralized scrubbing seam** — `Logger::log()` runs every message through it before `error_log`. Redaction covers GitHub PAT shapes (`ghp_*`, `github_pat_*`), `Authorization: token/Bearer …`, signed-URL params (`X-Amz-Signature`, `X-Amz-Credential`, `sig`, `jwt`, `access_token`). Call sites: `Updater` (`checkForUpdatesWithApi`, `findDownloadAsset`, `handlePreDownload`, `fixSourceDirectory`), `Admin` (`ajaxTestRepository`, `ajaxQuickCheckForUpdates`), `GitHubAPI::makeRequest`. Reach for `Logger::log()` rather than `error_log()` directly so debug output stays gated and redacted consistently. Reuse `Logger::redact()` directly when scrubbing strings outside the log path (e.g., AJAX response payloads, WP-CLI stdout).

`GitHubUpdaterManager::activate()` / `deactivate()` / `uninstall()` are the lifecycle hooks the consumer plugin must wire to `register_activation_hook`/etc. — the library cannot register them itself because it has no main plugin file.

## WordPress upgrader boundary (read before adding "reliability" code)

The package hands off to WordPress's native `Plugin_Upgrader` / `WP_Upgrader::install_package()` pipeline for the actual download → extract → install → activate flow. **WordPress already handles a large set of failure modes**; do not reimplement them in this package.

What WordPress (≥6.9) handles natively — **do not duplicate**:

- **ZIP integrity / corrupt-archive detection.** `unzip_file()` validates archive structure during extraction and returns `WP_Error` (`incompatible_archive`, `empty_archive`, etc.) on failure. Adding a separate `ZipArchive::CHECKCONS` pre-check is redundant — was tried, reverted as P7.
- **Automatic rollback on extraction failure.** `WP_Upgrader::install_package()` creates a temp backup in `wp-content/upgrade-temp-backup/plugins/{slug}/` before replacing files and restores it if the new version's main file is missing or extraction otherwise fails. Added in 6.3, hardened through 6.9. The package's WP 6.9 minimum exists specifically to inherit this — do not write a parallel rollback layer.
- **Filesystem writability checks.** `WP_Filesystem::connect()` + `WP_Filesystem_*::is_writable()` run before extraction. Don't pre-flight separately.
- **Disk space checks.** Implicit via filesystem write failures, which `unzip_file()` surfaces as `WP_Error`. Don't add `disk_free_space()` pre-checks.
- **Opcache invalidation after update.** WP invalidates the opcache for the affected plugin directory automatically post-update (6.3+).
- **Maintenance mode during update.** WP toggles the `.maintenance` file for the duration of the upgrade.

What WordPress does **not** handle, that the package legitimately owns:

- **Concurrent update lock.** `WP_Upgrader::create_lock()` / `release_lock()` exists (since 4.5) but WordPress doesn't call it for individual plugin updates by default — the package uses it (P6) to prevent two simultaneous `wp <slug> update` calls from racing.
- **Auth-aware caching.** The HTTP cache layer is the package's responsibility (`GitHubAPI`); WordPress's native plugin transient assumes wp.org.
- **GitHub release semantics.** Version extraction from tag names, pre-release filtering, private-repo signed-asset auth headers, `Authorization: Bearer …` injection — all package-specific.
- **Token storage + crypto.** The AES-256-CBC envelope around the GitHub PAT is the package's responsibility (`Config`).
- **Sensitive-string redaction in logs.** `Logger::redact()` exists because WordPress logs nothing automatically and `error_log` does not redact.

**Decision heuristic before adding code in the update path:** if the failure mode is generic to "extract a ZIP and replace plugin files safely," WordPress already handles it. Add code only when the failure mode is GitHub-specific, auth-specific, or coordination across multiple instances of this package.

## Class-collision constraint (read before refactoring public API)

Because every plugin that bundles this library autoloads the same `WPGitHubReleaseUpdater\*` classes, **the first plugin to load wins** — its version of each class is the one PHP uses everywhere. Consumers are expected to mitigate with PHP-Scoper (README §"Avoiding Class Collisions"), which rewrites the namespace to e.g. `MyPluginVendor\WPGitHubReleaseUpdater\`. Implications:

- Don't add singletons keyed by class name — they'd collide across plugins.
- Renames/removals of public classes or methods are silent breakages for older bundled copies. Treat the public surface (`GitHubUpdaterManager`, `Config`, `GitHubAPI`, `Updater`, `Admin`, `CLI`, `Logger`) as load-bearing.
- The data-isolation slug prefix is independent of scoping; both are needed.

## Tests

`tests/bootstrap.php` loads `vendor/autoload.php` then `tests/constants.php`, which defines a large set of WordPress function/class stubs (`add_action`, `wp_remote_get`, `WP_Error`, `WP_CLI`, options, transients, admin menu/screen/enqueue) plus globals that tests mutate to drive behaviour:

- `$GLOBALS['wp_gh_updater_test_transients']` — transient store. Inspect keys to verify cache-key derivation (P5 invariants).
- `$GLOBALS['wp_gh_updater_test_http_response']` — `wp_remote_get` stub response.
- Hook registry (P2) — captures `add_action`/`add_filter` calls so tests can assert which hooks fire in which context (admin vs frontend vs WP-CLI).
- `error_log` capture (P4) — buffers logger output instead of printing during PHPUnit so tests can assert against the redacted message.
- AJAX JSON response capture (P4) — stubs `wp_send_json_*` to record the payload so AJAX-redaction tests can inspect what would have been sent.
- Menu/enqueue/screen stubs (P3) — let tests assert which hook suffix the admin page registers under and which assets enqueue on which screen.

Reset the relevant global(s) in `setUp`/`tearDown` rather than introducing real WP. `WP_CLI::error` is stubbed to throw `WPCLITestException` so CLI tests can assert on failure paths.

**Reset the `Config` singleton between tests.** Because `Config::getInstance()` caches one instance per plugin file path (`realpath($plugin_file)`), leaking state across cases causes flaky tests. `CLITest::setUp/tearDown` already call `Config::clearAllInstances()` (and `WP_CLI::reset()`); mirror that pattern in any new test that constructs a `Config`, `GitHubUpdaterManager`, or anything downstream.

Suite size baseline (as of v1.7.0): **61 tests, 240 assertions**. Tests use PHPUnit 12 attribute syntax (`#[Test]`, `#[DataProvider]`).

## Coding standards (when writing code)

- `declare(strict_types=1);` at top of new PHP files.
- Use tabs for PHP indent (WPCS); JS/CSS uses tabs at width 4 (`biome.json`).
- Short array syntax is allowed; PSR-4 file naming (no `class-` prefix); camelCase methods are allowed except where WP-CLI requires snake_case subcommand names.
- PHPStan ignores listed in `phpstan.neon` exist to paper over WordPress stubs (`WP_Error`, `wp_send_json_*` unreachable-after-exit) — don't widen them blindly; fix the type instead.
- Rector is configured with `UP_TO_PHP_83` + `STRICT_BOOLEANS` + `PRIVATIZATION` sets. Expect it to push toward typed properties, early returns, and privatized members.
