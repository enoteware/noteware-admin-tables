<?php
/**
 * Site-owned configuration reader.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Config;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;

final class Configuration
{
    /** @var array<string, ScreenDefinition>|null */
    private ?array $screens = null;

    /** @return list<string> */
    public function postTypes(): array
    {
        return array_keys($this->all());
    }

    public function screen(string $postType): ?ScreenDefinition
    {
        return $this->all()[$postType] ?? null;
    }

    /** @return list<ColumnDefinition> */
    public function columns(string $postType): array
    {
        $screen = $this->screen($postType);
        return $screen ? $screen->columns : array();
    }

    public function column(string $postType, string $key): ?ColumnDefinition
    {
        foreach ($this->columns($postType) as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }
        return null;
    }

    /** @return array<string, ScreenDefinition> */
    private function all(): array
    {
        if (null !== $this->screens) {
            return $this->screens;
        }

        /**
         * Supplies site-owned post list-screen configuration.
         *
         * @param array<string, array{columns?: list<array<string, mixed>>, order?: list<string>, remove?: list<string>, min_width?: string}> $configuration Configuration by post type.
         */
        $raw = apply_filters('noteware_admin_tables_config', array());
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Noteware Admin Tables configuration must be an array.');
        }
        if (count($raw) > 50) {
            throw new InvalidArgumentException('Noteware Admin Tables supports at most 50 configured screens per request.');
        }

        $this->screens = array();
        foreach ($raw as $postType => $screen) {
            if (! is_string($postType) || ! post_type_exists($postType) || ! is_array($screen)) {
                throw new InvalidArgumentException('Each configured screen must name an existing post type.');
            }
            if (array_diff(array_keys($screen), array('columns', 'order', 'remove', 'min_width'))) {
                throw new InvalidArgumentException('Screen configuration contains an unknown option.');
            }
            $postTypeObject = get_post_type_object($postType);
            if (! $postTypeObject || ! $postTypeObject->show_ui) {
                throw new InvalidArgumentException('Configured post types must have an admin user interface.');
            }
            if (! isset($screen['columns']) || ! is_array($screen['columns']) || count($screen['columns']) > 100) {
                throw new InvalidArgumentException('Each screen must define no more than 100 columns.');
            }

            $columns = array();
            foreach ($screen['columns'] as $definition) {
                if (! is_array($definition)) {
                    throw new InvalidArgumentException('Each column definition must be an array.');
                }
                $columns[] = ColumnDefinition::fromArray($definition);
            }

            $this->screens[$postType] = new ScreenDefinition(
                $columns,
                $this->idList($screen['order'] ?? array()),
                $this->idList($screen['remove'] ?? array()),
                $this->minWidth($screen['min_width'] ?? null)
            );
        }
        return $this->screens;
    }

    private function minWidth(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('A screen minimum width must be a string.');
        }
        return $value;
    }

    /**
     * @param  mixed $value Raw configured list.
     * @return list<string>
     */
    private function idList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Column order and removal options must be arrays of column ids.');
        }
        $ids = array();
        foreach ($value as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('Column order and removal options must contain only strings.');
            }
            $ids[] = $id;
        }
        return $ids;
    }
}
