<?php
/**
 * Frozen export request allowlist.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Export\ExportScope;
use PHPUnit\Framework\TestCase;

final class ExportScopeTest extends TestCase
{
    public function test_copies_only_allowlisted_filter_keys(): void
    {
        $frozen = ExportScope::freeze(
            array(
                's'                      => 'needle',
                'post_status'            => 'draft',
                'nat_filter_nat_demo_text' => 'Text 37',
                'nat_op_nat_demo_text'   => 'is',
                'orderby'                => 'nat_nat_demo_number',
                'adapter'                => 'acf',
                'post_type'              => 'nat_demo_record',
                'format'                 => 'csv',
            )
        );
        self::assertSame(
            array(
                's'                        => 'needle',
                'post_status'              => 'draft',
                'nat_filter_nat_demo_text' => 'Text 37',
                'nat_op_nat_demo_text'     => 'is',
            ),
            $frozen
        );
    }

    public function test_selected_ids_are_bounded_positive_integers(): void
    {
        self::assertSame(array(12, 44), ExportScope::selectedIds(array('12', 44, '0', '-3', '12', 'nope')));
        self::assertSame(array(), ExportScope::selectedIds('12'));
        $overflow = array_map('strval', range(1, 600));
        self::assertCount(500, ExportScope::selectedIds($overflow));
    }
}
