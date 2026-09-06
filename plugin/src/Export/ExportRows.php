<?php
/**
 * Bounded, fail-closed page consumption independent of storage/query implementation.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use Generator;
use InvalidArgumentException;
use RuntimeException;
use Noteware\AdminTables\Model\StoredValue;

final class ExportRows
{
    /**
     * The loader must freeze the active view and use a deterministic snapshot/keyset cursor.
     * A null authorization subject checks job access; a row ID checks each record again.
     *
     * @param callable(?string, int): array{rows:list<array{id:string,values:array<string,StoredValue>}>,next:?string} $load
     * @param callable(?string):bool $authorize
     * @param callable():bool $cancelled
     * @return Generator<int,array<string,StoredValue>>
     */
    public static function iterate(callable $load, callable $authorize, callable $cancelled, int $pageSize = 100, int $maxRows = 10000): Generator
    {
        if ($pageSize < 1 || $pageSize > 500 || $maxRows < 1 || $maxRows > 100000) {
            throw new InvalidArgumentException('Export page or total row budget is invalid.');
        }
        $cursor = null;
        $cursors = array();
        $ids = array();
        do {
            self::guard($authorize, $cancelled, null);
            $page = $load($cursor, $pageSize);
            if (! isset($page['rows']) || ! is_array($page['rows']) || ! array_is_list($page['rows']) || count($page['rows']) > $pageSize || ! array_key_exists('next', $page)) {
                throw new RuntimeException('Export loader returned an invalid page.');
            }
            foreach ($page['rows'] as $row) {
                if (! is_array($row) || ! isset($row['id'], $row['values']) || ! is_string($row['id']) || '' === $row['id'] || strlen($row['id']) > 191 || ! is_array($row['values'])) {
                    throw new RuntimeException('Export loader returned an invalid row.');
                }
                if (isset($ids[$row['id']]) || count($ids) >= $maxRows) {
                    throw new RuntimeException('Export contains a repeated record or exceeds its row budget.');
                }
                self::guard($authorize, $cancelled, $row['id']);
                $ids[$row['id']] = true;
                yield $row['values'];
            }
            $cursor = $page['next'];
            if (null !== $cursor) {
                if (! is_string($cursor) || '' === $cursor || strlen($cursor) > 4096 || isset($cursors[$cursor]) || array() === $page['rows']) {
                    throw new RuntimeException('Export cursor did not make bounded progress.');
                }
                $cursors[$cursor] = true;
            }
        } while (null !== $cursor);
        self::guard($authorize, $cancelled, null);
    }

    /**
     * @param callable(?string):bool $authorize
     * @param callable():bool $cancelled
     */
    private static function guard(callable $authorize, callable $cancelled, ?string $id): void
    {
        if ($cancelled()) {
            throw new RuntimeException('Export was cancelled.');
        }
        if (! $authorize($id)) {
            throw new RuntimeException('Export permission was revoked or denied.');
        }
    }
}
