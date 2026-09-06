# ADR 0014: Optional integrations expose narrow read projections before editing

Status: Proposed for integration

A dependency roster does not establish API compatibility, field editability or activated integration testing. The catalog separates runtime public-function availability from fields implemented by this module and activated test evidence. All entries currently report no activated proof and no writes.

The first readers use public Meta Box scalar field APIs and Yoast SEO's documented post projection. They validate page bounds, explicit field allowlists and object edit capabilities before reading any row. API calls are isolated behind a fixed three-function allowlist. No caller can submit a function name outside that allowlist.

Read projections preserve zero, false, empty string and null outputs, but do not claim knowledge of underlying storage presence. `ReadResult::toArray()` reports `storage_state = null` and `editable = false`. A rendered empty SEO description may be a computed fallback, not an absent database value. This result must not be converted to a writable `StoredValue` by guessing.

Meta Box fields must be explicitly configured and match live field ID/type definitions. Clone and multiple values are rejected. The reader primes WordPress post metadata once per page but makes no blanket query-count promise for integration internals. Yoast fields are restricted to title, description and canonical URL. Both readers cap a page at two hundred input IDs and result strings at ten thousand bytes.

No optional plugin was activated by this task. Contract doubles establish our validation behavior only. A future integration release requires official distributions, generated fixtures and real plugin API results on every claimed field/screen. Writes additionally require integration-owned APIs, nonce/capability checks, validation, audit and undo.
