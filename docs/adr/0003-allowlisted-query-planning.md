# ADR 0003: Build sort and filter queries from allowlisted typed rules

- Status: Accepted
- Date: 2026-08-25

## Context

Admin request values are untrusted. Passing a requested metadata key, comparison operator, cast, order clause, or SQL fragment into a query would widen access and make cost hard to predict.

## Decision

Each sortable or filterable column owns an immutable query rule. Browser requests identify a column by an opaque plugin column ID. The query planner resolves that ID against validated configuration and creates a plan from hardcoded choices.

The first milestone supports exact scalar filters. Parsers enforce these rules:

- Text is scalar, has a fixed maximum length, and uses an exact comparison.
- Number is finite and within the adapter's configured range.
- Boolean accepts only the canonical true and false request values.
- Date uses a strict `Y-m-d` input shape and a real calendar date.
- Choice is an exact key from the configured choice map.

Sort direction accepts only `ASC` or `DESC`. Metadata comparison and cast values come from the field type, not the request. Native columns map to documented `WP_Query` order and filter arguments. Metadata columns map to named `meta_query` clauses with trusted keys and fixed comparison types.

The planner never accepts raw SQL, a request metadata key, a callback, or a free-form operator. Invalid input produces a safe no-op plus an admin error message. It does not fall back to a broader query.

Metadata sort and filter plans are marked as expensive. The list remains paginated. Performance tests record the query count and response time against the 10,000-record fixture.

Metadata sorting uses a named value clause plus an alternate `NOT EXISTS` clause so rows with absent values remain visible. The presence group is combined with any existing metadata query through a top-level `AND`, so sorting cannot broaden another component's filter.

## Consequences

- Query safety can be tested without issuing SQL.
- New comparison behavior requires a new typed rule and tests.
- The first milestone favors predictable exact filters over flexible search operators.
- Later indexed storage can replace a metadata plan without changing browser parameters.

## Public API basis

- [WordPress `WP_Query` reference](https://developer.wordpress.org/reference/classes/wp_query/)
- [WordPress `WP_Meta_Query` reference](https://developer.wordpress.org/reference/classes/wp_meta_query/)
- [WordPress `pre_get_posts` action reference](https://developer.wordpress.org/reference/hooks/pre_get_posts/)
