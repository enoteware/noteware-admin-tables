# Site configuration

Noteware Admin Tables reads site choices from a site plugin or must-use plugin. Do not add a site's field names, labels, capabilities, or presets to the core plugin.

## Configuration contract

Use the `noteware_admin_tables_config` filter. Return an array keyed by a public post type that has an admin user interface.

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

`source` accepts `native`, `meta`, or `acf`. The supported first-milestone types are `text`, `number`, `boolean`, `select`, `date`, and `image`. Every ACF definition must include its public `field_key` so the adapter can confirm the real field type. ACF columns are display-only in this milestone. Only allowlisted WordPress metadata scalar types may set `editable` to `true`.

A filterable or editable `select` must define at least one bounded scalar entry in `choices`. Invalid empty behavior configuration is rejected instead of rendering a control that cannot accept a value.

## Safety rules

- Start every field as read-only.
- Enable editing only for a documented editable WordPress metadata type.
- Use stable opaque column IDs. Do not expose a field key in a request parameter.
- Use arrays and scalar values only.
- Do not place closures, SQL, PHP, templates, or shortcodes in a column definition.
- Do not put credentials, private URLs, production data, or client names in public examples.
- Let the adapter enforce object and field capabilities. A site capability may only add a stricter check.

Invalid configuration is rejected. It does not become a partly trusted configuration.

## Sandbox configuration

Demo post types, fields, and values belong under `sandbox/`, not under `plugin/`. Use generic fixture names. Keep fixture setup idempotent so a developer can run it more than once.

See [ADR 0004](../adr/0004-external-site-configuration.md) for the decision and [WordPress must-use plugin documentation](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/) for the host mechanism.
