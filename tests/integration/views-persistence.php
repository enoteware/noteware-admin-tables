<?php
/**
 * Real WordPress saved-view persistence and permission boundaries.
 * Run only in an isolated disposable WordPress database via wp eval-file.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\View\ViewRepository;

if ('1' !== getenv('NAT_ISOLATED_VIEW_TEST')) {
    throw new RuntimeException('Explicit disposable-database opt-in is required.');
}

$originalUser = get_current_user_id();
$ids = array();
$postType = 'nat_view_fixture';
register_post_type($postType, array('show_ui' => true, 'public' => false));
$repository = new ViewRepository();
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    foreach (array('administrator', 'editor', 'author', 'subscriber') as $role) {
        $id = wp_insert_user(array('user_login' => 'nat_view_' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32), 'role' => $role));
        if (is_wp_error($id)) {
            throw new RuntimeException('Could not create isolated test user.');
        }
        $ids[$role] = $id;
    }
    wp_set_current_user($ids['administrator']);
    $view = array('version' => 1, 'id' => 'v_fixture', 'name' => 'Fixture', 'post_type' => $postType, 'visibility' => 'personal', 'roles' => array(), 'columns' => array());
    $repository->save($postType, array('columns' => array()), $view);
    $repository->select($postType, 'v_fixture');
    $check(isset((new ViewRepository())->available($postType)['v_fixture']), 'Saved view must reopen.');
    wp_set_current_user($ids['editor']);
    $check(array() === $repository->available($postType), 'Personal view leaked to another user.');
    wp_set_current_user($ids['administrator']);
    $view['visibility'] = 'shared';
    $view['roles'] = array('editor');
    $repository->save($postType, array('columns' => array()), $view);
    wp_set_current_user($ids['editor']);
    $check(isset($repository->available($postType)['v_fixture']), 'Selected role cannot read shared view.');
    $denied = false;
    try {
        $repository->save($postType, array('columns' => array()), $view);
    } catch (RuntimeException) {
        $denied = true;
    }
    $check($denied, 'Editor wrote shared view.');
    wp_set_current_user($ids['author']);
    $check(array() === $repository->available($postType), 'Shared view leaked outside selected role.');
    wp_set_current_user($ids['subscriber']);
    $denied = false;
    try {
        $repository->available($postType);
    } catch (RuntimeException) {
        $denied = true;
    }
    $check($denied, 'Subscriber accessed editor view storage.');
    echo "Saved view persistence and permissions passed.\n";
} finally {
    wp_set_current_user($originalUser);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($ids as $id) {
        wp_delete_user($id);
    }
    delete_option('nat_shared_views_' . $postType);
    unregister_post_type($postType);
}
