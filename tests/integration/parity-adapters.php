<?php
/**
 * ACF link, taxonomy, native, ordering, filtering, and bulk assertions
 * inside the real WordPress sandbox.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Adapter\AcfAdapter;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Adapter\MetaAdapter;
use Noteware\AdminTables\Adapter\NativeAdapter;
use Noteware\AdminTables\Adapter\TaxonomyAdapter;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Editing\BulkEditController;
use Noteware\AdminTables\Editing\EditController;
use Noteware\AdminTables\Query\QueryController;
use Noteware\AdminTables\Screen\ColumnRenderer;
use Noteware\AdminTables\Screen\PostScreenController;

$failures = array();
$assert   = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$expect_failure = static function (callable $operation, string $message) use (&$failures): ?Throwable {
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    $failures[] = $message;
    return null;
};

$fixture_post = static function (int $index): int {
    $ids = get_posts(
        array(
            'post_type'      => 'nat_demo_record',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_nat_fixture_index',
            'meta_value'     => (string) $index,
        )
    );
    return (int) ($ids[0] ?? 0);
};

$link_post   = $fixture_post(31);
$term_post   = $fixture_post(32);
$native_post = $fixture_post(33);
$bulk_posts  = array($fixture_post(34), $fixture_post(35), $fixture_post(36));

if (0 === $link_post || 0 === $term_post || 0 === $native_post || in_array(0, $bulk_posts, true)) {
    WP_CLI::error('The generic parity fixtures could not be resolved.');
}

$administrators = get_users(array('role' => 'administrator', 'number' => 1));
$administrator  = $administrators[0] ?? null;
if (! $administrator instanceof WP_User) {
    WP_CLI::error('An administrator is required for parity assertions.');
}

$ensure_user = static function (string $login, string $role): WP_User {
    $user = get_user_by('login', $login);
    if (! $user) {
        $user_id = wp_insert_user(
            array(
                'user_login' => $login,
                'user_pass'  => wp_generate_password(32),
                'user_email' => $login . '@example.test',
                'role'       => $role,
            )
        );
        if (is_wp_error($user_id)) {
            WP_CLI::error($user_id->get_error_message());
        }
        $user = get_user_by('id', $user_id);
    }
    if (! $user instanceof WP_User) {
        WP_CLI::error('A generic fixture user could not be prepared.');
    }
    return $user;
};

$subscriber = $ensure_user('nat_fixture_subscriber', 'subscriber');
$subscriber->set_role('subscriber');

add_filter(
    'noteware_admin_tables_config',
    static function (array $config): array {
        $config['nat_demo_record']['columns'][] = array(
            'key'        => 'nat_test_title',
            'label'      => 'Test title',
            'source'     => 'native',
            'type'       => 'text',
            'field'      => 'title',
            'sortable'   => true,
            'filterable' => false,
            'editable'   => true,
            'choices'    => array(),
        );
        return $config;
    },
    20
);

$configuration = new Configuration();
$adapters      = new AdapterRegistry(array(new NativeAdapter(), new MetaAdapter(), new AcfAdapter(), new TaxonomyAdapter()));
$audit         = new AuditRepository();
$edits         = new EditController($configuration, $adapters, $audit);
$bulk          = new BulkEditController($configuration, $adapters, $audit);
$renderer      = new ColumnRenderer();

$link_column   = $configuration->column('nat_demo_record', 'nat_demo_link');
$topic_column  = $configuration->column('nat_demo_record', 'nat_demo_topic');
$thumb_column  = $configuration->column('nat_demo_record', 'nat_demo_thumb');
$slug_column   = $configuration->column('nat_demo_record', 'nat_demo_slug');
$title_column  = $configuration->column('nat_demo_record', 'nat_test_title');
$choice_column = $configuration->column('nat_demo_record', 'nat_demo_choice');
$perma_column  = $configuration->column('nat_demo_record', 'nat_demo_permalink');

if (
    null === $link_column || null === $topic_column || null === $thumb_column
    || null === $slug_column || null === $title_column || null === $choice_column
    || null === $perma_column
) {
    WP_CLI::error('The generic parity columns are not configured.');
}

global $wpdb;
$audit_table = $wpdb->prefix . 'nat_edit_audit';

$raw_rows = static function (int $post_id, string $name) use ($wpdb): array {
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN (%s, %s) ORDER BY meta_key ASC',
            $wpdb->postmeta,
            $post_id,
            $name,
            '_' . $name
        ),
        ARRAY_A
    );
    $map = array();
    foreach ($rows as $row) {
        $map[(string) $row['meta_key']] = (string) $row['meta_value'];
    }
    return $map;
};

$audit_count = static function (int $post_id) use ($wpdb, $audit_table): int {
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id = %d', $audit_table, $post_id));
};

$edit_request = static function (int $post_id, string $column_key, mixed $value, string $snapshot, bool $remove = false): array {
    return array(
        'post_id'  => (string) $post_id,
        'column'   => $column_key,
        'nonce'    => wp_create_nonce('nat_edit_' . $post_id . '_' . $column_key),
        'value'    => $value,
        'remove'   => $remove ? '1' : '',
        'snapshot' => $snapshot,
    );
};

$acf_adapter      = $adapters->get('acf');
$taxonomy_adapter = $adapters->get('taxonomy');
$native_adapter   = $adapters->get('native');

wp_set_current_user($administrator->ID);

// --- ACF link display -------------------------------------------------------

$link_before = $acf_adapter->read($link_post, $link_column);
$link_markup = $renderer->value($link_column, $link_before);
$assert(str_contains($link_markup, 'rel="noopener nofollow external"'), 'A stored link must render as a guarded external link.');
$assert(str_contains($link_markup, 'target="_blank"'), 'A stored link must open in a new tab with a screen-reader hint.');

$absent_link = $acf_adapter->read($fixture_post(26), $link_column);
$assert(! $absent_link->exists, 'A deleted ACF link must read as absent.');
$assert(str_contains($renderer->value($link_column, $absent_link), 'No link'), 'An absent link must show the configured empty label.');

$empty_link = $acf_adapter->read($fixture_post(28), $link_column);
$assert($empty_link->exists && '' === $empty_link->value, 'An explicitly empty ACF link must stay a distinct empty state.');
$assert(str_contains($renderer->value($link_column, $empty_link), 'Empty'), 'An empty link must render as empty rather than absent.');

// --- ACF link editing, reference metadata, and undo --------------------------

$link_original_rows  = $raw_rows($link_post, 'nat_demo_link');
$link_original_state = $acf_adapter->read($link_post, $link_column);
$link_audit_before   = $audit_count($link_post);

$link_result = $edits->processEdit(
    $edit_request($link_post, 'nat_demo_link', 'https://example.test/apply/parity?ref=1', $link_original_state->hash())
);
$link_rows_after = $raw_rows($link_post, 'nat_demo_link');
$assert('https://example.test/apply/parity?ref=1' === ($link_rows_after['nat_demo_link'] ?? null), 'An ACF link edit must store the exact submitted link.');
$assert('field_nat_demo_link' === ($link_rows_after['_nat_demo_link'] ?? null), 'An ACF link edit must keep the field reference row correct.');
$assert($link_audit_before + 1 === $audit_count($link_post), 'An ACF link edit must append exactly one audit row.');

$link_undo = $edits->processUndo(
    array(
        'audit_id' => (string) $link_result['auditId'],
        'nonce'    => (string) $link_result['undoNonce'],
    )
);
$assert($link_original_rows === $raw_rows($link_post, 'nat_demo_link'), 'Undo must restore the exact ACF link storage, including the reference row.');
$assert($acf_adapter->read($link_post, $link_column)->hash() === ($link_undo['snapshot'] ?? null), 'Undo must return the restored snapshot.');

// Removal must clear the value row and the reference row together.
$remove_state = $acf_adapter->read($link_post, $link_column);
$remove_result = $edits->processEdit(
    $edit_request($link_post, 'nat_demo_link', '', $remove_state->hash(), true)
);
$assert(array() === $raw_rows($link_post, 'nat_demo_link'), 'Removing an ACF link must delete the value and reference rows.');
$assert(false === ($remove_result['exists'] ?? null), 'Removing an ACF link must report an absent state.');

$edits->processUndo(
    array(
        'audit_id' => (string) $remove_result['auditId'],
        'nonce'    => (string) $remove_result['undoNonce'],
    )
);
$assert($link_original_rows === $raw_rows($link_post, 'nat_demo_link'), 'Undoing a removal must restore both ACF rows exactly.');

// An explicit empty link must be storable and distinct from removal.
$empty_state  = $acf_adapter->read($link_post, $link_column);
$empty_result = $edits->processEdit($edit_request($link_post, 'nat_demo_link', '', $empty_state->hash()));
$empty_rows   = $raw_rows($link_post, 'nat_demo_link');
$assert('' === ($empty_rows['nat_demo_link'] ?? null), 'An empty ACF link must be stored as an empty value.');
$assert('field_nat_demo_link' === ($empty_rows['_nat_demo_link'] ?? null), 'An empty ACF link must keep its reference row.');
$assert(true === ($empty_result['exists'] ?? null), 'An empty ACF link must still report as stored.');
$edits->processUndo(
    array(
        'audit_id' => (string) $empty_result['auditId'],
        'nonce'    => (string) $empty_result['undoNonce'],
    )
);

// Unsafe and malformed links must fail closed without writing.
$unsafe_links = array(
    'javascript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'ftp://example.test/file',
    '/relative/path',
    'https://example.test/space here',
    "https://example.test/\r\nHeader: 1",
    'https://' . str_repeat('a', 2100) . '.test/',
);
foreach ($unsafe_links as $unsafe_link) {
    $before_rows  = $raw_rows($link_post, 'nat_demo_link');
    $before_audit = $audit_count($link_post);
    $unsafe_state = $acf_adapter->read($link_post, $link_column);
    $expect_failure(
        static fn (): array => $edits->processEdit($edit_request($link_post, 'nat_demo_link', $unsafe_link, $unsafe_state->hash())),
        'An unsafe or malformed link must be rejected.'
    );
    $assert($before_rows === $raw_rows($link_post, 'nat_demo_link'), 'A rejected link must not change storage.');
    $assert($before_audit === $audit_count($link_post), 'A rejected link must not append an audit row.');
}

// A stale snapshot must stop the write.
$stale_state = $acf_adapter->read($link_post, $link_column);
update_field('field_nat_demo_link', wp_slash('https://example.test/moved'), $link_post);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($link_post, 'nat_demo_link', 'https://example.test/late', $stale_state->hash())),
    'A stale link snapshot must be rejected.'
);
$assert('https://example.test/moved' === get_post_meta($link_post, 'nat_demo_link', true), 'A stale link snapshot must leave the newer value in place.');
update_field('field_nat_demo_link', wp_slash((string) ($link_original_rows['nat_demo_link'] ?? '')), $link_post);

// A subscriber must not be able to write an ACF link.
wp_set_current_user($subscriber->ID);
$denied_state = $acf_adapter->read($link_post, $link_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($link_post, 'nat_demo_link', 'https://example.test/denied', $denied_state->hash())),
    'A subscriber must not write an ACF link.'
);
$assert('https://example.test/denied' !== get_post_meta($link_post, 'nat_demo_link', true), 'A denied link edit must not write.');
wp_set_current_user($administrator->ID);

// --- ACF select writes stay inside the live and configured choices ----------

$choice_state = $acf_adapter->read($link_post, $choice_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($link_post, 'nat_demo_choice', 'retired', $choice_state->hash())),
    'An ACF select write outside the live choices must be rejected.'
);
$choice_result = $edits->processEdit($edit_request($link_post, 'nat_demo_choice', 'gamma', $choice_state->hash()));
$assert('gamma' === get_post_meta($link_post, 'nat_demo_choice', true), 'An allowed ACF select value must be written.');
$edits->processUndo(
    array(
        'audit_id' => (string) $choice_result['auditId'],
        'nonce'    => (string) $choice_result['undoNonce'],
    )
);

// --- Taxonomy display, editing, and full restore ----------------------------

wp_set_object_terms($term_post, array('topic-1', 'topic-2'), 'nat_demo_topic', false);
clean_object_term_cache($term_post, 'nat_demo_topic');
$term_before = $taxonomy_adapter->read($term_post, $topic_column);
$assert(array('topic-1', 'topic-2') === $term_before->value, 'A taxonomy column must read a sorted slug list.');
$assert(str_contains($renderer->value($topic_column, $term_before), 'Topic one'), 'A taxonomy column must display term names.');

$term_result = $edits->processEdit($edit_request($term_post, 'nat_demo_topic', 'topic-3', $term_before->hash()));
$assert(array('topic-3') === $taxonomy_adapter->read($term_post, $topic_column)->value, 'A taxonomy edit must replace the term set.');
$edits->processUndo(
    array(
        'audit_id' => (string) $term_result['auditId'],
        'nonce'    => (string) $term_result['undoNonce'],
    )
);
$assert(array('topic-1', 'topic-2') === $taxonomy_adapter->read($term_post, $topic_column)->value, 'Undo must restore every previously assigned term.');

$term_state = $taxonomy_adapter->read($term_post, $topic_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($term_post, 'nat_demo_topic', 'topic-missing', $term_state->hash())),
    'An unknown term slug must be rejected.'
);

$clear_result = $edits->processEdit($edit_request($term_post, 'nat_demo_topic', '', $term_state->hash(), true));
$assert(! $taxonomy_adapter->read($term_post, $topic_column)->exists, 'Clearing a taxonomy column must remove every term.');
$edits->processUndo(
    array(
        'audit_id' => (string) $clear_result['auditId'],
        'nonce'    => (string) $clear_result['undoNonce'],
    )
);
$assert(array('topic-1', 'topic-2') === $taxonomy_adapter->read($term_post, $topic_column)->value, 'Undoing a clear must restore every term.');

// --- Native title, slug, featured image, and permalink ----------------------

$title_state  = $native_adapter->read($native_post, $title_column);
$title_result = $edits->processEdit($edit_request($native_post, 'nat_test_title', 'Parity title', $title_state->hash()));
$assert('Parity title' === get_post_field('post_title', $native_post), 'A native title edit must write through WordPress.');
$edits->processUndo(
    array(
        'audit_id' => (string) $title_result['auditId'],
        'nonce'    => (string) $title_result['undoNonce'],
    )
);
$assert($title_state->value === get_post_field('post_title', $native_post), 'Undo must restore the original title.');

$title_state_again = $native_adapter->read($native_post, $title_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_test_title', '   ', $title_state_again->hash())),
    'A blank native title must be rejected.'
);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_test_title', '', $title_state_again->hash(), true)),
    'A native title must not be removable.'
);

$slug_state = $native_adapter->read($native_post, $slug_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_demo_slug', 'Not A Slug', $slug_state->hash())),
    'A malformed slug must be rejected.'
);
$slug_result = $edits->processEdit($edit_request($native_post, 'nat_demo_slug', 'parity-slug-test', $slug_state->hash()));
$assert('parity-slug-test' === get_post_field('post_name', $native_post), 'A native slug edit must write through WordPress.');
$edits->processUndo(
    array(
        'audit_id' => (string) $slug_result['auditId'],
        'nonce'    => (string) $slug_result['undoNonce'],
    )
);
$assert($slug_state->value === get_post_field('post_name', $native_post), 'Undo must restore the original slug.');

$fixture_images = get_posts(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_nat_fixture_image',
    )
);
$fixture_image_id = (int) ($fixture_images[0] ?? 0);
$assert($fixture_image_id > 0, 'The generic fixture image is required for featured-image assertions.');

$thumb_state = $native_adapter->read($native_post, $thumb_column);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_demo_thumb', '999999999', $thumb_state->hash())),
    'A missing media item must be rejected as a featured image.'
);
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_demo_thumb', (string) $native_post, $thumb_state->hash())),
    'A record that is not an attachment must be rejected as a featured image.'
);
$thumb_result = $edits->processEdit($edit_request($native_post, 'nat_demo_thumb', (string) $fixture_image_id, $thumb_state->hash()));
$assert($fixture_image_id === (int) get_post_thumbnail_id($native_post), 'A featured-image edit must set the thumbnail.');
$thumb_after  = $native_adapter->read($native_post, $thumb_column);
$assert(str_contains($renderer->value($thumb_column, $thumb_after), 'nat-thumbnail'), 'A featured image must render as a bounded thumbnail.');
$edits->processUndo(
    array(
        'audit_id' => (string) $thumb_result['auditId'],
        'nonce'    => (string) $thumb_result['undoNonce'],
    )
);
$assert(($thumb_state->exists ? (int) $thumb_state->value : 0) === (int) get_post_thumbnail_id($native_post), 'Undo must restore the original featured image state.');

$permalink_state = $native_adapter->read($native_post, $perma_column);
$assert($permalink_state->exists && is_string($permalink_state->value), 'A permalink column must resolve a link.');
$assert(str_contains($renderer->value($perma_column, $permalink_state), '<a class="nat-link"'), 'A permalink must render as a link.');
$expect_failure(
    static fn (): array => $edits->processEdit($edit_request($native_post, 'nat_demo_permalink', 'https://example.test/', $permalink_state->hash())),
    'A permalink column must stay read only.'
);

WP_CLI::line('NAT_PARITY_STAGE=adapters');

// --- Column order, removal, replacement, and widths --------------------------

$_GET['post_type'] = 'nat_demo_record';
$screen_controller = new PostScreenController($configuration, $adapters);
$resolved_columns  = $screen_controller->columns(
    array(
        'cb'       => '<input type="checkbox">',
        'title'    => 'Title',
        'author'   => 'Author',
        'comments' => 'Comments',
        'date'     => 'Date',
    )
);
$resolved_ids = array_keys($resolved_columns);

$assert(! in_array('author', $resolved_ids, true), 'A removed built-in column must not render.');
$assert(! in_array('date', $resolved_ids, true), 'A replaced built-in column must not render alongside its replacement.');
$assert(in_array('nat_nat_demo_published', $resolved_ids, true), 'A replacement column must render in place of its built-in column.');
$assert(array('cb', 'title', 'nat_nat_demo_link', 'nat_nat_demo_text', 'nat_nat_demo_choice', 'nat_nat_demo_topic') === array_slice($resolved_ids, 0, 6), 'The configured column order must lead the resolved columns.');
$assert(in_array('comments', $resolved_ids, true), 'An unordered built-in column must still render after the configured order.');
$assert(count($resolved_ids) === count(array_unique($resolved_ids)), 'Resolved columns must not repeat.');

ob_start();
set_current_screen('edit-nat_demo_record');
$screen_controller->renderColumnWidths();
$width_markup = (string) ob_get_clean();
$assert(str_contains($width_markup, '.column-nat_nat_demo_link{width:18%;}'), 'Configured widths must render as scoped CSS.');
$assert(str_contains($width_markup, '.column-nat_nat_demo_thumb{width:90px;}'), 'Pixel widths must render as scoped CSS.');
$assert(str_contains($width_markup, '.wp-list-table{min-width:1800px;}'), 'A configured screen minimum width must render as scoped CSS.');
$assert(str_contains($width_markup, '#posts-filter{overflow-x:auto;max-width:100%;}'), 'A screen with a minimum width must scroll sideways inside the page instead of widening it.');

// --- Filter operators --------------------------------------------------------

$run_filter = static function (array $request) use ($configuration, $adapters): WP_Query {
    $previous_get   = $_GET;
    $previous_query = $GLOBALS['wp_the_query'] ?? null;
    $previous_wp    = $GLOBALS['wp_query'] ?? null;

    set_current_screen('edit-nat_demo_record');
    $query = new WP_Query();
    $query->set('post_type', 'nat_demo_record');
    $GLOBALS['wp_the_query'] = $query;
    $GLOBALS['wp_query']     = $query;

    $_GET = array_merge(array('post_type' => 'nat_demo_record'), $request);
    (new QueryController($configuration, $adapters))->apply($query);

    $_GET                    = $previous_get;
    $GLOBALS['wp_the_query'] = $previous_query;
    $GLOBALS['wp_query']     = $previous_wp;
    return $query;
};

$empty_query = $run_filter(array('nat_op_nat_demo_link' => 'empty'));
$empty_meta  = $empty_query->get('meta_query');
$assert(is_array($empty_meta), 'An empty-link filter must build a metadata plan.');
$assert(str_contains((string) wp_json_encode($empty_meta), 'NOT EXISTS'), 'An empty-link filter must match absent values.');
$assert(str_contains((string) wp_json_encode($empty_meta), '"value":""'), 'An empty-link filter must also match stored empty values.');

$present_query = $run_filter(array('nat_op_nat_demo_link' => 'not_empty'));
$present_meta  = $present_query->get('meta_query');
$assert(str_contains((string) wp_json_encode($present_meta), '"compare":"EXISTS"'), 'A has-a-value filter must require the row to exist.');
$assert(str_contains((string) wp_json_encode($present_meta), '"compare":"!="'), 'A has-a-value filter must exclude stored empty values.');

$term_query = $run_filter(array('nat_filter_nat_demo_topic' => 'topic-1'));
$term_plan  = (string) wp_json_encode($term_query->get('tax_query'));
$assert(str_contains($term_plan, '"taxonomy":"nat_demo_topic"'), 'A taxonomy filter must build a taxonomy plan.');
$assert(str_contains($term_plan, '"field":"slug"'), 'A taxonomy filter must match on the exact slug.');

$term_absent = $run_filter(array('nat_op_nat_demo_topic' => 'empty'));
$assert(str_contains((string) wp_json_encode($term_absent->get('tax_query')), 'NOT EXISTS'), 'An empty taxonomy filter must match unassigned records.');

$bad_term = $run_filter(array('nat_filter_nat_demo_topic' => 'topic-missing'));
$assert(array(0) === $bad_term->get('post__in'), 'An unknown term filter must fail closed.');

$bad_operator = $run_filter(array('nat_op_nat_demo_link' => 'like'));
$assert(array(0) === $bad_operator->get('post__in'), 'An unsupported filter operator must fail closed.');

$unenabled_operator = $run_filter(array('nat_op_nat_demo_enabled' => 'empty'));
$assert(array(0) === $unenabled_operator->get('post__in'), 'An operator a column did not enable must fail closed.');

$default_operator = $run_filter(array('nat_filter_nat_demo_enabled' => '1'));
$assert(str_contains((string) wp_json_encode($default_operator->get('meta_query')), '"compare":"="'), 'A column with only the exact operator keeps working without an operator parameter.');

ob_start();
$screen_controller->cell('nat_nat_demo_link', $link_post);
$cell_markup = (string) ob_get_clean();
$assert(str_contains($cell_markup, 'data-field="value"'), 'An inline editor must address its fields with data attributes.');
$assert(! str_contains($cell_markup, 'name="'), 'Inline editor fields must not be submitted with the WordPress list filter form.');

ob_start();
$screen_controller->renderBulkEditor('top');
$bulk_markup = (string) ob_get_clean();
$assert(str_contains($bulk_markup, 'id="nat-bulk-panel"'), 'The bulk panel must render for a bulk-editable screen.');
$assert(! str_contains($bulk_markup, 'name="'), 'Bulk panel fields must not be submitted with the WordPress list filter form.');

ob_start();
$screen_controller->renderBulkEditor('bottom');
$assert('' === (string) ob_get_clean(), 'The bulk panel must render once per screen.');

WP_CLI::line('NAT_PARITY_STAGE=screen');

// --- Bulk editing -------------------------------------------------------------

$bulk_request = static function (array $post_ids, string $column_key, string $value, bool $remove = false): array {
    return array(
        'post_type' => 'nat_demo_record',
        'nonce'     => wp_create_nonce('nat_bulk_edit_nat_demo_record'),
        'column'    => $column_key,
        'value'     => $value,
        'remove'    => $remove ? '1' : '',
        'post_ids'  => array_map('strval', $post_ids),
    );
};

$bulk_audit_before = array_sum(array_map($audit_count, $bulk_posts));
$bulk_result = $bulk->processBulkEdit($bulk_request($bulk_posts, 'nat_demo_link', 'https://example.test/bulk'));
$assert(count($bulk_result['changed']) === count($bulk_posts), 'A permitted bulk edit must change every selected record.');
$assert(array() === $bulk_result['failed'], 'A permitted bulk edit must report no failures.');
foreach ($bulk_posts as $bulk_post) {
    $assert('https://example.test/bulk' === get_post_meta($bulk_post, 'nat_demo_link', true), 'Every bulk-edited record must store the new link.');
    $assert('field_nat_demo_link' === get_post_meta($bulk_post, '_nat_demo_link', true), 'Every bulk-edited record must keep its ACF reference row.');
}
$assert($bulk_audit_before + count($bulk_posts) === array_sum(array_map($audit_count, $bulk_posts)), 'A bulk edit must append one audit row per record.');

foreach ($bulk_result['changed'] as $change) {
    $edits->processUndo(
        array(
            'audit_id' => (string) $change['auditId'],
            'nonce'    => (string) $change['undoNonce'],
        )
    );
}

// A denied record must fail on its own without blocking the rest.
$denied_post = $fixture_post(38);
$assert($denied_post > 0, 'A denial fixture record is required.');
$denied_before = get_post_meta($denied_post, 'nat_demo_link', true);
wp_update_post(array('ID' => $denied_post, 'post_status' => 'private'));

$author = $ensure_user('nat_fixture_author', 'author');
$author->set_role('author');
wp_set_current_user($author->ID);
$owned_post = $bulk_posts[0];
wp_update_post(array('ID' => $owned_post, 'post_author' => $author->ID));

$mixed_result = $bulk->processBulkEdit($bulk_request(array($owned_post, $denied_post), 'nat_demo_link', 'https://example.test/mixed'));
$assert(1 === count($mixed_result['failed']), 'A bulk edit must report exactly the records it could not change.');
$assert($denied_post === ($mixed_result['failed'][0]['postId'] ?? 0), 'A bulk failure must name the exact record.');
$assert($denied_before === get_post_meta($denied_post, 'nat_demo_link', true), 'A denied bulk record must keep its stored value.');
foreach ($mixed_result['changed'] as $change) {
    $edits->processUndo(
        array(
            'audit_id' => (string) $change['auditId'],
            'nonce'    => (string) $change['undoNonce'],
        )
    );
}

wp_set_current_user($administrator->ID);
wp_update_post(array('ID' => $denied_post, 'post_status' => 'publish'));
wp_update_post(array('ID' => $owned_post, 'post_author' => $administrator->ID));

// Bulk boundaries must fail closed.
$expect_failure(
    static fn (): array => $bulk->processBulkEdit(
        array_merge($bulk_request($bulk_posts, 'nat_demo_link', 'https://example.test/nonce'), array('nonce' => 'invalid'))
    ),
    'A bad bulk nonce must be rejected.'
);
$expect_failure(
    static fn (): array => $bulk->processBulkEdit($bulk_request($bulk_posts, 'nat_demo_permalink', 'https://example.test/')),
    'A read-only column must not be bulk edited.'
);
$expect_failure(
    static fn (): array => $bulk->processBulkEdit($bulk_request($bulk_posts, 'nat_demo_thumb', '1')),
    'An image column must not be bulk edited.'
);
$expect_failure(
    static fn (): array => $bulk->processBulkEdit($bulk_request(array(), 'nat_demo_link', 'https://example.test/')),
    'An empty bulk selection must be rejected.'
);
$expect_failure(
    static fn (): array => $bulk->processBulkEdit($bulk_request(range(1, BulkEditController::MAX_OBJECTS + 1), 'nat_demo_link', 'https://example.test/')),
    'An oversized bulk selection must be rejected.'
);
$expect_failure(
    static fn (): array => $bulk->processBulkEdit($bulk_request($bulk_posts, 'nat_demo_link', 'javascript:alert(1)')),
    'An unsafe bulk link must be rejected before any record is touched.'
);
foreach ($bulk_posts as $bulk_post) {
    $assert('javascript:alert(1)' !== get_post_meta($bulk_post, 'nat_demo_link', true), 'A rejected bulk value must never be written.');
}

WP_CLI::line('NAT_PARITY_STAGE=bulk');

if ($failures) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d parity assertions failed.', count($failures)));
}

WP_CLI::success('Link, taxonomy, native, ordering, filter operator, and bulk parity assertions passed.');
