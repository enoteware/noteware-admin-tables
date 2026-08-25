#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

fixture_count=${NAT_FIXTURE_COUNT:-60}

case "$fixture_count" in
  ''|*[!0-9]*)
    printf '%s\n' 'NAT_FIXTURE_COUNT must be a positive integer.' >&2
    exit 1
    ;;
esac

if [ "$fixture_count" -lt 1 ]; then
  printf '%s\n' 'NAT_FIXTURE_COUNT must be a positive integer.' >&2
  exit 1
fi

docker compose run --rm \
  -e NAT_FIXTURE_COUNT="$fixture_count" \
  wpcli wp eval-file wp-content/noteware-admin-tables-tests/fixtures/seed.php
