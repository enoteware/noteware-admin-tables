# Current handoff

## State

The first review milestone is implemented on `feat/first-review-milestone`.

The plugin now provides external post-screen configuration, native and metadata columns, six ACF display types, typed sorting and filtering, allowlisted scalar editing, immutable audit snapshots, and conditional undo. Generic site configuration and fixture data stay in the sandbox layer outside the distributable plugin.

## Local proof

- Composer validation, PHP syntax, PHPCS, PHPStan, and PHPUnit pass.
- PHPUnit has 37 tests and 68 assertions.
- WordPress integration checks exercise edit and undo success, nonces, object and field capabilities, allowlists, invalid input, stale snapshots, repeated undo, audit creation, transaction rollback, literal backslash preservation, duplicate-row rejection, sparse and core-grouped metadata sorting, exact decimal comparison, preservation of pre-existing query relations, exact-screen cache preload, the dynamic Pages column hook, native fail-closed filters, ACF field-key mismatches, live and stale ACF select labels, hostile-label escaping, unsupported ACF select shapes and query controls, image-filter rejection, warning escaping, and the metadata-filter cost cap.
- JavaScript lint and one Jest interaction test pass.
- Playwright has six passing tests for display, exact sort and filter behavior, keyboard focus, edit, undo, invalid nonce, invalid-filter fail-closed behavior, and scoped WCAG checks in open, error, and OS-dark-preference states.
- The 10,000-record profile renders 160 cells for 20 rows and 800 cells for 100 rows. Both runs use eight total queries, including four preload and render queries. Query growth is zero.
- The latest local HTTP samples have a 0.102 second median and a 0.112 second maximum against a 5 second budget.
- Composer and full npm tooling dependency audits pass. The license report covers 904 dependencies with zero blocked and zero missing declarations.
- The full-worktree clean-room, secret-shape, and user-facing punctuation scan passes.
- Browser screenshots are generated under `tests/artifacts/` and ignored from git. CI uploads browser evidence as an artifact.
- An independent security and architecture review found no blockers after verifying fail-closed serializable locking, compare-and-swap writes, rollback cache invalidation, permission coverage, and query cost limits.

## Pull request

The public pull request is open:

https://github.com/enoteware/noteware-admin-tables/pull/1

GitHub is the live source for the current head SHA, CI results, and automated-review status. The branch remains unmerged.

## Next action

Follow `ship-codex` until CI and every available automated reviewer cover the current head. Fix or answer every finding with evidence. Do not merge without Elliot's explicit approval.
