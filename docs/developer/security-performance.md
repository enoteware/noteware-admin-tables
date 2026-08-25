# Security and performance checks

## Write boundary

Inline editing is deny-by-default. The server resolves the requested screen, column, and adapter from validated configuration before it accepts any write detail.

Each write must verify:

1. a nonce scoped to the edit or undo operation;
2. the current user's object capability;
3. the current user's field capability;
4. strict input shape and length;
5. type-specific validation;
6. type-specific sanitization; and
7. the expected current snapshot.

A nonce helps prevent cross-site request forgery. It is not authorization. Capability checks are always separate.

Test missing and invalid nonces, object access, field access, unknown columns, arrays where scalars are required, stale snapshots, cross-object audit IDs, and repeated undo.

Editable metadata must have one row for its configured key. Duplicate rows are rejected before update or removal because the visible scalar snapshot cannot represent them safely.

## Output boundary

Keep stored data unescaped. Escape when it enters HTML, an attribute, or a URL. Plugin JavaScript treats server values as text unless the markup is created by the plugin and separately sanitized.

Test stored and reflected payloads in field values, labels, choice labels, image metadata, error messages, and query parameters.

## Query boundary

Only an allowlisted column ID may select a query rule. The browser cannot choose a metadata key, comparison operator, cast, clause name, SQL fragment, or callback.

Use `WP_Query` and `WP_Meta_Query` arguments. If a custom audit query is needed, use trusted table and column identifiers and prepare every value through `wpdb`.

Plugin metadata filters are grouped with `AND`. The plugin then combines that group with a pre-existing metadata query through a top-level `AND`, so an existing `OR` query keeps its meaning. Decimal values use a bounded fixed-precision cast rather than an integer-like numeric cast.

Metadata sort plans use `meta_query`, which makes WordPress core group results by post ID. A real-query regression proves that pre-existing duplicate metadata rows do not repeat a post or displace another post at a pagination boundary.

## Audit and undo

Audit rows are append-only. An edit fails if its audit record cannot be written. Undo checks current authorization and refuses to overwrite a value that no longer matches the original edit.

Plugin data remains installed by default. Do not add uninstall deletion without an explicit administrator opt-in and tests for both choices.

## Performance budget

The list page is the batch boundary. Once preload finishes:

- built-in scalar cell rendering adds zero database queries;
- metadata values come from the primed cache;
- ACF definitions and choices are reused;
- attachment records are loaded as one bounded set; and
- no operation reads the complete post table.

Automatic preload requires the exact configured edit screen and the main query. It does not prime caches on dashboards, other post-type screens, or secondary queries.

The fixture command creates at least 10,000 generic records and is safe to run more than once. The performance check reports row count, database query count, and elapsed time. It compares more than one page size so proportional query growth is visible.

Metadata sorting and filtering are marked as expensive because standard WordPress metadata value columns are not generally indexed for these operations. Keep requests paginated and document the measured sandbox result.

The first milestone shows an admin warning before a metadata sort or filter runs. A request with more than five active metadata filters fails closed instead of building an unbounded compound query.

## Release evidence

The pull request includes:

- local command output;
- CI links for the current head;
- the measured query count and response time;
- authenticated browser proof;
- accessibility evidence for available WordPress admin color schemes;
- clean-room and secret-scan results; and
- each automated reviewer's result for the current head.

The pull request stays open until Elliot approves merge.
