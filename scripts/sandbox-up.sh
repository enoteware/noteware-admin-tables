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

case "$NAT_WP_ADMIN_PASSWORD $NAT_DB_PASSWORD $NAT_DB_ROOT_PASSWORD" in
  *replace-with-*)
    printf '%s\n' 'Replace all placeholder passwords in .env before starting the sandbox.' >&2
    exit 1
    ;;
esac

docker compose up -d db wordpress

attempt=0
until docker compose run --rm wpcli wp core version >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    printf '%s\n' 'WordPress files did not become ready in time.' >&2
    exit 1
  fi
  sleep 2
done

if ! docker compose run --rm wpcli wp core is-installed >/dev/null 2>&1; then
  docker compose run --rm wpcli wp core install \
    --url="$NAT_WP_URL" \
    --title="$NAT_WP_TITLE" \
    --admin_user="$NAT_WP_ADMIN_USER" \
    --admin_password="$NAT_WP_ADMIN_PASSWORD" \
    --admin_email="$NAT_WP_ADMIN_EMAIL" \
    --skip-email
fi

docker compose run --rm wpcli wp plugin activate noteware-admin-tables

if ! docker compose run --rm wpcli wp plugin is-installed advanced-custom-fields >/dev/null 2>&1; then
  docker compose run --rm wpcli wp plugin install advanced-custom-fields --activate
else
  docker compose run --rm wpcli wp plugin activate advanced-custom-fields
fi

docker compose run --rm wpcli wp rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli wp option update blog_public 0

printf 'Sandbox ready: %s\n' "$NAT_WP_URL"
