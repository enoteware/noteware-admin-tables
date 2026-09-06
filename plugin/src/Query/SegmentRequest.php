<?php
/**
 * Revalidate saved state against current configured columns before replay.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Query;

use InvalidArgumentException;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Contract\FilterableFieldAdapter;
use Noteware\AdminTables\Editing\ValueValidator;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Segment\SegmentDefinition;

final class SegmentRequest
{
    /**
     * @param list<ColumnDefinition> $columns Current screen columns.
     * @return list<array<string, string>> One request per AND condition, including repeated columns.
     */
    public static function compile(SegmentDefinition $segment, array $columns, AdapterRegistry $adapters): array
    {
        $byKey = array();
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }
        $data = $segment->toArray();
        if (isset($data['sort'])) {
            $column = $byKey[$data['sort']['column']] ?? null;
            if (! $column || ! $column->sortable || ! $adapters->get($column->source)->supports($column)) {
                throw new InvalidArgumentException('The saved sort is no longer available.');
            }
        }
        $requests = array();
        $nativeFields = array();
        foreach ($data['filters'] as $condition) {
            $column = $byKey[$condition['column']] ?? null;
            if (! $column || ! $column->supportsOperator($condition['operator'])) {
                throw new InvalidArgumentException('A saved filter is no longer available.');
            }
            if ('native' === $column->source) {
                if (isset($nativeFields[$column->field])) {
                    throw new InvalidArgumentException('Repeated native conditions are not supported.');
                }
                $nativeFields[$column->field] = true;
                if ('status' === $column->field && isset($data['status']) && ! in_array($data['status'], array('', 'all', $condition['value'] ?? null), true)) {
                    throw new InvalidArgumentException('The saved status conflicts with a condition.');
                }
            }
            $adapter = $adapters->get($column->source);
            if (! $adapter->supports($column)) {
                throw new InvalidArgumentException('A saved field is no longer available.');
            }
            $request = array('nat_op_' . $column->key => $condition['operator']);
            if (! in_array($condition['operator'], array('empty', 'not_empty', 'absent', 'present', 'stored_empty'), true)) {
                $keys = 'between' === $condition['operator'] ? array('value' => 'nat_filter_', 'value_to' => 'nat_filter_to_') : array('value' => 'nat_filter_');
                foreach ($keys as $key => $prefix) {
                    if (! isset($condition[$key]) || '' === $condition[$key]) {
                        throw new InvalidArgumentException('A saved condition requires a value.');
                    }
                    $value = $adapter instanceof FilterableFieldAdapter
                        ? $adapter->validateFilterValue($column, $condition[$key])
                        : ValueValidator::validate($column, $condition[$key]);
                    $request[$prefix . $column->key] = $value;
                }
            }
            $requests[] = $request;
        }
        return $requests;
    }
}
