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

ACF display adapters use documented ACF functions. They verify that the field key resolves to the configured field name and type before formatting or enabling query controls. The first milestone accepts only single-value ACF selects with the `value` return format, no more than 200 live choices, choice keys no longer than 191 bytes, and scalar labels no longer than 200 bytes. Multiple, array-return, and oversized selects fail closed. A field with any oversized or unsupported choice entry also fails closed instead of using a partial map. The resolved field's bounded choice map is authoritative for select display labels. A missing current choice displays the raw key instead of a stale configured label. The presentation label does not change the raw value, snapshot, equality, hash, audit data, or undo state. Image output uses WordPress attachment functions and batch-preloaded attachment data.

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
- `supportsRemoval` states whether the column can be cleared at all.
- `transactionalTables` names every table the write touches, so the edit is refused unless all of them use a transaction engine.
- `auditDescriptor` returns stable trusted identifiers for the audit row.

`supportsRemoval` exists because not every editable field has a meaningful empty state. A post title and a post slug are always present, so the inline editor does not offer a remove control for them and the endpoint refuses a remove request. A featured image, a metadata row, an ACF value, and a term set can all be cleared.

The adapter does not trust a capability name, field key, type, or comparison rule from the browser. It gets those values from validated configuration.

## Adapter notes

### ACF writes

ACF values are written with `update_field()` and cleared with `delete_field()`. The plugin never writes an ACF value as raw post metadata. WordPress metadata writes expect slashed input, so the validated value is passed through `wp_slash()` first. After every write the adapter reads the value back and confirms the ACF reference row, `_` plus the field name, still holds the configured field key. A mismatch fails the transaction so no half-written ACF value is kept.

Undo re-validates an audited value against the field as it is now. A field can gain a required flag or lose a select choice after an edit was made, and `update_field()` applies no form validation, so an undo that skipped this could restore a value the field would refuse today.

A required ACF field is never removable and never accepts an empty value. An ACF value whose reference row is missing or points elsewhere is refused rather than repaired.

An ACF value has three distinct states that the adapter preserves: no rows at all, a stored empty string with its reference row, and a stored value. Clearing removes both rows. Saving an empty string keeps both rows.

### Links

A link is validated without being rewritten. An empty string stays empty. Any other value must have an `http` or `https` scheme, a host, no spaces or control characters, at most 2000 characters, and must survive `esc_url_raw()` unchanged. A link WordPress would rewrite is rejected with a clear message instead of being silently altered, so the stored link is always the link that was reviewed.

### Taxonomies

WordPress has no explicit empty term assignment, so a record with no terms reads as absent rather than empty. The stored value is a sorted list of term slugs. An inline edit replaces the whole term set with one chosen term, and clearing removes every term. Undo restores the complete previous slug list, not just the single term the edit replaced, so a multiple term record is never quietly reduced. A record that currently holds more than one term is not editable from the list screen. The inline editor carries a single term, so saving it would delete the rest, and simply opening the editor and pressing save would be enough to lose them. Undo still restores a whole term set, because it is given the audited list rather than one value.

Filter and edit values must match a live term slug, and the configured `choices` map, when present, narrows that list further. When a taxonomy has more terms than the bounded choice list, only a configured allowlist may be used and each slug is resolved on its own rather than by loading every term. Writes resolve slugs to existing term ids, so an undo can never create a term that was deleted. The taxonomy must also be registered for the screen's post type.

### Native fields

Title and slug writes go through `wp_update_post()` and are read back. WordPress can make a submitted slug unique, so a slug that comes back different is treated as a failed edit rather than an accepted one. A featured image write validates that the media item exists, is an image, and is readable by the current user before `set_post_thumbnail()` runs.

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

A taxonomy column uses only `absent` and `value`, because WordPress cannot store an empty term assignment.

Do not claim support for a distinction that the storage API cannot preserve. Document the canonical storage representation for booleans, dates, and numbers.

## Required tests

Each display adapter needs type-match, type-mismatch, missing-value, formatting, escaping, and query-budget tests.

Each editable adapter also needs success, bad nonce, missing capability, invalid value, sanitization, write failure, audit rollback, stale snapshot, state restoration, and repeated-undo tests.

See [ADR 0002](../adr/0002-field-adapters-and-value-states.md) and [ADR 0005](../adr/0005-append-only-audit-and-conditional-undo.md).
