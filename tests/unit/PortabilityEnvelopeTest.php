<?php
/** @package NotewareAdminTables */
declare(strict_types=1);
namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;
use Noteware\AdminTables\Portability\ViewEnvelope;
use PHPUnit\Framework\TestCase;

final class PortabilityEnvelopeTest extends TestCase
{
    private function envelope(): ViewEnvelope
    {
        $column = ColumnDefinition::fromArray(array('key' => 'note', 'label' => 'Note', 'source' => 'meta', 'type' => 'text', 'field' => 'demo_note'));
        return new ViewEnvelope(array('post' => new ScreenDefinition(array($column))), array('editor'));
    }

    /** @return array<string,mixed> */
    private function view(): array
    {
        return array('version' => 1, 'id' => 'v_12345678-1234-1234-1234-123456789abc', 'name' => 'Notes', 'post_type' => 'post', 'visibility' => 'personal', 'roles' => array(), 'columns' => array(array('key' => 'note', 'label' => 'Résumé', 'width' => '12%', 'visible' => true)));
    }

    public function test_valid_overlay_round_trips_without_source_or_permissions(): void
    {
        $codec = $this->envelope();
        $encoded = $codec->encode(array($this->view()));
        self::assertSame(array($this->view()), $codec->decode($encoded)['views']);
    }

    public function test_unknown_field_definition_cannot_expand_site_configuration(): void
    {
        $view = $this->view();
        $view['columns'][0]['source'] = 'native';
        $this->expectException(InvalidArgumentException::class);
        $this->envelope()->encode(array($view));
    }

    public function test_unknown_schema_cannot_be_silently_migrated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->envelope()->decode('{"schema_version":99,"views":[]}');
    }

    public function test_executable_or_unknown_envelope_option_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->envelope()->decode('{"schema_version":1,"views":[],"callback":"system"}');
    }

    public function test_unknown_role_cannot_be_imported(): void
    {
        $view = $this->view();
        $view['visibility'] = 'shared';
        $view['roles'] = array('unregistered');
        $this->expectException(InvalidArgumentException::class);
        $this->envelope()->encode(array($view));
    }

    public function test_duplicate_view_identity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->envelope()->encode(array($this->view(), $this->view()));
    }
}
