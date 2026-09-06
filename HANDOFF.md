# Current handoff

## State

Two branches are open for review. Pull request 1 carries the first review
milestone. Pull request 2 builds on it with the parity work described below.
GitHub is the source for both branch names and their current heads.

The parity branch adds writable ACF text, link, and select fields, a taxonomy adapter, native title, slug, and featured-image editing, computed permalink display, filter operators for exact, empty, and has-a-value matching, bulk editing with per-record audit boundaries, site-owned column order, built-in column replacement, bounded column widths, and an undo control that survives a page reload. Site configuration and fixture data stay in the sandbox layer outside the distributable plugin.

## Local proof

- Composer validation, PHP syntax, PHPCS, PHPStan, and PHPUnit pass.
- PHPUnit has 77 tests and 125 assertions.
- WordPress integration checks cover the previous milestone plus ACF link display, editing, removal, explicit empty storage, ACF reference-row preservation, exact undo restoration, rejection of unsafe and malformed links, stale link snapshots, ACF select writes bounded by the live field and the configured allowlist, taxonomy display, single-term editing, clearing, full multiple-term undo, native title, slug, and featured-image editing, read-only permalinks, configured column order, built-in column removal and replacement, scoped column widths, the empty and has-a-value operators for metadata and taxonomy columns, fail-closed handling of an operator a column did not enable, editor and bulk-panel markup with no submittable field names, and every bulk editing success and failure boundary.
- JavaScript lint and Jest pass.
- Playwright has 10 passing tests, including the configured layout, a narrow-column target-size guard, a link edit followed by reload, undo, and a second reload, the empty and has-a-value filters, a bulk edit and group undo, keyboard focus, invalid nonce handling, and scoped WCAG checks in open, error, and OS-dark-preference states.
- The 10,000-record profile renders 280 cells for 20 rows and 1400 cells for 100 rows across 14 configured columns. Total queries are 13 and 12. Query growth is zero.
- The latest local HTTP samples have a 0.376 second median and a 0.455 second maximum against a 5-second budget.
- Composer and npm dependency audits pass. The license report covers 792 installed dependencies with zero blocked and zero missing declarations, and names the 112 lockfile entries npm did not install.
- The full-worktree clean-room, secret-shape, and user-facing punctuation scan passes.

## Review findings on the first head, all fixed

Cursor Bugbot and the Codex connector both reviewed `3a4b8918`. Every finding
was accepted and fixed, with a test for each.

- A presence filter applied to a screen that did not ask for it. A column whose
  only operator was is-empty or has-a-value left the first page silently
  filtered with no way to clear it.
- Saving a featured image that was already set failed as a stale write.
- The transaction engine check covered only post metadata and the audit table.
  Each adapter now names the tables its write touches, so a taxonomy or native
  write is checked as strictly.
- Undo passed audited term slugs straight to WordPress, which can create a
  missing term. Slugs now resolve to existing terms, so an undo cannot create a
  term the actor may not create.
- A required ACF field could be cleared or emptied.
- An ACF value with a wrong or missing field reference row was silently
  repaired, which left undo unable to restore the original pair.
- A failed edit cleared only the metadata cache, so a persistent object cache
  could keep serving a rolled back post or term value.
- A saved or undone cell collapsed to a raw value, so a thumbnail, a link, or a
  term list lost its shape until the next page load.
- A taxonomy with more than 200 terms rendered controls that rejected every
  choice they displayed.
- A taxonomy registered for another post type was accepted.
- The reload-safe undo lookup was bounded by time but not by result count.
- A bulk edit left a cell's earlier undo control in place, pointing at an audit row that no longer matched the value.
- A configuration could replace or remove the WordPress checkbox and title columns, which strips row actions and bulk selection from every row. Both are now reserved.
- The bulk editor had no way to clear a value, so a taxonomy or select column could only be overwritten.
- A column whose only operator was a presence operator rendered no control at all, so the filter could never be used.
- A taxonomy larger than the bounded choice list lost its presence filters as well as its exact filter.
- The page-scoped undo lookup limited raw rows, so repeated edits of one cell could hide another cell's undo control. It now groups in the database.
- Undo restored an audited ACF value without re-validating it against the field as it is now.
- The repository scan named the client and competitor it prohibits, and a broken pattern made it pass silently. It is now generic and fails when it cannot run.
- **The scan had never run in continuous integration.** Ripgrep is not installed on the runner, and the old `if rg ...` form treated the missing binary as a clean result. The scan now uses `grep`, and every rule is verified against a planted match, including a run with no tools on the path.

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


## Column views module (issue 5)

Tracking: https://github.com/enoteware/noteware-admin-tables/issues/5

The module is locally reviewed and tested but not installed in the dummy site. Root wiring is documented in docs/developer/views-wiring.md. Recent corrections preserve search after reordering, enqueue assets before the admin header, and consistently limit names to 100 Unicode code points.

Fresh isolated checks: PHPCS, PHPStan, 94 PHP tests / 152 assertions, focused ESLint and 3 view JavaScript tests. Real WordPress persistence and browser acceptance remain unverified. Saved view changes still need concurrency verification. Full feature issue remains open.

## Query and segment module (issue 6)

Tracking: https://github.com/enoteware/noteware-admin-tables/issues/6

The module includes typed metadata conditions, segment validation/storage, and query application. Screen/view identifiers use length-prefixed storage keys to avoid ambiguous underscore combinations. A regression covers personal and shared records. Focused storage tests pass (9 tests, 20 assertions), along with coding standards and static analysis after the correction.

Root wiring remains in docs/developer/segments-wiring.md. No new admin controls or active default selection are installed in the dummy site. The issue remains open for full workflow implementation and real WordPress verification.

## Acceptance catalog slice (issue 12)

The minimum inventory now maps 24 feature families to 5,600 requirement cells. None is marked implemented. The catalog validator and attack-case tests run in CI. Coverage remains incomplete by design; passing integrity does not claim product readiness.

Tracking: https://github.com/enoteware/noteware-admin-tables/issues/12

Project: https://github.com/users/enoteware/projects/5

Run `python3 scripts/check-acceptance.py` and `python3 -m unittest discover -s tests/acceptance -p 'test_*.py'`. `python3 scripts/check-acceptance.py --coverage` must currently exit 2. Reviewers must independently inspect receipts and their output before promoting any requirement.

This slice does not change runtime plugin behavior. Other issue branches own implementation and consumer evidence. The current inventory is a minimum and must expand as concrete integration fields and release combinations are reconciled.

## Work-in-progress checkpoint

This branch consolidates current source from issues 5 through 12. It is not an approved release or a full product-coverage claim. Nothing is merged.

Grok resumed implementation from `b6f5378` on `codex/all-work-checkpoint`. The slice that landed after that checkpoint:

- View bootstrap stays registered before `Configuration`. Isolated WordPress persistence and a Tools > Table views browser path were added. Issue 5 is still open for discovery, drag/resize, icons, and related editor work.
- Sandbox ACF number and true/false columns are editable. Isolated ACF scalar edit/undo and a browser path were added. Issue 7 is still open for dates, media, relationships, Quick Add, and all-matching jobs.
- List screens gained nonce-protected CSV/JSON/XLSX downloads of the active filtered view, with private owner-bound files and a post-ID keyset loader. Issue 8 is still open for background workers, view import, and consumer-matrix acceptance.

Progress automation stays paused unless Elliot restarts it. Do not merge without explicit approval.
