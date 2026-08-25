<?php
/**
 * Strict scalar input validation and sanitization.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Editing;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;

final class ValueValidator
{
    public static function validate(ColumnDefinition $column, mixed $raw): string
    {
        if (! is_string($raw) || strlen($raw) > 10000) {
            throw new InvalidArgumentException('The submitted value is not valid.');
        }

        return match ($column->type) {
            'text'    => sanitize_text_field($raw),
            'number'  => self::number($raw),
            'boolean' => self::boolean($raw),
            'select'  => self::choice($column, $raw),
            'date'    => self::date($raw),
            default   => throw new InvalidArgumentException('This field type is not editable.'),
        };
    }

    private static function number(string $raw): string
    {
        if (! preg_match('/^-?(?:\d+|\d*\.\d+)$/', $raw) || ! is_finite((float) $raw)) {
            throw new InvalidArgumentException('Enter a valid number.');
        }
        return $raw;
    }

    private static function boolean(string $raw): string
    {
        if (! in_array($raw, array('0', '1'), true)) {
            throw new InvalidArgumentException('Choose Yes or No.');
        }
        return $raw;
    }

    private static function choice(ColumnDefinition $column, string $raw): string
    {
        if (! array_key_exists($raw, $column->choices)) {
            throw new InvalidArgumentException('Choose an allowed value.');
        }
        return $raw;
    }

    private static function date(string $raw): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (! $date || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException('Enter a date in YYYY-MM-DD format.');
        }
        return $raw;
    }
}
