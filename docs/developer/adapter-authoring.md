# Adapter authoring

Adapters connect one known field type to the plugin. They are not a way to run site callbacks from saved configuration.

## Display adapter requirements

A display adapter must:

1. Confirm that it supports the configured field type.
2. Declare any data it needs during page preload.
3. Read from prepared WordPress caches where possible.
4. Return a typed value with an explicit value state.
5. Format without changing stored data.
6. Escape output at the final HTML boundary.

Unknown types are read-only and render a safe empty state. A missing integration must not cause a fatal error.

ACF display adapters use documented ACF functions. They verify the field type before formatting. Select labels come from the field's choice map. Image output uses WordPress attachment functions and batch-preloaded attachment data.

## Editable adapter requirements

An editable adapter must implement the complete `EditableFieldAdapter` contract. Missing one method means the column is not editable.

- `authorize` checks the object capability and the field capability.
- `nonceAction` scopes each edit and undo operation.
- `validate` rejects an invalid raw type or value.
- `sanitize` creates the canonical stored value.
- `lock` acquires the adapter's transaction-safe mutation boundary.
- `read` returns a typed snapshot with the current state.
- `write` uses a public WordPress API.
- `restore` supports every state claimed by the adapter.
- `auditDescriptor` returns stable trusted identifiers for the audit row.

The adapter does not trust a capability name, field key, type, or comparison rule from the browser. It gets those values from validated configuration.

## Value states

Tests must keep these cases separate when the upstream API can do so:

| State | Example meaning |
| --- | --- |
| `absent` | No metadata row exists. |
| `null` | The upstream API stores a meaningful null value. |
| `empty_string` | A metadata row exists with empty text. |
| `false` | A boolean field is false. |
| `zero` | A numeric field is zero. |
| `value` | A non-empty supported value exists. |

Do not claim support for a distinction that the storage API cannot preserve. Document the canonical storage representation for booleans, dates, and numbers.

## Required tests

Each display adapter needs type-match, type-mismatch, missing-value, formatting, escaping, and query-budget tests.

Each editable adapter also needs success, bad nonce, missing capability, invalid value, sanitization, write failure, audit rollback, stale snapshot, state restoration, and repeated-undo tests.

See [ADR 0002](../adr/0002-field-adapters-and-value-states.md) and [ADR 0005](../adr/0005-append-only-audit-and-conditional-undo.md).
