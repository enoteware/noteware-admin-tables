<?php
/**
 * Adapter for supported ACF Free fields through its public API.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use InvalidArgumentException;
use RuntimeException;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Editing\ValueValidator;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class AcfAdapter implements EditableFieldAdapter
{
    /** @var array<string, string> */
    private const TYPES = array(
        'text'    => 'text',
        'number'  => 'number',
        'boolean' => 'true_false',
        'select'  => 'select',
        'date'    => 'date_picker',
        'image'   => 'image',
        'url'     => 'url',
    );

    /** Types this adapter is allowed to write. */
    private const WRITABLE_TYPES = array('text', 'url', 'select');

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
        $value        = get_field($this->selector($column), $postId);
        $displayLabel = null;
        if ('select' === $column->type && is_scalar($value)) {
            $cacheKey     = $this->cacheKey($column);
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

        $cacheKey = $this->cacheKey($column);
        if (array_key_exists($cacheKey, $this->supportCache)) {
            return $this->supportCache[$cacheKey];
        }

        $field     = get_field_object($this->selector($column), false, false, false);
        $supported = is_array($field)
            && isset(self::TYPES[$column->type], $field['type'], $field['name'])
            && $column->field === $field['name']
            && self::TYPES[$column->type] === $field['type'];
        if ($supported && 'select' === $column->type) {
            $supported = $this->cacheSelectChoices($column, $field, $cacheKey);
        }
        $this->supportCache[$cacheKey] = $supported;
        return $supported;
    }

    public function authorize(int $postId, ColumnDefinition $column): void
    {
        if (! in_array($column->type, self::WRITABLE_TYPES, true)) {
            throw new InvalidArgumentException('This ACF field type is not editable.');
        }
        if (! $this->supports($column)) {
            throw new InvalidArgumentException('The configured ACF field no longer matches this column.');
        }
        if (! current_user_can('edit_post', $postId) || ! current_user_can('edit_post_meta', $postId, $column->field)) {
            throw new InvalidArgumentException('You do not have permission to edit this field.');
        }
    }

    public function nonceAction(string $operation, int $identifier, ColumnDefinition $column): string
    {
        return match ($operation) {
            'edit'  => 'nat_edit_' . $identifier . '_' . $column->key,
            'undo'  => 'nat_undo_' . $identifier,
            default => throw new InvalidArgumentException('Unsupported edit operation.'),
        };
    }

    public function validate(ColumnDefinition $column, mixed $value): mixed
    {
        if ('select' === $column->type) {
            return $this->validateChoice($column, $value);
        }
        return ValueValidator::validate($column, $value);
    }

    public function sanitize(ColumnDefinition $column, mixed $value): mixed
    {
        unset($column);
        return $value;
    }

    public function lock(int $postId, ColumnDefinition $column): void
    {
        global $wpdb;
        $postLock = $wpdb->query(
            $wpdb->prepare('SELECT ID FROM %i WHERE ID = %d FOR UPDATE', $wpdb->posts, $postId)
        );
        if (1 !== $postLock) {
            throw new RuntimeException('The post could not be locked for editing.');
        }
        foreach (array($column->field, $this->referenceKey($column)) as $metaKey) {
            $metaLock = $wpdb->query(
                $wpdb->prepare(
                    'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s FOR UPDATE',
                    $wpdb->postmeta,
                    $postId,
                    $metaKey
                )
            );
            if (false === $metaLock) {
                throw new RuntimeException('The field could not be locked for editing.');
            }
            if ($metaLock > 1) {
                throw new RuntimeException('Fields with multiple metadata rows cannot be edited safely.');
            }
        }
    }

    public function write(int $postId, ColumnDefinition $column, mixed $value, StoredValue $expected): void
    {
        unset($expected);
        if (! is_string($value)) {
            throw new InvalidArgumentException('This adapter only writes validated string values.');
        }

        // WordPress metadata writes expect slashed input, and ACF passes the value straight through.
        update_field($this->selector($column), wp_slash($value), $postId);

        $stored = $this->read($postId, $column);
        if (! $stored->exists || $stored->value !== $value) {
            throw new RuntimeException('The saved value could not be confirmed. No change was kept.');
        }
        $this->assertReference($postId, $column);
    }

    public function supportsRemoval(ColumnDefinition $column): bool
    {
        unset($column);
        return true;
    }

    public function remove(int $postId, ColumnDefinition $column, StoredValue $expected): void
    {
        if (! $expected->exists) {
            return;
        }
        delete_field($this->selector($column), $postId);

        if (metadata_exists('post', $postId, $column->field) || metadata_exists('post', $postId, $this->referenceKey($column))) {
            throw new RuntimeException('The stored value could not be removed cleanly.');
        }
    }

    public function restore(int $postId, ColumnDefinition $column, StoredValue $current, StoredValue $target): void
    {
        if ($target->exists) {
            $this->write($postId, $column, $target->value, $current);
            return;
        }
        $this->remove($postId, $column, $current);
    }

    public function auditDescriptor(ColumnDefinition $column): array
    {
        return array(
            'column_key' => $column->key,
            'source'     => $this->source(),
            'field_name' => $column->field,
        );
    }

    /**
     * The live choice map for a supported select column.
     *
     * @return array<string, string>
     */
    public function choices(ColumnDefinition $column): array
    {
        if ('select' !== $column->type || ! $this->supports($column)) {
            return array();
        }
        return $this->choiceCache[$this->cacheKey($column)] ?? array();
    }

    /**
     * A written choice must exist in the live field and, when the site narrows
     * the list, in the configured allowlist as well.
     */
    private function validateChoice(ColumnDefinition $column, mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The submitted value is not valid.');
        }
        $choices = $this->choices($column);
        if (! $choices || ! array_key_exists($value, $choices)) {
            throw new InvalidArgumentException('Choose an allowed value.');
        }
        if ($column->choices && ! array_key_exists($value, $column->choices)) {
            throw new InvalidArgumentException('Choose an allowed value.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $field Live ACF field definition.
     */
    private function cacheSelectChoices(ColumnDefinition $column, array $field, string $cacheKey): bool
    {
        unset($column);
        if (! empty($field['multiple']) || (isset($field['return_format']) && 'value' !== $field['return_format'])) {
            return false;
        }
        $fieldChoices = (array) ($field['choices'] ?? array());
        if (count($fieldChoices) > 200) {
            return false;
        }
        $choices = array();
        foreach ($fieldChoices as $value => $label) {
            $value = (string) $value;
            if (strlen($value) > 191 || ! is_scalar($label) || strlen((string) $label) > 200) {
                return false;
            }
            $choices[$value] = (string) $label;
        }
        $this->choiceCache[$cacheKey] = $choices;
        return true;
    }

    private function assertReference(int $postId, ColumnDefinition $column): void
    {
        $reference = get_post_meta($postId, $this->referenceKey($column), true);
        if (! is_string($reference) || $reference !== $column->fieldKey) {
            throw new RuntimeException('The ACF field reference is missing or incorrect after the write.');
        }
    }

    private function referenceKey(ColumnDefinition $column): string
    {
        return '_' . $column->field;
    }

    private function selector(ColumnDefinition $column): string
    {
        return $column->fieldKey ?? $column->field;
    }

    private function cacheKey(ColumnDefinition $column): string
    {
        return $this->selector($column) . ':' . $column->field . ':' . $column->type;
    }
}
