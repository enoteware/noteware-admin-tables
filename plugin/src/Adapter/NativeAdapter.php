<?php
/**
 * Native post fields, including the small set WordPress can edit safely.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use InvalidArgumentException;
use RuntimeException;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class NativeAdapter implements EditableFieldAdapter
{
    private const EDITABLE = array('title', 'slug', 'featured_image');

    private const MAX_TITLE_LENGTH = 500;

    private const MAX_SLUG_LENGTH = 200;

    public function source(): string
    {
        return 'native';
    }

    public function supports(ColumnDefinition $column): bool
    {
        unset($column);
        return true;
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        $post = get_post($postId);
        if (! $post) {
            return new StoredValue(false, null);
        }

        if ('featured_image' === $column->field) {
            $attachmentId = (int) get_post_thumbnail_id($postId);
            return $attachmentId > 0 ? new StoredValue(true, $attachmentId) : new StoredValue(false, null);
        }

        if ('permalink' === $column->field) {
            $permalink = get_permalink($postId);
            return is_string($permalink) && '' !== $permalink
                ? new StoredValue(true, $permalink)
                : new StoredValue(false, null);
        }

        // Every other native column is a real database column, so it exists
        // whenever the post does. An empty string stays a distinct empty state.
        $value = match ($column->field) {
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'slug'       => $post->post_name,
            // A numeric author column is the user ID, which is also what the
            // author filter matches. A text column shows the display name.
            'author'     => 'number' === $column->type
                ? (int) $post->post_author
                : (string) get_the_author_meta('display_name', (int) $post->post_author),
            'date'       => $post->post_date,
            'status'     => $post->post_status,
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
            'permalink'  => null,
            default      => null,
        };

        return new StoredValue(null !== $value, $value);
    }

    public function authorize(int $postId, ColumnDefinition $column): void
    {
        if (! in_array($column->field, self::EDITABLE, true)) {
            throw new InvalidArgumentException('This native field is not editable.');
        }
        if (! current_user_can('edit_post', $postId)) {
            throw new InvalidArgumentException('You do not have permission to edit this record.');
        }
    }

    public function nonceAction(string $operation, int $identifier, ColumnDefinition $column): string
    {
        return match ($operation) {
            'edit'  => 'nat_edit_' . $identifier . '_' . $column->key,
            'undo'  => 'nat_undo_' . $identifier,
            default => throw new InvalidArgumentException('Unsupported edit operation.'),
        };
    }

    public function validate(ColumnDefinition $column, mixed $value): mixed
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The submitted value is not valid.');
        }

        return match ($column->field) {
            'title'          => $this->title($value),
            'slug'           => $this->slug($value),
            'featured_image' => $this->attachment($value),
            default          => throw new InvalidArgumentException('This native field is not editable.'),
        };
    }

    public function sanitize(ColumnDefinition $column, mixed $value): mixed
    {
        unset($column);
        return $value;
    }

    public function lock(int $postId, ColumnDefinition $column): void
    {
        global $wpdb;
        $postLock = $wpdb->query(
            $wpdb->prepare('SELECT ID FROM %i WHERE ID = %d FOR UPDATE', $wpdb->posts, $postId)
        );
        if (1 !== $postLock) {
            throw new RuntimeException('The post could not be locked for editing.');
        }
        if ('featured_image' !== $column->field) {
            return;
        }
        $metaLock = $wpdb->query(
            $wpdb->prepare(
                'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s FOR UPDATE',
                $wpdb->postmeta,
                $postId,
                '_thumbnail_id'
            )
        );
        if (false === $metaLock) {
            throw new RuntimeException('The featured image could not be locked for editing.');
        }
        if ($metaLock > 1) {
            throw new RuntimeException('Records with more than one featured image row cannot be edited safely.');
        }
    }

    public function write(int $postId, ColumnDefinition $column, mixed $value, StoredValue $expected): void
    {
        unset($expected);
        if ('featured_image' === $column->field) {
            $this->writeThumbnail($postId, $column, (int) $value);
            return;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('This adapter only writes validated string values.');
        }
        $this->updatePost($postId, $column, $value);
    }

    public function supportsRemoval(ColumnDefinition $column): bool
    {
        return 'featured_image' === $column->field;
    }

    public function remove(int $postId, ColumnDefinition $column, StoredValue $expected): void
    {
        if ('featured_image' !== $column->field) {
            throw new InvalidArgumentException('This native field cannot be cleared.');
        }
        if (! $expected->exists) {
            return;
        }
        delete_post_thumbnail($postId);
        clean_post_cache($postId);
        if ($this->read($postId, $column)->exists) {
            throw new RuntimeException('The featured image could not be removed.');
        }
    }

    public function restore(int $postId, ColumnDefinition $column, StoredValue $current, StoredValue $target): void
    {
        if ($target->exists) {
            $this->write($postId, $column, $target->value, $current);
            return;
        }
        $this->remove($postId, $column, $current);
    }

    public function auditDescriptor(ColumnDefinition $column): array
    {
        return array(
            'column_key' => $column->key,
            'source'     => $this->source(),
            'field_name' => $column->field,
        );
    }

    public function transactionalTables(ColumnDefinition $column): array
    {
        global $wpdb;
        return 'featured_image' === $column->field
            ? array($wpdb->posts, $wpdb->postmeta)
            : array($wpdb->posts);
    }

    private function title(string $raw): string
    {
        $title = sanitize_text_field($raw);
        if ('' === trim($title) || strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException('Enter a title with 1 to 500 characters.');
        }
        return $title;
    }

    private function slug(string $raw): string
    {
        if ('' === $raw || strlen($raw) > self::MAX_SLUG_LENGTH) {
            throw new InvalidArgumentException('Enter a slug with 1 to 200 characters.');
        }
        if (sanitize_title($raw) !== $raw) {
            throw new InvalidArgumentException('Slugs may only use lowercase letters, numbers, and hyphens.');
        }
        return $raw;
    }

    private function attachment(string $raw): string
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $raw)) {
            throw new InvalidArgumentException('Enter the numeric ID of an image in the media library.');
        }
        $attachmentId = (int) $raw;
        $attachment   = get_post($attachmentId);
        if (! $attachment || 'attachment' !== $attachment->post_type) {
            throw new InvalidArgumentException('That media item does not exist.');
        }
        if (! wp_attachment_is_image($attachmentId)) {
            throw new InvalidArgumentException('Choose an image file.');
        }
        if (! current_user_can('read_post', $attachmentId)) {
            throw new InvalidArgumentException('You do not have permission to use that media item.');
        }
        return $raw;
    }

    private function writeThumbnail(int $postId, ColumnDefinition $column, int $attachmentId): void
    {
        // set_post_thumbnail() reports false when the stored value is already
        // the requested one, so the readback is the only reliable check.
        set_post_thumbnail($postId, $attachmentId);
        clean_post_cache($postId);

        $stored = $this->read($postId, $column);
        if (! $stored->exists || $stored->value !== $attachmentId) {
            throw new RuntimeException('The saved featured image could not be confirmed. No change was kept.');
        }
    }

    private function updatePost(int $postId, ColumnDefinition $column, string $value): void
    {
        $field  = 'title' === $column->field ? 'post_title' : 'post_name';
        $result = wp_update_post(
            array(
                'ID' => $postId,
                $field => wp_slash($value),
            ),
            true
        );
        if (is_wp_error($result)) {
            throw new RuntimeException('WordPress refused the change to this record.');
        }
        clean_post_cache($postId);

        $stored = $this->read($postId, $column);
        if (! $stored->exists || $stored->value !== $value) {
            throw new RuntimeException('WordPress changed the saved value, so the edit was not kept.');
        }
    }
}
