#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  printf '%s\n' 'Tracked .env files are not allowed.' >&2
  exit 1
fi

if rg -n -I -i \
  --glob '!.git/**' \
  --glob '!node_modules/**' \
  --glob '!vendor/**' \
  --glob '!package-lock.json' \
  --glob '!composer.lock' \
  --glob '!clean-room-check.sh' \
  'admin columns pro|sk_live_[A-Za-z0-9]+|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY' .; then
  printf '%s\n' 'The clean-room scan found a prohibited product name or secret-shaped value.' >&2
  exit 1
fi

if rg -n --glob '!clean-room-check.sh' '[—–]' README.md ROADMAP.md HANDOFF.md CHANGELOG.md CONTRIBUTING.md SECURITY.md docs plugin sandbox tests .github scripts; then
  printf '%s\n' 'User-facing project content contains an em-dash or en-dash.' >&2
  exit 1
fi

printf '%s\n' 'Clean-room, tracked-env, secret-shape, and user-facing punctuation checks passed.'
