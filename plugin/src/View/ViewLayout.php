<?php
/**
 * Safe presentation overlays on site-owned column definitions.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\View;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;

final class ViewLayout
{
    /**
     * @param array<string, mixed> $screen Site definition.
     * @param array<string, mixed> $view Saved presentation.
     * @return array<string, mixed>
     */
    public static function apply(array $screen, array $view): array
    {
        if (($view['version'] ?? null) !== 1 || ! is_array($view['columns'] ?? null) || count($view['columns']) > 98) {
            throw new InvalidArgumentException('Unsupported view version or column count.');
        }
        if (array_diff(array_keys($view), array('version', 'id', 'name', 'post_type', 'visibility', 'roles', 'columns'))) {
            throw new InvalidArgumentException('Unknown view setting.');
        }
        $catalog = array();
        foreach ($screen['columns'] ?? array() as $column) {
            $catalog[$column['key']] = $column;
        }
        $columns = array();
        $order = array('cb', 'title');
        $seen = array();
        foreach ($view['columns'] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), array('key', 'label', 'width', 'visible'))) {
                throw new InvalidArgumentException('Unknown column setting.');
            }
            $key = $item['key'] ?? null;
            if (! is_string($key) || ! isset($catalog[$key]) || isset($seen[$key]) || ! is_bool($item['visible'] ?? null)) {
                throw new InvalidArgumentException('Unknown, duplicate, or invalid column.');
            }
            $seen[$key] = true;
            $column = $catalog[$key];
            if (! is_string($item['label'] ?? null) || ! is_string($item['width'] ?? null)) {
                throw new InvalidArgumentException('Labels and widths must be text.');
            }
            $column['label'] = $item['label'];
            $column['width'] = '' === $item['width'] ? null : $item['width'];
            $definition = ColumnDefinition::fromArray($column);
            if ($item['visible']) {
                $columns[] = $column;
                $order[] = 'nat_' . $definition->key;
            }
        }
        $result = $screen;
        $result['columns'] = $columns;
        $result['order'] = $order;
        new ScreenDefinition(array_map(array(ColumnDefinition::class, 'fromArray'), $columns), $order, $screen['remove'] ?? array(), $screen['min_width'] ?? null);
        return $result;
    }
}
