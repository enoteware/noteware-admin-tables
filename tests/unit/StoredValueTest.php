<?php
/**
 * Stored-value state tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;

final class StoredValueTest extends TestCase
{
    public function test_absent_empty_false_and_zero_are_distinct(): void
    {
        $values = array(
            new StoredValue(false, null),
            new StoredValue(true, ''),
            new StoredValue(true, false),
            new StoredValue(true, 0),
            new StoredValue(true, '0'),
        );
        foreach ($values as $leftIndex => $left) {
            foreach ($values as $rightIndex => $right) {
                self::assertSame($leftIndex === $rightIndex, $left->equals($right));
            }
        }
    }

    public function test_audit_shape_records_state_type_and_stable_hash(): void
    {
        $stored = new StoredValue(true, '0');

        self::assertSame(
            array('exists' => true, 'state' => 'zero', 'type' => 'string', 'value' => '0'),
            $stored->toArray()
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $stored->hash());
        self::assertSame($stored->hash(), (new StoredValue(true, '0'))->hash());
        self::assertNotSame($stored->hash(), (new StoredValue(true, 0))->hash());
        self::assertSame($stored->hash(), (new StoredValue(true, '0', 'Resolved label'))->hash());
    }
}
