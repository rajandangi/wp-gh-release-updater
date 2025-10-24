# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

- **Report bugs**: [GitHub Issues](https://github.com/rajandangi/wp-github-updater-manager/issues)
- **Request features**: [GitHub Discussions](https://github.com/rajandangi/wp-github-updater-manager/discussions)

[Unreleased]: https://github.com/rajandangi/wp-github-updater-manager/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/rajandangi/wp-github-updater-manager/compare/v1.0.0...v1.2.0
[1.0.0]: https://github.com/rajandangi/wp-github-updater-manager/releases/tag/v1.0.0
