<?php
/**
 * Accessible view editor using the trusted site's column catalog.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Admin;

use Noteware\AdminTables\View\ViewRepository;
use Noteware\AdminTables\View\ViewLayout;
use Throwable;

final class ViewPage
{
    /** @param array<string, array<string, mixed>> $catalog */
    public function render(array $catalog, ViewRepository $repository): void
    {
        echo '<div class="wrap nat-views"><h1>' . esc_html__('Table views', 'noteware-admin-tables') . '</h1><p>' . esc_html__('Choose, arrange, and name columns. Checkbox and title columns always stay available. Save a copy to keep an existing view.', 'noteware-admin-tables') . '</p><noscript>' . esc_html__('Enable JavaScript to edit a view. Switching saved views still works.', 'noteware-admin-tables') . '</noscript>';
        foreach ($catalog as $postType => $screen) {
            try {
                $views = $repository->available($postType);
            } catch (Throwable) {
                continue;
            }
            $active = get_user_option('nat_active_view_' . $postType);
            $selected = is_string($active) ? ($views[$active] ?? null) : null;
            if (null !== $selected) {
                try {
                    ViewLayout::apply($screen, $selected);
                } catch (Throwable) {
                    $selected = null;
                }
            }
            $items = $selected['columns'] ?? array();
            $keys = array_column($items, 'key');
            foreach ($screen['columns'] ?? array() as $column) {
                if (! in_array($column['key'], $keys, true)) {
                    $items[] = array('key' => $column['key'], 'label' => $column['label'], 'width' => $column['width'] ?? '', 'visible' => null === $selected);
                }
            }
            $seed = array('version' => 1, 'id' => 'v_' . wp_generate_uuid4(), 'name' => '', 'post_type' => $postType, 'visibility' => 'personal', 'roles' => array(), 'columns' => $items);
            echo '<section class="nat-view-screen"><h2>' . esc_html($postType) . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden($postType, 'select');
            echo '<label>' . esc_html__('Active view', 'noteware-admin-tables') . ' <select name="view_id"><option value="default">' . esc_html__('Site default (reset)', 'noteware-admin-tables') . '</option>';
            foreach ($views as $id => $view) {
                echo '<option value="' . esc_attr($id) . '" ' . selected($active, $id, false) . '>' . esc_html($view['name']) . '</option>';
            }
            echo '</select></label> <button class="button">' . esc_html__('Switch view', 'noteware-admin-tables') . '</button></form>';
            echo '<form class="nat-view-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-view="' . esc_attr((string) wp_json_encode($seed)) . '">';
            $this->hidden($postType, 'save');
            echo '<input type="hidden" name="view"><p><label>' . esc_html__('New view name', 'noteware-admin-tables') . ' <input data-name required pattern=".{1,100}"></label></p><p><label>' . esc_html__('Find a column', 'noteware-admin-tables') . ' <input type="search" data-search></label></p><div class="nat-view-columns"></div>';
            if (current_user_can('manage_options')) {
                echo '<fieldset><legend>' . esc_html__('Share with roles (leave empty for a personal view)', 'noteware-admin-tables') . '</legend>';
                foreach (wp_roles()->roles as $role => $details) {
                    echo '<label><input type="checkbox" data-role value="' . esc_attr($role) . '"> ' . esc_html(translate_user_role($details['name'])) . '</label> ';
                }
                echo '</fieldset>';
            }
            echo '<p><button class="button button-primary">' . esc_html__('Save as new view', 'noteware-admin-tables') . '</button></p><p role="status" aria-live="polite"></p></form></section>';
        }
        echo '</div>';
    }

    private function hidden(string $postType, string $operation): void
    {
        wp_nonce_field('nat_save_view');
        echo '<input type="hidden" name="action" value="nat_save_view"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="post_type" value="' . esc_attr($postType) . '">';
    }
}
