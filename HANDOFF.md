# Current handoff

## State

Two branches are open for review.

1. `feat/first-review-milestone` carries the first review milestone and remains open as pull request 1.
2. `feat/acf-and-cfmtg-parity` builds on it and adds the parity work described below.

The parity branch adds writable ACF text, link, and select fields, a taxonomy adapter, native title, slug, and featured-image editing, computed permalink display, filter operators for exact, empty, and has-a-value matching, bulk editing with per-record audit boundaries, site-owned column order, built-in column replacement, bounded column widths, and an undo control that survives a page reload. Site configuration and fixture data stay in the sandbox layer outside the distributable plugin.

## Local proof

- Composer validation, PHP syntax, PHPCS, PHPStan, and PHPUnit pass.
- PHPUnit has 75 tests and 123 assertions.
- WordPress integration checks cover the previous milestone plus ACF link display, editing, removal, explicit empty storage, ACF reference-row preservation, exact undo restoration, rejection of unsafe and malformed links, stale link snapshots, ACF select writes bounded by the live field and the configured allowlist, taxonomy display, single-term editing, clearing, full multiple-term undo, native title, slug, and featured-image editing, read-only permalinks, configured column order, built-in column removal and replacement, scoped column widths, the empty and has-a-value operators for metadata and taxonomy columns, fail-closed handling of an operator a column did not enable, editor and bulk-panel markup with no submittable field names, and every bulk editing success and failure boundary.
- JavaScript lint and Jest pass.
- Playwright has 10 passing tests, including the configured layout, a narrow-column target-size guard, a link edit followed by reload, undo, and a second reload, the empty and has-a-value filters, a bulk edit and group undo, keyboard focus, invalid nonce handling, and scoped WCAG checks in open, error, and OS-dark-preference states.
- The 10,000-record profile renders 280 cells for 20 rows and 1,400 cells for 100 rows across 14 configured columns. Total queries are 13 and 12. Query growth is zero.
- The latest local HTTP samples have a 0.283 second median and a 0.404 second maximum against a 5-second budget.
- Composer and npm dependency audits pass. The license report covers 792 installed dependencies with zero blocked and zero missing declarations, and names the 112 lockfile entries npm did not install.
- The full-worktree clean-room, secret-shape, and user-facing punctuation scan passes.

## Notable fixes in this branch

- Inline editor and bulk panel fields now use `data-field` instead of `name`. Named fields were serialized into the WordPress list filter form, so a screen with several editable columns produced a request the web server rejected as too long.
- Plugin cells no longer force a minimum width that could overflow the table cell and cover a neighbouring column.
- An undo now records that it is an undo, so it is never offered back as a redo.
- A narrow configured column wrapped an edit control one character per line and shrank it below the minimum accessible target size. Found by running a dense real-world layout in a private sandbox.
- Percentage column widths that claimed the whole table starved the WordPress checkbox and title columns until their text wrapped one character per line. Configurations that do this are now rejected, and a screen with many columns can set a minimum width and scroll sideways instead.

## Pull requests

GitHub is the live source for the current head SHA, CI results, and automated-review status. Both branches remain unmerged.

## Next action

Follow `ship-codex` until CI and every available automated reviewer cover the current head. Fix or answer every finding with evidence. Do not merge without Elliot's explicit approval.
