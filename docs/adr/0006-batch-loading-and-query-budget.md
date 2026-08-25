# ADR 0006: Batch-load page data and give rendering a zero-query budget

- Status: Accepted
- Date: 2026-08-25

## Context

WordPress calls a custom column renderer once for each row and column. A data fetch inside that renderer can turn one list request into hundreds of database queries.

## Decision

The current page is the loading boundary. The loader collects its post IDs once, then prepares all values needed by registered columns before cell rendering begins.

The loader follows these rules:

- Reuse `WP_Query` post and metadata caches.
- Prime a missing metadata cache in one bounded call.
- Cache validated configuration, ACF field definitions, and reusable choice maps once per request.
- Collect image attachment IDs from cached values, then load attachment posts and metadata as one bounded set.
- Do not load all records for discovery, filtering, or display.
- Do not perform database queries from a cell renderer after page preload.

Built-in scalar renderers have a zero-query budget after page preload. Media preload may add a fixed number of queries, but that number must not grow with the row count.

The sandbox provides an idempotent 10,000-record fixture. Performance checks compare small and large pages, report query count and elapsed time, and fail when query growth is proportional to row count.

## Consequences

- Adapter read methods must work from a prepared cache.
- Image display needs a two-step preload.
- A new adapter must declare its preload needs.
- Query count is a release check, not only a manual observation.

## Public API basis

- [WordPress `update_meta_cache()` reference](https://developer.wordpress.org/reference/functions/update_meta_cache/)
- [WordPress `get_posts()` reference](https://developer.wordpress.org/reference/functions/get_posts/)
- [WordPress `wp_get_attachment_image()` reference](https://developer.wordpress.org/reference/functions/wp_get_attachment_image/)
- [WordPress `WP_Query` reference](https://developer.wordpress.org/reference/classes/wp_query/)
