<?php
/** @package NotewareAdminTables */
declare(strict_types=1);
namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Export\ExportRows;
use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportRowsTest extends TestCase
{
    public function test_pages_keep_provider_sort_order_and_check_each_record(): void
    {
        $checked = array();
        $load = static fn (?string $cursor, int $limit): array => array('rows' => array(array('id' => null === $cursor ? '9' : '2', 'values' => array('a' => new StoredValue(true, null === $cursor ? 'first' : 'second')))), 'next' => null === $cursor ? 'page-2' : null);
        $authorize = static function (?string $id) use (&$checked): bool {
            $checked[] = $id;
            return true;
        };
        $rows = iterator_to_array(ExportRows::iterate($load, $authorize, static fn (): bool => false, 1));
        self::assertSame('first', $rows[0]['a']->value);
        self::assertSame('second', $rows[1]['a']->value);
        self::assertContains('9', $checked);
        self::assertContains('2', $checked);
    }

    public function test_permission_revocation_aborts_instead_of_silently_skipping(): void
    {
        $load = static fn (): array => array('rows' => array(array('id' => 'restricted', 'values' => array())), 'next' => null);
        $this->expectException(RuntimeException::class);
        iterator_to_array(ExportRows::iterate($load, static fn (?string $id): bool => null === $id, static fn (): bool => false));
    }

    public function test_cancelled_job_does_not_call_loader(): void
    {
        $called = false;
        $load = static function () use (&$called): array {
            $called = true;
            return array('rows' => array(), 'next' => null);
        };
        try {
            iterator_to_array(ExportRows::iterate($load, static fn (): bool => true, static fn (): bool => true));
            self::fail('Cancellation must throw.');
        } catch (RuntimeException $error) {
            self::assertFalse($called);
        }
    }

    public function test_repeated_record_cannot_be_exported_twice(): void
    {
        $load = static fn (): array => array('rows' => array(array('id' => '1', 'values' => array()), array('id' => '1', 'values' => array())), 'next' => null);
        $this->expectException(RuntimeException::class);
        iterator_to_array(ExportRows::iterate($load, static fn (): bool => true, static fn (): bool => false));
    }

    public function test_empty_nonterminal_page_cannot_loop_forever(): void
    {
        $load = static fn (): array => array('rows' => array(), 'next' => 'again');
        $this->expectException(RuntimeException::class);
        iterator_to_array(ExportRows::iterate($load, static fn (): bool => true, static fn (): bool => false));
    }
    public function test_permissions_are_rechecked_before_fetching_another_page(): void
    {
        $fetches = 0;
        $load = static function () use (&$fetches): array {
            ++$fetches;
            return array('rows' => array(array('id' => '1', 'values' => array())), 'next' => 'next');
        };
        // Capture by reference so the second page sees the changed permission state.
        $authorize = static function (?string $id) use (&$fetches): bool {
            return null !== $id || 0 === $fetches;
        };
        try {
            iterator_to_array(ExportRows::iterate($load, $authorize, static fn (): bool => false));
            self::fail('Revoked permission must stop before fetching.');
        } catch (RuntimeException $error) {
            self::assertSame(1, $fetches);
        }
    }
}
