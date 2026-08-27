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

    public function test_select_choice_rules_keep_read_only_display_available(): void
    {
        $displayOnly = ColumnDefinition::fromArray(
            array('key' => 'state', 'label' => 'State', 'source' => 'meta', 'type' => 'select', 'field' => 'state')
        );
        $filterable = ColumnDefinition::fromArray(
            array(
                'key'        => 'state',
                'label'      => 'State',
                'source'     => 'meta',
                'type'       => 'select',
                'field'      => 'state',
                'filterable' => true,
                'choices'    => array('open' => 'Open'),
            )
        );
        $editableEmpty = ColumnDefinition::fromArray(
            array(
                'key'     => 'state',
                'label'   => 'State',
                'source'  => 'meta',
                'type'    => 'select',
                'field'   => 'state',
                'editable' => true,
                'choices' => array('' => 'Empty'),
            )
        );

        self::assertSame(array(), $displayOnly->choices);
        self::assertTrue($filterable->filterable);
        self::assertSame(array('open' => 'Open'), $filterable->choices);
        self::assertTrue($editableEmpty->editable);
        self::assertSame(array('' => 'Empty'), $editableEmpty->choices);
    }

    public function test_direct_constructor_cannot_bypass_select_choice_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnDefinition('state', 'State', 'meta', 'select', 'state', null, false, true, false, array(), 'Not set');
    }

    public function test_direct_constructor_rejects_empty_filter_choice_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnDefinition('state', 'State', 'meta', 'select', 'state', null, false, true, false, array('' => 'Empty'), 'Not set');
    }

    public function test_direct_constructor_cannot_make_images_filterable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnDefinition('photo', 'Photo', 'meta', 'image', 'photo', null, false, true, false, array(), 'Not set');
    }


    public function test_parity_options_are_captured(): void
    {
        $column = ColumnDefinition::fromArray(
            array(
                'key'           => 'apply',
                'label'         => 'Apply link',
                'source'        => 'acf',
                'type'          => 'url',
                'field'         => 'apply_url',
                'field_key'     => 'field_apply_url',
                'sortable'      => true,
                'filterable'    => true,
                'editable'      => true,
                'bulk_editable' => true,
                'operators'     => array('is', 'empty', 'not_empty'),
                'width'         => '18%',
                'replaces'      => 'title',
                'empty_label'   => 'No link',
            )
        );

        self::assertSame('18%', $column->width);
        self::assertTrue($column->bulkEditable);
        self::assertSame('title', $column->replaces);
        self::assertSame(array('is', 'empty', 'not_empty'), $column->operators);
        self::assertSame('is', $column->defaultOperator());
        self::assertTrue($column->supportsOperator('empty'));
        self::assertFalse($column->supportsOperator('unknown'));
    }

    public function test_a_read_only_column_reports_no_operator_support(): void
    {
        $column = ColumnDefinition::fromArray(
            array('key' => 'score', 'label' => 'Score', 'source' => 'meta', 'type' => 'number', 'field' => 'score')
        );

        self::assertFalse($column->supportsOperator('is'));
    }

    public function test_taxonomy_and_native_parity_columns_are_accepted(): void
    {
        $taxonomy = ColumnDefinition::fromArray(
            array(
                'key'        => 'region',
                'label'      => 'Region',
                'source'     => 'taxonomy',
                'type'       => 'select',
                'field'      => 'region',
                'filterable' => true,
                'editable'   => true,
                'operators'  => array('is', 'empty'),
            )
        );
        $thumbnail = ColumnDefinition::fromArray(
            array(
                'key'      => 'thumb',
                'label'    => 'Thumbnail',
                'source'   => 'native',
                'type'     => 'image',
                'field'    => 'featured_image',
                'editable' => true,
            )
        );
        $permalink = ColumnDefinition::fromArray(
            array('key' => 'link', 'label' => 'Link', 'source' => 'native', 'type' => 'url', 'field' => 'permalink')
        );

        self::assertTrue($taxonomy->editable);
        self::assertTrue($thumbnail->editable);
        self::assertFalse($permalink->editable);
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
        yield 'image filter' => array(array_replace($base, array('type' => 'image', 'filterable' => true)));
        yield 'filterable select without choices' => array(array_replace($base, array('type' => 'select', 'filterable' => true)));
        yield 'filterable select with empty choice value' => array(array_replace($base, array('type' => 'select', 'filterable' => true, 'choices' => array('' => 'Empty'))));
        yield 'editable select without choices' => array(array_replace($base, array('type' => 'select', 'editable' => true)));
        yield 'unknown operator' => array(array_replace($base, array('filterable' => true, 'operators' => array('like'))));
        yield 'repeated operator' => array(array_replace($base, array('filterable' => true, 'operators' => array('is', 'is'))));
        yield 'empty operator list' => array(array_replace($base, array('filterable' => true, 'operators' => array())));
        yield 'operators without filtering' => array(array_replace($base, array('operators' => array('empty'))));
        yield 'native operator beyond exact' => array(array_replace($base, array('source' => 'native', 'field' => 'status', 'type' => 'select', 'filterable' => true, 'choices' => array('publish' => 'Published'), 'operators' => array('is', 'empty'))));
        yield 'bulk without editing' => array(array_replace($base, array('bulk_editable' => true)));
        yield 'bulk image' => array(array_replace($base, array('source' => 'native', 'field' => 'featured_image', 'type' => 'image', 'editable' => true, 'bulk_editable' => true)));
        yield 'bad width' => array(array_replace($base, array('width' => '18 percent')));
        yield 'oversized width' => array(array_replace($base, array('width' => '99999px')));
        yield 'checkbox replacement' => array(array_replace($base, array('replaces' => 'cb')));
        yield 'bad replacement id' => array(array_replace($base, array('replaces' => 'Title Column')));
        yield 'field key without acf' => array(array_replace($base, array('field_key' => 'field_score')));
        yield 'acf date write' => array(array_replace($base, array('source' => 'acf', 'type' => 'date', 'field_key' => 'field_score', 'editable' => true)));
        yield 'unsupported native type' => array(array_replace($base, array('source' => 'native', 'field' => 'featured_image', 'type' => 'text')));
        yield 'native permalink write' => array(array_replace($base, array('source' => 'native', 'field' => 'permalink', 'type' => 'url', 'editable' => true)));
        yield 'taxonomy sort' => array(array_replace($base, array('source' => 'taxonomy', 'field' => 'region', 'type' => 'select', 'sortable' => true)));
        yield 'taxonomy wrong type' => array(array_replace($base, array('source' => 'taxonomy', 'field' => 'region', 'type' => 'text')));
        yield 'taxonomy bad name' => array(array_replace($base, array('source' => 'taxonomy', 'field' => 'Region:One', 'type' => 'select')));
    }
}
