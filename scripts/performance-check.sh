#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if [ ! -f .env ]; then
  printf '%s\n' 'Missing .env. Copy .env.example to .env and replace every placeholder.' >&2
  exit 1
fi

set -a
. "$repo_dir/.env"
set +a

max_seconds=${NAT_PERF_MAX_SECONDS:-5}
NAT_FIXTURE_COUNT=10000 bash "$repo_dir/scripts/seed-sandbox.sh"

docker compose run --rm wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/performance/query-count.php

perf_tmp_dir=$(mktemp -d)
trap 'rm -rf "$perf_tmp_dir"' EXIT HUP INT TERM
cookie_file="$perf_tmp_dir/cookies.txt"
login_body="$perf_tmp_dir/login.html"

login_url=$(curl -sS -L \
  --cookie-jar "$cookie_file" \
  --data-urlencode "log=$NAT_WP_ADMIN_USER" \
  --data-urlencode "pwd=$NAT_WP_ADMIN_PASSWORD" \
  --data-urlencode 'wp-submit=Log In' \
  --data-urlencode "redirect_to=$NAT_WP_URL/wp-admin/" \
  --output "$login_body" \
  --write-out '%{url_effective}' \
  "$NAT_WP_URL/wp-login.php")

case "$login_url" in
  *'/wp-admin/'*) ;;
  *)
    printf '%s\n' 'Could not authenticate the local performance request.' >&2
    exit 1
    ;;
esac

timings_file="$perf_tmp_dir/timings.txt"
: > "$timings_file"

run=1
while [ "$run" -le 5 ]; do
  curl -sS \
    --cookie "$cookie_file" \
    --output /dev/null \
    --write-out '%{time_total}\n' \
    "$NAT_WP_URL/wp-admin/edit.php?post_type=nat_demo_record" >> "$timings_file"
  run=$((run + 1))
done

median=$(sort -n "$timings_file" | awk 'NR == 3 { print $1 }')
maximum=$(sort -n "$timings_file" | tail -1)

if ! awk -v observed="$maximum" -v budget="$max_seconds" 'BEGIN { exit !(observed <= budget) }'; then
  printf 'Admin list response exceeded budget: max=%ss budget=%ss\n' "$maximum" "$max_seconds" >&2
  exit 1
fi

printf 'NAT_HTTP_PERFORMANCE median_seconds=%s max_seconds=%s budget_seconds=%s samples=5\n' \
  "$median" "$maximum" "$max_seconds"
