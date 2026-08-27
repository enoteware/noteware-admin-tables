<?php
/**
 * Complete contract for an adapter that may change stored values.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Contract;

use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

interface EditableFieldAdapter extends FieldAdapter
{
    public function authorize(int $postId, ColumnDefinition $column): void;

    public function nonceAction(string $operation, int $identifier, ColumnDefinition $column): string;

    public function validate(ColumnDefinition $column, mixed $value): mixed;

    public function sanitize(ColumnDefinition $column, mixed $value): mixed;

    public function lock(int $postId, ColumnDefinition $column): void;

    public function write(int $postId, ColumnDefinition $column, mixed $value, StoredValue $expected): void;

    /** Whether this column allows an operator to clear the stored value. */
    public function supportsRemoval(ColumnDefinition $column): bool;

    public function remove(int $postId, ColumnDefinition $column, StoredValue $expected): void;

    public function restore(int $postId, ColumnDefinition $column, StoredValue $current, StoredValue $target): void;

    /** @return array{column_key: string, source: string, field_name: string} */
    public function auditDescriptor(ColumnDefinition $column): array;
}
