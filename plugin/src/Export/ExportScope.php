<?php
/**
 * Allowlisted frozen export request values.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

final class ExportScope
{
    private const MAX_SELECTED = 500;

    private const MAX_VALUE = 10000;

    /**
     * @param array<array-key, mixed> $request Unslashed request values.
     * @return array<string, string>
     */
    public static function freeze(array $request): array
    {
        $frozen = array();
        foreach ($request as $key => $value) {
            if (! is_string($key) || ! is_string($value) || strlen($value) > self::MAX_VALUE) {
                continue;
            }
            if (in_array($key, array('s', 'post_status'), true) || 1 === preg_match('/^nat_(?:filter|op)_[a-z][a-z0-9_-]*$/', $key)) {
                $frozen[$key] = $value;
            }
        }
        return $frozen;
    }

    /**
     * @param mixed $posts Submitted list-table selection.
     * @return list<int>
     */
    public static function selectedIds(mixed $posts): array
    {
        if (! is_array($posts)) {
            return array();
        }
        $ids = array();
        foreach ($posts as $id) {
            if (count($ids) >= self::MAX_SELECTED) {
                break;
            }
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
                continue;
            }
            if (is_string($id) && 1 === preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
