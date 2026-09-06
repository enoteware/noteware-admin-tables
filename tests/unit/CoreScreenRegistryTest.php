<?php
/**
 * Core screen entry capability matrix tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Screen\Registry {
    function get_current_user_id(): int
    {
        return $GLOBALS['nat_registry_user'];
    }
    function current_user_can(string $capability): bool
    {
        return in_array($capability, $GLOBALS['nat_registry_caps'], true);
    }
    function get_post_type_object(string $type): ?object
    {
        return 'example' === $type ? (object) array('show_ui' => true, 'cap' => (object) array('edit_posts' => 'edit_examples')) : null;
    }
    function get_taxonomy(string $type): ?object
    {
        return 'example' === $type ? (object) array('show_ui' => true, 'cap' => (object) array('manage_terms' => 'manage_examples')) : null;
    }
    function is_multisite(): bool
    {
        return $GLOBALS['nat_registry_multisite'];
    }
    function is_network_admin(): bool
    {
        return $GLOBALS['nat_registry_network'];
    }
}

namespace Noteware\AdminTables\Tests {
    use Noteware\AdminTables\Screen\Registry\CoreScreenRegistry;
    use PHPUnit\Framework\TestCase;

    final class CoreScreenRegistryTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_registry_user'] = 1;
            $GLOBALS['nat_registry_caps'] = array();
            $GLOBALS['nat_registry_multisite'] = true;
            $GLOBALS['nat_registry_network'] = true;
        }

        public function testAllFamiliesDenyMissingCapabilities(): void
        {
            foreach (CoreScreenRegistry::families() as $family) {
                self::assertFalse(CoreScreenRegistry::canRead($family, 'example'));
            }
        }

        public function testEachFamilyRequiresItsSpecificCapability(): void
        {
            $matrix = array('posts' => 'edit_examples', 'terms' => 'manage_examples', 'media' => 'upload_files', 'users' => 'list_users', 'comments' => 'edit_posts', 'sites' => 'manage_sites');
            foreach ($matrix as $family => $capability) {
                $GLOBALS['nat_registry_caps'] = array($capability);
                self::assertTrue(CoreScreenRegistry::canRead($family, 'example'));
            }
        }

        public function testSitesAlsoRequireNetworkContext(): void
        {
            $GLOBALS['nat_registry_caps'] = array('manage_sites');
            $GLOBALS['nat_registry_network'] = false;
            self::assertFalse(CoreScreenRegistry::canRead('sites'));
            $GLOBALS['nat_registry_network'] = true;
            $GLOBALS['nat_registry_multisite'] = false;
            self::assertFalse(CoreScreenRegistry::canRead('sites'));
        }

        public function testUnknownPostTypeIsNotAllowed(): void
        {
            $GLOBALS['nat_registry_caps'] = array('edit_examples');
            self::assertFalse(CoreScreenRegistry::canRead('posts', 'missing'));
        }
    }
}
