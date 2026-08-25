<?php
/**
 * Integration assertions executed inside the real WordPress sandbox.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$assert(is_plugin_active('noteware-admin-tables/noteware-admin-tables.php'), 'The plugin must be active.');
$assert(did_action('noteware_admin_tables_loaded') > 0, 'The public loaded action must fire.');
$assert(post_type_exists('nat_demo_record'), 'The generic fixture post type must be registered.');
$assert(has_action('wp_ajax_nat_inline_edit'), 'The inline-edit AJAX action must be registered.');
$assert(has_action('wp_ajax_nat_undo_edit'), 'The undo AJAX action must be registered.');

// Core fires the dynamic post-type action for pages after the general hierarchical action.
add_filter(
    'noteware_admin_tables_config',
    static function (array $configuration): array {
        $configuration['page'] = array(
            'columns' => array(
                array(
                    'key'        => 'nat_test_page_note',
                    'label'      => 'Test page note',
                    'source'     => 'meta',
                    'type'       => 'text',
                    'field'      => 'nat_test_page_note',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => false,
                    'choices'    => array(),
                ),
            ),
        );
        return $configuration;
    },
    30
);
set_current_screen('edit-page');
$page_screen = get_current_screen();
$assert($page_screen instanceof WP_Screen, 'The page hook assertion requires the page edit screen.');
if ($page_screen instanceof WP_Screen) {
    $assert(has_action('manage_page_posts_custom_column'), 'Configured pages must use the documented dynamic page custom-column action.');
    $assert(! has_action('manage_pages_custom_column'), 'The plugin must not register both page actions and render each cell twice.');

    $page_id = wp_insert_post(
        array(
            'post_type'   => 'page',
            'post_status' => 'draft',
            'post_title'  => 'Generic page hook fixture',
        )
    );
    $assert(is_int($page_id) && $page_id > 0, 'The page hook assertion requires one generic page fixture.');
    if (is_int($page_id) && $page_id > 0) {
        update_post_meta($page_id, 'nat_test_page_note', 'Page hook value');
        if (! class_exists('WP_Posts_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
        }
        $page_list_table = new WP_Posts_List_Table(array('screen' => $page_screen));
        ob_start();
        $page_list_table->column_default(get_post($page_id), 'nat_nat_test_page_note');
        $page_cell = (string) ob_get_clean();
        $page_value_count = substr_count($page_cell, 'Page hook value');
        $assert(
            1 === $page_value_count,
            sprintf('The real Pages list-table dispatcher must render configured cell content exactly once; observed %d in %s.', $page_value_count, wp_strip_all_tags($page_cell))
        );
        wp_delete_post($page_id, true);
    }
}
set_current_screen();

$config = apply_filters('noteware_admin_tables_config', array());
$assert(isset($config['nat_demo_record']['columns']), 'The public configuration filter must return demo columns.');

$required_keys = array('key', 'label', 'source', 'type', 'field', 'sortable', 'filterable', 'editable', 'choices');
$columns       = $config['nat_demo_record']['columns'] ?? array();

foreach ($columns as $column_index => $column) {
    foreach ($required_keys as $required_key) {
        $assert(
            array_key_exists($required_key, $column),
            sprintf('Column %d must contain the %s key.', $column_index, $required_key)
        );
    }
}

$fixture_posts = get_posts(
    array(
        'post_type'      => 'nat_demo_record',
        'post_status'    => 'publish',
        'posts_per_page' => 60,
        'fields'         => 'ids',
        'meta_key'       => '_nat_fixture_index',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
    )
);

$assert(count($fixture_posts) >= 60, 'The default sandbox fixture must contain at least 60 records.');

$posts_by_index = array();
foreach ($fixture_posts as $post_id) {
    $posts_by_index[(int) get_post_meta((int) $post_id, '_nat_fixture_index', true)] = (int) $post_id;
}

$assert(isset($posts_by_index[9]), 'The fixture must contain the numeric-zero case.');
$assert(isset($posts_by_index[10]), 'The fixture must contain the empty-string case.');
$assert(isset($posts_by_index[11]), 'The fixture must contain the empty ACF text case.');

if (isset($posts_by_index[9])) {
    $assert(0 === (int) get_field('nat_demo_number', $posts_by_index[9]), 'Numeric zero must survive the field API.');
}

if (isset($posts_by_index[10])) {
    $assert('' === get_post_meta($posts_by_index[10], 'nat_demo_note', true), 'Stored empty text must remain empty.');
    $assert(metadata_exists('post', $posts_by_index[10], 'nat_demo_note'), 'Stored empty and absent values must differ.');
    $assert(! metadata_exists('post', $posts_by_index[10], '_nat_demo_absent'), 'The absent fixture value must remain absent.');
}

if (isset($posts_by_index[11])) {
    $assert('' === (string) get_field('nat_demo_text', $posts_by_index[11]), 'Stored empty ACF text must remain empty.');
}

if (isset($posts_by_index[1]) && function_exists('acf_add_local_field_group')) {
    acf_add_local_field_group(
        array(
            'key'      => 'group_nat_test_unsupported_selects',
            'title'    => 'Unsupported select test fields',
            'fields'   => array(
                array(
                    'key'           => 'field_nat_test_multiple_select',
                    'label'         => 'Multiple select',
                    'name'          => 'nat_test_multiple_select',
                    'type'          => 'select',
                    'choices'       => array('alpha' => 'Alpha'),
                    'multiple'      => 1,
                    'return_format' => 'value',
                ),
                array(
                    'key'           => 'field_nat_test_array_select',
                    'label'         => 'Array select',
                    'name'          => 'nat_test_array_select',
                    'type'          => 'select',
                    'choices'       => array('alpha' => 'Alpha'),
                    'multiple'      => 0,
                    'return_format' => 'array',
                ),
            ),
            'location' => array(),
        )
    );

    foreach (
        array(
            array('key' => 'multiple_select', 'field' => 'nat_test_multiple_select', 'field_key' => 'field_nat_test_multiple_select'),
            array('key' => 'array_select', 'field' => 'nat_test_array_select', 'field_key' => 'field_nat_test_array_select'),
        ) as $unsupported_select
    ) {
        $column = Noteware\AdminTables\Model\ColumnDefinition::fromArray(
            array_merge(
                $unsupported_select,
                array(
                    'label'      => 'Unsupported select',
                    'source'     => 'acf',
                    'type'       => 'select',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => false,
                    'choices'    => array('alpha' => 'Alpha'),
                )
            )
        );
        $stored = (new Noteware\AdminTables\Adapter\AcfAdapter())->read($posts_by_index[1], $column);
        $assert(! $stored->exists, sprintf('%s must fail closed instead of returning an array value.', $unsupported_select['key']));
    }

    update_post_meta($posts_by_index[1], 'nat_test_wrong_name', 'alpha');
    $mismatched_column = Noteware\AdminTables\Model\ColumnDefinition::fromArray(
        array(
            'key'        => 'mismatched_field',
            'label'      => 'Mismatched field',
            'source'     => 'acf',
            'type'       => 'select',
            'field'      => 'nat_test_wrong_name',
            'field_key'  => 'field_nat_demo_choice',
            'sortable'   => false,
            'filterable' => false,
            'editable'   => false,
            'choices'    => array('alpha' => 'Alpha'),
        )
    );
    $mismatched_stored = (new Noteware\AdminTables\Adapter\AcfAdapter())->read($posts_by_index[1], $mismatched_column);
    $assert(! $mismatched_stored->exists, 'An ACF field key with a different field name must fail closed.');
    delete_post_meta($posts_by_index[1], 'nat_test_wrong_name');
}

if ($failures) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d integration assertion(s) failed.', count($failures)));
}

WP_CLI::success('Public hook, fixture, and adapter integration assertions passed.');
