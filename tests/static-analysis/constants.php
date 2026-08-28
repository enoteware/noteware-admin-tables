<?php
/**
 * Constants supplied by the plugin entrypoint at runtime.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

const NAT_PLUGIN_FILE = __DIR__ . '/../../plugin/noteware-admin-tables.php';
const NAT_VERSION     = 'test';

if (! function_exists('get_field')) {
    /**
     * ACF Free public API declaration for static analysis.
     *
     * @param string $selector Field name or key.
     * @param int    $post_id  WordPress post ID.
     * @return mixed
     */
    function get_field(string $selector, int $post_id): mixed
    {
        return null;
    }
}

if (! function_exists('update_field')) {
    /**
     * ACF Free public API declaration for static analysis.
     *
     * @param string $selector Field name or key.
     * @param mixed  $value    Slashed value to store.
     * @param int    $post_id  WordPress post ID.
     * @return bool
     */
    function update_field(string $selector, mixed $value, int $post_id): bool
    {
        return true;
    }
}

if (! function_exists('delete_field')) {
    /**
     * ACF Free public API declaration for static analysis.
     *
     * @param string $selector Field name or key.
     * @param int    $post_id  WordPress post ID.
     * @return bool
     */
    function delete_field(string $selector, int $post_id): bool
    {
        return true;
    }
}
