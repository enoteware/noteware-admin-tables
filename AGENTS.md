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
