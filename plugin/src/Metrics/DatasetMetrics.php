<?php
/**
 * Metrics over a complete bounded iterable, with explicit value states.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Metrics;

use DateTimeImmutable;
use InvalidArgumentException;
use Noteware\AdminTables\DataSource\TypedValue;
use Noteware\AdminTables\Model\StoredValue;
use RuntimeException;

final class DatasetMetrics
{
    /**
     * Numeric results use compensated floating-point arithmetic, not financial decimal arithmetic.
     *
     * @param iterable<StoredValue> $values Complete filtered values, not the displayed page.
     * @return array<string, mixed>
     */
    public static function calculate(iterable $values, string $type, int $maxRows = 100000): array
    {
        if (! in_array($type, TypedValue::TYPES, true) || $maxRows < 1 || $maxRows > 100000) {
            throw new InvalidArgumentException('Invalid metric type or row budget.');
        }
        $rows = 0;
        $valid = 0;
        $invalid = 0;
        $states = array('absent' => 0, 'null' => 0, 'empty_string' => 0, 'false' => 0, 'zero' => 0, 'value' => 0);
        $sum = 0.0;
        $correction = 0.0;
        $min = null;
        $max = null;
        foreach ($values as $stored) {
            if (++$rows > $maxRows) {
                throw new RuntimeException('The metric row budget was exceeded; no complete result is available.');
            }
            ++$states[$stored->state()];
            if (in_array($stored->state(), array('absent', 'null', 'empty_string'), true)) {
                continue;
            }
            try {
                $value = TypedValue::parse($stored->value, $type);
            } catch (InvalidArgumentException) {
                ++$invalid;
                continue;
            }
            ++$valid;
            if (in_array($type, array('number', 'date'), true)) {
                $min = null === $min || $value < $min ? $value : $min;
                $max = null === $max || $value > $max ? $value : $max;
            }
            if ('number' === $type) {
                $adjusted = (float) $value - $correction;
                $next = $sum + $adjusted;
                $correction = ($next - $sum) - $adjusted;
                $sum = $next;
                if (! is_finite($sum)) {
                    throw new RuntimeException('The numeric metric exceeds the supported finite range.');
                }
            }
        }
        return array(
            'rows' => $rows, 'valid' => $valid, 'invalid' => $invalid, 'states' => $states,
            'valid_percent' => $rows ? 100.0 * $valid / $rows : null,
            'sum' => 'number' === $type ? $sum : null,
            'mean' => 'number' === $type && $valid ? $sum / $valid : null,
            'min' => $min, 'max' => $max,
            'span_days' => 'date' === $type && is_string($min) && is_string($max) ? (new DateTimeImmutable($min))->diff(new DateTimeImmutable($max))->days : null,
        );
    }
}
