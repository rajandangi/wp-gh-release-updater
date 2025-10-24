# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Fixed "The plugin is at the latest version" error when clicking "Update now" button. The plugin now properly injects update information into WordPress's update transient via the `site_transient_update_plugins` filter, ensuring WordPress can correctly detect and install available updates.
- Fixed plugin reactivation after update. The plugin now properly stores the active status before update and ensures reactivation after successful update completion.

## [1.0.0] - 2025-10-18
- Initial release of WP GitHub Updater Manager
---

## Support

- **Report bugs**: [GitHub Issues](https://github.com/rajandangi/wp-github-updater-manager/issues)
- **Request features**: [GitHub Discussions](https://github.com/rajandangi/wp-github-updater-manager/discussions)

[Unreleased]: https://github.com/rajandangi/wp-github-updater-manager/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/rajandangi/wp-github-updater-manager/releases/tag/v1.0.0
