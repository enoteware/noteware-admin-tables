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
use Noteware\AdminTables\Editing\ValueValidator;
use Noteware\AdminTables\Model\ColumnDefinition;

final class QueryController
{
    /** @var list<string> */
    private array $errors = array();

    /** @var list<string> */
    private array $warnings = array();

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

    public function apply(\WP_Query $query): void
    {
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
        if (is_string($orderby) && str_starts_with($orderby, 'nat_')) {
            $column = $this->configuration->column($postType, substr($orderby, 4));
            if ($column && $column->sortable && $this->adapters->get($column->source)->supports($column)) {
                $this->applySort($query, $column);
            }
        }

        $existingMetaQuery = $query->get('meta_query');
        $existingMetaQuery = is_array($existingMetaQuery) ? $existingMetaQuery : array();
        $pluginMetaFilters = array();
        $metadataFilters   = 0;
        foreach ($this->configuration->columns($postType) as $column) {
            if (! $column->filterable) {
                continue;
            }
            $parameter = 'nat_filter_' . $column->key;
            if (! isset($_GET[$parameter])) {
                continue;
            }
            if (! is_string($_GET[$parameter])) {
                $this->rejectFilter($query, $column);
                continue;
            }
            if ('' === $_GET[$parameter]) {
                continue;
            }
            if (! $this->adapters->get($column->source)->supports($column)) {
                $this->rejectFilter($query, $column, __('This field configuration does not support scalar filtering.', 'noteware-admin-tables'));
                continue;
            }
            try {
                $value = ValueValidator::validate($column, sanitize_text_field(wp_unslash($_GET[$parameter])));
            } catch (InvalidArgumentException) {
                $this->rejectFilter($query, $column);
                continue;
            }

            if ('native' === $column->source) {
                $this->applyNativeFilter($query, $column, $value);
                continue;
            }
            ++$metadataFilters;
            if ($metadataFilters > 5) {
                $this->rejectFilter($query, $column, __('No more than five metadata filters may run together.', 'noteware-admin-tables'));
                continue;
            }
            $this->markExpensive($column);
            if ('acf' === $column->source && 'date' === $column->type) {
                $value = str_replace('-', '', $value);
            }
            $pluginMetaFilters[] = array(
                'key'     => $column->field,
                'value'   => $value,
                'compare' => '=',
                'type'    => $this->metaType($column),
            );
        }

        if ($pluginMetaFilters) {
            $metaQuery = $existingMetaQuery
                ? array('relation' => 'AND', $existingMetaQuery, array_merge(array('relation' => 'AND'), $pluginMetaFilters))
                : $pluginMetaFilters;
            $query->set('meta_query', $metaQuery);
        }
    }

    private function applySort(\WP_Query $query, ColumnDefinition $column): void
    {
        if ('native' === $column->source) {
            $allowed = array('id' => 'ID', 'title' => 'title', 'author' => 'author', 'date' => 'date', 'status' => 'post_status');
            if (isset($allowed[$column->field])) {
                $query->set('orderby', $allowed[$column->field]);
            }
            return;
        }
        $this->markExpensive($column);
        $sortClause = 'nat_sort_' . $column->key;
        $metaQuery  = $query->get('meta_query');
        $metaQuery  = is_array($metaQuery) ? $metaQuery : array();
        $sortPresence = array(
            'relation'                    => 'OR',
            $sortClause                   => array(
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
        $query->set('orderby', $sortClause);
    }

    private function applyNativeFilter(\WP_Query $query, ColumnDefinition $column, string $value): void
    {
        if ('status' === $column->field && get_post_status_object($value)) {
            $query->set('post_status', $value);
            return;
        }
        if ('author' === $column->field && 1 === preg_match('/^[1-9][0-9]*$/D', $value)) {
            $query->set('author', (int) $value);
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
