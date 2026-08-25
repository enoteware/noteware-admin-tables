<?php
/**
 * A stored field value, including whether it exists.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Model;

final class StoredValue
{
    public function __construct(
        public readonly bool $exists,
        public readonly mixed $value,
        public readonly ?string $displayLabel = null
    ) {
    }

    /**
     * @return array{exists: bool, state: string, type: string, value: mixed}
     */
    public function toArray(): array
    {
        return array(
            'exists' => $this->exists,
            'state'  => $this->state(),
            'type'   => get_debug_type($this->value),
            'value'  => $this->value,
        );
    }

    public function equals(self $other): bool
    {
        return $this->exists === $other->exists && $this->value === $other->value;
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function state(): string
    {
        if (! $this->exists) {
            return 'absent';
        }
        if (null === $this->value) {
            return 'null';
        }
        if ('' === $this->value) {
            return 'empty_string';
        }
        if (false === $this->value) {
            return 'false';
        }
        if (0 === $this->value || '0' === $this->value) {
            return 'zero';
        }
        return 'value';
    }
}
