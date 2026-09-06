<?php
/**
 * Authorized export job and filtered-row download checks.
 * Run only in an isolated disposable WordPress database via wp eval-file.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Adapter\MetaAdapter;
use Noteware\AdminTables\Adapter\NativeAdapter;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Export\ExportController;
use Noteware\AdminTables\Query\QueryController;

if ('1' !== getenv('NAT_ISOLATED_EXPORT_TEST')) {
    throw new RuntimeException('Explicit disposable-database opt-in is required.');
}

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$postType = 'nat_export_fixture';
register_post_type($postType, array('show_ui' => true, 'public' => false, 'map_meta_cap' => true));
$provider = static function (array $config) use ($postType): array {
    $config[$postType] = array(
        'columns' => array(
            array(
                'key'        => 'export_note',
                'label'      => 'Export note',
                'source'     => 'meta',
                'type'       => 'text',
                'field'      => 'nat_export_note',
                'sortable'   => true,
                'filterable' => true,
                'editable'   => false,
                'choices'    => array(),
            ),
        ),
    );
    return $config;
};
add_filter('noteware_admin_tables_config', $provider, 30);

$originalUser = get_current_user_id();
$ids          = array();
$posts        = array();
$directory    = sys_get_temp_dir() . '/nat-export-' . wp_generate_uuid4();
try {
    foreach (array('administrator', 'subscriber') as $role) {
        $id = wp_insert_user(
            array(
                'user_login' => 'nat_export_' . wp_generate_uuid4(),
                'user_pass'  => wp_generate_password(32),
                'role'       => $role,
            )
        );
        if (is_wp_error($id)) {
            throw new RuntimeException('Could not create isolated export user.');
        }
        $ids[$role] = $id;
    }
    wp_set_current_user($ids['administrator']);
    foreach (array('keep-one', 'keep-two', 'skip-me') as $note) {
        $postId = wp_insert_post(
            array(
                'post_type'   => $postType,
                'post_status' => 'publish',
                'post_title'  => $note,
            ),
            true
        );
        if (is_wp_error($postId)) {
            throw new RuntimeException('Could not create export fixture posts.');
        }
        update_post_meta($postId, 'nat_export_note', $note);
        $posts[$note] = $postId;
    }

    $configuration = new Configuration();
    $adapters      = new AdapterRegistry(array(new NativeAdapter(), new MetaAdapter()));
    $query         = new QueryController($configuration, $adapters);
    $controller    = new ExportController($configuration, $adapters, $query);

    $denied = false;
    wp_set_current_user($ids['subscriber']);
    try {
        $controller->run(
            $ids['subscriber'],
            array(
                'post_type' => $postType,
                'format'    => 'csv',
            ),
            $directory
        );
    } catch (RuntimeException) {
        $denied = true;
    }
    $check($denied, 'A subscriber exported a screen they cannot edit.');

    wp_set_current_user($ids['administrator']);
    $filtered = $controller->run(
        $ids['administrator'],
        array(
            'post_type'                 => $postType,
            'format'                    => 'csv',
            'nat_filter_export_note'    => 'keep-one',
            'orderby'                   => 'title',
            'adapter'                   => 'native',
        ),
        $directory
    );
    $csv = (string) file_get_contents($filtered['path']);
    $check(str_contains($csv, 'keep-one'), 'Filtered export omitted the matching row.');
    $check(! str_contains($csv, 'keep-two'), 'Filtered export included a non-matching row.');
    $check(! str_contains($csv, 'skip-me'), 'Filtered export included an excluded row.');
    $check(! str_contains($csv, 'native'), 'Browser-supplied adapter leaked into the file.');

    $selected = $controller->run(
        $ids['administrator'],
        array(
            'post_type' => $postType,
            'format'    => 'json',
            'post'      => array((string) $posts['keep-two'], (string) $posts['skip-me']),
        ),
        $directory
    );
    $payload = json_decode((string) file_get_contents($selected['path']), true);
    $check(is_array($payload) && isset($payload['rows']) && 2 === count($payload['rows']), 'Selected-row export did not keep the requested records.');

    $invalid = false;
    try {
        $controller->run(
            $ids['administrator'],
            array(
                'post_type' => $postType,
                'format'    => 'sql',
            ),
            $directory
        );
    } catch (InvalidArgumentException) {
        $invalid = true;
    }
    $check($invalid, 'An unknown export format was accepted.');

    echo "Authorized export jobs, filters, selected rows and format denial passed.\n";
} finally {
    wp_set_current_user($originalUser);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($posts as $postId) {
        wp_delete_post($postId, true);
    }
    foreach ($ids as $id) {
        wp_delete_user($id);
    }
    remove_filter('noteware_admin_tables_config', $provider, 30);
    unregister_post_type($postType);
    if (is_dir($directory)) {
        foreach (scandir($directory) as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            unlink($directory . '/' . $name);
        }
        rmdir($directory);
    }
}
