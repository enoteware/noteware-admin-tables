<?php
/**
 * Typed metadata query plans.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Query\MetadataCondition;
use PHPUnit\Framework\TestCase;

final class MetadataQueryTest extends TestCase
{
    private function column(string $type = 'text'): ColumnDefinition
    {
        return ColumnDefinition::fromArray(array('key' => 'sample', 'label' => 'Sample', 'source' => 'meta', 'type' => $type, 'field' => 'sample', 'filterable' => true));
    }

    public function testTypedRangeRetainsZeroAndPrecision(): void
    {
        $plan = MetadataCondition::plan($this->column('number'), 'between', '0', '1.000000000000000001');
        self::assertSame(array('0', '1.000000000000000001'), $plan['value']);
        self::assertSame('BETWEEN', $plan['compare']);
        self::assertSame('DECIMAL(65,30)', $plan['type']);
    }

    public function testTextMatchingIsEscapedByWordPressMetaQuery(): void
    {
        $plan = MetadataCondition::plan($this->column(), 'contains', '50%_off');
        self::assertSame('LIKE', $plan['compare']);
        self::assertSame('50%_off', $plan['value']);
    }

    public function testMissingAndStoredEmptyRemainDistinct(): void
    {
        self::assertSame('NOT EXISTS', MetadataCondition::plan($this->column(), 'absent')['compare']);
        self::assertSame('', MetadataCondition::plan($this->column(), 'stored_empty')['value']);
        self::assertSame('0', MetadataCondition::plan($this->column('number'), 'is', '0')['value']);
        self::assertSame('OR', MetadataCondition::plan($this->column(), 'empty')['relation']);
    }

    public function testRejectsTypeMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetadataCondition::plan($this->column('number'), 'contains', '2');
    }

    public function testRejectsIncompleteRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetadataCondition::plan($this->column('number'), 'between', '0');
    }
}
