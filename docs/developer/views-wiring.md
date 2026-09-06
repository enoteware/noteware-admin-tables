# View module integration

The view module is registered in `Plugin::boot()` before `Configuration` is constructed:

```php
(new \Noteware\AdminTables\View\ViewController())->register();
$configuration = new Configuration();
```

No database migration is required. Assets are enqueued only on Tools > Table views. The module filters `noteware_admin_tables_config` at priority 999 and captures site configuration before applying the current user's presentation overlay. Site providers must run before priority 999.

CI runs `tests/integration/views-persistence.php` with `NAT_ISOLATED_VIEW_TEST=1`. Browser coverage lives in `tests/e2e/views-export.spec.js`. Remaining issue 5 work is listed in `views.md`.
