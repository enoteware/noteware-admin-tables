# ADR 0008: Saved presentation views over a trusted catalog

Status: Proposed module; shared bootstrap wiring pending integration.

Views are version 1 presentation overlays. They reference configured column keys and may change only visibility, ordering, labels, and bounded widths. Site-controlled definitions remain authoritative for source identifiers, field adapters, and edit/filter/sort permissions. Checkbox and title stay first. Unknown options, stale columns, duplicate keys, unsupported versions, and invalid widths reject saves. Invalid stored layouts fall back to the site configuration.

Personal records use site-scoped WordPress user options. Shared records use non-autoloaded WordPress options and require `manage_options` to publish. The post-type editing capability is required for every operation. Shared visibility is checked against the current user's roles on every read; administrators can access all shared views. The HTTP write endpoint requires a WordPress admin nonce. There is no arbitrary user-id parameter.

A screen may store 30 personal and 30 shared views with at most 98 configured columns in each view. Reordering the array defines column order. Version-controlled configuration remains the reset target. The first UI saves copies, making existing records stable. No destructive uninstall cleanup is added.

The view id is a slug matching `^[a-z][a-z0-9_-]{0,63}$`. New ids are `v_` plus a UUID; `default` is reserved. Segments can reference the id without embedding filter state into this schema.

Read-modify-write option storage can lose concurrent saves from two tabs. This module targets small bounded view catalogs. A revision/compare-and-swap write protocol is required before collaborative simultaneous editing is advertised.

View names allow at most 100 Unicode characters (code points), not 100 UTF-8 bytes. The browser uses a Unicode-aware input pattern rather than `maxlength`, which counts UTF-16 units and would incorrectly shorten names containing emoji. Invalid UTF-8 is rejected on save and read.
