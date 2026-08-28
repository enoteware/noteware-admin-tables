<?php
/**
 * Contract for adapters that validate their own filter input.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Contract;

use Noteware\AdminTables\Model\ColumnDefinition;

interface FilterableFieldAdapter extends FieldAdapter
{
    /**
     * Validate one exact filter value.
     *
     * @throws \InvalidArgumentException When the value is not an allowed filter input.
     */
    public function validateFilterValue(ColumnDefinition $column, string $raw): string;

    /**
     * The bounded filter choices this adapter offers, as value to label pairs.
     *
     * @return array<string, string>
     */
    public function filterChoices(ColumnDefinition $column): array;
}
