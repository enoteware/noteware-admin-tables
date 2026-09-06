# Root wiring and remaining acceptance criteria

This slice is a working library. Existing plugin/bootstrap/configuration/assets files were not changed.

1. Register explicit trusted `ReadOnlySource` implementations at plugin boot. Never derive registrations or callbacks from saved configuration. Connect each implementation to documented WordPress queries or a separately reviewed allowlisted custom-table reader. No custom SQL or join implementation is delivered here.
2. Add actual users, media, comments, taxonomy-term and multisite-site screen adapters. Check the registry's entry capability and WordPress object permissions. Preserve pagination, row actions, screen identity and network/site boundaries. Do not interpret registry family names as completed support.
3. Wire complete filtered provider iterators into footer metrics. The registry currently supports typed exact equality filters only; translating the existing richer query plan requires a reviewed provider contract extension. Catch any traversal error and display an unavailable result. Never fall back to current-page totals. Verify real fixture counts, percentages and numeric/date statistics independently for every screen.
4. Add authorized personal/shared rule persistence and nonce-protected mutation controls. The library authorizes rule creation and resolution, but does not save/delete/hydrate rules. Revalidate current column types and permissions before applying persisted rules. Scope them by site, screen and view. Use the existing renderer to escape labels and apply both foreground and background colors.
5. Add value preview UI and test both theme modes in a real browser. Palettes pass mathematical contrast tests, but no rendered dark/light screenshot or overall accessibility claim is made.

Remaining issue 9 criteria: all actual new WordPress screen adapters; object-level permission fixture coverage; rendered formatting and metrics UI; global/private rule storage and edit/delete boundaries; exact-decimal metrics if required; real full-filtered-dataset fixture reconciliation; custom MySQL registration integration, authorized write adapters and bounded joins/references; multisite and concurrency behavior; live browser and large-data performance verification.

No dependency or manifest changes, production changes, database writes, SQL executor or arbitrary saved code execution were introduced.
