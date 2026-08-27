#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if ! command -v grep >/dev/null 2>&1; then
  printf '%s\n' 'grep is required for this scan.' >&2
  exit 1
fi

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  printf '%s\n' 'Tracked .env files are not allowed.' >&2
  exit 1
fi

# Run one scan over tracked project content. A scan that cannot run is not a
# scan that passed, so any tool error fails the check.
scan() {
  scan_output=""
  set +e
  scan_output=$(grep -R -n -I -i -E \
    --exclude-dir=.git \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=test-results \
    --exclude-dir=playwright-report \
    --exclude=package-lock.json \
    --exclude=composer.lock \
    --exclude=clean-room-check.sh \
    "$1" .)
  scan_status=$?
  set -e

  if [ "$scan_status" -gt 1 ]; then
    printf '%s\n' 'The scan could not run, so it cannot be treated as passing.' >&2
    exit 1
  fi
}

# This project is deliberately generic about names. Listing a client or a
# competing product here would put that name in the public repository, which is
# the disclosure the check exists to prevent. The private site repository owns
# that scan, because it already knows those names. See docs/clean-room.md.
scan 'sk_live_[A-Za-z0-9]+|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY'
if [ -n "$scan_output" ]; then
  printf '%s\n' "$scan_output"
  printf '%s\n' 'The scan found a secret-shaped value.' >&2
  exit 1
fi

# Fixtures and documentation may only use reserved example domains.
scan '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'
real_addresses=$(printf '%s\n' "$scan_output" \
  | grep -v -E '@(example\.(test|com|org|net)|users\.noreply\.github\.com|noreply\.anthropic\.com)' \
  || true)
if [ -n "$real_addresses" ]; then
  printf '%s\n' "$real_addresses"
  printf '%s\n' 'Only reserved example email domains are allowed in this repository.' >&2
  exit 1
fi

# User-facing project content must not use an em-dash or an en-dash.
set +e
dashes=$(grep -R -n -I \
  --exclude-dir=.git \
  --exclude-dir=node_modules \
  --exclude-dir=vendor \
  --exclude=clean-room-check.sh \
  -e '—' -e '–' \
  README.md ROADMAP.md HANDOFF.md CHANGELOG.md CONTRIBUTING.md SECURITY.md docs plugin sandbox tests .github scripts)
dash_status=$?
set -e
if [ "$dash_status" -gt 1 ]; then
  printf '%s\n' 'The punctuation scan could not run, so it cannot be treated as passing.' >&2
  exit 1
fi
if [ -n "$dashes" ]; then
  printf '%s\n' "$dashes"
  printf '%s\n' 'User-facing project content contains an em-dash or en-dash.' >&2
  exit 1
fi

printf '%s\n' 'Tracked-env, secret-shape, example-domain, and user-facing punctuation checks passed.'
