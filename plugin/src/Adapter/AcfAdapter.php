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

    /** @var array<string, array<string, string>> */
    private array $choiceCache = array();

    public function source(): string
    {
        return 'acf';
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        if (! $this->supports($column)) {
            return new StoredValue(false, null);
        }
        $exists = metadata_exists('post', $postId, $column->field);
        if (! $exists) {
            return new StoredValue(false, null);
        }
        $value        = get_field($column->fieldKey ?? $column->field, $postId);
        $displayLabel = null;
        if ('select' === $column->type && is_scalar($value)) {
            $selector     = $column->fieldKey ?? $column->field;
            $cacheKey     = $selector . ':' . $column->field . ':' . $column->type;
            $choice       = (string) $value;
            $displayLabel = $choice;
            if (isset($this->choiceCache[$cacheKey]) && array_key_exists($choice, $this->choiceCache[$cacheKey])) {
                $displayLabel = $this->choiceCache[$cacheKey][$choice];
            }
        }
        return new StoredValue(true, $value, $displayLabel);
    }

    public function supports(ColumnDefinition $column): bool
    {
        if (! function_exists('get_field') || ! function_exists('get_field_object')) {
            return false;
        }

        $selector = $column->fieldKey ?? $column->field;
        $cacheKey = $selector . ':' . $column->field . ':' . $column->type;
        if (array_key_exists($cacheKey, $this->supportCache)) {
            return $this->supportCache[$cacheKey];
        }

        $field = get_field_object($selector, false, false, false);
        $types = array(
            'text'    => 'text',
            'number'  => 'number',
            'boolean' => 'true_false',
            'select'  => 'select',
            'date'    => 'date_picker',
            'image'   => 'image',
        );
        $supported = is_array($field)
            && isset($types[$column->type], $field['type'], $field['name'])
            && $column->field === $field['name']
            && $types[$column->type] === $field['type'];
        if ($supported && 'select' === $column->type) {
            $supported = empty($field['multiple'])
                && (! isset($field['return_format']) || 'value' === $field['return_format']);
            $fieldChoices = (array) ($field['choices'] ?? array());
            if (count($fieldChoices) > 200) {
                $supported = false;
            }
            if ($supported) {
                $choices = array();
                foreach ($fieldChoices as $value => $label) {
                    $value = (string) $value;
                    if (strlen($value) <= 191 && is_scalar($label) && strlen((string) $label) <= 200) {
                        $choices[$value] = (string) $label;
                    }
                }
                $this->choiceCache[$cacheKey] = $choices;
            }
        }
        $this->supportCache[$cacheKey] = $supported;
        return $supported;
    }
}
