# ADR 0012: Separate screen permissions, registered reads and presentation calculations

Status: Proposed for integration

Core screen families have different entry capabilities. `CoreScreenRegistry` records these checks through public WordPress APIs. It does not register list-table hooks or authorize individual rows. Each future adapter must preserve WordPress object-level permissions.

Custom data access requires an explicitly registered PHP `ReadOnlySource`. Saved UI data cannot register providers, tables, SQL, callbacks or joins. The first contract supports typed equality filters and cursor-based pages capped at 200 rows. A complete traversal rejects unknown fields, duplicate identities, repeated cursors, oversized pages, revoked access and more than 100,000 rows. There is no custom write path.

Metrics consume a complete iterable and return no result when traversal fails. Counts retain absent, null, stored empty, false and zero states. Numeric statistics use compensated floating-point sums and are approximate, unsuitable for exact financial decimal calculations. Date statistics accept strict calendar dates. Providers remain responsible for stable snapshots and truthful filtered completeness.

Conditional formatting uses immutable authorized rule objects and three fixed palettes. Personal rules belong to their creator. Shared rule creation requires administrator permission. Highest numeric priority wins; equal priority uses lexical rule ID. The normal text contrast of all palettes is tested in light and dark mode. Renderers must retain text meaning and escape at output.

This module introduces no persistence, HTTP endpoints, core screen adapters, SQL builder or join executor. Those remain separate integration work rather than being implied by registry support.
