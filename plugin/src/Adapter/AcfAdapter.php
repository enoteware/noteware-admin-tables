<?php
/**
 * Adapter for supported ACF Free fields through its public API.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use Noteware\AdminTables\Contract\FieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class AcfAdapter implements FieldAdapter
{
    /** @var array<string, bool> */
    private array $supportCache = array();

    public function source(): string
    {
        return 'acf';
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        if (! $this->supports($postId, $column)) {
            return new StoredValue(false, null);
        }
        $exists = metadata_exists('post', $postId, $column->field);
        if (! $exists) {
            return new StoredValue(false, null);
        }
        $value = get_field($column->fieldKey ?? $column->field, $postId);
        return new StoredValue(true, $value);
    }

    private function supports(int $postId, ColumnDefinition $column): bool
    {
        if (! function_exists('get_field') || ! function_exists('get_field_object')) {
            return false;
        }

        $selector = $column->fieldKey ?? $column->field;
        $cacheKey = $selector . ':' . $column->type;
        if (array_key_exists($cacheKey, $this->supportCache)) {
            return $this->supportCache[$cacheKey];
        }

        $field = get_field_object($selector, $postId, false, false);
        $types = array(
            'text'    => 'text',
            'number'  => 'number',
            'boolean' => 'true_false',
            'select'  => 'select',
            'date'    => 'date_picker',
            'image'   => 'image',
        );
        $supported = is_array($field)
            && isset($types[$column->type], $field['type'])
            && $types[$column->type] === $field['type'];
        $this->supportCache[$cacheKey] = $supported;
        return $supported;
    }
}
