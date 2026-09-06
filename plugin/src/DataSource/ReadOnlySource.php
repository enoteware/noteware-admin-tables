<?php
/**
 * Explicit PHP registration contract for permission-aware bounded data reads.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\DataSource;

use Noteware\AdminTables\Model\StoredValue;

interface ReadOnlySource
{
    public function id(): string;

    /** @return array<string, string> Field identifier to scalar type. */
    public function fields(): array;

    /**
     * Resolve the current user's permissions on every call.
     *
     * @phpstan-impure
     */
    public function canRead(): bool;

    /**
     * Return a stable, duplicate-free page for the exact typed equality filters.
     * A null next cursor means the complete filtered dataset has been exhausted.
     *
     * @param array<string, string|float|bool> $equals Validated typed equality filters.
     * @return array{rows: list<array{id: string, values: array<string, StoredValue>}>, next: ?string}
     */
    public function page(array $equals, int $limit, ?string $cursor): array;
}
