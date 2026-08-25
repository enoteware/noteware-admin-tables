# ADR 0005: Record writes in an append-only audit log and undo conditionally

- Status: Accepted
- Date: 2026-08-25

## Context

Inline editing must leave evidence and provide a safe recovery path. Undo must not overwrite a value changed by another user or by another system after the original edit.

## Decision

Plugin edits use one edit service. The service verifies the nonce, authorizes the object and field, starts a transaction, locks the mutation boundary, reads and compares the current snapshot, validates and sanitizes the new value, writes, reads back, and records the audit entry in that order.

Audit records use a plugin-owned database table created with `dbDelta()`. Each record stores:

- an audit ID;
- object type and object ID;
- trusted column, adapter, and field locators;
- actor user ID and GMT time;
- typed old and new snapshots; and
- undo linkage and state.

Audit value snapshots are immutable. Undo adds a new audit row, then conditionally records the undo time, actor, and new audit ID on the original row. It never rewrites the original old or new snapshots.

An edit request includes the snapshot hash rendered with the field. The edit fails as stale if the current snapshot differs. Undo also fails as stale unless the current value still matches the original edit's new snapshot.

Undo must pass a nonce scoped to the audit record. It repeats the adapter's current object and field capability checks. It also requires that the adapter remains eligible and that the record has not already been undone.

The parent post and editable metadata row or insertion gap are locked inside a serializable transaction before the snapshot comparison. The write also includes the expected old value as a compare-and-swap condition. The metadata write and audit insert require InnoDB and share one checked transaction. An audit failure rolls back the value change and clears the metadata cache even if rollback itself reports an error. Stored audit data and configuration remain on uninstall by default. A future explicit administrator option may request removal.

## Consequences

- An external change is never silently overwritten by undo.
- A missing old value can be restored as missing.
- Audit access requires the same field authorization as the edit path.
- Integration tests must cover rollback, stale edits, repeated undo, and cross-object requests.

## Public API basis

- [WordPress `check_ajax_referer()` reference](https://developer.wordpress.org/reference/functions/check_ajax_referer/)
- [WordPress `current_user_can()` reference](https://developer.wordpress.org/reference/functions/current_user_can/)
- [WordPress AJAX action reference](https://developer.wordpress.org/reference/hooks/wp_ajax_action/)
- [WordPress `dbDelta()` reference](https://developer.wordpress.org/reference/functions/dbdelta/)
- [WordPress `wpdb` reference](https://developer.wordpress.org/reference/classes/wpdb/)
