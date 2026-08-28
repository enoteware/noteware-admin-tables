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

Editable metadata must have one row for its configured key. Duplicate rows are rejected before update or removal because the visible scalar snapshot cannot represent them safely. An ACF column locks both its value row and its field reference row, so an edit cannot race a reference change.

Every adapter names the database tables its write touches, and an edit is refused unless all of them use a transaction engine. Checking only post metadata would let a taxonomy or native write commit outside the transaction.

A failed edit clears the metadata cache, the post cache, and the object term cache before the error is raised, so a persistent object cache cannot keep serving a value the transaction rolled back.

An ACF value whose field reference row is missing or points at a different field is refused rather than repaired in place. The audit snapshot records the value, not the reference, so a silent repair would leave undo unable to restore the original pair.

A required ACF field cannot be cleared or emptied from a list screen. `delete_field()` writes past the validation ACF would apply on its own form, so the field's own required setting is the only thing that keeps the value present.

Undo resolves every audited term slug to an existing term before assigning it. Passing a slug string would let WordPress create a missing term, which would turn an undo of a deleted term into term creation by someone who may only assign terms.

A taxonomy column must be registered for the screen's post type. A taxonomy with an admin interface but a different post type is refused at the screen, the query, and the write.

A bulk edit repeats the record-type and authorization checks after the lock is held. Until the record is locked, another request can change its owner or status, and the check before the lock would have been made against stale state.

Upgrading the audit schema marks rows that an earlier version recorded as undo results. Without that step, upgrading would present them as ordinary edits and let a user redo an edit they had already reversed.

A site restriction on an ACF reference key is honoured where the site registered that key. The check is deliberately conditional: an ACF reference key starts with an underscore, WordPress denies `edit_post_meta` on any such key by default, and an unconditional check would refuse every ACF edit on every site.

Bulk editing runs the same seven checks. It validates the submitted value once, before any record is touched, then repeats the capability check, lock, snapshot read, write, readback, and audit record on each record inside its own transaction. A denied or failing record is reported by ID and does not roll back the records that succeeded. See [ADR 0007](../adr/0007-bulk-editing-boundaries.md).

Inline editor controls and the bulk panel are rendered inside the WordPress list filter form, so their fields carry `data-field` rather than `name`. A `name` would serialize every rendered editor into the filter URL, which breaks the screen with a request that is too long as soon as a site configures several editable columns. An integration assertion guards this.

## Output boundary

Keep stored data unescaped. Escape when it enters HTML, an attribute, or a URL. Plugin JavaScript treats server values as text unless the markup is created by the plugin and separately sanitized.

Test stored and reflected payloads in field values, labels, choice labels, image metadata, error messages, and query parameters.

## Query boundary

Only an allowlisted column ID may select a query rule. The browser cannot choose a metadata key, comparison operator, cast, clause name, SQL fragment, or callback.

Use `WP_Query` and `WP_Meta_Query` arguments. If a custom audit query is needed, use trusted table and column identifiers and prepare every value through `wpdb`.

Plugin metadata filters are grouped with `AND`. The plugin then combines that group with a pre-existing metadata query through a top-level `AND`, so an existing `OR` query keeps its meaning. Decimal values use a bounded fixed-precision cast rather than an integer-like numeric cast.

Metadata sort plans use `meta_query`, which makes WordPress core group results by post ID. A real-query regression proves that pre-existing duplicate metadata rows do not repeat a post or displace another post at a pagination boundary.

## Audit and undo

Audit snapshots, actor data, field locators, and timestamps are immutable. Undo appends a new audit row, then updates only the guarded `undone_at`, `undone_by`, and `undo_audit_id` markers on the original row. An edit fails if its audit record cannot be written. Undo checks current authorization and refuses to overwrite a value that no longer matches the original edit.

An audit row records whether it was itself created by an undo. The list screen offers an undo control only for a user's own edit that has not been undone, is not itself an undo, and is less than 24 hours old. That keeps a reload-safe undo available for the person who made the change without turning undo into an unbounded redo control. Undo authorization is always rechecked on the server; the rendered control is a convenience, not a permission.

Plugin data remains installed by default. Do not add uninstall deletion without an explicit administrator opt-in and tests for both choices.

## Performance budget

The list page is the batch boundary. Once preload finishes:

- built-in scalar cell rendering adds zero database queries;
- metadata values come from the primed cache;
- ACF definitions and choices are reused;
- attachment records are loaded as one bounded set;
- term assignments for the page are primed in one call;
- undoable audit rows for the page are read in one query bounded by the page size and the configured column cap; and
- no operation reads the complete post table.

Automatic preload requires the exact configured edit screen and the main query. It does not prime caches on dashboards, other post-type screens, or secondary queries.

The fixture command creates at least 10,000 generic records and is safe to run more than once. The performance check reports row count, database query count, and elapsed time. It compares more than one page size so proportional query growth is visible.

Metadata sorting and filtering are marked as expensive because standard WordPress metadata value columns are not generally indexed for these operations. Keep requests paginated and document the measured sandbox result.

The first milestone shows an admin warning before a metadata sort or filter runs. A request with more than five plugin-added metadata filters fails closed instead of building an unbounded compound query.

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
