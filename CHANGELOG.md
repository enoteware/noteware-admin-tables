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

### Security

- Every table an edit writes is now checked for a transaction engine, not just post metadata and the audit table. A taxonomy or native write on a non transactional table is refused instead of committing outside the transaction.
- Undo resolves audited term slugs to existing terms. A user who may only assign terms can no longer recreate a deleted term through undo.
- A required ACF field can no longer be cleared or emptied from a list screen.
- An ACF value whose field reference row is missing or wrong is refused instead of silently repaired, because the audit snapshot could not restore the original pair.
- A taxonomy column that is not registered for the screen's post type fails closed at the screen, query, and write boundaries.

### Fixed

- A presence filter no longer applies to a screen that did not ask for it. A column whose only operator is is-empty or has-a-value left the first page silently filtered with no way to clear it.
- Saving a featured image that was already set no longer fails as a stale write.
- A failed edit now clears the post and object term caches as well as the metadata cache, so a persistent object cache cannot keep serving a rolled back value.
- A saved or undone cell keeps its rendered shape. A thumbnail, a link, and a term list no longer collapse to a raw value until the next page load.
- A taxonomy larger than the bounded choice list keeps working through a configured allowlist, validated by a direct term lookup, instead of rejecting every choice it displayed.
- The reload-safe undo lookup is bounded by the page size, so a heavily edited screen cannot scan an unbounded audit history.
- A bulk edit now replaces a cell's existing undo control instead of leaving one that points at an older audit row and fails when clicked.
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
