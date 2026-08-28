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

    /** @var array<string, bool> */
    private array $requiredCache = array();

    /** @var array<string, int> */
    private array $maxLengthCache = array();

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
        if ($supported && is_array($field)) {
            $this->requiredCache[$cacheKey] = ! empty($field['required']);
            unset($this->maxLengthCache[$cacheKey]);
            $maxLength = $field['maxlength'] ?? null;
            if (is_numeric($maxLength) && (int) $maxLength > 0) {
                $this->maxLengthCache[$cacheKey] = (int) $maxLength;
            }
        }
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
        // An ACF write touches the reference key as well as the value key, so a
        // deliberate site restriction on the reference key is honoured too.
        //
        // The check runs only when the site has registered that key. An ACF
        // reference key starts with an underscore, and WordPress denies
        // edit_post_meta on any such key by default, so an unconditional check
        // would refuse every ACF edit on every site.
        $reference = $this->referenceKey($column);
        $postType  = get_post_type($postId);
        if (
            is_string($postType)
            && registered_meta_key_exists('post', $reference, $postType)
            && ! current_user_can('edit_post_meta', $postId, $reference)
        ) {
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
        $validated = 'select' === $column->type
            ? $this->validateChoice($column, $value)
            : ValueValidator::validate($column, $value);

        if ('' === $validated && $this->isRequired($column)) {
            throw new InvalidArgumentException('This field is required, so it cannot be left empty.');
        }

        // update_field() writes past the length rule ACF applies on its own
        // form, so the field's own limit is enforced here.
        $maxLength = $this->maxLength($column);
        if (null !== $maxLength && is_string($validated) && mb_strlen($validated) > $maxLength) {
            throw new InvalidArgumentException('This value is longer than the field allows.');
        }

        return $validated;
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

        $this->assertReferenceBeforeWrite($postId, $column);

        // WordPress metadata writes expect slashed input, and ACF passes the value straight through.
        update_field($this->selector($column), wp_slash($value), $postId);

        $stored = $this->read($postId, $column);
        if (! $stored->exists || $stored->value !== $value) {
            throw new RuntimeException('The saved value could not be confirmed. No change was kept.');
        }
        $this->assertReference($postId, $column);
    }

    /**
     * A required ACF field must never be cleared from a list screen.
     *
     * delete_field() writes straight past the form validation ACF would apply,
     * so honouring the field's own required setting is the only thing that
     * keeps a required value present.
     */
    public function supportsRemoval(ColumnDefinition $column): bool
    {
        return ! $this->isRequired($column);
    }

    public function isRequired(ColumnDefinition $column): bool
    {
        if (! $this->supports($column)) {
            return false;
        }
        return $this->requiredCache[$this->cacheKey($column)] ?? false;
    }

    /** The live field's own length limit, when it defines one. */
    public function maxLength(ColumnDefinition $column): ?int
    {
        if (! $this->supports($column)) {
            return null;
        }
        return $this->maxLengthCache[$this->cacheKey($column)] ?? null;
    }

    public function remove(int $postId, ColumnDefinition $column, StoredValue $expected): void
    {
        if ($this->isRequired($column)) {
            throw new InvalidArgumentException('This field is required, so it cannot be cleared.');
        }
        if (! $expected->exists) {
            return;
        }
        $this->assertReferenceBeforeWrite($postId, $column);
        delete_field($this->selector($column), $postId);

        if (metadata_exists('post', $postId, $column->field) || metadata_exists('post', $postId, $this->referenceKey($column))) {
            throw new RuntimeException('The stored value could not be removed cleanly.');
        }
    }

    /**
     * Undo re-validates the audited value against the field as it is now.
     *
     * A field can gain a required flag or lose a choice after the edit was
     * made. `update_field()` applies no form validation, so without this an
     * undo could restore a value the field would refuse today.
     */
    public function restore(int $postId, ColumnDefinition $column, StoredValue $current, StoredValue $target): void
    {
        if (! $target->exists) {
            $this->remove($postId, $column, $current);
            return;
        }

        $validated = $this->validate($column, $target->value);
        if ($validated !== $target->value) {
            throw new RuntimeException('The audited value is no longer valid for this field, so the undo was refused.');
        }

        $this->write($postId, $column, $validated, $current);
    }

    public function auditDescriptor(ColumnDefinition $column): array
    {
        return array(
            'column_key' => $column->key,
            'source'     => $this->source(),
            'field_name' => $column->field,
        );
    }

    public function transactionalTables(ColumnDefinition $column): array
    {
        unset($column);
        global $wpdb;
        return array($wpdb->posts, $wpdb->postmeta);
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

    /**
     * Refuse to touch a value whose ACF reference row is inconsistent.
     *
     * Writing anyway would silently repair the reference, and the audit
     * snapshot only records the value, so an undo could not restore the pair
     * that was there before.
     */
    private function assertReferenceBeforeWrite(int $postId, ColumnDefinition $column): void
    {
        $referenceKey = $this->referenceKey($column);
        if (! metadata_exists('post', $postId, $column->field) && ! metadata_exists('post', $postId, $referenceKey)) {
            return;
        }
        $reference = get_post_meta($postId, $referenceKey, true);
        if (! is_string($reference) || $reference !== $column->fieldKey) {
            throw new RuntimeException('This record stores an inconsistent field reference. Repair the record before editing it here.');
        }
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
