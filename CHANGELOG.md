# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.8.0] - 2026-05-17

### Added
- Concurrent update lock via `WP_Upgrader::create_lock()` around `handlePreDownload()`. Two simultaneous "Update now" clicks (or admin + cron) previously raced on file replacement; the second caller now returns `WP_Error( 'update_in_progress' )` cleanly. The lock spans the full update window — acquired before download, held through extraction, released by `clearCacheAfterUpdate()` on success. WP core's 5-minute TTL covers crash-mid-update.

### Changed
- WordPress minimum bumped to **6.9**. The package now inherits `WP_Upgrader::install_package()` automatic rollback (added 6.3, hardened through 6.9): if an update fails after extraction, WP restores the previous plugin from `wp-content/upgrade-temp-backup/plugins/{slug}/`. Consumer plugins should declare `Requires at least: 6.9` in their plugin headers so older sites block activation cleanly.
- Collapsed the asset-resolution pipeline in `Updater`: `resolveAssetDownloadUrl()` is inlined into `findDownloadAsset()`, saving 27 lines in `src/Updater.php`. No behavioural change — all existing tests pass without edits.

### Compatibility
- All public surface (`GitHubUpdaterManager`, `Config`, `GitHubAPI`, `Updater`, `Admin`, `CLI`, `Logger`) preserved.
- Existing encrypted access tokens remain readable. Cache key format unchanged.

## [1.7.0] - 2026-05-17

### Changed
- `wp <slug> decrypt-token` now defaults to a safer debug output: `Token decrypted: N chars` plus a `Use --raw...` warning. Plaintext token output requires the explicit `--raw` flag.

### Fixed
- GitHub API cache keys now separate public requests from authenticated requests and include `sha256(token)` for authenticated requests. Switching tokens no longer reuses the previous token's cached release payload.
- Log, CLI, and AJAX error output now redact GitHub PAT shapes (`ghp_...`, `github_pat_...`), `Authorization: token/Bearer ...` headers, and signed asset URL credentials (`X-Amz-Signature`, `X-Amz-Credential`, `sig`, `jwt`, `access_token`).

### Compatibility
- Existing encrypted access tokens remain readable. The AES-256-CBC token envelope is unchanged.
- Existing `authed` GitHub API transients may be orphaned by the new auth discriminator, but expire harmlessly under the normal transient TTL.

## [1.6.0] - 2026-05-16

### Changed
- Removed the shared `window.pluginUpdaterConfig` plugins-page global. Quick-check data now lives on each action link's `data-*` attributes, and `event.__wpGhUpdaterHandled` prevents duplicate AJAX requests when multiple scoped copies load on the same page.
- Upgraded PHPUnit from `^9` to `^12`.

### Fixed
- `menu_parent` config now controls admin settings placement. The hook suffix returned from the WordPress menu API is stored and used to gate settings CSS/JS and admin notices.

## [1.2.0] - 2025-10-24

### Added
- "Check for Updates" action link on plugins page for manual update checks
- Pure vanilla JavaScript (no jQuery dependency)
- Visual feedback with loading states and success/error messages

### Fixed
- Update detection reliability - properly injects update data into WordPress transient
- "Plugin is at the latest version" error when clicking "Update now"

### Changed
- Complete architecture refactoring - separated admin and core update logic
- Simplified to WordPress-standard update flow (no custom reactivation)
- Admin page now shows only GitHub authentication settings
- Removed plugin status section (check updates from plugins page instead)

## [1.0.0] - 2025-10-18
- Initial release of WP GitHub Updater Manager
---

## Support

- **Report bugs**: [GitHub Issues](https://github.com/rajandangi/wp-gh-release-updater/issues)
- **Request features**: [GitHub Discussions](https://github.com/rajandangi/wp-gh-release-updater/discussions)

[Unreleased]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.8.0...HEAD
[1.8.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.5.4...v1.6.0
[1.2.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.0.0...v1.2.0
[1.0.0]: https://github.com/rajandangi/wp-gh-release-updater/releases/tag/v1.0.0
