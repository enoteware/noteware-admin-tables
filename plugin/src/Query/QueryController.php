<?php
/**
 * Allowlisted list-screen sorting and filtering.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Query;

use InvalidArgumentException;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Contract\FilterableFieldAdapter;
use Noteware\AdminTables\Editing\ValueValidator;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;
use Noteware\AdminTables\Segment\SegmentDefinition;

final class QueryController
{
    private const MAX_METADATA_FILTERS = 5;

    /** @var list<string> */
    private array $errors = array();

    /** @var list<string> */
    private array $warnings = array();

    /** @var array<string, mixed> */
    private array $request = array();

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AdapterRegistry $adapters
    ) {
    }

    public function register(): void
    {
        add_action('pre_get_posts', array($this, 'apply'));
        add_action('admin_notices', array($this, 'renderErrors'));
    }

    /** @param array<string, mixed>|null $request Explicit unslashed segment values, or the browser request. */
    public function apply(\WP_Query $query, ?array $request = null): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filter values are individually validated below.
        $this->request = $request ?? wp_unslash($_GET);
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType = $query->get('post_type');
        $postType = is_string($postType) && '' !== $postType ? $postType : 'post';
        if (! in_array($postType, $this->configuration->postTypes(), true)) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || 'edit' !== $screen->base || $postType !== $screen->post_type) {
            return;
        }

        $orderby = $query->get('orderby');
        if (is_string($orderby) && str_starts_with($orderby, ScreenDefinition::COLUMN_PREFIX)) {
            $column = $this->configuration->column($postType, substr($orderby, strlen(ScreenDefinition::COLUMN_PREFIX)));
            if ($column && $column->sortable && $this->adapters->get($column->source)->supports($column)) {
                $this->applySort($query, $column);
            }
        }

        $this->applyColumnFilters($query, $postType);
    }

    /**
     * Apply allowlisted filters from an explicit frozen request.
     *
     * Used by export jobs that are not the current list-screen main query.
     *
     * @param array<string, mixed> $request Unslashed filter values.
     */
    public function applyFrozenFilters(\WP_Query $query, string $postType, array $request): void
    {
        if (! in_array($postType, $this->configuration->postTypes(), true)) {
            return;
        }
        $this->request = $request;
        $this->applyColumnFilters($query, $postType);
    }

    private function applyColumnFilters(\WP_Query $query, string $postType): void
    {
        $pluginMetaFilters = array();
        $pluginTaxFilters  = array();
        $metadataFilters   = 0;

        foreach ($this->configuration->columns($postType) as $column) {
            if (! $column->filterable) {
                continue;
            }
            $operator = $this->requestedOperator($query, $column);
            if (null === $operator) {
                continue;
            }
            if (! $this->adapters->get($column->source)->supports($column)) {
                $this->rejectFilter($query, $column, __('This field configuration does not support filtering.', 'noteware-admin-tables'));
                continue;
            }
            if ('taxonomy' === $column->source && ! is_object_in_taxonomy($postType, $column->field)) {
                $this->rejectFilter($query, $column, __('This taxonomy is not registered for this screen.', 'noteware-admin-tables'));
                continue;
            }

            if ('taxonomy' === $column->source) {
                $clause = $this->taxonomyClause($query, $column, $operator);
                if (null !== $clause) {
                    $pluginTaxFilters[] = $clause;
                }
                continue;
            }

            if ('native' === $column->source) {
                $value = $this->exactValue($query, $column);
                if (null !== $value) {
                    $this->applyNativeFilter($query, $column, $value);
                }
                continue;
            }

            ++$metadataFilters;
            if ($metadataFilters > self::MAX_METADATA_FILTERS) {
                $this->rejectFilter($query, $column, __('No more than five metadata filters may run together.', 'noteware-admin-tables'));
                continue;
            }
            $clause = $this->metadataClause($query, $column, $operator);
            if (null !== $clause) {
                $this->markExpensive($column);
                $pluginMetaFilters[] = $clause;
            }
        }

        if ($pluginMetaFilters) {
            $existing  = $query->get('meta_query');
            $existing  = is_array($existing) ? $existing : array();
            $additions = array_merge(array('relation' => 'AND'), $pluginMetaFilters);
            $query->set('meta_query', $existing ? array('relation' => 'AND', $existing, $additions) : $pluginMetaFilters);
        }

        if ($pluginTaxFilters) {
            $existing  = $query->get('tax_query');
            $existing  = is_array($existing) ? $existing : array();
            $additions = array_merge(array('relation' => 'AND'), $pluginTaxFilters);
            $query->set('tax_query', $existing ? array('relation' => 'AND', $existing, $additions) : $pluginTaxFilters);
        }
    }

    /**
     * Replay only on the current editable post screen. Invalid saved state fails closed.
     * The caller resolves the visible view and personal/shared segment before calling.
     */
    public function applySegment(\WP_Query $query, string $postType, SegmentDefinition $segment): void
    {
        $screen = get_current_screen();
        $type = get_post_type_object($postType);
        $queryType = $query->get('post_type') ?: 'post';
        if (! is_admin() || ! $query->is_main_query() || ! $screen || 'edit' !== $screen->base || $screen->post_type !== $postType || $queryType !== $postType) {
            return;
        }
        try {
            if (! $type || ! current_user_can($type->cap->edit_posts) || ! $this->configuration->screen($postType)) {
                throw new InvalidArgumentException('The saved segment screen is not accessible.');
            }
            $requests = SegmentRequest::compile($segment, $this->configuration->columns($postType), $this->adapters);
            $data = $segment->toArray();
            if (isset($data['status']) && '' !== $data['status'] && 'all' !== $data['status'] && ! get_post_status_object($data['status'])) {
                throw new InvalidArgumentException('The saved status is no longer available.');
            }
            if (isset($data['sort'])) {
                $query->set('orderby', ScreenDefinition::COLUMN_PREFIX . $data['sort']['column']);
                $query->set('order', $data['sort']['direction']);
            }
            if (isset($data['search'])) {
                $query->set('s', sanitize_text_field($data['search']));
            }
            if (isset($data['status'])) {
                $query->set('post_status', in_array($data['status'], array('', 'all'), true) ? '' : $data['status']);
            }
            // No condition is omitted. Multiple conditions on one column are ANDed by apply.
            foreach ($requests ?: array(array()) as $request) {
                $this->apply($query, $request);
            }
        } catch (InvalidArgumentException $error) {
            $query->set('post__in', array(0));
            $this->errors[] = $error->getMessage();
        }
    }

    /**
     * Resolve the operator this request asks for, or null when no filter applies.
     *
     * A presence operator only ever applies when the request names it. An
     * unfiltered list screen must show every record, so no configuration order
     * can make the first page arrive already filtered.
     */
    private function requestedOperator(\WP_Query $query, ColumnDefinition $column): ?string
    {
        $operatorParameter = 'nat_op_' . $column->key;
        $operator          = 'is';

        if (isset($this->request[$operatorParameter])) {
            if (! is_string($this->request[$operatorParameter])) {
                $this->rejectFilter($query, $column);
                return null;
            }
            $requested = sanitize_key($this->request[$operatorParameter]);
            if ('' !== $requested) {
                if (! $column->supportsOperator($requested)) {
                    $this->rejectFilter($query, $column, __('That filter type is not available for this column.', 'noteware-admin-tables'));
                    return null;
                }
                $operator = $requested;
            }
        }

        if ('is' !== $operator) {
            return $operator;
        }
        if (! $column->supportsOperator('is')) {
            // The column only offers presence filters, and this request did not
            // ask for one, so the screen stays unfiltered.
            return null;
        }

        $parameter = 'nat_filter_' . $column->key;
        if (! isset($this->request[$parameter])) {
            return null;
        }
        if (! is_string($this->request[$parameter])) {
            $this->rejectFilter($query, $column);
            return null;
        }
        if ('' === $this->request[$parameter]) {
            return null;
        }
        return 'is';
    }

    /**
     * The validated exact filter value, or null when the request was rejected.
     */
    private function exactValue(\WP_Query $query, ColumnDefinition $column, string $prefix = 'nat_filter_'): ?string
    {
        $parameter = $prefix . $column->key;
        if (! isset($this->request[$parameter]) || ! is_string($this->request[$parameter])) {
            $this->rejectFilter($query, $column);
            return null;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The typed validator must see the exact unslashed value and sanitizes text itself.
        $raw     = $this->request[$parameter];
        $adapter = $this->adapters->get($column->source);
        try {
            if ($adapter instanceof FilterableFieldAdapter) {
                return $adapter->validateFilterValue($column, $raw);
            }
            return ValueValidator::validate($column, $raw);
        } catch (InvalidArgumentException) {
            $this->rejectFilter($query, $column);
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function metadataClause(\WP_Query $query, ColumnDefinition $column, string $operator): ?array
    {
        if (in_array($operator, array('empty', 'not_empty', 'absent', 'present', 'stored_empty'), true)) {
            return MetadataCondition::plan($column, $operator);
        }
        $value = $this->exactValue($query, $column);
        if (null === $value) {
            return null;
        }
        $upper = 'between' === $operator ? $this->exactValue($query, $column, 'nat_filter_to_') : null;
        if ('between' === $operator && null === $upper) {
            return null;
        }
        try {
            return MetadataCondition::plan($column, $operator, $value, $upper);
        } catch (InvalidArgumentException) {
            $this->rejectFilter($query, $column);
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function taxonomyClause(\WP_Query $query, ColumnDefinition $column, string $operator): ?array
    {
        if ('empty' === $operator) {
            return array(
                'taxonomy' => $column->field,
                'operator' => 'NOT EXISTS',
            );
        }
        if ('not_empty' === $operator) {
            return array(
                'taxonomy' => $column->field,
                'operator' => 'EXISTS',
            );
        }

        $value = $this->exactValue($query, $column);
        if (null === $value) {
            return null;
        }
        return array(
            'taxonomy'         => $column->field,
            'field'            => 'slug',
            'terms'            => array($value),
            'operator'         => 'IN',
            'include_children' => false,
        );
    }

    private function applySort(\WP_Query $query, ColumnDefinition $column): void
    {
        if ('native' === $column->source) {
            $allowed = array('id' => 'ID', 'title' => 'title', 'slug' => 'name', 'author' => 'author', 'date' => 'date');
            if (isset($allowed[$column->field])) {
                $direction = 'ASC' === strtoupper((string) $query->get('order')) ? 'ASC' : 'DESC';
                $query->set('orderby', array($allowed[$column->field] => $direction, 'ID' => $direction));
            }
            return;
        }
        $this->markExpensive($column);
        $sortClause = 'nat_sort_' . $column->key;
        $metaQuery  = $query->get('meta_query');
        $metaQuery  = is_array($metaQuery) ? $metaQuery : array();
        $sortPresence = array(
            'relation'                   => 'OR',
            $sortClause                  => array(
                'key'     => $column->field,
                'compare' => 'EXISTS',
                'type'    => $this->metaType($column),
            ),
            $sortClause . '_not_present' => array(
                'key'     => $column->field,
                'compare' => 'NOT EXISTS',
            ),
        );
        $metaQuery = $metaQuery
            ? array('relation' => 'AND', $metaQuery, $sortPresence)
            : array($sortPresence);
        $query->set('meta_query', $metaQuery);
        $direction = 'ASC' === strtoupper((string) $query->get('order')) ? 'ASC' : 'DESC';
        $query->set('orderby', array($sortClause => $direction, 'ID' => $direction));
    }

    private function applyNativeFilter(\WP_Query $query, ColumnDefinition $column, string $value): void
    {
        if ('status' === $column->field && get_post_status_object($value)) {
            $query->set('post_status', $value);
            return;
        }
        if ('author' === $column->field && 1 === preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            // An exact list rather than the author argument, because an
            // imported or system-generated post can legitimately hold author
            // zero and the author argument treats zero as no filter at all.
            $query->set('author__in', array((int) $value));
            return;
        }
        $this->rejectFilter($query, $column);
    }

    private function metaType(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'number'  => 'DECIMAL(65,30)',
            'boolean' => 'UNSIGNED',
            'date'    => 'acf' === $column->source ? 'UNSIGNED' : 'DATE',
            default   => 'CHAR',
        };
    }

    public function renderErrors(): void
    {
        foreach (array_unique($this->errors) as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
        foreach (array_unique($this->warnings) as $warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
    }

    private function rejectFilter(\WP_Query $query, ColumnDefinition $column, ?string $message = null): void
    {
        $query->set('post__in', array(0));
        $this->errors[] = $message ?? sprintf(__('The %s filter value is invalid.', 'noteware-admin-tables'), $column->label);
    }

    private function markExpensive(ColumnDefinition $column): void
    {
        $this->warnings[] = sprintf(
            __('The %s operation uses unindexed metadata and may be slow on large sites.', 'noteware-admin-tables'),
            $column->label
        );
    }
}
