# WooCommerce order module integration

Register the optional admin consumer in `Plugin::boot()`:

```php
(new \Noteware\AdminTables\Integration\WooCommerce\OrdersPage())->register();
```

The menu callback checks WooCommerce availability at `admin_menu`, after plugins have loaded. There is no unconditional dependency on WooCommerce. No public column type, source allowlist, database migration, or existing adapter interface changes are needed.

The consumer appears under WooCommerce > Order data table. This is a dedicated read-only table; it is not yet a source for the generic column/view editor, inline editing, or CSV export.

Root must run `tests/integration/woocommerce-orders.php` against an isolated WooCommerce installation with `NAT_ISOLATED_WOOCOMMERCE_TEST=1`, once with HPOS and once with legacy order storage. The fixture creates generic orders and a refund without invoking a payment gateway. It checks the real admin HTML consumer as well as the query service. This fixture has not run in the module worktree.

Do not declare the whole plugin HPOS-compatible until the complete plugin matrix passes. No `FeaturesUtil::declare_compatibility` call is added by this module.
