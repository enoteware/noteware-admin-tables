# 0010: Scalar streaming exports and presentation-only portability

Status: proposed module, pending application integration.

The export boundary accepts ordered scalar `StoredValue` projections rather than rendered HTML or direct database access. This preserves stored-state semantics and leaves query scope, record authorization and integration-owned reads with the application layer. Unsupported complex fields fail explicitly until an adapter defines a scalar projection.

CSV and XLSX include state/type companion columns. JSON carries typed cell envelopes with decimal-string numeric values to avoid consumer rounding. XLSX uses a disk-backed worksheet and shared strings with explicit types. Generating files requires private staging; partial bytes are never a successful downloadable artifact. The runtime ZIP extension is optional at plugin level and required only when XLSX is selected.

A bounded page iterator verifies progress, duplicate IDs, cancellation and current authorization. It is not a persistent scheduler or snapshot implementation. Those application boundaries need their own tests before full export acceptance.

Portable settings contain presentation overlays only. They reference the site's existing columns and cannot expand adapter definitions, field keys or editing permissions. Version 1 is validated strictly; unknown versions fail until a reviewed migration exists. Decoding never writes configuration or executes file content.

This design enables isolated tests without database mutation. Its tradeoff is deliberate caller responsibility for job durability, stable filtered queries, private downloads, atomic view storage and end-user interactions.
