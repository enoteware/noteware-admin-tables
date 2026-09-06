# Segment module

`SegmentDefinition::fromArray()` accepts only `id`, `name`, `filters`, `sort`, `status`, and `search`. Identifiers use lowercase slug characters, begin with a letter, and have at most 64 characters. `default` identifies the configured base view. Generated view IDs from the view module satisfy the same contract.

Example state:

```php
$segment = SegmentDefinition::fromArray(array(
    'id' => 'low_stock',
    'name' => 'Low stock',
    'filters' => array(
        array('column' => 'quantity', 'operator' => 'gte', 'value' => '0'),
        array('column' => 'quantity', 'operator' => 'lte', 'value' => '10'),
    ),
    'sort' => array('column' => 'quantity', 'direction' => 'ASC'),
    'status' => 'publish',
    'search' => '',
));
```

Both operators must be enabled in current configuration. A range instead uses `operator: between`, `value`, and `value_to`. Values remain strings so decimal precision and zero survive storage. Use `stored_empty` for an exact empty metadata string; an empty exact-value request remains the legacy clear-filter action.

`SegmentRepository($postType, $viewId)` provides `read($shared)`, `save($definition, $shared)`, `delete($id, $shared)` and `setDefault($idOrNull, $shared)`. `read()` returns `segments` keyed by ID and a nullable `default` ID. Storage is capped at twenty segments per post type and view in each personal/shared scope. At the cap, saving a new ID throws and preserves existing data; updating an existing ID remains allowed. Personal scope is the default. Identity comes from the current WordPress user and blog, not request fields. Deleting the default clears its reference. Failed persistence raises an exception unless readback equals the requested state.

Save, delete and default changes serialize through a prepared database advisory lock with a one-second acquisition limit. The lock scope includes database, site, screen, view and personal user ID or shared scope. After acquiring it, the repository clears its relevant option/user-meta caches and reloads state before changing it. A busy/unavailable lock raises an exception without writing; retry the requested operation after the active writer completes. Locks release in `finally` and when the connection closes, so a dead request cannot leave a permanent lock option. No timed lease takeover is used. Nested same-scope writes in one request are rejected to prevent advisory-lock reentrancy from bypassing serialization.

A lock-release error leaves the write outcome uncertain: re-read the saved state before retrying. The implementation assumes the standard single WordPress database connection throughout the mutation. Alternate database routing/pooling needs explicit compatibility testing. Direct external option writes are outside cooperative locking. Concurrent edits to the same segment remain later-successful-save-wins; unrelated segment changes are preserved.

`QueryController::applySegment($query, $postType, $definition)` applies a validated segment on the current editable main admin post query. It fails closed with `post__in = [0]` and an admin error when saved state is invalid. Callers must hydrate stored data through `SegmentDefinition::fromArray()` and catch validation errors at the query boundary, also forcing `[0]`. No default selection is automatic in this module.

`SegmentRequest::compile()` validates all conditions before replay. Repeated metadata/taxonomy conditions are retained. Repeated native fields are rejected. An explicit status that conflicts with a native status condition is rejected.

The query controller still supports the existing browser parameters and adds `nat_filter_to_COLUMN` for range upper bounds. The new scalar operator matrix is `MetadataCondition::operators($source, $type)`. Native and taxonomy behavior remains unchanged. Metadata text/URL adds `contains` and `not_contains`; number/date adds `gt`, `gte`, `lt`, `lte`, and `between`; scalar metadata adds `is_not` plus the distinct presence operators.

Not yet delivered: nonce-protected segment HTTP controls, clear/reset UI, automatic default precedence, relative dates, multi-value/relationship operators, additional list-screen families, browser acceptance tests and a fresh 10,000-record benchmark. A reversed range is allowed and naturally yields no matches. No range bound is swapped or rounded.
