<?php
/**
 * Query-count and render-time budget for the 10k-record fixture.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Adapter\AcfAdapter;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Adapter\MetaAdapter;
use Noteware\AdminTables\Adapter\NativeAdapter;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Screen\PostScreenController;

$published = wp_count_posts('nat_demo_record')->publish;
if ((int) $published < 10000) {
    WP_CLI::error('The performance fixture must contain at least 10,000 published records.');
}

$administrators = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    )
);
if ($administrators) {
    wp_set_current_user((int) $administrators[0]);
}

$_GET['post_type'] = 'nat_demo_record';

$measure = static function (int $rows): array {
    wp_cache_flush();

    $configuration = new Configuration();
    $adapters      = new AdapterRegistry(array(new NativeAdapter(), new MetaAdapter(), new AcfAdapter()));
    $controller    = new PostScreenController($configuration, $adapters);

    $queries_before = get_num_queries();
    $started_at     = microtime(true);
    $query          = new WP_Query(
        array(
            'post_type'              => 'nat_demo_record',
            'post_status'            => 'publish',
            'posts_per_page'         => $rows,
            'orderby'                => 'meta_value_num',
            'order'                  => 'ASC',
            'meta_key'               => 'nat_demo_number',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );
    $query_count    = get_num_queries() - $queries_before;
    $post_ids       = array_map(
        static fn (WP_Post $post): int => $post->ID,
        $query->posts
    );

    $render_queries_before = get_num_queries();
    $controller->preload($post_ids);

    $rendered_cells = 0;
    foreach ($post_ids as $post_id) {
        foreach ($configuration->columns('nat_demo_record') as $column) {
            ob_start();
            $controller->cell('nat_' . $column->key, $post_id);
            $html = (string) ob_get_clean();
            if ('' === $html || ! str_contains($html, 'class="nat-cell"')) {
                WP_CLI::error('A configured performance cell did not render.');
            }
            ++$rendered_cells;
        }
    }

    $render_queries = get_num_queries() - $render_queries_before;

    return array(
        'rows'                   => $rows,
        'returned'               => count($post_ids),
        'configured_columns'     => count($configuration->columns('nat_demo_record')),
        'rendered_cells'         => $rendered_cells,
        'query_queries'          => $query_count,
        'preload_render_queries' => $render_queries,
        'total_queries'          => get_num_queries() - $queries_before,
        'elapsed_seconds'        => round(microtime(true) - $started_at, 4),
    );
};

$small = $measure(20);
$large = $measure(100);
$query_delta  = $large['query_queries'] - $small['query_queries'];
$render_delta = $large['preload_render_queries'] - $small['preload_render_queries'];
$total_delta  = $large['total_queries'] - $small['total_queries'];

if ($small['returned'] !== 20 || $large['returned'] !== 100) {
    WP_CLI::error('The performance query did not return its bounded page size.');
}

if ($small['rendered_cells'] !== 20 * $small['configured_columns']) {
    WP_CLI::error('The 20-row render did not exercise every configured cell.');
}

if ($large['rendered_cells'] !== 100 * $large['configured_columns']) {
    WP_CLI::error('The 100-row render did not exercise every configured cell.');
}

if ($query_delta > 2) {
    WP_CLI::error(sprintf('List query count grew by %d when the page grew from 20 to 100 rows.', $query_delta));
}

if ($render_delta > 2) {
    WP_CLI::error(sprintf('Preload and cell-render query count grew by %d when the page grew from 20 to 100 rows.', $render_delta));
}

if ($total_delta > 4) {
    WP_CLI::error(sprintf('Total list-screen query count grew by %d when the page grew from 20 to 100 rows.', $total_delta));
}

$metrics = array(
    'fixture_records' => (int) $published,
    'small_page'      => $small,
    'large_page'      => $large,
    'query_delta'     => $query_delta,
    'render_delta'    => $render_delta,
    'total_delta'     => $total_delta,
);

WP_CLI::log('NAT_PERFORMANCE_METRICS=' . wp_json_encode($metrics));
WP_CLI::success('Query, preload, and cell-render performance budgets passed.');
