<?php
/**
 * Site-scoped personal views and administrator-published role views.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\View;

use InvalidArgumentException;
use RuntimeException;

final class ViewRepository
{
    /** @var array<string, bool> Reject same-connection nested writes to a locked scope. */
    private static array $heldLocks = array();

    /** @return array<string, array<string, mixed>> */
    public function available(string $postType): array
    {
        $this->authorize($postType);
        $personal = get_user_option('nat_views_' . $postType);
        $shared = get_option('nat_shared_views_' . $postType, array());
        $views = is_array($personal) ? $personal : array();
        foreach (is_array($shared) ? $shared : array() as $id => $view) {
            if (is_array($view) && (current_user_can('manage_options') || array_intersect(wp_get_current_user()->roles, (array) ($view['roles'] ?? array())))) {
                $views[$id] = $view;
            }
        }
        return array_filter($views, static function (mixed $view, mixed $id) use ($postType): bool {
            return is_array($view)
                && is_string($id)
                && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)
                && ($view['id'] ?? null) === $id
                && ($view['post_type'] ?? null) === $postType
                && self::validName($view['name'] ?? null);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string, mixed> $screen Site definition.
     * @param array<string, mixed> $view Saved presentation.
     */
    public function save(string $postType, array $screen, array $view): string
    {
        $this->authorize($postType);
        $id = $view['id'] ?? '';
        if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) || 'default' === $id) {
            throw new InvalidArgumentException('Invalid view identifier.');
        }
        if (($view['post_type'] ?? null) !== $postType || ! self::validName($view['name'] ?? null)) {
            throw new InvalidArgumentException('A view needs a name of at most 100 characters and the matching screen.');
        }
        if (! in_array($view['visibility'] ?? null, array('personal', 'shared'), true) || ! is_array($view['roles'] ?? null)) {
            throw new InvalidArgumentException('Invalid view visibility.');
        }
        $shared = 'shared' === $view['visibility'];
        if ($shared && ! current_user_can('manage_options')) {
            throw new RuntimeException('Only administrators can publish shared views.');
        }
        foreach ($view['roles'] as $role) {
            if (! is_string($role) || ! wp_roles()->is_role($role)) {
                throw new InvalidArgumentException('Unknown role.');
            }
        }
        if (($shared && ! $view['roles']) || (! $shared && $view['roles'])) {
            throw new InvalidArgumentException('Shared views require roles; personal views cannot assign roles.');
        }
        ViewLayout::apply($screen, $view);
        $this->persistNew($postType, $view, $shared);
        return $id;
    }

    /** @param array<string, mixed> $view Validated new view. */
    private function persistNew(string $postType, array $view, bool $shared): void
    {
        global $wpdb;
        $key = ($shared ? 'nat_shared_views_' : 'nat_views_') . $postType;
        $userId = get_current_user_id();
        $scope = $shared ? 'shared' : 'user:' . $userId;
        $database = defined('DB_NAME') ? (string) constant('DB_NAME') : $wpdb->options;
        $lock = 'nat_view_' . substr(hash('sha256', $database . ':' . $wpdb->options . ':' . $key . ':' . $scope), 0, 55);
        // Connection-bound database locks have no expiry or unsafe takeover.
        if (isset(self::$heldLocks[$lock]) || '1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock))) {
            throw new RuntimeException('View storage is busy or unavailable. Retry after the current writer finishes.');
        }
        self::$heldLocks[$lock] = true;
        try {
            $this->assertLockOwner($lock);
            $this->freshStorage($key, $shared, $userId);
            $this->authorize($postType);
            if (get_current_user_id() !== $userId || ($shared && ! current_user_can('manage_options'))) {
                throw new RuntimeException('Permission to save this view changed. Reload the editor.');
            }
            $stored = $shared ? get_option($key, array()) : get_user_option($key);
            if (! $shared && false === $stored) {
                $stored = array();
            }
            if (! is_array($stored)) {
                throw new RuntimeException('The stored view collection is invalid and was not changed.');
            }
            $id = $view['id'];
            if (array_key_exists($id, $stored)) {
                throw new InvalidArgumentException('This view identifier already exists. Reload the editor to save a new copy.');
            }
            if (count($stored) >= 30) {
                throw new InvalidArgumentException('This screen already has 30 views.');
            }
            $stored[$id] = $view;
            $this->assertLockOwner($lock);
            if ($shared) {
                update_option($key, $stored, false);
            } else {
                update_user_option($userId, $key, $stored, false);
            }
            $this->assertLockOwner($lock);
            // Verify persisted state, never an optimistic request-cache entry.
            $this->freshStorage($key, $shared, $userId);
            $readback = $shared ? get_option($key) : get_user_option($key);
            if ($readback !== $stored) {
                throw new RuntimeException('The view could not be saved.');
            }
        } finally {
            try {
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                if ('1' !== (string) $released) {
                    throw new RuntimeException('The view storage connection changed. Verify the stored result before retrying.');
                }
            } finally {
                unset(self::$heldLocks[$lock]);
            }
        }
    }

    private function assertLockOwner(string $lock): void
    {
        global $wpdb;
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock))) {
            throw new RuntimeException('The view storage connection changed. Verify the stored result before retrying.');
        }
    }

    private function freshStorage(string $key, bool $shared, int $userId): void
    {
        if ($shared) {
            wp_cache_delete($key, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        } else {
            wp_cache_delete($userId, 'user_meta');
        }
    }

    public function select(string $postType, string $id): void
    {
        $this->authorize($postType);
        if ('default' !== $id && ! isset($this->available($postType)[$id])) {
            throw new InvalidArgumentException('This view is unavailable.');
        }
        $key = 'nat_active_view_' . $postType;
        update_user_option(get_current_user_id(), $key, $id, false);
        if (get_user_option($key) !== $id) {
            throw new RuntimeException('The selected view could not be saved.');
        }
    }

    private static function validName(mixed $name): bool
    {
        if (! is_string($name) || '' === trim($name) || strlen($name) > 400) {
            return false;
        }
        // Count Unicode code points without requiring optional mbstring.
        // Invalid UTF-8 is rejected rather than assigned a misleading length.
        $length = preg_match_all('/./us', $name);
        return false !== $length && $length <= 100;
    }

    public function authorize(string $postType): void
    {
        $type = get_post_type_object($postType);
        if (! $type || ! $type->show_ui || ! current_user_can($type->cap->edit_posts)) {
            throw new RuntimeException('This screen is not available to the current user.');
        }
    }
}
