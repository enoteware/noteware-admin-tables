# Scalar ACF editing integration

Root-owned change in `plugin/src/Model/ColumnDefinition.php`:

```php
private const ACF_EDITABLE_TYPES = array('text', 'url', 'select', 'number', 'boolean');
```

No interface, bootstrap, dependency, new public type, or UI change is required. Existing number and boolean controls submit strings. Site configuration must still explicitly set `editable` and optionally `bulk_editable` to true. Existing date and image restrictions remain.

The new unit tests exercise adapter methods using display-only definitions because the allowlist file belongs to root. Run the real-WordPress integration fixture after applying this allowlist change. Execute `tests/integration/editing-acf-scalars.php` via WP CLI only in a disposable database with `NAT_ISOLATED_EDITING_TEST=1`. It uses newly generated fixtures, installs the plugin audit table if needed, and tests actual edit/undo transactions. It must never run on production or the shared dummy database.

The sandbox demo now sets `editable` and `bulk_editable` on the generic ACF number and true/false columns. CI runs `tests/integration/editing-acf-scalars.php` with `NAT_ISOLATED_EDITING_TEST=1`. Browser coverage lives in `tests/e2e/views-export.spec.js`. Remaining issue 7 work is listed in `editing-expansion.md`.
