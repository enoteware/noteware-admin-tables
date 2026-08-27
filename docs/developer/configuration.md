# Site configuration

Noteware Admin Tables reads site choices from a site plugin or must-use plugin. Do not add a site's field names, labels, capabilities, or presets to the core plugin.

## Configuration contract

Use the `noteware_admin_tables_config` filter. Return an array keyed by an existing post type that has an admin user interface.

```php
<?php
/**
 * Example site configuration.
 *
 * @license GPL-2.0-or-later
 */

add_filter(
    'noteware_admin_tables_config',
    static function (array $configuration): array {
        $configuration['sample_record'] = array(
            'columns' => array(
                array(
                    'key'        => 'reference_score',
                    'label'      => __('Reference score', 'sample-site'),
                    'source'     => 'meta',
                    'type'       => 'number',
                    'field'      => 'sample_reference_score',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(),
                ),
            ),
        );

        return $configuration;
    }
);
```

`source` accepts `native`, `meta`, `acf`, or `taxonomy`. The supported types are `text`, `number`, `boolean`, `select`, `date`, `image`, and `url`. Every ACF definition must include its public `field_key` so the adapter can confirm the real field type. Only an ACF column may declare a `field_key`.

Editing stays deny by default and is limited to a documented set:

| Source | Editable types | Notes |
| --- | --- | --- |
| `meta` | `text`, `number`, `boolean`, `select`, `date`, `url` | Allowlisted WordPress metadata scalars. |
| `acf` | `text`, `url`, `select` | Written through the documented ACF write functions. |
| `taxonomy` | `select` | One term slug replaces the whole term set for that taxonomy. |
| `native` | `title`, `slug`, `featured_image` | Written through `wp_update_post()` and the WordPress thumbnail functions. |

ACF number, boolean, and date fields stay display only. Their stored formats need their own decision record before a write path is safe. The `permalink` native field is always display only.

### Column options

| Option | Purpose |
| --- | --- |
| `sortable` | Adds a WordPress sort control for the column. |
| `filterable` | Adds a filter control above the table. |
| `operators` | The enabled filter operators. Supported values are `is`, `empty`, and `not_empty`. Defaults to `is`. Native columns support `is` only. |
| `editable` | Enables the inline editor for the column. |
| `bulk_editable` | Adds the column to the bulk edit panel. Requires `editable`. |
| `width` | A bounded CSS length for the column, such as `18%` or `120px`. |
| `replaces` | The built-in WordPress column this column takes the place of, such as `date`. |
| `empty_label` | The text shown when nothing is stored. |

`empty` matches a record with no stored row and a record whose stored value is an empty string. `not_empty` requires both a stored row and a value that is not empty. That keeps a blank link, which is a meaningful state on many sites, findable in both directions.

### Screen options

A screen may also declare `order` and `remove`.

```php
$configuration['sample_record'] = array(
    'columns' => array( /* ... */ ),
    'order'     => array( 'cb', 'title', 'nat_reference_score' ),
    'remove'    => array( 'author' ),
    'min_width' => '1800px',
);
```

A screen may also declare `min_width`, a pixel length such as `2200px`. A list screen with many columns otherwise squeezes every column until its text wraps one character per line, including the WordPress title column. With `min_width` the table keeps its columns readable and the screen scrolls sideways instead.

Percentage column widths must together claim no more than 75 percent of the table. WordPress still renders its own checkbox and title columns, and a configuration that claims everything starves them.

`order` lists final WordPress column ids in the order they should appear. A plugin column appears as `nat_` plus its configured key. Any column the list does not name keeps its existing relative position after the ordered ones, so a later WordPress release cannot silently drop a column. `remove` hides built-in WordPress columns. The bulk action checkbox column cannot be removed, and a plugin column is removed by leaving it out of `columns` rather than by naming it here.

A filterable or editable `select` must define at least one bounded scalar entry in `choices`. A filterable select cannot use an empty choice key because the empty request value is reserved for its `All` option. Other choice keys are matched exactly after WordPress request unslashing. ACF select display labels come from the resolved field definition, so display-only ACF selects do not need to duplicate those labels. Image columns do not support filtering. Invalid behavior configuration is rejected instead of rendering a control that cannot accept a value.

## Safety rules

- Start every field as read-only.
- Enable editing only for a documented editable WordPress metadata type.
- Use stable opaque column IDs. Do not expose a field key in a request parameter.
- Use arrays and scalar values only.
- Do not place closures, SQL, PHP, templates, or shortcodes in a column definition.
- Do not put credentials, private URLs, production data, or client names in public examples.
- Let the adapter enforce object and field capabilities. A site capability may only add a stricter check.
- Keep `bulk_editable` off unless a wrong value applied to many records is genuinely recoverable through undo.

Invalid configuration is rejected. It does not become a partly trusted configuration.

## Sandbox configuration

Demo post types, fields, and values belong under `sandbox/`, not under `plugin/`. Use generic fixture names. Keep fixture setup idempotent so a developer can run it more than once.

See [ADR 0004](../adr/0004-external-site-configuration.md) for the decision and [WordPress must-use plugin documentation](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/) for the host mechanism.
