<?php
/**
 * Versioned data-only presentation settings. Never imports field adapters or permissions.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Portability;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;

final class ViewEnvelope
{
    /**
     * @param array<string,ScreenDefinition> $screens
     * @param list<string> $roles
     */
    public function __construct(private readonly array $screens, private readonly array $roles)
    {
    }

    /** @return array{schema_version:int,views:list<array<string,mixed>>} */
    public function decode(string $json): array
    {
        if (strlen($json) > 1048576) {
            throw new InvalidArgumentException('Settings exceed the one MiB import limit.');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_diff(array_keys($data), array('schema_version', 'views')) || 1 !== ($data['schema_version'] ?? null) || ! isset($data['views']) || ! is_array($data['views']) || ! array_is_list($data['views']) || count($data['views']) > 100) {
            throw new InvalidArgumentException('Unknown settings envelope or schema version.');
        }
        $ids = array();
        foreach ($data['views'] as $view) {
            if (! is_array($view)) {
                throw new InvalidArgumentException('Each view must be an object.');
            }
            $this->view($view);
            $id = (string) $view['id'];
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Imported view IDs must be unique.');
            }
            $ids[$id] = true;
        }
        return array('schema_version' => 1, 'views' => $data['views']);
    }

    /** @param list<array<string,mixed>> $views */
    public function encode(array $views): string
    {
        $json = json_encode(array('schema_version' => 1, 'views' => $views), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->decode($json . "\n");
        return $json . "\n";
    }

    /** @param array<string,mixed> $view */
    private function view(array $view): void
    {
        $keys = array('version', 'id', 'name', 'post_type', 'visibility', 'roles', 'columns');
        if (array_diff(array_keys($view), $keys) || array_diff($keys, array_keys($view)) || 1 !== $view['version'] || ! is_string($view['id']) || ! preg_match('/^v_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $view['id']) || ! is_string($view['name']) || '' === trim($view['name']) || strlen($view['name']) > 100 || ! preg_match('//u', $view['name'])) {
            throw new InvalidArgumentException('View shape, version, ID or name is invalid.');
        }
        if (! is_string($view['post_type']) || ! isset($this->screens[$view['post_type']]) || ! in_array($view['visibility'], array('personal', 'shared'), true) || ! is_array($view['roles']) || ! array_is_list($view['roles'])) {
            throw new InvalidArgumentException('View screen, visibility or roles are invalid.');
        }
        foreach ($view['roles'] as $role) {
            if (! is_string($role) || ! in_array($role, $this->roles, true)) {
                throw new InvalidArgumentException('An imported role is not registered on this site.');
            }
        }
        if (count(array_unique($view['roles'])) !== count($view['roles']) || ('personal' === $view['visibility'] && array() !== $view['roles'])) {
            throw new InvalidArgumentException('View role scope is inconsistent.');
        }
        if (! is_array($view['columns']) || ! array_is_list($view['columns']) || count($view['columns']) > 100) {
            throw new InvalidArgumentException('View columns must be a bounded ordered list.');
        }
        $catalog = array();
        foreach ($this->screens[$view['post_type']]->columns as $column) {
            $catalog[$column->key] = $column;
        }
        $seen = array();
        $columns = array();
        foreach ($view['columns'] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), array('key', 'label', 'width', 'visible')) || count($item) !== 4 || ! isset($item['key'], $item['label'], $item['width'], $item['visible']) || ! is_string($item['key']) || ! isset($catalog[$item['key']]) || isset($seen[$item['key']]) || ! is_string($item['label']) || ! preg_match('//u', $item['label']) || ! is_string($item['width']) || ! is_bool($item['visible'])) {
                throw new InvalidArgumentException('Imported column must be a unique registered presentation overlay.');
            }
            $seen[$item['key']] = true;
            $source = $catalog[$item['key']];
            $column = new ColumnDefinition($source->key, $item['label'], $source->source, $source->type, $source->field, $source->fieldKey, $source->sortable, $source->filterable, $source->editable, $source->choices, $source->emptyLabel, '' === $item['width'] ? null : $item['width'], $source->bulkEditable, $source->operators, $source->replaces);
            if ($item['visible']) {
                $columns[] = $column;
            }
        }
        // Reuse the existing aggregate width safety budget, without importing remove/title controls.
        new ScreenDefinition($columns);
    }
}
