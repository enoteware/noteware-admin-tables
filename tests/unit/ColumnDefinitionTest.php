<?php
/**
 * Column definition safety tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnDefinitionTest extends TestCase
{
    public function test_editing_is_disabled_by_default(): void
    {
        $column = ColumnDefinition::fromArray(array('key' => 'score', 'label' => 'Score', 'source' => 'meta', 'type' => 'number', 'field' => 'score'));
        self::assertFalse($column->editable);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_or_unsafe_definitions_are_rejected(array $definition): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColumnDefinition::fromArray($definition);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidDefinitions(): iterable
    {
        $base = array('key' => 'score', 'label' => 'Score', 'source' => 'meta', 'type' => 'number', 'field' => 'score');
        yield 'bad key' => array(array_replace($base, array('key' => '../../score')));
        yield 'unknown source' => array(array_replace($base, array('source' => 'sql')));
        yield 'unknown type' => array(array_replace($base, array('type' => 'template')));
        yield 'unknown option' => array(array_replace($base, array('sql' => 'SELECT 1')));
        yield 'array field' => array(array_replace($base, array('field' => array('score'))));
        yield 'callback label' => array(array_replace($base, array('label' => static fn (): string => 'Score')));
        yield 'non boolean flag' => array(array_replace($base, array('sortable' => 'yes')));
        yield 'malformed field' => array(array_replace($base, array('field' => 'score; DROP TABLE')));
        yield 'native write' => array(array_replace($base, array('source' => 'native', 'editable' => true)));
        yield 'unsupported native sort' => array(array_replace($base, array('source' => 'native', 'field' => 'status', 'type' => 'select', 'sortable' => true)));
        yield 'acf write without field key' => array(array_replace($base, array('source' => 'acf', 'editable' => true)));
        yield 'image write' => array(array_replace($base, array('type' => 'image', 'editable' => true)));
    }
}
