<?php
/**
 * Read-only native post fields.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use Noteware\AdminTables\Contract\FieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class NativeAdapter implements FieldAdapter
{
    public function source(): string
    {
        return 'native';
    }

    public function read(int $postId, ColumnDefinition $column): StoredValue
    {
        $post = get_post($postId);
        if (! $post) {
            return new StoredValue(false, null);
        }

        $value = match ($column->field) {
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'author'         => get_the_author_meta('display_name', (int) $post->post_author),
            'date'           => $post->post_date,
            'status'         => $post->post_status,
            'word_count'     => str_word_count(wp_strip_all_tags($post->post_content)),
            'featured_image' => get_post_thumbnail_id($postId),
            default          => null,
        };

        return new StoredValue(null !== $value && false !== $value, $value);
    }
}
