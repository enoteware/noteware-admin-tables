# ADR 0007: Audit every bulk change on its own object boundary

- Status: Accepted
- Date: 2026-08-27

## Context

Site teams need to set one value on many records at once. A naive bulk write would use a single transaction, a single capability check, and a single audit row. That would hide partial failures, allow one denied record to roll back permitted work, and leave changes that cannot be reversed one record at a time.

## Decision

Bulk editing reuses the inline editing contract without weakening it.

- The request carries the screen post type, a screen nonce, one column key, one value, and a bounded list of record IDs. The plugin accepts at most 100 records per request.
- The column must be configured as both `editable` and `bulk_editable`. Image columns cannot be bulk edited because a media choice is per record.
- The value is validated and sanitized once, before any record is touched. An invalid value fails the whole request without a write.
- Each record then runs the normal path on its own: object capability check, serializable transaction, adapter lock, snapshot read, adapter write, readback, and one audit row.
- A record that fails rolls back only its own transaction and is reported by ID with a precise message. Permitted records in the same request still complete.
- The response returns one audit ID and one undo nonce per changed record, so a bulk change can be reversed record by record through the same conditional undo endpoint.

There is no per-record client snapshot in a bulk request. The authoritative before value is the one read inside the lock, and it is what the audit row records.

## Consequences

- A partial bulk failure is visible and precise instead of silent.
- No write can happen without its own audit row.
- Undo of a bulk change is the same reviewed code path as undo of a single edit.
- Bulk editing cannot be used to bypass a capability, a nonce, or a validation rule.

## Public API basis

- [WordPress `check_ajax_referer()` reference](https://developer.wordpress.org/reference/functions/check_ajax_referer/)
- [WordPress `current_user_can()` reference](https://developer.wordpress.org/reference/functions/current_user_can/)
- [WordPress `wp_send_json_success()` reference](https://developer.wordpress.org/reference/functions/wp_send_json_success/)
