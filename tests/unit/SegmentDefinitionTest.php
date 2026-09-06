<?php
/**
 * Segment validation tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Segment\SegmentDefinition;
use PHPUnit\Framework\TestCase;

final class SegmentDefinitionTest extends TestCase
{
    public function testRetainsExplicitEmptyAndZeroValues(): void
    {
        $segment = SegmentDefinition::fromArray(array(
            'id' => 'zero', 'name' => 'Zero',
            'filters' => array(array('column' => 'amount', 'operator' => 'is', 'value' => '0')),
            'search' => '', 'status' => 'draft', 'sort' => array('column' => 'amount', 'direction' => 'ASC'),
        ));
        self::assertSame('0', $segment->toArray()['filters'][0]['value']);
        self::assertSame('', $segment->toArray()['search']);
    }

    public function testRejectsUnboundedConditions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SegmentDefinition::fromArray(array('id' => 'many', 'name' => 'Many', 'filters' => array_fill(0, 6, array('column' => 'a', 'operator' => 'is', 'value' => '1'))));
    }

    public function testRejectsRawQueryKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SegmentDefinition::fromArray(array('id' => 'bad', 'name' => 'Bad', 'meta_key' => 'arbitrary'));
    }

    public function testRejectsMalformedSort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SegmentDefinition::fromArray(array('id' => 'bad', 'name' => 'Bad', 'sort' => array('column' => 'a', 'direction' => 'SQL')));
    }
}
