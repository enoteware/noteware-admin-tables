<?php
/**
 * Connection-scoped advisory lock double for deterministic view interleavings.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use PHPUnit\Framework\TestCase;

final class ViewLockDatabase
{
    public string $options = 'example_options';
    public array $locks = array();
    public array $acquired = array();
    public int $connection = 1;
    public bool $failAcquire = false;
    public bool $failRelease = false;
    public ?\Closure $afterAcquire = null;
    private array $prepared = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared = $args;
        TestCase::assertContains($query, array('SELECT GET_LOCK(%s, 1)', 'SELECT RELEASE_LOCK(%s)', 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()'));
        TestCase::assertLessThanOrEqual(64, strlen($args[0]));
        return $query;
    }

    public function get_var(string $query): ?string
    {
        $name = $this->prepared[0];
        if (str_contains($query, 'IS_USED_LOCK')) {
            return ($this->locks[$name] ?? null) === $this->connection ? '1' : '0';
        }
        if (str_contains($query, 'RELEASE_LOCK')) {
            if ($this->failRelease || ($this->locks[$name] ?? null) !== $this->connection) {
                return null;
            }
            unset($this->locks[$name]);
            return '1';
        }
        if ($this->failAcquire) {
            return null;
        }
        if (isset($this->locks[$name]) && $this->locks[$name] !== $this->connection) {
            return '0';
        }
        // Real MySQL permits recursive acquisition on the same connection.
        // The repository's static guard must reject that interleaving itself.
        $this->locks[$name] = $this->connection;
        $this->acquired[] = $name;
        if ($this->afterAcquire) {
            $callback = $this->afterAcquire;
            $this->afterAcquire = null;
            $callback();
        }
        return '1';
    }

    public function disconnect(): void
    {
        $this->locks = array_filter($this->locks, fn (int $owner): bool => $owner !== $this->connection);
        ++$this->connection;
    }
}
