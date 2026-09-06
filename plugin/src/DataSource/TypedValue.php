<?php
/**
 * Strict scalar types used by explicitly registered data providers.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\DataSource;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TypedValue
{
    public const TYPES = array('text', 'number', 'date', 'boolean');

    public static function parse(mixed $value, string $type): string|float|bool
    {
        if ('number' === $type) {
            if ((! is_int($value) && ! is_float($value) && (! is_string($value) || strlen($value) > 100 || ! preg_match('/^-?(?:\d+|\d*\.\d+)$/D', $value))) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Expected a finite number.');
            }
            return (float) $value;
        }
        if ('date' === $type && is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if ($date && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }
        if ('text' === $type && is_string($value) && strlen($value) <= 10000) {
            return $value;
        }
        if ('boolean' === $type && in_array($value, array(true, false, '0', '1', 0, 1), true)) {
            return in_array($value, array(true, '1', 1), true);
        }
        throw new InvalidArgumentException('The value does not match its declared type.');
    }
}
