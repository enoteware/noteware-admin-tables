# View module integration

Register the view module in `Plugin::boot()` before constructing or registering consumers of `Configuration`:

```php
(new \Noteware\AdminTables\View\ViewController())->register();
$configuration = new Configuration();
```

No database migration is required. Composer's existing PSR-4 loader discovers the new classes. Assets are enqueued only on Tools > Table views. The module filters `noteware_admin_tables_config` at priority 999 and captures site configuration before applying the current user's presentation overlay. Site providers must run before priority 999.

Run the existing aggregate PHP and JavaScript checks; the new tests follow existing globs. A browser fixture must enable the registration above. Production and the shared dummy database were not modified by this module's author.
