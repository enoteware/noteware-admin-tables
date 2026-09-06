# Table views module

After bootstrap integration, open Tools > Table views. Every screen configured by the site appears if the current user can edit that post type. Select a saved view and choose Switch view. The editor starts with its columns. Toggle columns, change labels and widths, use Up/Down buttons, enter a new name, and save a copy. The copy becomes the active view. Administrators can check roles to publish a shared copy. Choose Site default (reset) to restore version-controlled columns and widths.

Column search checks keys and labels. Up/Down buttons work with the keyboard, retain focus, and announce the new position. The editor scrolls horizontally at narrow widths. All labels are rendered as text. CSS includes explicit light and dark foreground, background, border, and focus colors.

## Persistence contract

- `nat_views_{postType}`: current site's user option, id to view record.
- `nat_shared_views_{postType}`: current site's option, id to view record, autoload disabled.
- `nat_active_view_{postType}`: current site's user option, selected id or `default`.

Records contain `version`, `id`, `name`, `post_type`, `visibility`, `roles`, and `columns`. Each column contains `key`, `label`, `width` (empty means site-independent auto width), and boolean `visible`. Array order controls presentation order. Role names must exist. The module never receives a user id from the browser.

## Scope still outstanding for issue 5

This is a working module, not completion of the broad issue. Pending features include discovery of fields beyond the configured catalog; icons; type-specific formatting; permission-narrowing toggles; rename/update/delete and ordering of saved views; direct dragging/resizing on list tables; separate per-view personal width overrides on shared views; sticky headers/columns; and configurable primary-column controls beyond retaining the WordPress title. No UI is offered for unknown source definitions.

The module needs the bootstrap change in `views-wiring.md`, isolated WordPress HTTP/nonces integration tests, and real-browser reopen, role restriction, light/dark contrast and responsive verification before acceptance. Unit and DOM tests alone are not evidence for those delivery-surface checks. JavaScript strings still require WordPress translation wiring.

View names allow at most 100 Unicode characters (code points), not 100 UTF-8 bytes. The browser uses a Unicode-aware input pattern rather than `maxlength`, which counts UTF-16 units and would incorrectly shorten names containing emoji. Invalid UTF-8 is rejected on save and read.
