<?php
/**
 * WordPress post metadata adapter.
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

final class MetaAdapter implements EditableFieldAdapter
{
    public function source(): string
    {
        return 'meta';
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        $exists = metadata_exists('post', $postId, $column->field);
        return new StoredValue($exists, $exists ? get_post_meta($postId, $column->field, true) : null);
    }

    public function authorize(int $postId, ColumnDefinition $column): void
    {
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
        $metaLock = $wpdb->query(
            $wpdb->prepare(
                'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s FOR UPDATE',
                $wpdb->postmeta,
                $postId,
                $column->field
            )
        );
        if (false === $metaLock) {
            throw new RuntimeException('The field could not be locked for editing.');
        }
    }

    public function write(int $postId, ColumnDefinition $column, mixed $value, StoredValue $expected): void
    {
        $storageValue = wp_slash($value);
        if (! $expected->exists) {
            if (! add_post_meta($postId, $column->field, $storageValue, true)) {
                throw new RuntimeException('The field changed before the edit could be saved.');
            }
            return;
        }

        $result = update_post_meta($postId, $column->field, $storageValue, $expected->value);
        if (false === $result && ($expected->value !== $value || ! $expected->equals($this->read($postId, $column)))) {
            throw new RuntimeException('The field changed before the edit could be saved.');
        }
    }

    public function remove(int $postId, ColumnDefinition $column, StoredValue $expected): void
    {
        if (! $expected->exists) {
            return;
        }
        if (! delete_post_meta($postId, $column->field, wp_slash($expected->value))) {
            throw new RuntimeException('The field changed before it could be removed.');
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
}
