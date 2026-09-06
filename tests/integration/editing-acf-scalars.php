<?php
/**
 * ACF scalar controller checks for a disposable WordPress database.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Adapter\AcfAdapter;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Editing\EditController;

if ('1' !== getenv('NAT_ISOLATED_EDITING_TEST') || ! function_exists('acf_add_local_field_group')) {
    throw new RuntimeException('This fixture requires disposable-database opt-in and ACF Free.');
}
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$denied = static function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException) {
        return;
    }
    $check(false, 'An invalid scalar operation was accepted.');
};
$postType = 'nat_scalar_fixture';
register_post_type($postType, array('show_ui' => true));
acf_add_local_field_group(array(
    'key' => 'group_nat_scalar_fixture',
    'title' => 'Scalar fixture',
    'fields' => array(
        array('key' => 'field_nat_scalar_number', 'name' => 'nat_scalar_number', 'label' => 'Number', 'type' => 'number', 'required' => 0, 'min' => '0', 'max' => '100'),
        array('key' => 'field_nat_scalar_boolean', 'name' => 'nat_scalar_boolean', 'label' => 'Boolean', 'type' => 'true_false', 'required' => 0),
    ),
    'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => $postType))),
));
$provider = static function (array $config) use ($postType): array {
    $config[$postType] = array('columns' => array());
    foreach (array('number', 'boolean') as $type) {
        $config[$postType]['columns'][] = array('key' => 'scalar_' . $type, 'label' => $type, 'source' => 'acf', 'type' => $type, 'field' => 'nat_scalar_' . $type, 'field_key' => 'field_nat_scalar_' . $type, 'editable' => true);
    }
    return $config;
};
add_filter('noteware_admin_tables_config', $provider, 30);
$originalUser = get_current_user_id();
$user = wp_insert_user(array('user_login' => 'nat_scalar_' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32), 'role' => 'administrator'));
if (is_wp_error($user)) {
    throw new RuntimeException('Could not create the scalar test user.');
}
$postId = 0;
try {
    wp_set_current_user($user);
    $postId = wp_insert_post(array('post_type' => $postType, 'post_status' => 'draft', 'post_title' => 'Scalar fixture'), true);
    if (is_wp_error($postId)) {
        throw new RuntimeException('Could not create the scalar fixture.');
    }
    $config = new Configuration();
    $adapter = new AcfAdapter();
    $audit = new AuditRepository();
    $audit->maybeInstall();
    $controller = new EditController($config, new AdapterRegistry(array($adapter)), $audit);
    foreach (array('number', 'boolean') as $type) {
        $column = $config->column($postType, 'scalar_' . $type);
        $check(null !== $column && $column->editable, 'The scalar public allowlist wiring is missing.');
        $before = $adapter->read($postId, $column);
        $request = array('post_id' => (string) $postId, 'column' => $column->key, 'nonce' => wp_create_nonce('nat_edit_' . $postId . '_' . $column->key), 'snapshot' => $before->hash(), 'value' => '0');
        $badNonce = $request;
        $badNonce['nonce'] = 'invalid';
        $denied(static fn () => $controller->processEdit($badNonce));
        $result = $controller->processEdit($request);
        $check('0' === get_post_meta($postId, $column->field, true), 'Zero did not persist exactly.');
        $check($column->fieldKey === get_post_meta($postId, '_' . $column->field, true), 'Reference key mismatch.');
        $check(null !== $audit->find((int) $result['auditId']), 'Audit missing.');
        $denied(static fn () => $controller->processEdit($request));
        $controller->processUndo(array('audit_id' => (string) $result['auditId'], 'nonce' => wp_create_nonce('nat_undo_' . $result['auditId'])));
        $check(! metadata_exists('post', $postId, $column->field) && ! metadata_exists('post', $postId, '_' . $column->field), 'Undo did not restore absent pair.');

        // Explicit empty storage must survive edit and undo independently of absence.
        $request['snapshot'] = $adapter->read($postId, $column)->hash();
        $request['value'] = '';
        $emptyResult = $controller->processEdit($request);
        $emptyStored = $adapter->read($postId, $column);
        $check($emptyStored->exists && '' === $emptyStored->value, 'An explicit empty scalar was not preserved.');
        $check(metadata_exists('post', $postId, $column->field), 'An explicit empty scalar became absent.');
        $request['snapshot'] = $emptyStored->hash();
        $request['value'] = '0';
        $zeroResult = $controller->processEdit($request);
        $check('0' === $adapter->read($postId, $column)->value, 'Zero was confused with stored empty.');
        if ('boolean' === $type) {
            $check(false === get_field($column->fieldKey, $postId), 'ACF did not interpret canonical stored zero as false.');
        }
        $controller->processUndo(array('audit_id' => (string) $zeroResult['auditId'], 'nonce' => wp_create_nonce('nat_undo_' . $zeroResult['auditId'])));
        $restoredEmpty = $adapter->read($postId, $column);
        $check($restoredEmpty->exists && '' === $restoredEmpty->value, 'Undo did not restore explicit empty storage.');
        $controller->processUndo(array('audit_id' => (string) $emptyResult['auditId'], 'nonce' => wp_create_nonce('nat_undo_' . $emptyResult['auditId'])));
        $check(! metadata_exists('post', $postId, $column->field) && ! metadata_exists('post', $postId, '_' . $column->field), 'The final undo did not restore the absent pair.');

    }
    $column = $config->column($postType, 'scalar_number');
    $rewrite = static fn () => '99';
    add_filter('acf/update_value/key=field_nat_scalar_number', $rewrite, 99);
    try {
        $request = array('post_id' => (string) $postId, 'column' => $column->key, 'nonce' => wp_create_nonce('nat_edit_' . $postId . '_' . $column->key), 'snapshot' => $adapter->read($postId, $column)->hash(), 'value' => '12');
        $denied(static fn () => $controller->processEdit($request));
        $check(! metadata_exists('post', $postId, $column->field), 'Failed readback did not roll back the value.');
        $check(! metadata_exists('post', $postId, '_' . $column->field), 'Failed readback did not roll back the reference.');
    } finally {
        remove_filter('acf/update_value/key=field_nat_scalar_number', $rewrite, 99);
    }
    echo "ACF scalar absent/empty/zero/false, edit, nonce, stale-state, audit, undo and rollback checks passed.\n";
} finally {
    remove_filter('noteware_admin_tables_config', $provider, 30);
    if (is_int($postId) && $postId > 0) {
        wp_delete_post($postId, true);
    }
    wp_set_current_user($originalUser);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user);
    acf_remove_local_field_group('group_nat_scalar_fixture');
    unregister_post_type($postType);
}
