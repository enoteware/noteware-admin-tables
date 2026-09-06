<?php
/**
 * Scalar projections for export writers.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class ExportProjection
{
    private const TYPES = array('text', 'number', 'boolean', 'select', 'date', 'url');

    public static function isExportable(ColumnDefinition $column): bool
    {
        return in_array($column->type, self::TYPES, true) && 'featured_image' !== $column->field;
    }

    public static function scalar(StoredValue $stored): StoredValue
    {
        if (! $stored->exists) {
            return new StoredValue(false, null);
        }
        if (is_array($stored->value)) {
            if (! array_is_list($stored->value)) {
                throw new InvalidArgumentException('Export requires an explicit scalar projection for complex fields.');
            }
            $parts = array();
            foreach ($stored->value as $item) {
                if (! is_string($item) && ! is_int($item)) {
                    throw new InvalidArgumentException('Export requires an explicit scalar projection for complex fields.');
                }
                $parts[] = (string) $item;
            }
            return new StoredValue(true, implode(',', $parts), $stored->displayLabel);
        }
        if (! is_scalar($stored->value) && null !== $stored->value) {
            throw new InvalidArgumentException('Export requires an explicit scalar projection for complex fields.');
        }
        return $stored;
    }
}
