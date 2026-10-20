# Agent instructions

Read this file, `CLAUDE.md`, `ROADMAP.md`, and all relevant process documents before changing code.

## Scope

Build Noteware Admin Tables as an original open-source WordPress plugin. It may reproduce useful workflows that users expect from admin-table tools, but it must not copy proprietary source code, assets, documentation, product names, or visual trade dress.

The public repository must contain no client names, private field keys, production URLs, credentials, database contents, or licensed commercial code.

## Process

- Work on a feature branch and open a pull request to `main`.
- Never merge to `main` without Elliot's explicit approval.
- Never force-push or skip CI.
- Follow the local `ship-codex` workflow for fresh-head review and CI checks.
- Keep the pull request small enough to review. Use later pull requests for later roadmap phases.
- Record architecture choices in `docs/adr/`.
- Keep `HANDOFF.md` current so another agent can continue the work.

## Engineering boundaries

- License all project code as GPL-2.0-or-later.
- Use public WordPress APIs and documented integration APIs.
- Keep site-specific configuration outside the core plugin.
- Treat editing as deny-by-default. Each editable field adapter must define capability, nonce, validation, sanitization, write, audit, and undo behavior.
- Preserve the semantic difference between absent, empty, false, zero, and null-like values where the upstream field API does.
- Escape output at the final boundary.
- Avoid per-row queries and unbounded reads.
- Do not delete stored configuration on uninstall unless an administrator explicitly opts in.
- Add automated tests for every write path and permission boundary.

## Sandbox

Run `bash scripts/sandbox-up.sh` to start the local WordPress site. The Tailscale-only review URL is `http://note-devbox.tailac4262.ts.net:8097`.

Local credentials are in the gitignored `.env` file. Do not paste them into chat, issues, logs, commits, or pull requests.

## First milestone

Complete the first review milestone in `ROADMAP.md`. Done means:

- The documented checks pass and their output is visible in the pull request.
- The sandbox plugin is active and the vertical slice works in the browser.
- Security, accessibility, performance, and clean-room boundaries have tests or explicit evidence.
- A public pull request is open and all available automated reviewers have reviewed the current head.
- Review findings are fixed or answered with evidence.
- The branch remains unmerged pending Elliot's approval.

## Code Review Rules

These rules are for automated reviewers (Codex, CodeRabbit, Cursor Bugbot) and for
agents triaging their findings.

### What counts as a blocker

- Flag as P0/P1 only a concrete failure this PR introduces: security hole,
  credential exposure, auth or tenant-scope bypass, data loss or corruption,
  destructive or incorrect behavior, broken compatibility, or an unmet
  acceptance criterion from the PR description. Name the file and the failure.
- Style, naming, refactors, optional hardening, and pre-existing problems are
  follow-ups. Say "follow-up" and do not block on them.
- Any literal secret, token, API key, password, private key, or database URL
  with a password is always a blocker, including in tests, docs, and scripts.

### Auth and tenant scope

- Every route, server action, or handler that touches tenant data authenticates
  first and scopes by org/project membership, never by a record id from the
  request alone. Safe path: the repo's scope helper (name it here, for example
  `requireOrgRole` or `resolveProjectScope`).
- Webhooks verify the provider signature before parsing the body.

### Money and client communications

- Prices, amounts, and discounts are computed and validated on the server, in
  integer minor units. A client-supplied amount is never trusted.
- Client-facing copy (emails, notifications, templates, user guides, portal
  text) must not state prices, rates, quotes, or estimates unless the PR
  description says Elliot approved that wording.

### WordPress

- Escape all output (esc_html/esc_attr/esc_url/wp_kses), sanitize all request
  input, use $wpdb->prepare() for SQL, and verify a nonce plus a
  current_user_can() capability on every form, AJAX, admin, and REST handler.
- A lower role must never be able to edit a higher-privilege user or grant
  itself access.

### Repo-specific

- `main` is branch-protected (1 approval, stale approvals dismissed). Do not suggest bypassing protection.
- Shell scripts must run on macOS bash 3.2 and Linux.

### Handling review findings (agents)

- Verify every finding against the current head before acting; labels are
  evidence, not verdicts.
- Fix verified blockers in this PR. Reply with the disposition (fixed in
  `<sha>`, follow-up #N, not applicable because ...) and resolve the thread.
- Do not merge with an unresolved, non-outdated P0/P1 thread or an unresolved
  CodeRabbit "Potential issue" (Critical/Major) thread.
