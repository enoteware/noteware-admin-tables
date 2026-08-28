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
    private const MAX_URL_LENGTH = 2000;

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
            'url'     => self::url($raw),
            default   => throw new InvalidArgumentException('This field type is not editable.'),
        };
    }

    private static function number(string $raw): string
    {
        if (! preg_match('/^-?(?:\d+|\d*\.\d+)$/', $raw) || ! is_finite((float) $raw)) {
            throw new InvalidArgumentException('Enter a valid number.');
        }
        $parts          = explode('.', ltrim($raw, '-'), 2);
        $integerDigits  = max(1, strlen(ltrim($parts[0], '0')));
        $fractionDigits = isset($parts[1]) ? strlen($parts[1]) : 0;
        if ($integerDigits > 35 || $fractionDigits > 30) {
            throw new InvalidArgumentException('Enter a number with no more than 35 integer and 30 decimal digits.');
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

    /**
     * Validate a link without changing it.
     *
     * An empty string is a meaningful stored state and stays empty. Any other
     * value must already be a safe absolute HTTP or HTTPS link that WordPress
     * would not rewrite, so a saved link is always the exact link that was
     * reviewed.
     */
    private static function url(string $raw): string
    {
        if ('' === $raw) {
            return '';
        }
        if (strlen($raw) > self::MAX_URL_LENGTH) {
            throw new InvalidArgumentException('Enter a link with no more than 2000 characters.');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $raw)) {
            throw new InvalidArgumentException('Links cannot contain spaces or control characters.');
        }

        $parts = wp_parse_url($raw);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Enter a full link that starts with http:// or https://.');
        }
        if (! in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
            throw new InvalidArgumentException('Only http and https links are allowed.');
        }
        if ('' === (string) $parts['host']) {
            throw new InvalidArgumentException('Enter a full link that includes a site address.');
        }

        $safe = esc_url_raw($raw, array('http', 'https'));
        if ('' === $safe || $safe !== $raw) {
            throw new InvalidArgumentException('That link contains characters WordPress would rewrite. Paste the exact final link.');
        }

        return $raw;
    }
}
