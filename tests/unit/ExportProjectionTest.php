<?php
/**
 * Scalar export projections.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Export\ExportProjection;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;

final class ExportProjectionTest extends TestCase
{
    public function test_skips_image_and_featured_image_columns(): void
    {
        $image = ColumnDefinition::fromArray(
            array(
                'key'    => 'thumb',
                'label'  => 'Thumb',
                'source' => 'native',
                'type'   => 'image',
                'field'  => 'featured_image',
            )
        );
        $id = ColumnDefinition::fromArray(
            array(
                'key'    => 'ident',
                'label'  => 'ID',
                'source' => 'native',
                'type'   => 'number',
                'field'  => 'id',
            )
        );
        self::assertFalse(ExportProjection::isExportable($image));
        self::assertTrue(ExportProjection::isExportable($id));
    }

    public function test_joins_string_lists_and_rejects_maps(): void
    {
        $joined = ExportProjection::scalar(new StoredValue(true, array('beta', 'alpha'), 'Alpha, Beta'));
        self::assertSame('beta,alpha', $joined->value);
        $this->expectException(InvalidArgumentException::class);
        ExportProjection::scalar(new StoredValue(true, array('url' => 'https://example.test')));
    }

    public function test_absent_stays_absent(): void
    {
        $absent = ExportProjection::scalar(new StoredValue(false, null));
        self::assertFalse($absent->exists);
        self::assertNull($absent->value);
    }
}
