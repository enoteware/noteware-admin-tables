# ADR 0002: Separate display adapters from deny-by-default edit adapters

- Status: Accepted
- Date: 2026-08-25

## Context

WordPress metadata and field integrations do not all represent missing and empty values in the same way. Editing also adds authorization, validation, storage, audit, and recovery duties that a display adapter does not need.

## Decision

Display and edit behavior use separate contracts.

A display adapter may read and format a supported field type. It cannot write. An editable adapter must explicitly provide all of these behaviors:

- capability authorization for the object and field;
- a nonce action for each write operation;
- raw-input validation;
- type-specific sanitization;
- a typed snapshot of the current value;
- the write operation;
- restoration of a prior snapshot; and
- a stable audit descriptor.

The edit service rejects a request unless the column is explicitly editable and the resolved adapter implements the complete edit contract. Unknown field types and incomplete adapters are read-only.

Values cross adapter boundaries as a typed value object. Its state is one of:

- `absent`;
- `null`;
- `empty_string`;
- `false`;
- `zero`; or
- `value`.

The snapshot also records the value type. This keeps integer zero, string zero, false, empty text, a missing record, and a supported null value distinct.

An adapter must document the states its upstream API can store. Plain WordPress metadata cannot preserve every PHP type after serialization. A boolean metadata adapter therefore uses a documented canonical representation. It does not pretend that an empty string and false are distinct if the storage API has already collapsed them.

The editable metadata adapter requires one row for its configured key. It rejects duplicate rows before mutation because a single visible snapshot cannot safely describe or restore multiple stored values.

The first milestone keeps ACF adapters display-only. ACF access uses documented functions. The adapter verifies that the configured field key resolves to the configured field name and type. The same support decision gates display reads, sorting controls, filter controls, and query planning. For scalar selects, the resolved ACF field is authoritative for current choice labels. The adapter keeps a bounded choice map as presentation metadata while preserving the raw value for identity and query behavior. A field fails closed if the map or any choice entry exceeds those bounds, so it never uses a partial authoritative map. Presentation labels do not enter snapshots, equality, hashes, audit data, or undo state.

## Consequences

- Adding display support never grants write access.
- Every write path has the same security and recovery surface.
- Undo can restore a missing metadata row by deleting it, while retaining a deliberately empty value by updating it.
- Adapter tests must cover each supported state and every required write method.

## Public API basis

- [WordPress `metadata_exists()` reference](https://developer.wordpress.org/reference/functions/metadata_exists/)
- [WordPress `get_post_meta()` reference](https://developer.wordpress.org/reference/functions/get_post_meta/)
- [WordPress `update_post_meta()` reference](https://developer.wordpress.org/reference/functions/update_post_meta/)
- [WordPress `delete_post_meta()` reference](https://developer.wordpress.org/reference/functions/delete_post_meta/)
- [WordPress `register_post_meta()` reference](https://developer.wordpress.org/reference/functions/register_post_meta/)
- [ACF `get_field()` reference](https://www.advancedcustomfields.com/resources/get_field/)
- [ACF `get_field_object()` reference](https://www.advancedcustomfields.com/resources/get_field_object/)

## Amendment, 2026-08-27: writable ACF, taxonomy, and native adapters

The adapter set now includes a taxonomy adapter, and the ACF and native adapters implement the editable contract.

- ACF `text`, `url`, and `select` fields are writable through `update_field()` and `delete_field()`. Their stored value is the same string the field API returns, so a snapshot, an audit row, and a readback all describe the same storage. ACF `number`, `boolean`, `date`, and `image` fields stay display only because their stored formats differ from their returned formats and need their own decision.
- Every ACF write confirms the ACF reference row after the write, and every removal confirms that both the value row and the reference row are gone.
- The taxonomy adapter reads a sorted list of term slugs. WordPress cannot store an empty term assignment, so a record with no terms is `absent` and never `empty_string`. An edit replaces the whole term set with one validated slug, and undo restores the complete previous list.
- The native adapter writes `title` and `slug` through `wp_update_post()` and `featured_image` through the WordPress thumbnail functions, each confirmed by a readback. `permalink` and `word_count` are computed and stay read only.
- The editable contract gained `supportsRemoval()` so a field with no meaningful empty state, such as a post title, neither renders a remove control nor accepts a remove request.
