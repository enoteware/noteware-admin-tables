<?php
/**
 * Validated list-screen configuration for one post type.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Model;

use InvalidArgumentException;

final class ScreenDefinition
{
    public const COLUMN_PREFIX = 'nat_';

    private const MAX_ORDER = 100;

    private const MAX_REMOVED = 50;

    private const MAX_CLAIMED_PERCENT = 75.0;

    /**
     * @param list<ColumnDefinition> $columns  Configured plugin columns.
     * @param list<string>           $order    Final WordPress column ids, in order.
     * @param list<string>           $remove   Built-in WordPress column ids to hide.
     * @param string|null            $minWidth Least table width before the screen scrolls sideways.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $order = array(),
        public readonly array $remove = array(),
        public readonly ?string $minWidth = null
    ) {
        if (null !== $minWidth && ! preg_match('/^[1-9][0-9]{2,4}px$/', $minWidth)) {
            throw new InvalidArgumentException('A screen minimum width must be a pixel length between 100px and 99999px.');
        }
        $keys = array();
        foreach ($this->columns as $column) {
            if (isset($keys[$column->key])) {
                throw new InvalidArgumentException('Column keys must be unique within a screen.');
            }
            $keys[$column->key] = true;
        }

        $this->assertOrder($this->order);
        $this->assertRemoval($this->remove);

        foreach ($this->order as $id) {
            if (str_starts_with($id, self::COLUMN_PREFIX) && ! isset($keys[substr($id, strlen(self::COLUMN_PREFIX))])) {
                throw new InvalidArgumentException('The column order names a plugin column that is not configured.');
            }
        }
        foreach ($this->remove as $id) {
            if ('cb' === $id) {
                throw new InvalidArgumentException('The bulk action checkbox column cannot be removed.');
            }
            if (str_starts_with($id, self::COLUMN_PREFIX)) {
                throw new InvalidArgumentException('Remove plugin columns by dropping them from the column list, not the removal list.');
            }
            if (in_array($id, $this->order, true)) {
                throw new InvalidArgumentException('A column cannot be ordered and removed at the same time.');
            }
        }

        foreach ($this->columns as $column) {
            if (null !== $column->replaces && in_array($column->replaces, $this->remove, true)) {
                throw new InvalidArgumentException('A replaced built-in column is already removed by the replacement.');
            }
        }

        $this->assertWidthsFit();
    }

    /**
     * Built-in column ids that a configured column replaces.
     *
     * @return list<string>
     */
    public function replacedColumns(): array
    {
        $replaced = array();
        foreach ($this->columns as $column) {
            if (null !== $column->replaces) {
                $replaced[] = $column->replaces;
            }
        }
        return array_values(array_unique($replaced));
    }

    /** @param list<string> $ids Candidate WordPress column ids. */
    private function assertOrder(array $ids): void
    {
        if (count($ids) > self::MAX_ORDER) {
            throw new InvalidArgumentException('The column order list is too long.');
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('The column order list must not repeat a column.');
        }
        if (! $this->validIds($ids)) {
            throw new InvalidArgumentException('The column order list contains an invalid column id.');
        }
    }

    /** @param list<string> $ids Candidate WordPress column ids. */
    private function assertRemoval(array $ids): void
    {
        if (count($ids) > self::MAX_REMOVED) {
            throw new InvalidArgumentException('The column removal list is too long.');
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('The column removal list must not repeat a column.');
        }
        if (! $this->validIds($ids)) {
            throw new InvalidArgumentException('The column removal list contains an invalid column id.');
        }
    }

    /**
     * Percentage widths cannot claim more than the table has.
     *
     * WordPress still shows its own checkbox and title columns, so a screen
     * that claims every percent squeezes those to nothing and wraps their text
     * one character per line. Leaving a quarter of the table free keeps them
     * readable.
     */
    private function assertWidthsFit(): void
    {
        $claimed = 0.0;
        foreach ($this->columns as $column) {
            if (null === $column->width || ! str_ends_with($column->width, '%')) {
                continue;
            }
            $claimed += (float) rtrim($column->width, '%');
        }
        if ($claimed > self::MAX_CLAIMED_PERCENT) {
            throw new InvalidArgumentException('Configured percentage column widths must leave at least a quarter of the table for the built-in WordPress columns.');
        }
    }

    /** @param list<string> $ids Candidate WordPress column ids. */
    private function validIds(array $ids): bool
    {
        foreach ($ids as $id) {
            if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
                return false;
            }
        }
        return true;
    }
}
