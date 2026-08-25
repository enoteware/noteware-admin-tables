<?php
/**
 * Query cost and fail-closed assertions in the real WordPress sandbox.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Query\QueryController;

$failures = array();
$assert   = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

add_filter(
    'noteware_admin_tables_config',
    static function (array $config): array {
        foreach ($config['nat_demo_record']['columns'] as &$column) {
            if ('nat_demo_number' === $column['key']) {
                $column['label'] = '<script>Cost probe</script>';
            }
        }
        unset($column);
        $config['nat_demo_record']['columns'][] = array(
            'key'        => 'nat_test_author',
            'label'      => 'Test author',
            'source'     => 'native',
            'type'       => 'number',
            'field'      => 'author',
            'sortable'   => false,
            'filterable' => true,
            'editable'   => false,
            'choices'    => array(),
        );
        $config['nat_demo_record']['columns'][] = array(
            'key'        => 'nat_test_status',
            'label'      => 'Test status',
            'source'     => 'native',
            'type'       => 'select',
            'field'      => 'status',
            'sortable'   => false,
            'filterable' => true,
            'editable'   => false,
            'choices'    => array(
                'draft'   => 'Draft',
                'removed' => 'Removed status',
                'publish' => 'Published',
            ),
        );
        return $config;
    },
    20
);

set_current_screen('edit-nat_demo_record');
$configuration = new Configuration();
$controller    = new QueryController($configuration);
$query         = new WP_Query();
$query->set('post_type', 'nat_demo_record');
$query->set('orderby', 'nat_nat_demo_number');
$GLOBALS['wp_the_query'] = $query;
$GLOBALS['wp_query']     = $query;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters are the behavior under test.
$_GET = array(
    'post_type'                   => 'nat_demo_record',
    'nat_filter_nat_demo_text'    => 'Text 37',
    'nat_filter_nat_demo_number'  => '111',
    'nat_filter_nat_demo_enabled' => '0',
    'nat_filter_nat_demo_choice'  => 'beta',
    'nat_filter_nat_demo_date'    => '2024-02-07',
    'nat_filter_nat_demo_note'    => 'Note 37',
);
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$controller->apply($query);
$assert(array(0) === $query->get('post__in'), 'A sixth metadata filter must fail closed with no results.');
$assert('' === $query->get('meta_key'), 'A sparse-safe metadata sort must not use the row-dropping meta_key shortcut.');
$assert('nat_sort_nat_demo_number' === $query->get('orderby'), 'A metadata sort must use its allowlisted named clause.');

$meta_query = $query->get('meta_query');
$assert(is_array($meta_query) && 6 === count($meta_query), 'One sparse sort group and only five metadata filters may reach WP_Meta_Query.');
$sort_group = is_array($meta_query) ? ($meta_query[0] ?? array()) : array();
$assert('OR' === ($sort_group['relation'] ?? null), 'Sparse metadata sorting must combine present and absent rows.');
$assert('EXISTS' === ($sort_group['nat_sort_nat_demo_number']['compare'] ?? null), 'Sparse sorting must include rows with values.');
$assert('NOT EXISTS' === ($sort_group['nat_sort_nat_demo_number_not_present']['compare'] ?? null), 'Sparse sorting must include rows without values.');
foreach (array_slice((array) $meta_query, 1) as $clause) {
    $assert('=' === ($clause['compare'] ?? null), 'Every first-milestone metadata filter must use exact comparison.');
}

ob_start();
$controller->renderErrors();
$notices = (string) ob_get_clean();
$assert(str_contains($notices, 'notice-warning'), 'A metadata operation must emit a cost warning before the query runs.');
$assert(str_contains($notices, 'No more than five metadata filters may run together.'), 'The filter cap must emit a clear error.');
$assert(str_contains($notices, '&lt;script&gt;Cost probe&lt;/script&gt;'), 'Cost-warning labels must be escaped at the HTML boundary.');
$assert(! str_contains($notices, '<script>'), 'Cost-warning labels must never render executable markup.');

// Invalid native filters must fail closed instead of silently returning an unfiltered list.
foreach (
    array(
        'nat_filter_nat_test_author' => '-1',
        'nat_filter_nat_test_status' => 'removed',
    ) as $parameter => $invalid_value
) {
    $_GET = array($parameter => $invalid_value);
    $invalid_query = new WP_Query();
    $invalid_query->set('post_type', 'nat_demo_record');
    $GLOBALS['wp_the_query'] = $invalid_query;
    $GLOBALS['wp_query']     = $invalid_query;
    $controller->apply($invalid_query);
    $assert(array(0) === $invalid_query->get('post__in'), sprintf('%s must fail closed with no results.', $parameter));
}

$_GET = array('nat_filter_nat_test_author' => '0');
$zero_author_query = new WP_Query();
$zero_author_query->set('post_type', 'nat_demo_record');
$GLOBALS['wp_the_query'] = $zero_author_query;
$GLOBALS['wp_query']     = $zero_author_query;
$controller->apply($zero_author_query);
$assert(array(0) === $zero_author_query->get('post__in'), 'Author zero must fail closed instead of broadening results.');

$administrator_ids = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$assert(1 === count($administrator_ids), 'Native-filter assertions require one administrator.');
if ($administrator_ids) {
    $_GET = array('nat_filter_nat_test_author' => (string) $administrator_ids[0]);
    $valid_author_query = new WP_Query();
    $valid_author_query->set('post_type', 'nat_demo_record');
    $GLOBALS['wp_the_query'] = $valid_author_query;
    $GLOBALS['wp_query']     = $valid_author_query;
    $controller->apply($valid_author_query);
    $assert((int) $administrator_ids[0] === $valid_author_query->get('author'), 'A positive author ID must use the exact native query variable.');
}

$_GET = array('nat_filter_nat_test_status' => 'publish');
$valid_status_query = new WP_Query();
$valid_status_query->set('post_type', 'nat_demo_record');
$GLOBALS['wp_the_query'] = $valid_status_query;
$GLOBALS['wp_query']     = $valid_status_query;
$controller->apply($valid_status_query);
$assert('publish' === $valid_status_query->get('post_status'), 'A registered status must use the exact native query variable.');

// Metadata sorting must retain posts where the configured value is absent.
$_GET       = array();
$fixture_ids = get_posts(
    array(
        'post_type'      => 'nat_demo_record',
        'post_status'    => 'publish',
        'posts_per_page' => 2,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_nat_fixture_index',
                'value'   => array(37, 38),
                'compare' => 'IN',
                'type'    => 'NUMERIC',
            ),
        ),
    )
);
$assert(2 === count($fixture_ids), 'Sparse-sort assertions require fixture records 37 and 38.');
if (2 === count($fixture_ids)) {
    $fixture_by_index = array();
    foreach ($fixture_ids as $fixture_id) {
        $fixture_by_index[(int) get_post_meta((int) $fixture_id, '_nat_fixture_index', true)] = (int) $fixture_id;
    }
    $absent_id      = (int) $fixture_ids[1];
    $absent_existed = metadata_exists('post', $absent_id, 'nat_demo_note');
    $absent_value   = get_post_meta($absent_id, 'nat_demo_note', true);
    delete_post_meta($absent_id, 'nat_demo_note');

    try {
        foreach (array('ASC', 'DESC') as $direction) {
            $sparse_query = new WP_Query();
            $GLOBALS['wp_the_query'] = $sparse_query;
            $GLOBALS['wp_query']     = $sparse_query;
            $sparse_query->query(
                array(
                    'post_type'      => 'nat_demo_record',
                    'post_status'    => 'publish',
                    'post__in'       => array_map('intval', $fixture_ids),
                    'posts_per_page' => 2,
                    'fields'         => 'ids',
                    'orderby'        => 'nat_nat_demo_note',
                    'order'          => $direction,
                    'no_found_rows'  => true,
                )
            );
            $sorted_ids = array_map('intval', $sparse_query->posts);
            sort($sorted_ids);
            $expected_ids = array_map('intval', $fixture_ids);
            sort($expected_ids);
            $assert($expected_ids === $sorted_ids, sprintf('%s metadata sorting must retain rows where the sort value is absent.', $direction));
        }

        $existing_or_query = new WP_Query();
        $GLOBALS['wp_the_query'] = $existing_or_query;
        $GLOBALS['wp_query']     = $existing_or_query;
        $existing_or_query->query(
            array(
                'post_type'      => 'nat_demo_record',
                'post_status'    => 'publish',
                'post__in'       => array_map('intval', $fixture_ids),
                'posts_per_page' => 2,
                'fields'         => 'ids',
                'orderby'        => 'nat_nat_demo_note',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array('key' => '_nat_fixture_index', 'value' => 37, 'compare' => '=', 'type' => 'NUMERIC'),
                    array('key' => '_nat_fixture_index', 'value' => 999999, 'compare' => '=', 'type' => 'NUMERIC'),
                ),
                'no_found_rows'  => true,
            )
        );
        $assert(array($fixture_by_index[37]) === array_map('intval', $existing_or_query->posts), 'Sparse sorting must not broaden a pre-existing OR metadata filter.');
    } finally {
        if ($absent_existed) {
            update_post_meta($absent_id, 'nat_demo_note', wp_slash($absent_value));
        } else {
            delete_post_meta($absent_id, 'nat_demo_note');
        }
    }
}

// Configured query parameters must be ignored outside the matching edit list screen.
foreach (array('dashboard', 'edit-post') as $screen_id) {
    set_current_screen($screen_id);
    $_GET = array('nat_filter_nat_demo_note' => 'Note 37');
    $offscreen_query = new WP_Query();
    $offscreen_query->set('post_type', 'nat_demo_record');
    $offscreen_query->set('orderby', 'nat_nat_demo_note');
    $GLOBALS['wp_the_query'] = $offscreen_query;
    $GLOBALS['wp_query']     = $offscreen_query;
    $controller->apply($offscreen_query);
    $assert('nat_nat_demo_note' === $offscreen_query->get('orderby'), sprintf('%s must not change sorting.', $screen_id));
    $assert('' === $offscreen_query->get('meta_key'), sprintf('%s must not add a metadata sort key.', $screen_id));
    $assert(empty($offscreen_query->get('meta_query')), sprintf('%s must not add metadata filters.', $screen_id));
    $assert('' === $offscreen_query->get('post__in'), sprintf('%s must not change result inclusion.', $screen_id));
    $assert('' === $offscreen_query->get('author'), sprintf('%s must not add an author filter.', $screen_id));
    $assert('' === $offscreen_query->get('post_status'), sprintf('%s must not add a status filter.', $screen_id));
}

$_GET = array();
set_current_screen();

if ($failures) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d query safety assertion(s) failed.', count($failures)));
}

WP_CLI::success('Exact filters, sparse sorting, screen scope, native fail-closed rules, warnings, escaping, and the five-filter cap passed.');
