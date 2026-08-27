# ADR 0004: Keep site configuration outside the core plugin

- Status: Accepted
- Date: 2026-08-25

## Context

Column choices, metadata keys, ACF field names, labels, and edit policies belong to a site. Shipping those values in the reusable plugin would couple the plugin to one installation and could expose private details.

## Decision

The first configuration provider is a WordPress filter. A site plugin or must-use plugin adds a PHP array through `noteware_admin_tables_config`. The array is keyed by post type and each screen contains a `columns` list. Each column declares a stable `key`, `label`, `source`, `type`, trusted `field`, behavior flags, and any bounded choice map.

Core configuration contains only reusable defaults and adapter definitions. Sandbox configuration lives in a sandbox-only must-use plugin outside the distributable plugin directory.

The configuration loader validates the full array once per request. It rejects:

- unknown post types, adapters, field types, or options;
- duplicate screen or column IDs;
- malformed field identifiers;
- executable values such as closures and callbacks;
- unknown options that could be mistaken for SQL, PHP, template, shortcode, or callback settings;
- non-scalar values where a scalar is required; and
- values over documented count or length limits.

Edit access defaults to false. Configuration can narrow an adapter's built-in capability policy. It cannot remove the adapter's object and field capability checks.

The PHP array shape is the first schema. The first milestone does not save site column definitions in plugin options. Versioned JSON files and saved views are deferred until their migration rules are defined.

## Consequences

- Site configuration can be reviewed and version controlled with the site code.
- The public plugin does not need private field keys.
- The demo proves configuration without making demo fields part of plugin behavior.
- Configuration examples must use generic fixture names and values.

## Public API basis

- [WordPress `add_filter()` reference](https://developer.wordpress.org/reference/functions/add_filter/)
- [WordPress `apply_filters()` reference](https://developer.wordpress.org/reference/functions/apply_filters/)
- [WordPress must-use plugin documentation](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/)

## Amendment, 2026-08-27: screen ordering, removal, replacement, and widths

A screen may now declare `order` and `remove` beside `columns`, and a column may declare `replaces` and `width`.

`order` names final WordPress column ids. Any column the list does not name keeps its relative position after the ordered ones, so a new WordPress column is never silently dropped. `remove` hides built-in columns only; a plugin column is removed by leaving it out of `columns`. The bulk action checkbox column cannot be removed or replaced, which keeps WordPress bulk actions, search, pagination, and screen options working.

`width` is a bounded CSS length rendered as a screen-scoped rule for that column. It is presentation configuration owned by the site, so it stays out of the plugin.

Every one of these options is validated with the rest of the configuration. An order entry naming a plugin column that is not configured, a duplicate entry, a malformed column id, or a column that is both ordered and removed is rejected.
