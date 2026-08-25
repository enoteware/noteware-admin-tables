# Testing

The first milestone uses several checks because one test type cannot prove the full list-screen workflow.

## Local checks

Install the development dependencies, then run the documented checks:

```bash
composer install
composer check
bash scripts/clean-room-check.sh
bash scripts/test-integration.sh
bash scripts/performance-check.sh
npm ci
npm run lint
npm test
npm run audit
npm run check:licenses
npm run test:e2e
```

These commands cover:

- PHP syntax on every plugin PHP file;
- WordPress coding standards;
- PHP static analysis;
- PHP unit tests;
- WordPress integration tests;
- JavaScript lint and unit tests;
- dependency license reporting;
- the 10,000-record performance check; and
- the clean-room and secret scan.

Run any additional CI-only dependency and license jobs shown in the pull request. Do not skip a group because a different group passes.

## WordPress integration coverage

CI runs PHP checks on PHP 8.1 and PHP 8.3. The Docker sandbox provides real WordPress and ACF browser proof.

Integration tests cover:

- public hook registration and generic external configuration;
- native, metadata, and six ACF display definitions;
- absent, empty, false, and zero fixture states;
- subscriber and editor object and metadata capabilities;
- valid and invalid request-specific nonces;
- unknown and read-only column denial;
- typed validator rejection and audit-table installation;
- browser sorting, filtering, editing, undo, error, focus, and scoped WCAG behavior; and
- a stable query count as the displayed row count grows.

## Sandbox proof

Start the local site:

```bash
bash scripts/sandbox-up.sh
```

Open the Tailscale-only review URL:

http://note-devbox.tailac4262.ts.net:8097

Use the local credentials from the ignored `.env` file. Never paste them into a terminal transcript, issue, commit, pull request, or screenshot.

The browser proof must show:

1. The plugin and ACF Free are active.
2. Generic demo content exists.
3. Native, metadata, and ACF columns display expected values.
4. Supported sort and filter controls change the list.
5. An allowed inline edit succeeds and creates an undo action.
6. Undo restores the exact earlier state.
7. Error text is clear for a rejected edit.
8. Keyboard focus and status messages work.
9. The default WordPress admin color scheme has readable contrast. If a site adds a dark admin scheme, test that scheme separately.

Record the current branch commit and the exact check output in the pull request. Automated review must cover the current head commit.
