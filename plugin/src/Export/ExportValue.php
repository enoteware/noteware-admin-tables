<?php
/**
 * Lossless, scalar export boundary.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use InvalidArgumentException;
use Noteware\AdminTables\Model\StoredValue;

final class ExportValue
{
    /** @return array{exists: bool, state: string, type: string, value: string|null|bool} */
    public static function encode(StoredValue $stored): array
    {
        $value = $stored->value;
        if (! is_scalar($value) && null !== $value) {
            throw new InvalidArgumentException('Export requires an explicit scalar projection for complex fields.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Non-finite numbers cannot be exported.');
        }
        if (is_string($value) && (strlen($value) > 131068 || ! preg_match('//u', $value))) {
            throw new InvalidArgumentException('Export text must be bounded valid UTF-8.');
        }
        if (! $stored->exists && null !== $value) {
            throw new InvalidArgumentException('Absent fields must not carry a hidden value.');
        }
        $encoded = null;
        if (is_int($value)) {
            $encoded = (string) $value;
        } elseif (is_float($value)) {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($value) || is_bool($value)) {
            $encoded = $value;
        }
        return array('exists' => $stored->exists, 'state' => $stored->state(), 'type' => get_debug_type($value), 'value' => $encoded);
    }

    public static function text(StoredValue $stored): string
    {
        $value = self::encode($stored)['value'];
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    public static function csvText(string $text): string
    {
        // Neutralize only a dangerous leading token, including leading control/space bytes.
        // An ordinary address containing @ in its middle remains unchanged.
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $text) ? "'" . $text : $text;
    }
}
