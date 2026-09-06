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
        $this->authorize($shared);
        $state = $this->read($shared);
        $data  = $segment->toArray();
        if (! isset($state['segments'][$data['id']]) && count($state['segments']) >= 20) {
            throw new RuntimeException('A view can hold at most twenty segments per scope.');
        }
        $state['segments'][$data['id']] = $data;
        $this->write($state, $shared);
    }

    public function delete(string $id, bool $shared = false): void
    {
        $this->authorize($shared);
        SegmentDefinition::assertKey($id);
        $state = $this->read($shared);
        unset($state['segments'][$id]);
        if ($state['default'] === $id) {
            $state['default'] = null;
        }
        $this->write($state, $shared);
    }

    public function setDefault(?string $id, bool $shared = false): void
    {
        $this->authorize($shared);
        $state = $this->read($shared);
        if (null !== $id && ! isset($state['segments'][$id])) {
            throw new RuntimeException('The default must name a segment in this scope.');
        }
        $state['default'] = $id;
        $this->write($state, $shared);
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
