# Export wiring handoff

`ExportController` is registered from `Plugin::boot()`. Do not mark issue 8 complete merely because isolated checks pass.

1. Done for this slice: authorized `admin_post` action, nonce, allowlisted `csv`/`json`/`xlsx`, visible overlay columns, no browser-supplied adapter or raw query. Taxonomy lists project to a comma-separated slug string. Image columns are omitted.
2. Done for this slice: `ExportRows` loader against `QueryController::applyFrozenFilters()`. Filters, search, status and selected-row intersection are frozen. Pagination is a stable post-ID keyset, not the interactive list sort. List-table sort is ignored so omitted/duplicated pages cannot come from an unstable metadata order.
3. Still open: durable background workers, byte/time quotas, cancellation UI and resume checkpoints. The current job is synchronous, stores a private file, discards the file on any exception and expires after one hour.
4. Done for this slice: owner-bound download with expiry, content headers and permission rechecks. Files live under `wp-content/nat-private-exports/` and are never linked as public uploads.
5. Still open: wiring `ViewEnvelope` into the view repository, import preview, templates and schema migrations.

Dependencies: XLSX needs PHP `ext-zip`; checks also use SimpleXML. Root should add an appropriate runtime feature check and CI coverage rather than make the entire plugin unavailable on a host without ZIP. No Composer library or JavaScript dependency was added. Existing PSR-4 loading discovers the new namespaces automatically.

Suggested CI checks are documented in `exports.md`. Add consumer validation to the integration pipeline using its own pinned reader environment. Unit tests include formulas, ordinary email, Unicode/multiline quoting, state distinction, large-integer precision, malformed imports, row budgets, duplicate IDs, cancellation and permission revocation. They do not prove production files or current/minimum version compatibility.
