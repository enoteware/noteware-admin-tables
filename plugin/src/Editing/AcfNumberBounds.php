<?php
/**
 * Decimal bounds without rounding large or high-precision values to floats.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Editing;

use InvalidArgumentException;

final class AcfNumberBounds
{
    /** @param array<string, mixed> $field Live ACF definition. */
    public static function validate(string $value, array $field): void
    {
        foreach (array('min', 'max') as $name) {
            $bound = $field[$name] ?? '';
            if ('' === $bound) {
                continue;
            }
            if (! is_scalar($bound) || is_bool($bound) || ! preg_match('/^-?(?:\d+|\d*\.\d+)$/', (string) $bound) || strlen((string) $bound) > 100) {
                throw new InvalidArgumentException('This field has an unsupported numeric bound. Open the record to edit it.');
            }
            $comparison = self::compare($value, (string) $bound);
            if (('min' === $name && $comparison < 0) || ('max' === $name && $comparison > 0)) {
                throw new InvalidArgumentException('The number is outside the range allowed by this field.');
            }
        }
    }

    private static function compare(string $left, string $right): int
    {
        [$leftSign, $leftInteger, $leftFraction] = self::parts($left);
        [$rightSign, $rightInteger, $rightFraction] = self::parts($right);
        if ($leftSign !== $rightSign) {
            return $leftSign <=> $rightSign;
        }
        $comparison = strlen($leftInteger) <=> strlen($rightInteger);
        if (0 === $comparison) {
            $comparison = strcmp($leftInteger, $rightInteger);
        }
        if (0 === $comparison) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $comparison = strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0'));
        }
        return $leftSign * $comparison;
    }

    /** @return array{int, string, string} */
    private static function parts(string $value): array
    {
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $integer = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');
        $zero = '' === $integer && '' === $fraction;
        return array($negative && ! $zero ? -1 : 1, '' === $integer ? '0' : $integer, $fraction);
    }
}
