<?php
/**
 * A projected integration value does not imply an underlying storage state.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration;

use InvalidArgumentException;

final class ReadResult
{
    public function __construct(public readonly string|int|float|bool|null $value)
    {
        if (is_string($value) && strlen($value) > 10000) {
            throw new InvalidArgumentException('Integration values cannot exceed 10000 bytes.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Integration values must be finite.');
        }
    }

    /** @return array{value: string|int|float|bool|null, storage_state: null, editable: false} */
    public function toArray(): array
    {
        return array('value' => $this->value, 'storage_state' => null, 'editable' => false);
    }
}
