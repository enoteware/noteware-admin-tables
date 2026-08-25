<?php
/**
 * Post list-screen registry and rendering.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Screen;

use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Config\Configuration;

final class PostScreenController
{
    private readonly ColumnRenderer $renderer;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AdapterRegistry $adapters
    ) {
        $this->renderer = new ColumnRenderer();
    }

    public function register(): void
    {
        add_action('current_screen', array($this, 'registerScreen'));
        add_action('restrict_manage_posts', array($this, 'renderFilters'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
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
        $postType = $this->currentPostType();
        foreach ($this->configuration->columns($postType) as $column) {
            $columns['nat_' . $column->key] = $column->label;
        }
        return $columns;
    }

    /**
     * @param  array<string, string> $columns Sortable columns.
     * @return array<string, string>
     */
    public function sortableColumns(array $columns): array
    {
        foreach ($this->configuration->columns($this->currentPostType()) as $column) {
            if ($column->sortable && $this->adapters->get($column->source)->supports($column)) {
                $columns['nat_' . $column->key] = 'nat_' . $column->key;
            }
        }
        return $columns;
    }

    public function cell(string $columnName, int $postId): void
    {
        if (! str_starts_with($columnName, 'nat_')) {
            return;
        }
        $postType = get_post_type($postId);
        if (! is_string($postType)) {
            return;
        }
        $column = $this->configuration->column($postType, substr($columnName, 4));
        if (! $column) {
            return;
        }
        $stored = $this->adapters->get($column->source)->read($postId, $column);
        echo '<div class="nat-cell" data-column="' . esc_attr($column->key) . '"><span class="nat-value">';
        echo wp_kses_post($this->renderer->value($column, $stored));
        echo '</span>' . wp_kses($this->renderer->editor($postId, $column, $stored), $this->editorAllowedHtml()) . '</div>';
    }

    /**
     * Prime the page-scoped value and attachment caches before cell rendering.
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

        $postType = get_post_type($postIds[0]);
        if (! is_string($postType)) {
            return;
        }
        $attachmentIds = array();
        foreach ($this->configuration->columns($postType) as $column) {
            if ('image' !== $column->type || 'native' === $column->source) {
                continue;
            }
            foreach ($postIds as $postId) {
                $attachmentId = (int) get_post_meta($postId, $column->field, true);
                if ($attachmentId > 0) {
                    $attachmentIds[$attachmentId] = $attachmentId;
                }
            }
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
            $name     = 'nat_filter_' . $column->key;
            $selected = $this->selectedFilterValue($name);
            echo '<label class="screen-reader-text" for="' . esc_attr($name) . '">' . esc_html(sprintf(__('Filter by %s', 'noteware-admin-tables'), $column->label)) . '</label>';
            if (in_array($column->type, array('boolean', 'select'), true)) {
                $choices = 'boolean' === $column->type ? array('1' => __('Yes', 'noteware-admin-tables'), '0' => __('No', 'noteware-admin-tables')) : $column->choices;
                echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '"><option value="">' . esc_html(sprintf(__('All %s', 'noteware-admin-tables'), $column->label)) . '</option>';
                foreach ($choices as $value => $label) {
                    echo '<option value="' . esc_attr((string) $value) . '"' . selected($selected, (string) $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } else {
                $type = 'number' === $column->type ? 'number' : ('date' === $column->type ? 'date' : 'search');
                echo '<input id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($selected) . '" placeholder="' . esc_attr($column->label) . '">';
            }
        }
    }

    public function enqueueAssets(string $hook): void
    {
        if ('edit.php' !== $hook || ! in_array($this->currentPostType(), $this->configuration->postTypes(), true)) {
            return;
        }
        wp_enqueue_style('noteware-admin-tables', plugins_url('assets/admin.css', NAT_PLUGIN_FILE), array(), NAT_VERSION);
        wp_enqueue_script('noteware-admin-tables', plugins_url('assets/admin.js', NAT_PLUGIN_FILE), array(), NAT_VERSION, true);
        wp_localize_script('noteware-admin-tables', 'natAdminTables', array('ajaxUrl' => admin_url('admin-ajax.php')));
    }

    private function selectedFilterValue(string $name): string
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
                'type'          => true,
                'class'         => true,
                'aria-expanded' => true,
                'aria-controls' => true,
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
                'id'    => true,
                'name'  => true,
                'type'  => true,
                'value' => true,
                'step'  => true,
            ),
            'select' => array(
                'id'   => true,
                'name' => true,
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
