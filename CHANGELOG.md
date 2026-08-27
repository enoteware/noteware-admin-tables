# Changelog

All notable changes to this project are documented in this file.

## [0.2.0.0] - 2026-08-27

### Added

- Editable ACF text, link, and select fields written through the documented ACF write functions, with the ACF field reference row verified after every write, removal, and undo.
- A generic link column type that validates without rewriting, allows only safe HTTP and HTTPS links, and keeps a stored empty link distinct from an absent one.
- A taxonomy adapter for display, filtering, and single-term editing, with undo that restores the complete previous term set.
- Native post title, post slug, and featured-image editing, plus computed permalink and word-count display.
- Filter operators for exact match, is empty, and has a value, on metadata and taxonomy columns.
- Bulk editing with per-record capability checks, per-record transactions, per-record audit rows, precise partial-failure reporting, and per-record undo.
- Site-owned column order, replacement of selected built-in columns, and bounded column widths.
- An undo control that survives a page reload, backed by a bounded page-scoped audit query.
- A screen minimum width so a list with many columns scrolls sideways instead of squeezing every column until its text wraps one character per line.

### Changed

- The audit table records whether a row was created by an undo. Schema version 3.
- Inline editor and bulk panel fields use `data-field` instead of `name`, so they are never serialized into the WordPress list filter URL.

### Fixed

- The dependency license report failed when an optional peer dependency listed in the lockfile was not installed. It now reports only what npm installed and prints how many entries it skipped.
- A list screen with several editable columns could produce a filter request that the web server rejected as too long.
- Plugin cells could overflow their table cell and cover a neighbouring column on a screen with many columns.
- A narrow configured column wrapped an edit control one character per line, which shrank it below the minimum accessible target size.
- Percentage column widths that claimed the whole table are now rejected instead of starving the WordPress checkbox and title columns.

## [0.1.0.0] - 2026-08-25

### Added

- Configurable post list-screen columns for native fields, metadata, and six ACF field types.
- Typed sorting and filtering through allowlisted WordPress query settings.
- Deny-by-default inline editing with capability checks, request-specific nonces, validation, sanitization, audit records, and conditional undo.
- Generic Docker sandbox fixtures, including a 10,000-record performance profile.
- PHP, JavaScript, integration, performance, security, accessibility, and browser test gates.
- Architecture decisions and developer documentation for the first review milestone.
