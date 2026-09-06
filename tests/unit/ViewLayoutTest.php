<?php
/**
 * View overlay safety tests.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);
namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\View\ViewLayout;
use PHPUnit\Framework\TestCase;

final class ViewLayoutTest extends TestCase
{
    private function screen(): array
    {
        return array('columns' => array(array('key' => 'score', 'label' => 'Score', 'source' => 'meta', 'type' => 'number', 'field' => 'score', 'editable' => false)));
    }

    private function view(): array
    {
        return array('version' => 1, 'columns' => array(array('key' => 'score', 'label' => 'Rating', 'width' => '120px', 'visible' => true)));
    }

    public function testPresentationPreservesPermissionCeilingAndEssentials(): void
    {
        $result = ViewLayout::apply($this->screen(), $this->view());
        self::assertFalse($result['columns'][0]['editable']);
        self::assertSame('Rating', $result['columns'][0]['label']);
        self::assertSame(array('cb', 'title', 'nat_score'), $result['order']);
    }

    public function testCannotPersistBehaviorOrUnknownSettings(): void
    {
        $view = $this->view();
        $view['columns'][0]['editable'] = true;
        $this->expectException(InvalidArgumentException::class);
        ViewLayout::apply($this->screen(), $view);
    }

    public function testInvalidCssFails(): void
    {
        $view = $this->view();
        $view['columns'][0]['width'] = '1px;display:none';
        $this->expectException(InvalidArgumentException::class);
        ViewLayout::apply($this->screen(), $view);
    }

    public function testHidingColumnRetainsTitleAndCheckbox(): void
    {
        $view = $this->view();
        $view['columns'][0]['visible'] = false;
        self::assertSame(array('cb', 'title'), ViewLayout::apply($this->screen(), $view)['order']);
    }

    public function testRemovedCatalogColumnInvalidatesStaleView(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ViewLayout::apply(array('columns' => array()), $this->view());
    }
}
