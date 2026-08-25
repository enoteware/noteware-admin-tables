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

final class Configuration
{
    /** @var array<string, list<ColumnDefinition>>|null */
    private ?array $columns = null;

    /** @return list<string> */
    public function postTypes(): array
    {
        return array_keys($this->all());
    }

    /** @return list<ColumnDefinition> */
    public function columns(string $postType): array
    {
        return $this->all()[$postType] ?? array();
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

    /** @return array<string, list<ColumnDefinition>> */
    private function all(): array
    {
        if (null !== $this->columns) {
            return $this->columns;
        }

        /**
         * Supplies site-owned post list-screen configuration.
         *
         * @param array<string, array{columns?: list<array<string, mixed>>}> $configuration Configuration by post type.
         */
        $raw = apply_filters('noteware_admin_tables_config', array());
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Noteware Admin Tables configuration must be an array.');
        }
        if (count($raw) > 50) {
            throw new InvalidArgumentException('Noteware Admin Tables supports at most 50 configured screens per request.');
        }

        $this->columns = array();
        foreach ($raw as $postType => $screen) {
            if (! is_string($postType) || ! post_type_exists($postType) || ! is_array($screen)) {
                throw new InvalidArgumentException('Each configured screen must name an existing post type.');
            }
            if (array_diff(array_keys($screen), array('columns'))) {
                throw new InvalidArgumentException('Screen configuration contains an unknown option.');
            }
            $postTypeObject = get_post_type_object($postType);
            if (! $postTypeObject || ! $postTypeObject->show_ui) {
                throw new InvalidArgumentException('Configured post types must have an admin user interface.');
            }
            if (! isset($screen['columns']) || ! is_array($screen['columns']) || count($screen['columns']) > 100) {
                throw new InvalidArgumentException('Each screen must define no more than 100 columns.');
            }
            $seen = array();
            foreach ((array) ($screen['columns'] ?? array()) as $definition) {
                if (! is_array($definition)) {
                    throw new InvalidArgumentException('Each column definition must be an array.');
                }
                $column = ColumnDefinition::fromArray($definition);
                if (isset($seen[$column->key])) {
                    throw new InvalidArgumentException('Column keys must be unique within a screen.');
                }
                $seen[$column->key]          = true;
                $this->columns[$postType][] = $column;
            }
        }
        return $this->columns;
    }
}
