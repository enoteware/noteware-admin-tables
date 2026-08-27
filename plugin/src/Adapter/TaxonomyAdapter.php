<?php
/**
 * Adapter for registered WordPress taxonomies.
 *
 * A taxonomy assignment is present or absent. WordPress has no explicit empty
 * assignment, so this adapter reports an object with no terms as absent.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use InvalidArgumentException;
use RuntimeException;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Contract\FilterableFieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class TaxonomyAdapter implements EditableFieldAdapter, FilterableFieldAdapter
{
    private const MAX_CHOICES = 200;

    /** @var array<string, array<string, string>|null> */
    private array $termCache = array();

    /** @var array<string, int|false> */
    private array $slugCache = array();

    public function source(): string
    {
        return 'taxonomy';
    }

    public function supports(ColumnDefinition $column): bool
    {
        $taxonomy = get_taxonomy($column->field);
        return false !== $taxonomy && (bool) $taxonomy->show_ui;
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        if (! $this->supports($column)) {
            return new StoredValue(false, null);
        }
        $terms = get_the_terms($postId, $column->field);
        if (! is_array($terms) || ! $terms) {
            return new StoredValue(false, null);
        }

        $slugs = array();
        $names = array();
        foreach ($terms as $term) {
            $slugs[] = (string) $term->slug;
            $names[] = (string) $term->name;
        }
        sort($slugs, SORT_STRING);
        sort($names, SORT_STRING);

        return new StoredValue(true, $slugs, implode(', ', $names));
    }

    public function authorize(int $postId, ColumnDefinition $column): void
    {
        if (! $this->supports($column)) {
            throw new InvalidArgumentException('The configured taxonomy is not available.');
        }
        $taxonomy = get_taxonomy($column->field);
        if (false === $taxonomy) {
            throw new InvalidArgumentException('The configured taxonomy is not available.');
        }
        $postType = get_post_type($postId);
        if (! is_string($postType) || ! is_object_in_taxonomy($postType, $column->field)) {
            // A taxonomy registered for a different post type must never gain
            // relationships here just because it has an admin interface.
            throw new InvalidArgumentException('This taxonomy is not registered for this record.');
        }
        if (! current_user_can('edit_post', $postId) || ! current_user_can($taxonomy->cap->assign_terms)) {
            throw new InvalidArgumentException('You do not have permission to change these terms.');
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
        if (! is_string($value)) {
            throw new InvalidArgumentException('The submitted value is not valid.');
        }
        return $this->validateFilterValue($column, $value);
    }

    public function validateFilterValue(ColumnDefinition $column, string $raw): string
    {
        if ('' === $raw || strlen($raw) > 200) {
            throw new InvalidArgumentException('Choose an allowed term.');
        }
        if ($column->choices && ! array_key_exists($raw, $column->choices)) {
            throw new InvalidArgumentException('Choose an allowed term.');
        }

        $choices = $this->filterChoices($column);
        if ($choices) {
            if (! array_key_exists($raw, $choices)) {
                throw new InvalidArgumentException('Choose an allowed term.');
            }
            return $raw;
        }

        // The taxonomy is larger than the bounded choice list, so only a
        // configured allowlist may be used, and each slug is looked up on its
        // own rather than by loading every term.
        if (! $column->choices || false === $this->termId($column, $raw)) {
            throw new InvalidArgumentException('Choose an allowed term.');
        }
        return $raw;
    }

    public function filterChoices(ColumnDefinition $column): array
    {
        if (! $this->supports($column)) {
            return array();
        }
        if (array_key_exists($column->field, $this->termCache)) {
            return $this->termCache[$column->field] ?? array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => $column->field,
                'hide_empty' => false,
                'number'     => self::MAX_CHOICES + 1,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        if (! is_array($terms) || count($terms) > self::MAX_CHOICES) {
            $this->termCache[$column->field] = null;
            return array();
        }

        $choices = array();
        foreach ($terms as $term) {
            $slug = (string) $term->slug;
            $name = (string) $term->name;
            if ('' === $slug || strlen($slug) > 200 || strlen($name) > 200) {
                $this->termCache[$column->field] = null;
                return array();
            }
            $choices[$slug] = $name;
        }
        $this->termCache[$column->field] = $choices;
        return $choices;
    }

    public function sanitize(ColumnDefinition $column, mixed $value): mixed
    {
        unset($column);
        return $value;
    }

    public function lock(int $postId, ColumnDefinition $column): void
    {
        unset($column);
        global $wpdb;
        $postLock = $wpdb->query(
            $wpdb->prepare('SELECT ID FROM %i WHERE ID = %d FOR UPDATE', $wpdb->posts, $postId)
        );
        if (1 !== $postLock) {
            throw new RuntimeException('The post could not be locked for editing.');
        }
        $relationLock = $wpdb->query(
            $wpdb->prepare('SELECT object_id FROM %i WHERE object_id = %d FOR UPDATE', $wpdb->term_relationships, $postId)
        );
        if (false === $relationLock) {
            throw new RuntimeException('The term assignments could not be locked for editing.');
        }
    }

    public function write(int $postId, ColumnDefinition $column, mixed $value, StoredValue $expected): void
    {
        unset($expected);
        if (! is_string($value)) {
            throw new InvalidArgumentException('This adapter only writes one validated term slug.');
        }
        $this->assign($postId, $column, array($value));
    }

    /**
     * Resolve one slug to its term id, or false when no such term exists.
     */
    private function termId(ColumnDefinition $column, string $slug): int|false
    {
        $key = $column->field . ':' . $slug;
        if (array_key_exists($key, $this->slugCache)) {
            return $this->slugCache[$key];
        }
        $term = get_term_by('slug', $slug, $column->field);
        $this->slugCache[$key] = $term instanceof \WP_Term ? (int) $term->term_id : false;
        return $this->slugCache[$key];
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
        $this->assign($postId, $column, array());
    }

    public function restore(int $postId, ColumnDefinition $column, StoredValue $current, StoredValue $target): void
    {
        unset($current);
        $slugs = array();
        if ($target->exists && is_array($target->value)) {
            foreach ($target->value as $slug) {
                if (! is_string($slug)) {
                    throw new RuntimeException('The audited term list is not valid.');
                }
                $slugs[] = $slug;
            }
        }
        $this->assign($postId, $column, $slugs);
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
        return array($wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy);
    }

    /**
     * Replace the whole term set for this taxonomy and confirm the result.
     *
     * Slugs are resolved to existing term ids first. Passing a slug string
     * would let WordPress create a missing term, which would turn an undo of a
     * deleted term into term creation by someone who may only assign terms.
     *
     * @param list<string> $slugs Exact term slugs to assign.
     */
    private function assign(int $postId, ColumnDefinition $column, array $slugs): void
    {
        $termIds = array();
        foreach ($slugs as $slug) {
            $termId = $this->termId($column, $slug);
            if (false === $termId) {
                throw new RuntimeException('One of these terms no longer exists, so the change was refused.');
            }
            $termIds[] = $termId;
        }

        $result = wp_set_object_terms($postId, $termIds, $column->field, false);
        if (is_wp_error($result)) {
            throw new RuntimeException('The terms could not be saved.');
        }
        clean_object_term_cache($postId, $column->field);

        $stored   = $this->read($postId, $column);
        $expected = $slugs;
        sort($expected, SORT_STRING);
        $actual = $stored->exists && is_array($stored->value) ? $stored->value : array();
        if ($actual !== $expected) {
            throw new RuntimeException('The saved terms could not be confirmed. No change was kept.');
        }
    }
}
