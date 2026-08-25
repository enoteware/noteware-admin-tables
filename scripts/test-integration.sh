#!/usr/bin/env bash
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

bash "$repo_dir/scripts/seed-sandbox.sh"

docker compose run --rm wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/public-hooks.php

docker compose run --rm wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/security-boundaries.php

docker compose run --rm wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/query-safety.php
