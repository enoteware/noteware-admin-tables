<?php
/**
 * Bounded saved query state, independent of storage and screen configuration.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Segment;

use InvalidArgumentException;

final class SegmentDefinition
{
    /** @param array<string, mixed> $data Validated state. */
    private function __construct(private readonly array $data)
    {
    }

    public static function assertKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key)) {
            throw new InvalidArgumentException('Invalid segment, column, screen, or view identifier.');
        }
    }

    /** @param array<string, mixed> $data Saved query state. */
    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), array('id', 'name', 'filters', 'sort', 'status', 'search'))) {
            throw new InvalidArgumentException('Unknown segment property.');
        }
        foreach (array('id', 'name') as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || '' === trim($data[$key])) {
                throw new InvalidArgumentException('Segments need an identifier and name.');
            }
        }
        self::assertKey($data['id']);
        if (strlen($data['name']) > 200) {
            throw new InvalidArgumentException('Segment names cannot exceed 200 bytes.');
        }
        $filters = $data['filters'] ?? array();
        if (! is_array($filters) || ! array_is_list($filters) || count($filters) > 5) {
            throw new InvalidArgumentException('Segments support at most five AND conditions.');
        }
        foreach ($filters as $condition) {
            if (! is_array($condition) || array_diff(array_keys($condition), array('column', 'operator', 'value', 'value_to'))) {
                throw new InvalidArgumentException('Invalid segment condition.');
            }
            foreach (array('column', 'operator') as $key) {
                if (! isset($condition[$key]) || ! is_string($condition[$key])) {
                    throw new InvalidArgumentException('Conditions require a column and operator.');
                }
                self::assertKey($condition[$key]);
            }
            foreach (array('value', 'value_to') as $key) {
                if (array_key_exists($key, $condition) && (! is_string($condition[$key]) || strlen($condition[$key]) > 10000)) {
                    throw new InvalidArgumentException('Condition values must be bounded strings.');
                }
            }
        }
        $data['filters'] = $filters;
        if (isset($data['sort'])) {
            $sort = $data['sort'];
            if (! is_array($sort) || count($sort) !== 2 || ! isset($sort['column'], $sort['direction']) || ! is_string($sort['column']) || ! in_array($sort['direction'], array('ASC', 'DESC'), true)) {
                throw new InvalidArgumentException('Invalid segment sort.');
            }
            self::assertKey($sort['column']);
        } elseif (array_key_exists('sort', $data)) {
            throw new InvalidArgumentException('A sort cannot be null.');
        }
        foreach (array('search' => 200, 'status' => 32) as $key => $limit) {
            if (array_key_exists($key, $data) && (! is_string($data[$key]) || strlen($data[$key]) > $limit)) {
                throw new InvalidArgumentException('Search and status must be bounded strings.');
            }
        }
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
