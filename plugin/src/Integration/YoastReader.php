<?php
/**
 * Read-only computed SEO projections through the documented Surfaces API.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration;

use InvalidArgumentException;
use RuntimeException;

final class YoastReader
{
    public function __construct(private readonly PublicFunctions $api)
    {
    }

    /**
     * @param list<int> $postIds Current admin page IDs.
     * @return array<int, ReadResult>
     */
    public function readPage(array $postIds, string $field): array
    {
        if (! in_array($field, array('title', 'description', 'canonical'), true) || ! array_is_list($postIds) || count($postIds) > 200) {
            throw new InvalidArgumentException('Unsupported SEO field or excessive page.');
        }
        if (! $this->api->available('YoastSEO')) {
            throw new RuntimeException('The SEO surface is unavailable.');
        }
        foreach ($postIds as $postId) {
            if (! is_int($postId) || $postId < 1 || ! current_user_can('edit_post', $postId)) {
                throw new RuntimeException('An integration row is not accessible to the current editor.');
            }
        }
        $root = $this->api->call('YoastSEO', array());
        if (! is_object($root) || (! property_exists($root, 'meta') && ! is_callable(array($root, '__get')))) {
            throw new RuntimeException('The SEO post surface is unavailable.');
        }
        /** @var object{meta: mixed} $root Public documented magic surface. */
        $meta = $root->meta;
        if (! is_object($meta) || ! is_callable(array($meta, 'for_post'))) {
            throw new RuntimeException('The SEO post surface is unavailable.');
        }
        $result = array();
        foreach (array_unique($postIds) as $postId) {
            $surface = $meta->for_post($postId);
            if (! is_object($surface)) {
                throw new RuntimeException('The SEO post projection is unavailable.');
            }
            /** @var object{title: mixed, description: mixed, canonical: mixed} $surface Documented projection properties. */
            $value = $surface->$field;
            if (! is_string($value) && null !== $value) {
                throw new RuntimeException('The SEO projection returned an unsupported value.');
            }
            $result[$postId] = new ReadResult($value);
        }
        return $result;
    }
}
