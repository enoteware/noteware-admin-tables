<?php
/**
 * Tests for the plugin bootstrap.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function test_boot_emits_loaded_action_only_once(): void
    {
        Plugin::boot();
        Plugin::boot();

        self::assertArrayHasKey('noteware_admin_tables_loaded', $GLOBALS['nat_test_actions']);
        self::assertCount(1, $GLOBALS['nat_test_actions']['noteware_admin_tables_loaded']);
    }
}
