<?php
/**
 * Column renderer boundary tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;
use Noteware\AdminTables\Screen\ColumnRenderer;
use PHPUnit\Framework\TestCase;

final class ColumnRendererTest extends TestCase
{
    public function test_non_scalar_select_values_render_as_empty(): void
    {
        $column = ColumnDefinition::fromArray(
            array(
                'key'     => 'choice',
                'label'   => 'Choice',
                'source'  => 'meta',
                'type'    => 'select',
                'field'   => 'choice',
                'choices' => array('alpha' => 'Alpha'),
            )
        );
        $renderer = new ColumnRenderer();
        $stored   = new StoredValue(true, array('alpha'));

        self::assertSame('', $renderer->text($column, $stored));
        self::assertSame('<span class="nat-empty">Empty</span>', $renderer->value($column, $stored));
    }
}
