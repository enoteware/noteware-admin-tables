<?php
/**
 * Connection-scoped database lock double for deterministic writer interleavings.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use PHPUnit\Framework\TestCase;

final class SegmentLockDatabase
{
    public string $options = 'example_options';
    public array $locks = array();
    public bool $failAcquire = false;
    private array $prepared = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared = $args;
        TestCase::assertContains($query, array('SELECT GET_LOCK(%s, 1)', 'SELECT RELEASE_LOCK(%s)'));
        TestCase::assertLessThanOrEqual(64, strlen($args[0]));
        return $query;
    }

    public function get_var(string $query): ?string
    {
        $name = $this->prepared[0];
        if (str_contains($query, 'RELEASE_LOCK')) {
            if (! isset($this->locks[$name])) {
                return null;
            }
            unset($this->locks[$name]);
            return '1';
        }
        if ($this->failAcquire) {
            return null;
        }
        if (isset($this->locks[$name])) {
            return '0';
        }
        $this->locks[$name] = true;
        return '1';
    }
}
