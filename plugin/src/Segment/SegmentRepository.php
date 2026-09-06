<?php
/**
 * Site, screen, view and current-user scoped segment persistence.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Segment;

use RuntimeException;

final class SegmentRepository
{
    /** @var array<string, bool> Prevent same-connection nested acquisition from bypassing serialization. */
    private static array $heldLocks = array();

    public function __construct(private readonly string $postType, private readonly string $viewId = 'default')
    {
        SegmentDefinition::assertKey($postType);
        SegmentDefinition::assertKey($viewId);
    }

    /** @return array<string, mixed> */
    public function read(bool $shared = false): array
    {
        $this->authorize(false);
        $value = $shared ? get_option($this->key(), array()) : get_user_option($this->key());
        if (! is_array($value)) {
            return array('segments' => array(), 'default' => null);
        }
        return array('segments' => is_array($value['segments'] ?? null) ? $value['segments'] : array(), 'default' => $value['default'] ?? null);
    }

    public function save(SegmentDefinition $segment, bool $shared = false): void
    {
        $data = $segment->toArray();
        $this->mutate($shared, static function (array $state) use ($data): array {
            if (! isset($state['segments'][$data['id']]) && count($state['segments']) >= 20) {
                throw new RuntimeException('A view can hold at most twenty segments per scope.');
            }
            $state['segments'][$data['id']] = $data;
            return $state;
        });
    }

    public function delete(string $id, bool $shared = false): void
    {
        SegmentDefinition::assertKey($id);
        $this->mutate($shared, static function (array $state) use ($id): array {
            unset($state['segments'][$id]);
            if ($state['default'] === $id) {
                $state['default'] = null;
            }
            return $state;
        });
    }

    public function setDefault(?string $id, bool $shared = false): void
    {
        $this->mutate($shared, static function (array $state) use ($id): array {
            if (null !== $id && ! isset($state['segments'][$id])) {
                throw new RuntimeException('The default must name a segment in this scope.');
            }
            $state['default'] = $id;
            return $state;
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change Mutation against freshly loaded state. */
    private function mutate(bool $shared, callable $change): void
    {
        $this->authorize($shared);
        global $wpdb;
        $scope = $shared ? 'shared' : 'user:' . get_current_user_id();
        $database = defined('DB_NAME') ? (string) constant('DB_NAME') : $wpdb->options;
        $lock = 'nat_seg_' . substr(hash('sha256', $database . ':' . $wpdb->options . ':' . $this->key() . ':' . $scope), 0, 56);
        // Database locks are connection-scoped, not expiring leases. Never steal an owner.
        if (isset(self::$heldLocks[$lock]) || '1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock))) {
            throw new RuntimeException('Segment storage is busy or unavailable. Retry the change after the current writer finishes.');
        }
        self::$heldLocks[$lock] = true;
        try {
            // A caller may already hold stale option/user-meta state in its request cache.
            if ($shared) {
                wp_cache_delete($this->key(), 'options');
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
            } else {
                wp_cache_delete(get_current_user_id(), 'user_meta');
            }
            $this->authorize($shared);
            $this->write($change($this->read($shared)), $shared);
        } finally {
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            unset(self::$heldLocks[$lock]);
            if ('1' !== (string) $released) {
                throw new RuntimeException('The segment storage connection changed. Verify the stored result before retrying.');
            }
        }
    }

    private function key(): string
    {
        // The blog id also prevents get_user_option's global fallback leaking a default across sites.
        return 'nat_segments_' . get_current_blog_id() . '_' . strlen($this->postType) . '_' . $this->postType . '_' . strlen($this->viewId) . '_' . $this->viewId;
    }

    private function authorize(bool $sharedWrite): void
    {
        $type = get_post_type_object($this->postType);
        if (! get_current_user_id() || ! $type || ! current_user_can($type->cap->edit_posts) || ($sharedWrite && ! current_user_can('manage_options'))) {
            throw new RuntimeException('This user cannot access or change these segments.');
        }
    }

    /** @param array<string, mixed> $state Scoped segment state. */
    private function write(array $state, bool $shared): void
    {
        $saved = $shared ? update_option($this->key(), $state, false) : update_user_option(get_current_user_id(), $this->key(), $state, false);
        if (! $saved && $this->read($shared) !== $state) {
            throw new RuntimeException('The segment could not be saved.');
        }
    }
}
