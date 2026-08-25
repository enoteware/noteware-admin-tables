# ADR 0001: Register post list screens through WordPress hooks

- Status: Accepted
- Date: 2026-08-25

## Context

The plugin must support posts, pages, and custom post types without replacing the WordPress list table. It must not depend on private list-table methods or assumptions from another product.

## Decision

The registry reads post types from validated site configuration after WordPress registers them. `Configuration::postTypes()` exposes only configured post types that exist and have an admin user interface.

Each registered screen owns immutable column definitions. It connects them to documented WordPress extension points for:

- column headers;
- custom column cells;
- sortable column declarations;
- filter controls;
- main-query changes; and
- screen-specific scripts and styles.

Every query change must pass all of these gates:

1. The request is in the WordPress admin area.
2. The query is the main query.
3. The current page is the post list screen.
4. The post type is registered in validated plugin configuration.
5. The requested column resolves to a definition on that screen.

The registry does not replace `WP_List_Table`. It does not discover screens by running database queries. Unsupported screens remain unchanged.

The plugin uses the documented dynamic post-type cell action. WordPress core fires this action for posts, pages, and custom post types. It fires `manage_page_posts_custom_column` for pages after the general hierarchical action, so registering both actions would render Page cells twice.

## Consequences

- Core WordPress keeps ownership of pagination, row actions, bulk actions, and screen options.
- Posts, pages, and custom post types use one registry contract.
- Later screen types can use separate registry implementations.
- Integration tests must prove that hidden post types and unrelated admin queries are unchanged.

## Public API basis

- [WordPress post-type column filter reference](https://developer.wordpress.org/reference/hooks/manage_post_type_posts_columns/)
- [WordPress custom post-type column action reference](https://developer.wordpress.org/reference/hooks/manage_post-post_type_posts_custom_column/)
- [WordPress `restrict_manage_posts` action reference](https://developer.wordpress.org/reference/hooks/restrict_manage_posts/)
- [WordPress `pre_get_posts` action reference](https://developer.wordpress.org/reference/hooks/pre_get_posts/)
- [WordPress `admin_enqueue_scripts` action reference](https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/)
