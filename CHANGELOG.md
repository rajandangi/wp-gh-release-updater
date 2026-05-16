# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.5.4...v1.6.0
[1.2.0]: https://github.com/rajandangi/wp-gh-release-updater/compare/v1.0.0...v1.2.0
[1.0.0]: https://github.com/rajandangi/wp-gh-release-updater/releases/tag/v1.0.0
