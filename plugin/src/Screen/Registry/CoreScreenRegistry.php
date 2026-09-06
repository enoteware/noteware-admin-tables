<?php
/**
 * Permission metadata for core list-screen families, without claiming UI adapters.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Screen\Registry;

use InvalidArgumentException;

final class CoreScreenRegistry
{
    /** @return list<string> */
    public static function families(): array
    {
        return array('posts', 'media', 'users', 'comments', 'terms', 'sites');
    }

    public static function canRead(string $family, ?string $objectType = null): bool
    {
        if (! in_array($family, self::families(), true)) {
            throw new InvalidArgumentException('Unknown screen family.');
        }
        if (! get_current_user_id()) {
            return false;
        }
        if ('posts' === $family) {
            $type = null === $objectType ? null : get_post_type_object($objectType);
            return $type && $type->show_ui && current_user_can($type->cap->edit_posts);
        }
        if ('terms' === $family) {
            $taxonomy = null === $objectType ? null : get_taxonomy($objectType);
            return $taxonomy && $taxonomy->show_ui && current_user_can($taxonomy->cap->manage_terms);
        }
        return match ($family) {
            'media' => current_user_can('upload_files'),
            'users' => current_user_can('list_users'),
            'comments' => current_user_can('edit_posts'),
            'sites' => is_multisite() && is_network_admin() && current_user_can('manage_sites'),
            default => false,
        };
    }
}
