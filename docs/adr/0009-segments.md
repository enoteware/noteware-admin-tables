# ADR 0009: Scoped saved segments and typed scalar operators

Status: Proposed for integration

Segments hold query state independently of column views. A segment stores a bounded list of AND conditions, a configured sort column and direction, status, and search. Conditions use configured column identifiers, never metadata keys or SQL. Repeated metadata and taxonomy conditions remain separate AND clauses. Repeated native conditions fail closed because their WordPress arguments would otherwise overwrite each other.

Storage uses one site-local option for shared segments and one site-local user option for personal segments, scoped additionally by post type and view ID. Both scopes have a maximum of twenty segments. Segment reads require the post type's edit capability. Shared writes additionally require `manage_options`. The HTTP boundary must verify nonces. View visibility must be checked by the view owner before constructing the repository.

Each segment contains at most five conditions. Replay revalidates current columns, enabled operators, adapter support and typed values before applying conditions. Removed fields, stale operators and invalid values fail closed. Existing metadata and taxonomy query groups remain under AND. Metadata sorts retain missing rows and add the post ID as a deterministic tie breaker.

Metadata operators are typed and explicitly enabled per column. `empty` retains its legacy meaning of absent OR stored empty; `absent`, `present`, and `stored_empty` provide distinct state tests. WordPress owns SQL preparation and LIKE escaping. Negative comparisons follow WordPress metadata semantics and do not include absent keys.

The module adds no custom SQL, row reads, recursive condition trees or unbounded discovery. Existing pagination remains in force. Real WordPress pagination, duplicate-row and 10,000-record benchmarks remain integration gates. This change does not establish a missing-last ordering policy in both directions; it retains core NULL ordering and adds stable ties.

Every save, delete and default change acquires a prepared MySQL/MariaDB advisory lock for the database, site, post type, view and personal/shared scope. Acquisition waits at most one second; contention or database errors reject the change explicitly. A request-local guard also prevents nested calls on one database connection from acquiring the same lock recursively. While holding the lock, the repository invalidates request option/user-meta caches, reloads current state, and applies only the requested mutation. Distinct successful saves therefore preserve both segments. A delete and default update cannot resurrect a removed entry.

The lock is released in `finally` and automatically by the database when its connection closes. There are no expiring leases, persistent lock options or stale-owner takeovers. Runtime providers must route the lock and mutation through the same primary database connection; connection-pooling or database-routing replacements need their own compatibility verification. These are cooperative locks: external code that directly changes the storage options bypasses this repository's protection. They serialize operations rather than resolve conflicting edits to the same segment; later successful same-segment saves replace that segment.

Storage is capped at twenty segments in each personal/shared post-type/view scope. At the limit, updating an existing segment is allowed; creating another throws without changing state.

Public locking contract:
https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html
