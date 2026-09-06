# Screen expansion library

## Core entry permissions

`CoreScreenRegistry::canRead($family, $objectType)` checks the current WordPress user:

| Family | Entry capability |
| --- | --- |
| posts | Registered post type's `edit_posts` capability and `show_ui` |
| media | `upload_files` |
| users | `list_users` |
| comments | `edit_posts` |
| terms | Registered taxonomy's `manage_terms` capability and `show_ui` |
| sites | `manage_sites`, multisite enabled, and network admin context |

These are screen entry checks only. Row read/write permissions and current screen identity remain adapter responsibilities. Registry families do not mean those screens are implemented.

Public WordPress capability reference for comments:
https://developer.wordpress.org/reference/classes/wp_comments_list_table/ajax_user_can/

Public WordPress capability reference for sites:
https://developer.wordpress.org/reference/classes/wp_ms_sites_list_table/ajax_user_can/

## Full-dataset metrics and providers

Implement `ReadOnlySource` in trusted PHP and register it with `SourceRegistry::register()`. A source declares an ID, typed field map, current-user permission check, and bounded cursor page loader. The loader receives only normalized exact equality filters, page size and cursor. Each row has a stable string `id` and a field map of `StoredValue` objects. The final page has `next = null`.

```php
$registry->register($siteOwnedSource);
$result = DatasetMetrics::calculate(
    $registry->values('example', 'amount', array('state' => 'active')),
    'number'
);
```

The example source must explicitly declare both fields. Allowed scalar types are `text`, `number`, `date`, and `boolean`. Equality filtering is the provider's responsibility; the registry validates names and values before invoking it. Providers must enforce object permissions, filter the entire dataset, use stable ordering and cursor semantics, and avoid duplicates. The registry checks `canRead()` again before each page.

The registry allows fifty sources, one hundred fields each, five equality conditions per read, two hundred rows per page and at most one hundred thousand returned rows. Limits fail with an exception. They never turn a partial traversal into a complete metric.

Metrics return `rows`, `valid`, `invalid`, `states`, `valid_percent`, `sum`, `mean`, `min`, `max`, and `span_days`. Percentage is valid typed nonblank values divided by all traversed rows. Empty input has no percentage or mean. Numeric empty input has sum zero. Missing, null and empty strings are counted separately and excluded from typed statistics. Invalid typed values are counted but excluded. False is valid only for boolean metrics; zero is a valid number. Dates provide minimum, maximum and calendar span. Numeric arithmetic is floating point, so exact decimal accounting is not supported.

## Formatting and value previews

`FormattingRule::create($data, $shared)` takes `id`, `column`, `type`, `operator`, `value`, `tone`, and optional integer `priority` from zero to one hundred. Creation requires a signed-in user with `read`; shared creation additionally requires `manage_options`. Owner identity is taken from WordPress and cannot be supplied in saved rule data.

Operators are typed `is`, `absent`, and `stored_empty`; numbers and dates also support `gt` and `lt`. Tones are `notice`, `success`, and `danger`. Arbitrary style strings are rejected. `ConditionalFormatter::resolve($column, $type, $storedValue, $rules, $dark)` returns the winning rule ID, tone and foreground/background colors, or null. The same function can calculate a value preview. It skips private rules owned by another user and rules for a different column or type. It accepts at most one hundred rules.

There is no rule persistence or hydration API in this slice. Browser saves, deletion, ownership checks during persistence, nonce handling and actual preview UI remain unimplemented. A renderer must apply both palette colors and retain a readable text label; mathematical contrast checks do not replace browser QA.
