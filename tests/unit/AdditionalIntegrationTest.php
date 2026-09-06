<?php
/**
 * Public API contract doubles, not activated-plugin compatibility tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration {
    function current_user_can(string $capability, int $postId): bool
    {
        return ! in_array($postId, $GLOBALS['nat_integration_denied'], true);
    }
    function update_meta_cache(string $type, array $ids): void
    {
        $GLOBALS['nat_integration_cache'][] = $ids;
    }
}

namespace Noteware\AdminTables\Tests {
    use InvalidArgumentException;
    use Noteware\AdminTables\Integration\IntegrationCatalog;
    use Noteware\AdminTables\Integration\MetaBoxReader;
    use Noteware\AdminTables\Integration\PublicFunctions;
    use Noteware\AdminTables\Integration\YoastReader;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class AdditionalIntegrationTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_integration_denied'] = array();
            $GLOBALS['nat_integration_cache'] = array();
        }

        private function api(): PublicFunctions
        {
            return new class extends PublicFunctions {
                public bool $enabled = true;
                public bool $clone = false;
                public int $calls = 0;
                public function available(string $function): bool
                {
                    return $this->enabled;
                }
                public function call(string $function, array $arguments): mixed
                {
                    ++$this->calls;
                    if ('rwmb_get_field_settings' === $function) {
                        return array('id' => 'example', 'type' => 'text', 'clone' => $this->clone);
                    }
                    if ('rwmb_get_value' === $function) {
                        return array(1 => '0', 2 => '', 3 => null)[$arguments[2]];
                    }
                    return new class {
                        public function __get(string $name): object
                        {
                            return new class {
                                public function for_post(int $id): object
                                {
                                    return (object) array('title' => 'Example ' . $id, 'description' => '', 'canonical' => 'https://example.test/item');
                                }
                            };
                        }
                    };
                }
            };
        }

        public function testProjectedStatesAreNotInvented(): void
        {
            $result = (new MetaBoxReader($this->api(), array('example' => 'text')))->readPage(array(1, 2, 3), 'example');
            self::assertSame('0', $result[1]->value);
            self::assertSame('', $result[2]->value);
            self::assertNull($result[3]->value);
            self::assertNull($result[1]->toArray()['storage_state']);
            self::assertFalse($result[1]->toArray()['editable']);
            self::assertCount(1, $GLOBALS['nat_integration_cache']);
        }

        public function testPermissionFailureOccursBeforeAnyApiRead(): void
        {
            $api = $this->api();
            $GLOBALS['nat_integration_denied'] = array(2);
            try {
                (new MetaBoxReader($api, array('example' => 'text')))->readPage(array(1, 2), 'example');
                self::fail('Unauthorized read succeeded.');
            } catch (RuntimeException) {
                self::assertSame(0, $api->calls);
                self::assertSame(array(), $GLOBALS['nat_integration_cache']);
            }
        }

        public function testCloneFieldsAreRejected(): void
        {
            $api = $this->api();
            $api->clone = true;
            $this->expectException(RuntimeException::class);
            (new MetaBoxReader($api, array('example' => 'text')))->readPage(array(1), 'example');
        }

        public function testUnavailableDependencyFailsExplicitly(): void
        {
            $api = $this->api();
            $api->enabled = false;
            $this->expectException(RuntimeException::class);
            (new MetaBoxReader($api, array('example' => 'text')))->readPage(array(1), 'example');
        }

        public function testUnknownFieldsCannotReachApi(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new MetaBoxReader($this->api(), array('example' => 'text')))->readPage(array(1), 'unknown');
        }

        public function testPagesAreBounded(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new YoastReader($this->api()))->readPage(range(1, 201), 'title');
        }

        public function testSeoPublicMagicSurfaceWorks(): void
        {
            $result = (new YoastReader($this->api()))->readPage(array(1, 1, 2), 'title');
            self::assertCount(2, $result);
            self::assertSame('Example 2', $result[2]->value);
        }

        public function testApiDetectionNeverClaimsActivatedTesting(): void
        {
            $report = IntegrationCatalog::report($this->api());
            self::assertCount(12, $report);
            self::assertTrue($report['meta_box']['api_available']);
            self::assertNull($report['gravity_forms']['api_available']);
            foreach ($report as $entry) {
                self::assertFalse($entry['activated_tested']);
                self::assertFalse($entry['writes']);
            }
        }

        public function testPublicFunctionBoundaryRejectsArbitraryCalls(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new PublicFunctions())->call('arbitrary_callback', array());
        }
    }
}
