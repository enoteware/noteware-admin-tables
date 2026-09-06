<?php
/**
 * Authorized, typed formatting rules with fixed accessible color tokens.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Formatting;

use InvalidArgumentException;
use Noteware\AdminTables\DataSource\TypedValue;
use Noteware\AdminTables\Model\StoredValue;
use RuntimeException;

final class FormattingRule
{
    private function __construct(
        public readonly string $id,
        public readonly string $column,
        public readonly string $type,
        public readonly string $operator,
        public readonly string|float|bool|null $value,
        public readonly string $tone,
        public readonly int $priority,
        public readonly ?int $owner
    ) {
    }

    /** @param array<string, mixed> $data Scalar rule data. */
    public static function create(array $data, bool $shared = false): self
    {
        $userId = get_current_user_id();
        if (! $userId || ! current_user_can('read') || ($shared && ! current_user_can('manage_options'))) {
            throw new RuntimeException('The current user cannot create this formatting rule.');
        }
        if (array_diff(array_keys($data), array('id', 'column', 'type', 'operator', 'value', 'tone', 'priority'))) {
            throw new InvalidArgumentException('Unknown formatting property.');
        }
        foreach (array('id', 'column') as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $data[$key])) {
                throw new InvalidArgumentException('Invalid formatting identifier.');
            }
        }
        if (! isset($data['type'], $data['operator'], $data['tone']) || ! in_array($data['type'], TypedValue::TYPES, true) || ! in_array($data['tone'], array('notice', 'success', 'danger'), true)) {
            throw new InvalidArgumentException('Invalid formatting type or tone.');
        }
        $operators = array('is', 'absent', 'stored_empty');
        if (in_array($data['type'], array('number', 'date'), true)) {
            $operators = array_merge($operators, array('gt', 'lt'));
        }
        if (! in_array($data['operator'], $operators, true) || ! is_int($data['priority'] ?? 0) || ($data['priority'] ?? 0) < 0 || ($data['priority'] ?? 0) > 100) {
            throw new InvalidArgumentException('Invalid formatting operator or priority.');
        }
        $value = in_array($data['operator'], array('absent', 'stored_empty'), true) ? null : TypedValue::parse($data['value'] ?? null, $data['type']);
        return new self($data['id'], $data['column'], $data['type'], $data['operator'], $value, $data['tone'], $data['priority'] ?? 0, $shared ? null : $userId);
    }

    public function matches(StoredValue $stored): bool
    {
        if ('absent' === $this->operator) {
            return ! $stored->exists;
        }
        if ('stored_empty' === $this->operator) {
            return $stored->exists && '' === $stored->value;
        }
        if (! $stored->exists) {
            return false;
        }
        try {
            $value = TypedValue::parse($stored->value, $this->type);
        } catch (InvalidArgumentException) {
            return false;
        }
        return match ($this->operator) {
            'is' => $value === $this->value,
            'gt' => $value > $this->value,
            'lt' => $value < $this->value,
            default => false,
        };
    }
}
