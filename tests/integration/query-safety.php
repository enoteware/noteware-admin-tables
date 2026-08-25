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
$assert('nat_demo_number' === $query->get('meta_key'), 'A configured metadata sort must use its trusted field key.');
$assert('meta_value_num' === $query->get('orderby'), 'A numeric metadata sort must use the numeric WordPress order rule.');

$meta_query = $query->get('meta_query');
$assert(is_array($meta_query) && 5 === count($meta_query), 'Only five metadata filters may reach WP_Meta_Query.');
foreach ((array) $meta_query as $clause) {
    $assert('=' === ($clause['compare'] ?? null), 'Every first-milestone metadata filter must use exact comparison.');
}

ob_start();
$controller->renderErrors();
$notices = (string) ob_get_clean();
$assert(str_contains($notices, 'notice-warning'), 'A metadata operation must emit a cost warning before the query runs.');
$assert(str_contains($notices, 'No more than five metadata filters may run together.'), 'The filter cap must emit a clear error.');
$assert(str_contains($notices, '&lt;script&gt;Cost probe&lt;/script&gt;'), 'Cost-warning labels must be escaped at the HTML boundary.');
$assert(! str_contains($notices, '<script>'), 'Cost-warning labels must never render executable markup.');

$_GET = array();
set_current_screen();

if ($failures) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d query safety assertion(s) failed.', count($failures)));
}

WP_CLI::success('Exact filters, metadata cost warnings, escaping, and the five-filter cap passed.');
