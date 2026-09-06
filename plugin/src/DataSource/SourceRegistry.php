<?php
/**
 * Deny-by-default registration and complete bounded data traversal.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\DataSource;

use InvalidArgumentException;
use Noteware\AdminTables\Model\StoredValue;
use RuntimeException;

final class SourceRegistry
{
    /** @var array<string, ReadOnlySource> */
    private array $sources = array();

    public function register(ReadOnlySource $source): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source->id()) || isset($this->sources[$source->id()]) || count($this->sources) >= 50) {
            throw new InvalidArgumentException('Invalid, duplicate, or excessive data-source registration.');
        }
        $fields = $source->fields();
        if (! $fields || count($fields) > 100) {
            throw new InvalidArgumentException('A source requires one to one hundred typed fields.');
        }
        foreach ($fields as $key => $type) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || ! in_array($type, TypedValue::TYPES, true)) {
                throw new InvalidArgumentException('Invalid registered field or scalar type.');
            }
        }
        $this->sources[$source->id()] = $source;
    }

    /**
     * Exhaustion proves completeness only when the registered provider honors its contract.
     * The method never returns a partial metric when a page, permission, or budget fails.
     *
     * @param array<string, mixed> $equals Exact field filters; no SQL or operators.
     * @return \Generator<int, StoredValue>
     */
    public function values(string $sourceId, string $field, array $equals = array(), int $maxRows = 100000): \Generator
    {
        $source = $this->sources[$sourceId] ?? null;
        if (! $source || ! $source->canRead()) {
            throw new RuntimeException('This data source is not available to the current user.');
        }
        $fields = $source->fields();
        if (! isset($fields[$field]) || count($equals) > 5 || $maxRows < 1 || $maxRows > 100000) {
            throw new InvalidArgumentException('Invalid field, filter count, or row budget.');
        }
        $filters = array();
        foreach ($equals as $key => $value) {
            if (! isset($fields[$key])) {
                throw new InvalidArgumentException('Unknown filter field.');
            }
            $filters[$key] = TypedValue::parse($value, $fields[$key]);
        }
        $cursor = null;
        $seenCursors = array();
        $seenRows = array();
        $count = 0;
        do {
            if (! $source->canRead()) {
                throw new RuntimeException('Data access was revoked before the next page.');
            }
            $page = $source->page($filters, 200, $cursor);
            if (! array_is_list($page['rows']) || count($page['rows']) > 200 || (null !== $page['next'] && ('' === $page['next'] || strlen($page['next']) > 1000))) {
                throw new RuntimeException('The data provider returned an invalid or oversized page.');
            }
            foreach ($page['rows'] as $row) {
                if ('' === $row['id'] || strlen($row['id']) > 191 || isset($seenRows[$row['id']]) || ! isset($row['values'][$field]) || ! $row['values'][$field] instanceof StoredValue) {
                    throw new RuntimeException('The data provider returned a duplicate or incomplete row.');
                }
                $seenRows[$row['id']] = true;
                if (++$count > $maxRows) {
                    throw new RuntimeException('The full dataset exceeds the row budget.');
                }
                yield $row['values'][$field];
            }
            $cursor = $page['next'];
            if (null !== $cursor) {
                if (isset($seenCursors[$cursor]) || ! $page['rows']) {
                    throw new RuntimeException('The data provider did not advance its cursor.');
                }
                $seenCursors[$cursor] = true;
            }
        } while (null !== $cursor);
    }
}
