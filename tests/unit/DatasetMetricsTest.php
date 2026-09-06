<?php
/**
 * Full iterable metrics regression tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Metrics\DatasetMetrics;
use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatasetMetricsTest extends TestCase
{
    public function testNumbersReconcileWithoutCollapsingStates(): void
    {
        $result = DatasetMetrics::calculate(array(new StoredValue(false, null), new StoredValue(true, ''), new StoredValue(true, '0'), new StoredValue(true, false), new StoredValue(true, '10'), new StoredValue(true, 'bad')), 'number');
        self::assertSame(6, $result['rows']);
        self::assertSame(2, $result['valid']);
        self::assertSame(10.0, $result['sum']);
        self::assertSame(5.0, $result['mean']);
        self::assertSame(1, $result['states']['zero']);
        self::assertSame(1, $result['states']['false']);
        self::assertEqualsWithDelta(100 / 3, $result['valid_percent'], 0.00001);
    }

    public function testDatesUseCalendarValidation(): void
    {
        $result = DatasetMetrics::calculate(array(new StoredValue(true, '2024-02-29'), new StoredValue(true, '2024-03-02'), new StoredValue(true, '2024-02-30')), 'date');
        self::assertSame('2024-02-29', $result['min']);
        self::assertSame('2024-03-02', $result['max']);
        self::assertSame(2, $result['span_days']);
        self::assertSame(1, $result['invalid']);
    }

    public function testNoValuesHaveNoInventedAverage(): void
    {
        $result = DatasetMetrics::calculate(array(), 'number');
        self::assertNull($result['mean']);
        self::assertNull($result['valid_percent']);
    }

    public function testCapFailsRatherThanReturningPartialMetrics(): void
    {
        $this->expectException(RuntimeException::class);
        DatasetMetrics::calculate(array_fill(0, 3, new StoredValue(true, '1')), 'number', 2);
    }
}
