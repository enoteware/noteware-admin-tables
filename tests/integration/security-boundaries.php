<?php
/**
 * Security and permission assertions inside the real WordPress sandbox.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Adapter\AcfAdapter;
use Noteware\AdminTables\Adapter\TaxonomyAdapter;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Adapter\MetaAdapter;
use Noteware\AdminTables\Adapter\NativeAdapter;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Editing\EditController;

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

$post_ids = get_posts(
    array(
        'post_type'      => 'nat_demo_record',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_nat_fixture_index',
        'meta_value'     => '37',
    )
);
$assert(! empty($post_ids), 'Fixture record 37 is required for security assertions.');
$post_id = (int) ($post_ids[0] ?? 0);

$subscriber = get_user_by('login', 'nat_fixture_subscriber');
if (! $subscriber) {
    $subscriber_id = wp_create_user('nat_fixture_subscriber', wp_generate_password(32), 'subscriber@example.test');
    if (is_wp_error($subscriber_id)) {
        WP_CLI::error($subscriber_id->get_error_message());
    }
    $subscriber = get_user_by('id', $subscriber_id);
}

$administrators = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
    )
);
$administrator = $administrators[0] ?? null;

if (! $administrator instanceof WP_User || ! $subscriber instanceof WP_User || 0 === $post_id) {
    WP_CLI::error('The generic fixture users or post could not be prepared.');
}

add_filter(
    'noteware_admin_tables_config',
    static function (array $config): array {
        $config['nat_demo_record']['columns'] = array_merge(
            $config['nat_demo_record']['columns'],
            array(
                array(
                    'key'        => 'nat_test_number',
                    'label'      => 'Test number',
                    'source'     => 'meta',
                    'type'       => 'number',
                    'field'      => 'nat_test_number',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => true,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_test_boolean',
                    'label'      => 'Test boolean',
                    'source'     => 'meta',
                    'type'       => 'boolean',
                    'field'      => 'nat_test_boolean',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => true,
                    'choices'    => array('0' => 'No', '1' => 'Yes'),
                ),
                array(
                    'key'        => 'nat_test_select',
                    'label'      => 'Test select',
                    'source'     => 'meta',
                    'type'       => 'select',
                    'field'      => 'nat_test_select',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => true,
                    'choices'    => array('alpha' => 'Alpha', 'beta' => 'Beta'),
                ),
                array(
                    'key'        => 'nat_test_date',
                    'label'      => 'Test date',
                    'source'     => 'meta',
                    'type'       => 'date',
                    'field'      => 'nat_test_date',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => true,
                    'choices'    => array(),
                ),
            )
        );
        return $config;
    },
    20
);

$configuration = new Configuration();
$adapters      = new AdapterRegistry(array(new NativeAdapter(), new MetaAdapter(), new AcfAdapter(), new TaxonomyAdapter()));
$audit         = new AuditRepository();
$controller    = new EditController($configuration, $adapters, $audit);
$note_column   = $configuration->column('nat_demo_record', 'nat_demo_note');
$id_column     = $configuration->column('nat_demo_record', 'nat_demo_id');

$assert(null !== $note_column && $note_column->editable, 'The generic metadata note must be editable.');
$assert(null !== $id_column && ! $id_column->editable, 'The native ID must remain read-only.');

if (null === $note_column || null === $id_column) {
    WP_CLI::error('Required generic fixture columns are unavailable.');
}

global $wpdb;
$audit_table = $wpdb->prefix . 'nat_edit_audit';
$found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table));
$assert($found_table === $audit_table, 'The append-only audit table must be installed.');

$audit_count = static function () use ($wpdb, $audit_table, $post_id): int {
    return (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id = %d', $audit_table, $post_id)
    );
};
$note_state = static function () use ($adapters, $note_column, $post_id): Noteware\AdminTables\Model\StoredValue {
    return $adapters->get($note_column->source)->read($post_id, $note_column);
};
$edit_request = static function (string $column_key, mixed $value, string $snapshot, bool $remove = false) use ($post_id): array {
    return array(
        'post_id'  => (string) $post_id,
        'column'   => $column_key,
        'nonce'    => wp_create_nonce('nat_edit_' . $post_id . '_' . $column_key),
        'value'    => $value,
        'remove'   => $remove ? '1' : '',
        'snapshot' => $snapshot,
    );
};

wp_set_current_user($administrator->ID);
$original_state = $note_state();
$original_value = $original_state->value;

// A successful edit must write once and append one audit row.
$before_count = $audit_count();
$edit_result  = $controller->processEdit(
    $edit_request('nat_demo_note', 'Boundary edit value', $original_state->hash())
);
$assert('Boundary edit value' === get_post_meta($post_id, $note_column->field, true), 'A valid edit must update metadata.');
$assert($before_count + 1 === $audit_count(), 'A valid edit must append exactly one audit row.');
$assert((int) ($edit_result['auditId'] ?? 0) > 0, 'A valid edit must return its audit ID.');
$assert('Boundary edit value' === ($edit_result['value'] ?? null), 'A valid edit must return the saved raw value.');
$assert(true === ($edit_result['exists'] ?? null), 'A valid edit must return the stored-state flag.');
$assert($note_state()->hash() === ($edit_result['snapshot'] ?? null), 'A valid edit must return the new snapshot hash.');
$assert(null !== $audit->find((int) ($edit_result['auditId'] ?? 0)), 'The returned audit ID must resolve to a stored row.');

// Undo nonce and capability failures must preserve the edit and audit history.
foreach (array('missing', 'bad') as $nonce_case) {
    $undo_denied_count = $audit_count();
    $undo_request      = array(
        'audit_id' => (string) $edit_result['auditId'],
        'nonce'    => (string) $edit_result['undoNonce'],
    );
    if ('missing' === $nonce_case) {
        unset($undo_request['nonce']);
    } else {
        $undo_request['nonce'] = 'invalid';
    }
    $expect_failure(
        static fn (): array => $controller->processUndo($undo_request),
        ucfirst($nonce_case) . ' undo nonce must be rejected.'
    );
    $assert('Boundary edit value' === get_post_meta($post_id, $note_column->field, true), ucfirst($nonce_case) . ' undo nonce must not write.');
    $assert($undo_denied_count === $audit_count(), ucfirst($nonce_case) . ' undo nonce must not append an audit row.');
}

wp_set_current_user($subscriber->ID);
$subscriber_undo_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processUndo(
        array(
            'audit_id' => (string) $edit_result['auditId'],
            'nonce'    => wp_create_nonce('nat_undo_' . $edit_result['auditId']),
        )
    ),
    'A subscriber undo must be rejected.'
);
$assert('Boundary edit value' === get_post_meta($post_id, $note_column->field, true), 'Undo capability denial must not write.');
$assert($subscriber_undo_count === $audit_count(), 'Undo capability denial must not append an audit row.');
wp_set_current_user($administrator->ID);

// Undo must restore the prior typed state and append its own audit row.
$undo_before_count = $audit_count();
$undo_result       = $controller->processUndo(
    array(
        'audit_id' => (string) $edit_result['auditId'],
        'nonce'    => (string) $edit_result['undoNonce'],
        'snapshot' => (string) $edit_result['snapshot'],
    )
);
$assert($original_value === get_post_meta($post_id, $note_column->field, true), 'Undo must restore the prior metadata value.');
$assert($undo_before_count + 1 === $audit_count(), 'Undo must append exactly one audit row.');
$assert($note_state()->hash() === ($undo_result['snapshot'] ?? null), 'Undo must return the restored snapshot hash.');

// Every allowed scalar metadata type must use the same secured write and undo service.
$scalar_cases = array(
    'nat_test_number'  => array('4', '12.5'),
    'nat_test_boolean' => array('1', '0'),
    'nat_test_select'  => array('alpha', 'beta'),
    'nat_test_date'    => array('2026-01-02', '2026-08-25'),
);
foreach ($scalar_cases as $column_key => [$initial_value, $new_value]) {
    $scalar_column = $configuration->column('nat_demo_record', $column_key);
    $assert(null !== $scalar_column && $scalar_column->editable, sprintf('%s must be an editable test column.', $column_key));
    if (null === $scalar_column) {
        continue;
    }
    update_post_meta($post_id, $scalar_column->field, $initial_value);
    $scalar_before = $adapters->get('meta')->read($post_id, $scalar_column);
    $scalar_edit   = $controller->processEdit($edit_request($column_key, $new_value, $scalar_before->hash()));
    $assert($new_value === get_post_meta($post_id, $scalar_column->field, true), sprintf('%s must store its validated value.', $column_key));
    $controller->processUndo(
        array(
            'audit_id' => (string) $scalar_edit['auditId'],
            'nonce'    => (string) $scalar_edit['undoNonce'],
        )
    );
    $assert($initial_value === get_post_meta($post_id, $scalar_column->field, true), sprintf('%s undo must restore its earlier value.', $column_key));
    delete_post_meta($post_id, $scalar_column->field);
}

// The remove path must preserve absence and let undo restore the exact earlier value.
$remove_boundary_value = 'Removal C:\\Archive\\record.txt';
update_post_meta($post_id, $note_column->field, wp_slash($remove_boundary_value));
$remove_before = $note_state();
$remove_edit   = $controller->processEdit(
    $edit_request('nat_demo_note', '', $remove_before->hash(), true)
);
$assert(! metadata_exists('post', $post_id, $note_column->field), 'Remove must delete the metadata row.');
$controller->processUndo(
    array(
        'audit_id' => (string) $remove_edit['auditId'],
        'nonce'    => (string) $remove_edit['undoNonce'],
    )
);
$assert($remove_boundary_value === get_post_meta($post_id, $note_column->field, true), 'Undo must restore a removed metadata row without losing backslashes.');

// WordPress request slashing and audit JSON must preserve literal backslashes through edit and undo.
$backslash_before = 'Original C:\\Temp\\record.txt';
$backslash_after  = 'Saved D:\\Archive\\note.txt';
update_post_meta($post_id, $note_column->field, wp_slash($backslash_before));
$backslash_state = $note_state();
$backslash_edit  = $controller->processEdit(
    $edit_request('nat_demo_note', wp_slash($backslash_after), $backslash_state->hash())
);
$assert($backslash_after === get_post_meta($post_id, $note_column->field, true), 'Edit request unslashing must preserve literal backslashes.');
$assert($backslash_after === ($backslash_edit['value'] ?? null), 'The edit response must return the canonical value with literal backslashes.');
$controller->processUndo(
    array(
        'audit_id' => (string) $backslash_edit['auditId'],
        'nonce'    => (string) $backslash_edit['undoNonce'],
    )
);
$assert($backslash_before === get_post_meta($post_id, $note_column->field, true), 'Undo must restore literal backslashes from the audit snapshot.');

delete_post_meta($post_id, $note_column->field);
$backslash_absent_state = $note_state();
$backslash_add          = $controller->processEdit(
    $edit_request('nat_demo_note', wp_slash($backslash_after), $backslash_absent_state->hash())
);
$assert($backslash_after === get_post_meta($post_id, $note_column->field, true), 'The absent-value add path must preserve literal backslashes.');
$controller->processUndo(
    array(
        'audit_id' => (string) $backslash_add['auditId'],
        'nonce'    => (string) $backslash_add['undoNonce'],
    )
);
$assert(! metadata_exists('post', $post_id, $note_column->field), 'Undo after an add must restore the exact absent state.');

if ($original_state->exists) {
    update_post_meta($post_id, $note_column->field, wp_slash($original_value));
} else {
    delete_post_meta($post_id, $note_column->field);
}

$repeated_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processUndo(
        array(
            'audit_id' => (string) $edit_result['auditId'],
            'nonce'    => (string) $edit_result['undoNonce'],
            'snapshot' => (string) $undo_result['snapshot'],
        )
    ),
    'A repeated undo must be rejected.'
);
$assert($repeated_count === $audit_count(), 'A repeated undo must not append an audit row.');

// Missing and bad nonces must fail before any write or audit.
foreach (array('missing', 'bad') as $nonce_case) {
    $state        = $note_state();
    $before_value = get_post_meta($post_id, $note_column->field, true);
    $before_count = $audit_count();
    $request      = $edit_request('nat_demo_note', 'Nonce must not write', $state->hash());
    if ('missing' === $nonce_case) {
        unset($request['nonce']);
    } else {
        $request['nonce'] = 'invalid';
    }
    $expect_failure(
        static fn (): array => $controller->processEdit($request),
        ucfirst($nonce_case) . ' nonce must be rejected.'
    );
    $assert($before_value === get_post_meta($post_id, $note_column->field, true), ucfirst($nonce_case) . ' nonce must not write.');
    $assert($before_count === $audit_count(), ucfirst($nonce_case) . ' nonce must not append an audit row.');
}

// Object capability denial must preserve both data and audit history.
wp_set_current_user($subscriber->ID);
$subscriber_state = $note_state();
$subscriber_value = get_post_meta($post_id, $note_column->field, true);
$subscriber_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processEdit(
        $edit_request('nat_demo_note', 'Subscriber must not write', $subscriber_state->hash())
    ),
    'A subscriber edit must be rejected.'
);
$assert($subscriber_value === get_post_meta($post_id, $note_column->field, true), 'Capability denial must not write.');
$assert($subscriber_count === $audit_count(), 'Capability denial must not append an audit row.');

wp_set_current_user($administrator->ID);

// A field capability denial must fail even when the object capability passes.
$deny_meta = static fn (): bool => false;
add_filter('auth_post_meta_nat_demo_note', $deny_meta, 999);
$assert(current_user_can('edit_post', $post_id), 'The field-only denial test requires object edit access.');
$assert(! current_user_can('edit_post_meta', $post_id, $note_column->field), 'The field-only denial test must remove metadata access.');
$field_denied_state = $note_state();
$field_denied_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processEdit(
        $edit_request('nat_demo_note', 'Field denial must not write', $field_denied_state->hash())
    ),
    'A metadata capability denial must reject editing.'
);
$assert($field_denied_state->equals($note_state()), 'A metadata capability denial must not write.');
$assert($field_denied_count === $audit_count(), 'A metadata capability denial must not append an audit row.');
remove_filter('auth_post_meta_nat_demo_note', $deny_meta, 999);

// Undo must repeat the field capability check, not only the object check.
$undo_cap_state = $note_state();
$undo_cap_edit  = $controller->processEdit(
    $edit_request('nat_demo_note', 'Undo capability boundary', $undo_cap_state->hash())
);
add_filter('auth_post_meta_nat_demo_note', $deny_meta, 999);
$undo_cap_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processUndo(
        array(
            'audit_id' => (string) $undo_cap_edit['auditId'],
            'nonce'    => (string) $undo_cap_edit['undoNonce'],
        )
    ),
    'A metadata capability denial must reject undo.'
);
$assert('Undo capability boundary' === get_post_meta($post_id, $note_column->field, true), 'Undo metadata denial must not write.');
$assert($undo_cap_count === $audit_count(), 'Undo metadata denial must not append an audit row.');
remove_filter('auth_post_meta_nat_demo_note', $deny_meta, 999);
$controller->processUndo(
    array(
        'audit_id' => (string) $undo_cap_edit['auditId'],
        'nonce'    => (string) $undo_cap_edit['undoNonce'],
    )
);

// Unknown and explicitly read-only columns must fail closed.
foreach (array('unknown', 'nat_demo_id') as $column_key) {
    $before_value = get_post_meta($post_id, $note_column->field, true);
    $before_count = $audit_count();
    $snapshot     = 'unknown' === $column_key
        ? str_repeat('0', 64)
        : $adapters->get($id_column->source)->read($post_id, $id_column)->hash();
    $expect_failure(
        static fn (): array => $controller->processEdit(
            $edit_request($column_key, 'Read-only must not write', $snapshot)
        ),
        sprintf('Column %s must reject editing.', $column_key)
    );
    $assert($before_value === get_post_meta($post_id, $note_column->field, true), 'A denied column must not write another field.');
    $assert($before_count === $audit_count(), 'A denied column must not append an audit row.');
}

// Array payloads and invalid typed values must not reach adapters.
$invalid_identifier_request            = $edit_request('nat_demo_note', 'Invalid identifier must not write', $note_state()->hash());
$invalid_identifier_request['post_id'] = 'not-a-number';
$invalid_requests                      = array(
    $edit_request('nat_demo_note', array('not-a-scalar'), $note_state()->hash()),
    $invalid_identifier_request,
);
foreach ($invalid_requests as $invalid_request) {
    $before_count = $audit_count();
    $expect_failure(
        static fn (): array => $controller->processEdit($invalid_request),
        'An invalid edit payload must be rejected.'
    );
    $assert($before_count === $audit_count(), 'An invalid edit payload must not append an audit row.');
}

// Typed adapters must receive the canonical unslashed input before validation.
foreach (
    array(
        'nat_test_number' => '12<script>5</script>',
        'nat_test_select' => 'alpha%20',
        'nat_test_date'   => '2026-01-01%20',
    ) as $column_key => $invalid_value
) {
    $typed_column = $configuration->column('nat_demo_record', $column_key);
    if (null === $typed_column) {
        $failures[] = sprintf('The %s test column is unavailable.', $column_key);
        continue;
    }
    $typed_adapter = $adapters->get($typed_column->source);
    $typed_before  = $typed_adapter->read($post_id, $typed_column);
    $typed_count   = $audit_count();
    $expect_failure(
        static fn (): array => $controller->processEdit(
            $edit_request($column_key, $invalid_value, $typed_before->hash())
        ),
        sprintf('The %s adapter must reject input that only becomes valid after generic text sanitization.', $column_key)
    );
    $assert($typed_before->equals($typed_adapter->read($post_id, $typed_column)), 'Rejected typed input must not write.');
    $assert($typed_count === $audit_count(), 'Rejected typed input must not append an audit row.');
}

// A stale edit snapshot must not overwrite a newer external change.
$stale_snapshot = $note_state()->hash();
update_post_meta($post_id, $note_column->field, 'External newer value');
$stale_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processEdit(
        $edit_request('nat_demo_note', 'Stale overwrite', $stale_snapshot)
    ),
    'A stale edit snapshot must be rejected.'
);
$assert('External newer value' === get_post_meta($post_id, $note_column->field, true), 'A stale edit must preserve the newer value.');
$assert($stale_count === $audit_count(), 'A stale edit must not append an audit row.');

// A stale undo must not overwrite a value changed after the audited edit.
$fresh_state       = $note_state();
$stale_undo_result = $controller->processEdit(
    $edit_request('nat_demo_note', 'Value awaiting undo', $fresh_state->hash())
);
update_post_meta($post_id, $note_column->field, 'External value after edit');
$stale_undo_count = $audit_count();
$expect_failure(
    static fn (): array => $controller->processUndo(
        array(
            'audit_id' => (string) $stale_undo_result['auditId'],
            'nonce'    => (string) $stale_undo_result['undoNonce'],
            'snapshot' => (string) $stale_undo_result['snapshot'],
        )
    ),
    'A stale undo must be rejected.'
);
$assert('External value after edit' === get_post_meta($post_id, $note_column->field, true), 'A stale undo must preserve the newer value.');
$assert($stale_undo_count === $audit_count(), 'A stale undo must not append an audit row.');

// Duplicate metadata rows are ambiguous, so edit and remove must fail closed.
$duplicate_before = $note_state();
delete_post_meta($post_id, $note_column->field);
$duplicate_value = 'Duplicate C:\\Rows\\record.txt';
add_post_meta($post_id, $note_column->field, wp_slash($duplicate_value), false);
add_post_meta($post_id, $note_column->field, wp_slash($duplicate_value), false);
$duplicate_state = $note_state();
foreach (array(false, true) as $remove_duplicate) {
    $duplicate_count = $audit_count();
    $expect_failure(
        static fn (): array => $controller->processEdit(
            $edit_request('nat_demo_note', 'Duplicate rows must not write', $duplicate_state->hash(), $remove_duplicate)
        ),
        $remove_duplicate ? 'Duplicate metadata rows must reject removal.' : 'Duplicate metadata rows must reject editing.'
    );
    $duplicate_rows = get_post_meta($post_id, $note_column->field, false);
    $assert(array($duplicate_value, $duplicate_value) === $duplicate_rows, 'A rejected duplicate-row operation must preserve every stored row exactly.');
    $assert($duplicate_count === $audit_count(), 'A rejected duplicate-row operation must not append an audit row.');
}
delete_post_meta($post_id, $note_column->field);
if ($duplicate_before->exists) {
    update_post_meta($post_id, $note_column->field, wp_slash($duplicate_before->value));
}

// Force a safe insert error. The transaction must roll back the adapter write.
$rollback_state = $note_state();
$rollback_count = $audit_count();
$query_filter   = static function (string $query) use ($audit_table): string {
    if (str_contains($query, 'INSERT INTO') && str_contains($query, $audit_table)) {
        return 'INSERT INTO `' . $audit_table . '` (`nat_missing_test_column`) VALUES (1)';
    }
    return $query;
};
$wpdb->suppress_errors(true);
add_filter('query', $query_filter, 999);
$expect_failure(
    static fn (): array => $controller->processEdit(
        $edit_request('nat_demo_note', 'Rolled back value', $rollback_state->hash())
    ),
    'An audit insert failure must reject the edit.'
);
remove_filter('query', $query_filter, 999);
$wpdb->suppress_errors(false);
$assert($rollback_state->equals($note_state()), 'An audit insert failure must roll back the adapter write.');
$assert($rollback_count === $audit_count(), 'An audit insert failure must not append an audit row.');

// Leave the reusable fixture deterministic for later browser runs.
if ($original_state->exists) {
    update_post_meta($post_id, $note_column->field, wp_slash($original_value));
} else {
    delete_post_meta($post_id, $note_column->field);
}
wp_set_current_user(0);

if ($failures) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d security assertion(s) failed.', count($failures)));
}

WP_CLI::success('Edit, nonce, capability, validation, audit, snapshot, undo, and rollback boundaries passed.');
