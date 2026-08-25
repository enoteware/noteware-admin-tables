<?php
/**
 * Main plugin bootstrap.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables;

use Noteware\AdminTables\Adapter\AcfAdapter;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Adapter\MetaAdapter;
use Noteware\AdminTables\Adapter\NativeAdapter;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Editing\EditController;
use Noteware\AdminTables\Query\QueryController;
use Noteware\AdminTables\Screen\PostScreenController;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $configuration = new Configuration();
        $adapters      = new AdapterRegistry(array(new NativeAdapter(), new MetaAdapter(), new AcfAdapter()));
        $audit         = new AuditRepository();

        add_action('admin_init', array($audit, 'maybeInstall'));
        (new PostScreenController($configuration, $adapters))->register();
        (new QueryController($configuration, $adapters))->register();
        (new EditController($configuration, $adapters, $audit))->register();

        do_action('noteware_admin_tables_loaded', $configuration, $adapters);
    }

    public static function activate(): void
    {
        (new AuditRepository())->install();
    }
}
