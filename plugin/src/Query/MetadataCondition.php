<?php
/**
 * Typed metadata condition plans built solely from trusted column definitions.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Query;

use InvalidArgumentException;
use Noteware\AdminTables\Model\ColumnDefinition;

final class MetadataCondition
{
    /** @return list<string> */
    public static function operators(string $source, string $type): array
    {
        if ('native' === $source) {
            return array('is');
        }
        if ('taxonomy' === $source) {
            return array('is', 'empty', 'not_empty');
        }
        $operators = array('is', 'is_not', 'empty', 'not_empty', 'absent', 'present', 'stored_empty');
        if (in_array($type, array('text', 'url'), true)) {
            return array_merge($operators, array('contains', 'not_contains'));
        }
        if (in_array($type, array('number', 'date'), true)) {
            return array_merge($operators, array('gt', 'gte', 'lt', 'lte', 'between'));
        }
        return $operators;
    }

    /**
     * Values must already be validated by the field adapter. The controller owns that boundary.
     *
     * @return array<array-key, mixed>
     */
    public static function plan(ColumnDefinition $column, string $operator, ?string $value = null, ?string $upper = null): array
    {
        if (! in_array($column->source, array('meta', 'acf'), true) || ! in_array($operator, self::operators($column->source, $column->type), true)) {
            throw new InvalidArgumentException('Unsupported metadata operator.');
        }
        $absent = array('key' => $column->field, 'compare' => 'NOT EXISTS');
        $present = array('key' => $column->field, 'compare' => 'EXISTS');
        $empty = array('key' => $column->field, 'value' => '', 'compare' => '=');
        if ('absent' === $operator) {
            return $absent;
        }
        if ('present' === $operator) {
            return $present;
        }
        if ('stored_empty' === $operator) {
            return $empty;
        }
        if ('empty' === $operator) {
            return array('relation' => 'OR', $absent, $empty);
        }
        if ('not_empty' === $operator) {
            return array('relation' => 'AND', $present, array('key' => $column->field, 'value' => '', 'compare' => '!='));
        }
        if (null === $value || ('between' === $operator && null === $upper)) {
            throw new InvalidArgumentException('This operator requires a value.');
        }
        if ('acf' === $column->source && 'date' === $column->type) {
            $value = str_replace('-', '', $value);
            $upper = null === $upper ? null : str_replace('-', '', $upper);
        }
        $compare = array('is' => '=', 'is_not' => '!=', 'contains' => 'LIKE', 'not_contains' => 'NOT LIKE', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'between' => 'BETWEEN');
        return array(
            'key' => $column->field,
            'value' => 'between' === $operator ? array($value, $upper) : $value,
            'compare' => $compare[$operator],
            'type' => match ($column->type) {
                'number' => 'DECIMAL(65,30)',
                'boolean' => 'UNSIGNED',
                'date' => 'acf' === $column->source ? 'UNSIGNED' : 'DATE',
                default => 'CHAR',
            },
        );
    }
}
