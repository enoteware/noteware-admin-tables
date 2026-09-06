<?php
/**
 * Explicitly configured scalar post-field reads through Meta Box's public API.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration;

use InvalidArgumentException;
use RuntimeException;

final class MetaBoxReader
{
    /** @param array<string, string> $fields Trusted field ID to public Meta Box type. */
    public function __construct(private readonly PublicFunctions $api, private readonly array $fields)
    {
        if (count($fields) > 100) {
            throw new InvalidArgumentException('At most one hundred integration fields may be configured.');
        }
        foreach ($fields as $field => $type) {
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,190}$/D', $field) || ! in_array($type, array('text', 'textarea', 'number', 'email', 'url', 'checkbox', 'select', 'radio'), true)) {
                throw new InvalidArgumentException('Unsupported Meta Box scalar field registration.');
            }
        }
    }

    public function available(): bool
    {
        return $this->api->available('rwmb_get_field_settings') && $this->api->available('rwmb_get_value');
    }

    /**
     * @param list<int> $postIds Authorized admin page IDs, at most two hundred.
     * @return array<int, ReadResult>
     */
    public function readPage(array $postIds, string $field): array
    {
        if (! isset($this->fields[$field]) || ! array_is_list($postIds) || count($postIds) > 200) {
            throw new InvalidArgumentException('Unknown field or excessive integration page.');
        }
        if (! $this->available()) {
            throw new RuntimeException('Meta Box public APIs are unavailable.');
        }
        foreach ($postIds as $postId) {
            if (! is_int($postId) || $postId < 1 || ! current_user_can('edit_post', $postId)) {
                throw new RuntimeException('An integration row is not accessible to the current editor.');
            }
        }
        update_meta_cache('post', $postIds);
        $result = array();
        foreach (array_unique($postIds) as $postId) {
            $settings = $this->api->call('rwmb_get_field_settings', array($field, array(), $postId));
            if (! is_array($settings) || ($settings['id'] ?? null) !== $field || ($settings['type'] ?? null) !== $this->fields[$field] || ! empty($settings['clone']) || ! empty($settings['multiple'])) {
                throw new RuntimeException('This integration field is missing, changed, or multi-value.');
            }
            $value = $this->api->call('rwmb_get_value', array($field, array(), $postId));
            if (! is_scalar($value) && null !== $value) {
                throw new RuntimeException('The integration returned a non-scalar value.');
            }
            $result[$postId] = new ReadResult($value);
        }
        return $result;
    }
}
