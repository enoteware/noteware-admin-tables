#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  printf '%s\n' 'Tracked .env files are not allowed.' >&2
  exit 1
fi

# Run one ripgrep scan and treat a tool error as a failure. A scan that cannot
# run is not a scan that passed.
scan() {
  scan_output=""
  set +e
  scan_output=$(rg -n -I -i \
    --glob '!.git/**' \
    --glob '!node_modules/**' \
    --glob '!vendor/**' \
    --glob '!package-lock.json' \
    --glob '!composer.lock' \
    --glob '!clean-room-check.sh' \
    "$1" .)
  scan_status=$?
  set -e

  if [ "$scan_status" -gt 1 ]; then
    printf '%s\n' 'The scan could not run, so it cannot be treated as passing.' >&2
    exit 1
  fi
  return 0
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
real_addresses=$(printf '%s\n' "$scan_output" | grep -v -E '@(example\.(test|com|org|net)|users\.noreply\.github\.com|noreply\.anthropic\.com)' || true)
if [ -n "$real_addresses" ]; then
  printf '%s\n' "$real_addresses"
  printf '%s\n' 'Only reserved example email domains are allowed in this repository.' >&2
  exit 1
fi

if rg -n --glob '!clean-room-check.sh' '[—–]' README.md ROADMAP.md HANDOFF.md CHANGELOG.md CONTRIBUTING.md SECURITY.md docs plugin sandbox tests .github scripts; then
  printf '%s\n' 'User-facing project content contains an em-dash or en-dash.' >&2
  exit 1
fi

printf '%s\n' 'Tracked-env, secret-shape, example-domain, and user-facing punctuation checks passed.'
