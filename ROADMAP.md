# Product roadmap

## Product goal

Build a reusable WordPress plugin that gives site teams a strong admin-table workflow without a paid license. The plugin must work across client sites while keeping each site's field rules and presets in a separate configuration layer.

This is a feature-equivalent product goal. It is not a source-code clone. Contributors must not copy proprietary source code, assets, names, documentation, or visual trade dress from Admin Columns Pro or any other commercial product.

## Architecture

The plugin has five main layers:

1. A list-screen registry discovers supported WordPress admin screens.
2. Column definitions describe how values load, render, sort, filter, edit, and export.
3. Field adapters handle native WordPress fields, metadata, taxonomies, ACF, and later integrations.
4. A query layer translates sort and filter rules into safe WordPress queries.
5. A view layer stores ordered columns, widths, filters, permissions, and personal or shared presets.

All site-specific choices must live in configuration, not in the core plugin. The first supported configuration mechanism should be PHP filters or JSON that can be version controlled in a site plugin.

## Phase 0: clean-room foundation

Done when:

- The public repository has an explicit GPL-2.0-or-later declaration.
- Supported WordPress and PHP versions are documented.
- Architecture decision records cover the list-screen registry, field adapters, query safety, and configuration storage.
- CI runs syntax, coding-standard, static-analysis, unit, integration, and JavaScript checks where applicable.
- The Docker sandbox starts with demo content and ACF Free.
- No client data, names, credentials, or proprietary code appear in the repository.

## Phase 1: useful alpha

Support posts, pages, and custom post types.

- Add, remove, rename, reorder, and resize columns.
- Provide native field, post meta, taxonomy, featured image, author, date, ID, status, and word-count columns.
- Add column-level display settings and empty-value handling.
- Add sorting for native scalar fields and safe metadata types.
- Add filters for text, number, date, boolean, choice, taxonomy, author, and status values.
- Add safe inline editing for supported scalar and choice fields.
- Include capability checks, nonces, validation, sanitization, error states, and an audit record for each write.
- Add an undo path for edits made through the plugin.
- Add ACF adapters for text, number, email, URL, true/false, select, radio, checkbox, date, taxonomy, post object, user, and image fields.

## Phase 2: team workflows

- Bulk edit supported fields.
- Export the active filtered view to CSV.
- Save personal views.
- Publish shared views for selected roles.
- Add conditional formatting and row emphasis.
- Add column search and reusable column groups.
- Add media, user, comment, and taxonomy list screens.

## Phase 3: scale and compatibility

- Add WooCommerce adapters without making WooCommerce a core dependency.
- Add background export for large result sets.
- Add query-cost guards and clear warnings for unindexed metadata sorts.
- Add multisite support.
- Add import helpers that read existing configuration through public APIs or user-provided exports.
- Add a compatibility test matrix for current supported WordPress, PHP, ACF, and WooCommerce versions.

## Phase 4: community release

- Complete accessibility, privacy, internationalization, and security reviews.
- Produce installable release ZIP files and signed checksums.
- Publish user, developer, adapter, and migration documentation.
- Prepare a WordPress.org-compatible `readme.txt` and run the official validators.
- Establish a disclosure process, release cadence, and deprecation policy.

## Data and safety rules

- Default to read-only behavior for unknown field types.
- A field is editable only when an adapter explicitly implements read, validate, sanitize, authorize, write, audit, and undo behavior.
- Preserve empty, absent, false, zero, and null-like values as distinct states when the field API distinguishes them.
- Never bypass WordPress capability checks or plugin-specific field permissions.
- Never execute arbitrary PHP, SQL, shortcodes, or templates from saved column configuration.
- Escape output at the final rendering boundary.
- Paginate all discovery and export operations.
- Do not delete plugin data during uninstall unless the administrator explicitly opts in.

## Performance targets

- Do not add per-row database queries for built-in columns.
- Cache field definitions and reusable option lists.
- Detect expensive sorts and filters before running them.
- Keep ordinary list screens usable with at least 10,000 records in the sandbox fixture set.
- Include query-count and response-time checks in performance tests.

## First review milestone

The first pull request should deliver the Phase 0 foundation plus a narrow Phase 1 vertical slice:

- A registry for post list screens
- Native and metadata column definitions
- ACF text, number, true/false, select, date, and image display adapters
- Sorting and filtering for supported scalar types
- Inline editing for a small explicit allowlist of safe scalar types
- Write audit and undo for those edits
- Demo content, tests, CI, and developer documentation

The pull request must remain open until Elliot approves merge.
