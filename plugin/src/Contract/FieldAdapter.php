<?php
/**
 * Field adapter contract.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Contract;

use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

interface FieldAdapter
{
    public function source(): string;

    public function read(int $postId, ColumnDefinition $column): StoredValue;
}
