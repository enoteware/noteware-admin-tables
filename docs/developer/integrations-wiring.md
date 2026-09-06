# Root integration wiring

1. Instantiate optional readers after plugins have loaded. Register only trusted site-configured Meta Box field IDs and actual live types. Use runtime availability as a graceful disabled-state signal, not as compatibility proof. Do not automatically register every catalog entry as an adapter.
2. Bridge `ReadResult` into a display-only column contract. Existing `StoredValue` requires known presence, while these projections intentionally do not claim it. Do not guess presence from a blank or null API value. Keep filter, sort, bulk edit, inline edit and undo controls disabled for this contract.
3. Pass only the bounded IDs from the current authorized post admin page. Both readers independently require `edit_post` for every ID before reading. The caller must also validate current screen, post type, view visibility and field visibility. Escape output and never print full API objects.
4. Add explicit optional dependency diagnostics using `IntegrationCatalog`. For unknown availability, show not implemented/unverified rather than installed or missing. Do not persist an `activated_tested` flag based on detection. Maintain actual test receipts separately with exact versions.
5. Run official activated plugin fixtures in an isolated sandbox before enabling these columns for release. Unit API doubles do not prove integration compatibility. No dependency installation, shared database mutation or production change was performed here.

The only public function dispatcher operations are `rwmb_get_field_settings`, `rwmb_get_value`, and `YoastSEO`. Adding another API requires a bounded adapter and tests. There is no generic saved callback execution, SQL, shortcode rendering or write dispatcher.

Static analysis has one localized suppression at the optional callable guard because the core-only dependency set does not declare these vendor functions. Runtime checks still require both the allowlist and a callable function. No broad analysis exclusions or dependency stubs were added.
