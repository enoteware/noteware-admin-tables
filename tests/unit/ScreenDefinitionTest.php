<?php
/**
 * Screen definition ordering and removal tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;
use PHPUnit\Framework\TestCase;

final class ScreenDefinitionTest extends TestCase
{
    public function test_a_screen_records_order_removal_and_replacement(): void
    {
        $screen = new ScreenDefinition(
            array($this->column('apply', 'title'), $this->column('note', null)),
            array('cb', 'nat_apply', 'nat_note'),
            array('comments')
        );

        self::assertSame(array('cb', 'nat_apply', 'nat_note'), $screen->order);
        self::assertSame(array('comments'), $screen->remove);
        self::assertSame(array('title'), $screen->replacedColumns());
    }

    public function test_duplicate_column_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null), $this->column('apply', null)));
    }

    public function test_the_checkbox_column_cannot_be_removed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array(), array('cb'));
    }

    public function test_plugin_columns_cannot_be_removed_through_the_removal_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array(), array('nat_apply'));
    }

    public function test_ordering_an_unconfigured_plugin_column_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array('nat_missing'));
    }

    public function test_a_column_cannot_be_ordered_and_removed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array('comments'), array('comments'));
    }

    public function test_a_replaced_column_cannot_also_be_removed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', 'title')), array(), array('title'));
    }

    public function test_repeated_order_entries_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array('cb', 'cb'));
    }

    public function test_malformed_order_entries_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScreenDefinition(array($this->column('apply', null)), array('Title Column'));
    }

    private function column(string $key, ?string $replaces): ColumnDefinition
    {
        return ColumnDefinition::fromArray(
            array_filter(
                array(
                    'key'      => $key,
                    'label'    => ucfirst($key),
                    'source'   => 'meta',
                    'type'     => 'text',
                    'field'    => $key,
                    'replaces' => $replaces,
                ),
                static fn (mixed $value): bool => null !== $value
            )
        );
    }
}
