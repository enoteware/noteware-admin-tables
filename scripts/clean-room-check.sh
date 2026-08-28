#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

for tool in grep git; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    printf '%s is required for this scan.\n' "$tool" >&2
    exit 1
  fi
done

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  printf '%s\n' 'Tracked .env files are not allowed.' >&2
  exit 1
fi

# Only tracked files are scanned. A developer's ignored .env holds real sandbox
# credentials, and scanning the working directory would both fail this check on
# a correct machine and print those credentials into the log.
tracked=()
while IFS= read -r -d '' tracked_file; do
  tracked+=("$tracked_file")
done < <(git ls-files -z \
  ':!:package-lock.json' \
  ':!:composer.lock' \
  ':!:scripts/clean-room-check.sh')

if [ "${#tracked[@]}" -eq 0 ]; then
  printf '%s\n' 'No tracked files were listed, so the scan cannot be treated as passing.' >&2
  exit 1
fi

# grep is called once with the whole list, so its exit code is grep's own: zero
# for a match, one for no match, and anything higher for a real failure. Piping
# through xargs would hide that behind the xargs exit code instead.
scan() {
  scan_output=""
  set +e
  scan_output=$(grep -n -I -i -E "$1" -- "${tracked[@]}")
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
prose=()
while IFS= read -r -d '' prose_file; do
  prose+=("$prose_file")
done < <(git ls-files -z -- \
  README.md ROADMAP.md HANDOFF.md CHANGELOG.md CONTRIBUTING.md SECURITY.md \
  docs plugin sandbox tests .github scripts \
  ':!:scripts/clean-room-check.sh')

if [ "${#prose[@]}" -eq 0 ]; then
  printf '%s\n' 'No user-facing files were listed, so the punctuation scan cannot be treated as passing.' >&2
  exit 1
fi

set +e
dashes=$(grep -n -I -e '—' -e '–' -- "${prose[@]}")
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

printf 'Scanned %s tracked files. Tracked-env, secret-shape, example-domain, and user-facing punctuation checks passed.\n' "${#tracked[@]}"
