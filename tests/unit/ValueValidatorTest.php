<?php
/**
 * Typed input validation tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Editing\ValueValidator;
use Noteware\AdminTables\Model\ColumnDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueValidatorTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_valid_scalar_values_preserve_meaning(string $type, string $raw, string $expected, array $choices = array()): void
    {
        self::assertSame($expected, ValueValidator::validate($this->column($type, $choices), $raw));
    }

    /** @return iterable<string, array{string, string, string, array<string, string>?}> */
    public static function validValues(): iterable
    {
        yield 'zero' => array('number', '0', '0');
        yield 'decimal' => array('number', '-1.25', '-1.25');
        yield 'false' => array('boolean', '0', '0');
        yield 'true' => array('boolean', '1', '1');
        yield 'choice' => array('select', 'safe', 'safe', array('safe' => 'Safe'));
        yield 'date' => array('date', '2026-08-25', '2026-08-25');
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(string $type, mixed $raw, array $choices = array()): void
    {
        $this->expectException(InvalidArgumentException::class);
        ValueValidator::validate($this->column($type, $choices), $raw);
    }

    /** @return iterable<string, array{string, mixed, array<string, string>?}> */
    public static function invalidValues(): iterable
    {
        yield 'array payload' => array('text', array('x'));
        yield 'sql-shaped number' => array('number', '1 OR 1=1');
        yield 'nan' => array('number', 'NaN');
        yield 'too many integer digits' => array('number', str_repeat('9', 36));
        yield 'too many decimal digits' => array('number', '0.' . str_repeat('9', 31));
        yield 'unknown boolean' => array('boolean', 'yes');
        yield 'unknown choice' => array('select', 'other', array('safe' => 'Safe'));
        yield 'bad date' => array('date', '2026-02-30');
        yield 'oversize' => array('text', str_repeat('a', 10001));
    }

    /** @param array<string, string> $choices */
    private function column(string $type, array $choices): ColumnDefinition
    {
        return ColumnDefinition::fromArray(array('key' => 'field', 'label' => 'Field', 'source' => 'meta', 'type' => $type, 'field' => 'field', 'editable' => true, 'choices' => $choices));
    }
}
