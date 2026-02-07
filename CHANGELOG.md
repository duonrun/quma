# Changelog

## [0.1.1](https://github.com/duonrun/quma/releases/tag/0.1.1) (2026-02-07)

### Changed

- Reworked template execution for `*.tpql` and PHP migrations to load files via `include`/`require` instead of evaluating raw file contents.
- Improved SQL and migration directory parsing for nested and namespaced configurations, including safer handling of invalid entries.
- Hardened named-parameter preparation for template queries to keep only placeholders that are actually present in rendered SQL.

### Fixed

- Added stricter migration loading validation with clearer failures for missing files and invalid migration objects.
- Added a defensive runtime guard when reading the PDO connection before initialization.

## [0.1.0](https://github.com/duonrun/quma/releases/tag/0.1.0) (2026-01-31)

Initial release.

### Added

- No-ORM database library for executing raw SQL files
- SQL file organization and query management
- Database migration support
- PDO-based connection handling
