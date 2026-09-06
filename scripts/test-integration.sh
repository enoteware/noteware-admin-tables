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

docker compose run --rm wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/parity-adapters.php

docker compose run --rm -e NAT_ISOLATED_VIEW_TEST=1 wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/views-persistence.php

docker compose run --rm -e NAT_ISOLATED_EDITING_TEST=1 wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/editing-acf-scalars.php

docker compose run --rm -e NAT_ISOLATED_EXPORT_TEST=1 wpcli \
  wp eval-file wp-content/noteware-admin-tables-tests/integration/export-download.php
