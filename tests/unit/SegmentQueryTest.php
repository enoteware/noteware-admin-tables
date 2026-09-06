<?php
/**
 * Saved filters must survive current configuration validation before replay.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Contract\FieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;
use Noteware\AdminTables\Query\SegmentRequest;
use Noteware\AdminTables\Segment\SegmentDefinition;
use PHPUnit\Framework\TestCase;

final class SegmentQueryTest extends TestCase
{
    private function adapters(): AdapterRegistry
    {
        return new AdapterRegistry(array(new class implements FieldAdapter {
            public function source(): string
            {
                return 'meta';
            }
            public function supports(ColumnDefinition $column): bool
            {
                return true;
            }
            public function read(int $postId, ColumnDefinition $column): StoredValue
            {
                throw new InvalidArgumentException('The query compiler must never read rows.');
            }
        }));
    }

    private function column(): ColumnDefinition
    {
        return ColumnDefinition::fromArray(array('key' => 'amount', 'label' => 'Amount', 'source' => 'meta', 'type' => 'number', 'field' => 'amount', 'filterable' => true));
    }

    public function testRepeatedFieldConditionsRemainSeparateAndKeepZero(): void
    {
        $segment = SegmentDefinition::fromArray(array('id' => 'test', 'name' => 'Test', 'filters' => array(
            array('column' => 'amount', 'operator' => 'is', 'value' => '0'),
            array('column' => 'amount', 'operator' => 'is', 'value' => '1'),
        )));
        $requests = SegmentRequest::compile($segment, array($this->column()), $this->adapters());
        self::assertCount(2, $requests);
        self::assertSame('0', $requests[0]['nat_filter_amount']);
        self::assertSame('1', $requests[1]['nat_filter_amount']);
    }

    public function testRemovedColumnFailsClosed(): void
    {
        $segment = SegmentDefinition::fromArray(array('id' => 'test', 'name' => 'Test', 'filters' => array(array('column' => 'removed', 'operator' => 'is', 'value' => '0'))));
        $this->expectException(InvalidArgumentException::class);
        SegmentRequest::compile($segment, array($this->column()), $this->adapters());
    }

    public function testInvalidTypedValueFailsBeforeReplay(): void
    {
        $segment = SegmentDefinition::fromArray(array('id' => 'test', 'name' => 'Test', 'filters' => array(array('column' => 'amount', 'operator' => 'is', 'value' => 'not a number'))));
        $this->expectException(InvalidArgumentException::class);
        SegmentRequest::compile($segment, array($this->column()), $this->adapters());
    }

    public function testDisabledOperatorFailsBeforeReplay(): void
    {
        $segment = SegmentDefinition::fromArray(array('id' => 'test', 'name' => 'Test', 'filters' => array(array('column' => 'amount', 'operator' => 'gt', 'value' => '0'))));
        $this->expectException(InvalidArgumentException::class);
        SegmentRequest::compile($segment, array($this->column()), $this->adapters());
    }
}
