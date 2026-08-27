<?php
/**
 * Post list-screen registry and rendering.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Screen;

use Throwable;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\ScreenDefinition;

final class PostScreenController
{
    private readonly ColumnRenderer $renderer;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AdapterRegistry $adapters,
        private readonly ?AuditRepository $audit = null
    ) {
        $this->renderer = new ColumnRenderer();
    }

    public function register(): void
    {
        add_action('current_screen', array($this, 'registerScreen'));
        add_action('restrict_manage_posts', array($this, 'renderFilters'));
        add_action('manage_posts_extra_tablenav', array($this, 'renderBulkEditor'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('admin_head', array($this, 'renderColumnWidths'));
        add_filter('the_posts', array($this, 'preloadPosts'), 10, 2);
    }

    public function registerScreen(\WP_Screen $screen): void
    {
        if ('edit' !== $screen->base || ! $screen->post_type || ! in_array($screen->post_type, $this->configuration->postTypes(), true)) {
            return;
        }
        $postType = $screen->post_type;
        add_filter("manage_edit-{$postType}_columns", array($this, 'columns'));
        add_action("manage_{$postType}_posts_custom_column", array($this, 'cell'), 10, 2);
        add_filter("manage_edit-{$postType}_sortable_columns", array($this, 'sortableColumns'));
    }

    /**
     * @param  array<string, string> $columns Existing columns.
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $screen = $this->configuration->screen($this->currentPostType());
        if (! $screen) {
            return $columns;
        }

        $hidden   = array_merge($screen->remove, $screen->replacedColumns());
        $replaces = array();
        $appended = array();
        foreach ($screen->columns as $column) {
            if (null !== $column->replaces) {
                $replaces[$column->replaces][] = $column;
                continue;
            }
            $appended[ScreenDefinition::COLUMN_PREFIX . $column->key] = $column->label;
        }

        $result = array();
        foreach ($columns as $id => $label) {
            if (isset($replaces[$id])) {
                foreach ($replaces[$id] as $column) {
                    $result[ScreenDefinition::COLUMN_PREFIX . $column->key] = $column->label;
                }
                continue;
            }
            if (in_array($id, $hidden, true)) {
                continue;
            }
            $result[$id] = $label;
        }

        // A replacement for a built-in column that this screen does not show still
        // has to appear, otherwise the configured column would silently vanish.
        foreach ($replaces as $builtIn => $replacements) {
            if (array_key_exists($builtIn, $columns)) {
                continue;
            }
            foreach ($replacements as $column) {
                $result[ScreenDefinition::COLUMN_PREFIX . $column->key] = $column->label;
            }
        }

        $result = array_merge($result, $appended);

        return $screen->order ? $this->applyOrder($result, $screen->order) : $result;
    }

    /**
     * @param  array<string, string> $columns Resolved columns.
     * @param  list<string>          $order   Configured order.
     * @return array<string, string>
     */
    private function applyOrder(array $columns, array $order): array
    {
        $ordered = array();
        foreach ($order as $id) {
            if (array_key_exists($id, $columns)) {
                $ordered[$id] = $columns[$id];
            }
        }
        foreach ($columns as $id => $label) {
            if (! array_key_exists($id, $ordered)) {
                $ordered[$id] = $label;
            }
        }
        return $ordered;
    }

    /**
     * @param  array<string, string> $columns Sortable columns.
     * @return array<string, string>
     */
    public function sortableColumns(array $columns): array
    {
        foreach ($this->configuration->columns($this->currentPostType()) as $column) {
            if ($column->sortable && $this->adapters->get($column->source)->supports($column)) {
                $columns[ScreenDefinition::COLUMN_PREFIX . $column->key] = ScreenDefinition::COLUMN_PREFIX . $column->key;
            }
        }
        return $columns;
    }

    public function cell(string $columnName, int $postId): void
    {
        if (! str_starts_with($columnName, ScreenDefinition::COLUMN_PREFIX)) {
            return;
        }
        $postType = get_post_type($postId);
        if (! is_string($postType)) {
            return;
        }
        $column = $this->configuration->column($postType, substr($columnName, strlen(ScreenDefinition::COLUMN_PREFIX)));
        if (! $column) {
            return;
        }
        $adapter = $this->adapters->get($column->source);
        $stored  = $adapter->read($postId, $column);

        $editor = '';
        if ($column->editable && $adapter instanceof EditableFieldAdapter && $this->mayEdit($adapter, $postId, $column)) {
            $editor = $this->renderer->editor(
                $postId,
                $column,
                $stored,
                $this->choicesFor($column),
                $adapter->supportsRemoval($column)
            );
            $auditId = $this->audit?->undoableId($postId, $column->key);
            if (null !== $auditId) {
                $editor .= $this->renderer->undoButton($auditId, wp_create_nonce($adapter->nonceAction('undo', $auditId, $column)));
            }
        }

        echo '<div class="nat-cell" data-column="' . esc_attr($column->key) . '"><span class="nat-value">';
        echo wp_kses($this->renderer->safeValue($column, $stored), ColumnRenderer::allowedValueHtml());
        echo '</span>' . wp_kses($editor, $this->editorAllowedHtml()) . '</div>';
    }

    /**
     * Prime the page-scoped value, term, and attachment caches before cell rendering.
     *
     * @param  list<\WP_Post> $posts Main query posts.
     * @return list<\WP_Post>
     */
    public function preloadPosts(array $posts, \WP_Query $query): array
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return $posts;
        }
        $postType = $query->get('post_type');
        $postType = is_string($postType) && '' !== $postType ? $postType : 'post';
        if (! in_array($postType, $this->configuration->postTypes(), true)) {
            return $posts;
        }
        $screen = get_current_screen();
        if (! $screen || 'edit' !== $screen->base || $postType !== $screen->post_type) {
            return $posts;
        }
        $this->preload(array_map(static fn (\WP_Post $post): int => $post->ID, $posts));
        return $posts;
    }

    /** @param list<int> $postIds Page-scoped post IDs. */
    public function preload(array $postIds): void
    {
        if (! $postIds) {
            return;
        }
        update_meta_cache('post', $postIds);
        $this->audit?->preloadUndoable($postIds, get_current_user_id());

        $postType = get_post_type($postIds[0]);
        if (! is_string($postType)) {
            return;
        }

        $taxonomies    = array();
        $attachmentIds = array();
        foreach ($this->configuration->columns($postType) as $column) {
            if ('taxonomy' === $column->source) {
                $taxonomies[$column->field] = $column->field;
                continue;
            }
            if ('image' !== $column->type) {
                continue;
            }
            foreach ($postIds as $postId) {
                $attachmentId = 'native' === $column->source
                    ? (int) get_post_meta($postId, '_thumbnail_id', true)
                    : (int) get_post_meta($postId, $column->field, true);
                if ($attachmentId > 0) {
                    $attachmentIds[$attachmentId] = $attachmentId;
                }
            }
        }

        if ($taxonomies) {
            update_object_term_cache($postIds, $postType);
        }

        if ($attachmentIds) {
            get_posts(
                array(
                    'post_type'              => 'attachment',
                    'post_status'            => 'inherit',
                    'post__in'               => array_values($attachmentIds),
                    'posts_per_page'         => count($attachmentIds),
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                )
            );
        }
    }

    public function renderFilters(string $postType): void
    {
        foreach ($this->configuration->columns($postType) as $column) {
            if (! $column->filterable || ! $this->adapters->get($column->source)->supports($column)) {
                continue;
            }
            if ('taxonomy' === $column->source && ! is_object_in_taxonomy($postType, $column->field)) {
                continue;
            }
            $name     = 'nat_filter_' . $column->key;
            $operator = 'nat_op_' . $column->key;
            $selected = $this->requestValue($name);
            $choices  = $this->choicesFor($column);

            // A taxonomy larger than the bounded choice list has no safe exact
            // control, but its presence operators still work without one.
            $exactAvailable = $column->supportsOperator('is')
                && ('taxonomy' !== $column->source || (bool) $choices);
            $operators      = array_values(
                array_filter(
                    $column->operators,
                    static fn (string $available): bool => 'is' !== $available || $exactAvailable
                )
            );
            if (! $operators) {
                continue;
            }

            // The operator control is rendered whenever anything beyond the
            // plain exact filter is offered. A presence operator can only reach
            // the server through this control.
            if (array('is') !== $operators) {
                $chosen = $this->requestValue($operator);
                if (! in_array($chosen, $operators, true)) {
                    $chosen = in_array('is', $operators, true) ? 'is' : '';
                }
                echo '<label class="screen-reader-text" for="' . esc_attr($operator) . '">' . esc_html(sprintf(__('%s filter type', 'noteware-admin-tables'), $column->label)) . '</label>';
                echo '<select id="' . esc_attr($operator) . '" name="' . esc_attr($operator) . '" class="nat-filter-operator">';
                if (! $exactAvailable) {
                    // Without an exact option there is no other neutral choice,
                    // so the control offers one that matches an unfiltered list.
                    echo '<option value=""' . selected($chosen, '', false) . '>' . esc_html(sprintf(__('All %s', 'noteware-admin-tables'), $column->label)) . '</option>';
                }
                foreach ($operators as $available) {
                    echo '<option value="' . esc_attr($available) . '"' . selected($chosen, $available, false) . '>' . esc_html($this->operatorLabel($available)) . '</option>';
                }
                echo '</select>';
            }

            if (! $exactAvailable) {
                continue;
            }

            echo '<label class="screen-reader-text" for="' . esc_attr($name) . '">' . esc_html(sprintf(__('Filter by %s', 'noteware-admin-tables'), $column->label)) . '</label>';
            if ($choices) {
                echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '"><option value="">' . esc_html(sprintf(__('All %s', 'noteware-admin-tables'), $column->label)) . '</option>';
                foreach ($choices as $value => $label) {
                    echo '<option value="' . esc_attr((string) $value) . '"' . selected($selected, (string) $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } else {
                $type = match ($column->type) {
                    'number' => 'number',
                    'date'   => 'date',
                    'url'    => 'search',
                    default  => 'search',
                };
                echo '<input id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($selected) . '" placeholder="' . esc_attr($column->label) . '">';
            }
        }
    }

    public function renderBulkEditor(string $which): void
    {
        if ('top' !== $which) {
            return;
        }
        $postType = $this->currentPostType();
        $columns  = array();
        foreach ($this->configuration->columns($postType) as $column) {
            $adapter = $this->adapters->get($column->source);
            if (! $column->bulkEditable || ! $adapter instanceof EditableFieldAdapter || ! $adapter->supports($column)) {
                continue;
            }
            $columns[] = $column;
        }
        $postTypeObject = get_post_type_object($postType);
        $capability     = is_object($postTypeObject) ? (string) $postTypeObject->cap->edit_posts : 'edit_posts';
        if (! $columns || ! current_user_can($capability)) {
            return;
        }

        echo '<div class="nat-bulk alignleft actions">';
        echo '<button type="button" class="button nat-bulk-toggle" aria-expanded="false" aria-controls="nat-bulk-panel">' . esc_html__('Bulk edit fields', 'noteware-admin-tables') . '</button>';
        echo '<div id="nat-bulk-panel" class="nat-bulk-panel" hidden>';
        echo '<label class="screen-reader-text" for="nat-bulk-column">' . esc_html__('Field to change', 'noteware-admin-tables') . '</label>';
        echo '<select id="nat-bulk-column" class="nat-bulk-column">';
        foreach ($columns as $column) {
            echo '<option value="' . esc_attr($column->key) . '">' . esc_html($column->label) . '</option>';
        }
        echo '</select>';
        foreach ($columns as $column) {
            $adapter = $this->adapters->get($column->source);
            echo '<div class="nat-bulk-control" data-column="' . esc_attr($column->key) . '" hidden>';
            echo '<label class="screen-reader-text" for="' . esc_attr('nat-bulk-value-' . $column->key) . '">' . esc_html(sprintf(__('New %s', 'noteware-admin-tables'), $column->label)) . '</label>';
            echo wp_kses($this->renderer->bulkControl($column, $this->choicesFor($column)), $this->editorAllowedHtml());
            if ($adapter instanceof EditableFieldAdapter && $adapter->supportsRemoval($column)) {
                echo '<label class="nat-bulk-remove"><input type="checkbox" data-field="remove" value="1"> '
                    . esc_html__('Clear the stored value instead', 'noteware-admin-tables') . '</label>';
            }
            echo '</div>';
        }
        echo '<input type="hidden" id="nat-bulk-nonce" value="' . esc_attr(wp_create_nonce('nat_bulk_edit_' . $postType)) . '">';
        echo '<input type="hidden" id="nat-bulk-post-type" value="' . esc_attr($postType) . '">';
        // The panel lives inside the WordPress filter form, so nothing here is named.
        echo '<button type="button" class="button button-primary nat-bulk-apply">' . esc_html__('Apply to selected', 'noteware-admin-tables') . '</button>';
        echo '<div class="nat-bulk-status" role="status" aria-live="polite"></div>';
        echo '</div></div>';
    }

    public function renderColumnWidths(): void
    {
        $screen = get_current_screen();
        if (! $screen || 'edit' !== $screen->base || ! is_string($screen->post_type)) {
            return;
        }
        $definition = $this->configuration->screen($screen->post_type);
        if (! $definition) {
            return;
        }

        $rules = '';
        if (null !== $definition->minWidth) {
            // A screen with many columns scrolls sideways instead of squeezing
            // every column until its text wraps one character per line.
            $rules .= sprintf(
                '#posts-filter{overflow-x:auto;max-width:100%%;}.wp-list-table{min-width:%s;}',
                $definition->minWidth
            );
        }
        foreach ($definition->columns as $column) {
            if (null === $column->width) {
                continue;
            }
            $rules .= sprintf(
                '.wp-list-table .column-%1$s{width:%2$s;}',
                ScreenDefinition::COLUMN_PREFIX . $column->key,
                $column->width
            );
        }
        if ('' === $rules) {
            return;
        }
        // Both the column key and the width are validated against strict patterns.
        echo '<style id="noteware-admin-tables-widths">' . esc_html($rules) . '</style>';
    }

    public function enqueueAssets(string $hook): void
    {
        if ('edit.php' !== $hook || ! in_array($this->currentPostType(), $this->configuration->postTypes(), true)) {
            return;
        }
        wp_enqueue_style('noteware-admin-tables', plugins_url('assets/admin.css', NAT_PLUGIN_FILE), array(), NAT_VERSION);
        wp_enqueue_script('noteware-admin-tables', plugins_url('assets/admin.js', NAT_PLUGIN_FILE), array(), NAT_VERSION, true);
        wp_localize_script(
            'noteware-admin-tables',
            'natAdminTables',
            array(
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'noSelectionLabel' => __('Select at least one record first.', 'noteware-admin-tables'),
                'undoLabel'        => __('Undo', 'noteware-admin-tables'),
                'workingLabel'     => __('Working.', 'noteware-admin-tables'),
                'undoAllLabel'     => __('Undo these changes', 'noteware-admin-tables'),
            )
        );
    }

    /**
     * @return array<array-key, string>
     */
    private function choicesFor(ColumnDefinition $column): array
    {
        if ('boolean' === $column->type) {
            return array('1' => __('Yes', 'noteware-admin-tables'), '0' => __('No', 'noteware-admin-tables'));
        }
        if ('select' !== $column->type) {
            return array();
        }

        $adapter = $this->adapters->get($column->source);
        $live    = array();
        if ($adapter instanceof \Noteware\AdminTables\Contract\FilterableFieldAdapter) {
            $live = $adapter->filterChoices($column);
        } elseif ($adapter instanceof \Noteware\AdminTables\Adapter\AcfAdapter) {
            $live = $adapter->choices($column);
        }
        if (! $live) {
            return $column->choices;
        }
        if (! $column->choices) {
            return $live;
        }
        return array_intersect_key($live, $column->choices);
    }

    private function mayEdit(EditableFieldAdapter $adapter, int $postId, ColumnDefinition $column): bool
    {
        try {
            $adapter->authorize($postId, $column);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function operatorLabel(string $operator): string
    {
        return match ($operator) {
            'empty'     => __('Is empty', 'noteware-admin-tables'),
            'not_empty' => __('Has a value', 'noteware-admin-tables'),
            default     => __('Is exactly', 'noteware-admin-tables'),
        };
    }

    private function requestValue(string $name): string
    {
        if (! isset($_GET[$name]) || ! is_string($_GET[$name])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact choice keys are escaped at the HTML boundary.
        $value = wp_unslash($_GET[$name]);
        return strlen($value) <= 10000 ? $value : '';
    }

    private function currentPostType(): string
    {
        $postType = isset($_GET['post_type']) && is_string($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        return '' !== $postType ? $postType : 'post';
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function editorAllowedHtml(): array
    {
        return array(
            'button' => array(
                'type'           => true,
                'class'          => true,
                'aria-expanded'  => true,
                'aria-controls'  => true,
                'data-audit-id'  => true,
                'data-nonce'     => true,
            ),
            'div'    => array(
                'id'     => true,
                'class'  => true,
                'hidden' => true,
            ),
            'label'  => array(
                'class' => true,
                'for'   => true,
            ),
            'input'  => array(
                'id'          => true,
                'data-field'  => true,
                'type'        => true,
                'value'       => true,
                'step'        => true,
                'min'         => true,
                'inputmode'   => true,
                'placeholder' => true,
            ),
            'select' => array(
                'id'         => true,
                'data-field' => true,
            ),
            'option' => array(
                'value'    => true,
                'selected' => true,
            ),
            'span'   => array(
                'class'     => true,
                'role'      => true,
                'aria-live' => true,
            ),
        );
    }
}
