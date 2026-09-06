# Product acceptance catalog

This catalog is a minimum requirement inventory, not a parity statement. The public source is this project's dated issue scope. External version comparisons and older workflows stay in a separate private record. No external code, assets, prose or client fixtures are included.

Run from the repository root with Python 3.9 or later:

```bash
python3 scripts/check-acceptance.py
python3 -m unittest discover -s tests/acceptance -p 'test_*.py'
python3 scripts/check-acceptance.py --coverage
python3 scripts/check-acceptance.py --coverage --details
```

The first command checks catalog integrity. The coverage command returns **2 while requirements remain missing or unverified**. Exit 1 means malformed data or a contradictory claim. Only exit 0 from the coverage command means the catalog's mapped requirements have evidence. This gate does not authorize production migration. Full mapped acceptance, human review and explicit approval are still required.

`catalog.json` is maintained data. Each feature's `screens × fields × operations` expands into deterministic cell IDs: `feature/screen/field/operation`. Shared defaults avoid thousands of repeated JSON objects; `cell_overrides` record individual exceptions. No operation silently disappears. Unsupported operations stay missing until their owner either implements them or documents a reviewed applicability decision in the requirement scope. There is intentionally no automatic not-applicable escape hatch.

States mean:

- `missing`: the required behavior is not implemented at the inventoried revision, including explicitly unsupported operations.
- `unverified`: existing code or an unresolved capability needs exact-cell proof. This includes incomplete field inventories and unavailable dependencies.
- `implemented`: implementation, fixture and test references exist, the dependency is installed, and a passing receipt names that exact cell at the current Git HEAD. This is a reviewed claim backed by evidence, not a claim inferred from a filename.

`installed_state` is independent of implementation. No dependencies were activated during catalog work. Existing tests were inventoried from source, not executed. PHP data providers can expand a single method into many cases; inventory entries count selectors or whole integration suites, not executed cases. Integration scripts have suite-level IDs because they do not expose individually selectable assertion IDs. Never cite suite existence or historical aggregate counts as full coverage.

The initial inventory remains explicitly incomplete. Field/property inventories in issues 5 through 11 must replace pending placeholders and reconcile all documented capabilities. Missing native field variants, integration-owned properties, field-specific operator subcases and concrete integration screens must be added before any inventory-complete flag changes. Do not shrink the denominator to make a report pass. The validator checks structure and evidence references; source completeness and receipt truth still require independent review.

## Evidence contract

For each passed cell or release/improvement row, set `evidence` to one or more repository-relative JSON receipt paths. A receipt contains:

```json
{
  "revision": "full-current-git-head-sha",
  "date": "2026-09-05",
  "result": "pass",
  "subjects": ["native-fields/posts/title/display"],
  "command": "exact command actually executed",
  "environment": {"wordpress": "actual version", "php": "actual version", "dependencies": "actual activated versions", "roles": "actual roles", "fixture": "actual fixture configuration"},
  "reviewer": "identity of the reviewer who inspected the result",
  "test_ids": ["existing exact inventory ID"],
  "fixture_ids": ["demo-posts"],
  "output_path": "tests/artifacts/reviewed-output.txt"
}
```

This is a schema example, not passing evidence. Add actual new fixtures and test selectors to the inventory as owners implement them. Do not store credentials or private browser/session data in evidence. A receipt must point to real saved output and list exact subjects; no wildcard promotes other cells. Stale revision evidence fails. A test runner or reviewer must inspect logs and the actual delivery surface; the validator cannot establish whether a human-authored receipt is truthful.

## Release and improvement protocol

The release matrix lists required axes, not executed combinations. Enumerate supported WordPress/PHP/integration combinations and each affected screen/field/role before marking `release_cross_product_complete`. Licensed dependencies that cannot be installed remain unverified. The minimum versions come from README; current-version candidates require reconciliation with release policy. Include single-site and multisite, keyboard, screen readers, light and every supported dark appearance. OS dark preference alone does not validate a third-party admin dark theme.

Compare candidate and legally measured reference with the same synthetic dataset, machine, versions and cache conditions. Keep comparative provenance in the private record and publish only permitted original measurements. For timings use at least five paired runs and report medians, failures and fixture identity. No reference measurements exist in this catalog yet.

The improvement rows define measurable targets for query growth, task completion time, exact audit/undo recovery, mutation-free previews, resumable bulk work and accessible errors/focus. The existing performance script measures bounded query growth between 20-row and 100-row pages on a 10,000-record fixture; it is not a comparative runtime result. Export acceptance must open CSV/XLSX in actual consumers, parse JSON, reconcile every row and preserve precision, Unicode, multiline data and safe formula handling. Concurrency and rollback require failure injection and independent persisted-state readback.

Current owner requirements are linked in each feature. Catalog validation passing is useful infrastructure progress. Product coverage remains incomplete until every missing/unverified cell, inventory gap, release combination and improvement criterion has been resolved with evidence.
