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

- An inline edit and an undo repeat the adapter authorization after acquiring the record lock, matching the bulk path. Until the record is locked, a concurrent request can change its owner or status and the earlier check would stand.
- A site restriction on an ACF reference key is honoured whether the site registered the key or attached an authorization filter to it. The earlier form checked only for a registration and skipped hook-only policies.
- Upgrading the audit schema marks rows an earlier version recorded as undo results. Without that, upgrading would offer those rows as undoable and let a user redo the very edit they had already reversed.
- A bulk edit rechecks the record type and the adapter authorization after acquiring the record lock, so a permission or ownership change made by a concurrent request cannot be bypassed.
- Undo re-validates an audited featured image against the media library as it is now, so a deleted, replaced, or unreadable attachment cannot be restored.
- A deliberate site restriction on an ACF reference key is honoured. The check runs only where the site registered that key, because WordPress denies `edit_post_meta` on any underscore-prefixed key by default and an unconditional check would refuse every ACF edit everywhere.
- The repository scan reads its file list from `git ls-files`. Scanning the working directory pulled in a developer's ignored `.env`, which both failed the check on a correct machine and printed real sandbox credentials into the log.
- The undo endpoint enforces the same 24 hour deadline the control advertises. A nonce stays valid longer than that window, so a page left open could otherwise undo an edit after the deadline. An undo can also no longer be undone through the endpoint.
- Undo re-validates an audited ACF value against the field as it is now, so a field that became required or lost a choice cannot have an invalid value restored.
- The checkbox and title columns can no longer be replaced or removed. WordPress renders row actions and bulk selection from them, so a configuration that took either one stripped Edit, Quick Edit, Trash, View, and selection from every row.
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
- The bulk editor offers a clear option for every column whose adapter supports removal, so a taxonomy or select value can be cleared in bulk instead of only overwritten.
- A column whose only operator is a presence operator renders that control, with a neutral first option so the initial screen matches an unfiltered list.
- A native title and slug length limit counts characters rather than bytes, so a title in a script that needs more than one byte per character is no longer rejected well inside the stated limit.
- A taxonomy with more terms than the discovery cap keeps its configured allowlist, resolved one slug at a time. That cap limits what can be listed, not what is legal.
- Metadata undo re-validates the audited value against the column as it is now, so a choice removed during the undo window cannot be written back.
- A native edit refreshes the cached post after taking the row lock, so the snapshot comparison reads the locked row rather than a copy cached before the lock.
- A length check no longer depends on the optional mbstring extension, which would have turned the new rule into a fatal error on a host without it.
- An adapter that speaks for a column and reports no choice is believed. The editors and filters no longer fall back to a stale configured list that every save would reject.
- A writable ACF text field's own length limit is enforced. `update_field()` writes past the rule ACF applies on its own form, so an inline or bulk edit could store a longer value than the field permits.
- A group undo runs a few requests at a time rather than all at once. A hundred simultaneous requests would occupy a normal worker pool and time out both the undos and unrelated admin requests.
- A select column with no legal value no longer advertises the stale configured options that every save would reject. The editor and the bulk control are withheld instead.
- The word count reads any script. `str_word_count()` is locale dependent, returns zero for Chinese, Japanese, or Arabic content, and splits accented Latin text incorrectly.
- The author filter can select records whose author is zero, which is a real state for imported and system-generated posts. It uses an exact list, because the WordPress author argument treats zero as no filter at all.
- A native author column now agrees with itself. A numeric column reads the user ID that the filter matches, a text column reads the display name, and a text author column can no longer be marked filterable, because the filter matches an ID.
- A taxonomy larger than the bounded choice list keeps its presence filters, which never needed a term list.
- The page-scoped undo lookup selects the newest row per cell in the database, so repeated edits of one cell cannot hide another cell's undo control.
- The repository scan fails when it cannot run, instead of passing silently on a tool error, and it no longer names the things it prohibits. It also uses `grep`, which is always present. Ripgrep is not installed on the continuous integration runner, so the previous version of this scan had never actually run there.
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
