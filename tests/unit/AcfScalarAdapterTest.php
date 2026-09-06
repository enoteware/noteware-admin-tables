<?php
/**
 * Scalar ACF mutation tests with isolated public-API doubles.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace {
    if (! function_exists('get_field_object')) {
        function get_field_object(string $selector, mixed $postId = false, bool $format = true, bool $load = true): array
        {
            return $GLOBALS['nat_acf_scalar_field'];
        }
    }
    if (! function_exists('get_field')) {
        function get_field(string $selector, mixed $postId = false): mixed
        {
            return $GLOBALS['nat_acf_scalar_meta'][$postId][$GLOBALS['nat_acf_scalar_field']['name']] ?? null;
        }
    }
}

namespace Noteware\AdminTables\Adapter {
    if (! function_exists(__NAMESPACE__ . '\\metadata_exists')) {
        function metadata_exists(string $type, int $postId, string $key): bool
        {
            return array_key_exists($key, $GLOBALS['nat_acf_scalar_meta'][$postId] ?? array());
        }
        function get_post_meta(int $postId, string $key, bool $single): mixed
        {
            return $GLOBALS['nat_acf_scalar_meta'][$postId][$key] ?? '';
        }
        function current_user_can(string $cap, mixed ...$args): bool
        {
            return $GLOBALS['nat_acf_scalar_allowed'];
        }
        function get_post_type(int $postId): string
        {
            return 'post';
        }
        function registered_meta_key_exists(string $type, string $key, string $subtype): bool
        {
            return false;
        }
        function has_filter(string $hook): bool
        {
            return false;
        }
        function wp_slash(mixed $value): mixed
        {
            return $value;
        }
        function update_field(string $selector, mixed $value, int $postId): bool
        {
            $name = $GLOBALS['nat_acf_scalar_field']['name'];
            $GLOBALS['nat_acf_scalar_meta'][$postId][$name] = $value;
            $GLOBALS['nat_acf_scalar_meta'][$postId]['_' . $name] = $selector;
            ++$GLOBALS['nat_acf_scalar_writes'];
            return true;
        }
        function delete_field(string $selector, int $postId): bool
        {
            $name = $GLOBALS['nat_acf_scalar_field']['name'];
            unset($GLOBALS['nat_acf_scalar_meta'][$postId][$name], $GLOBALS['nat_acf_scalar_meta'][$postId]['_' . $name]);
            ++$GLOBALS['nat_acf_scalar_writes'];
            return true;
        }
    }
}

namespace Noteware\AdminTables\Tests {
    use InvalidArgumentException;
    use Noteware\AdminTables\Adapter\AcfAdapter;
    use Noteware\AdminTables\Model\ColumnDefinition;
    use Noteware\AdminTables\Model\StoredValue;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class AcfScalarAdapterTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_acf_scalar_field'] = array('name' => 'fixture_scalar', 'type' => 'number', 'required' => false);
            $GLOBALS['nat_acf_scalar_meta'] = array();
            $GLOBALS['nat_acf_scalar_allowed'] = true;
            $GLOBALS['nat_acf_scalar_writes'] = 0;
        }

        private function column(string $type = 'number'): ColumnDefinition
        {
            // The adapter contract is tested separately from the root-owned
            // public editable-type allowlist, which must enable these types.
            return ColumnDefinition::fromArray(array('key' => 'scalar', 'label' => 'Scalar', 'source' => 'acf', 'type' => $type, 'field' => 'fixture_scalar', 'field_key' => 'field_fixture_scalar'));
        }

        public function testNumberRoundTripPreservesPrecisionAndUndoAbsence(): void
        {
            $adapter = new AcfAdapter();
            $column = $this->column();
            $before = $adapter->read(1, $column);
            $value = '123456789012345678901234567890.123456789';
            $adapter->write(1, $column, $adapter->validate($column, $value), $before);
            self::assertSame($value, $adapter->read(1, $column)->value);
            self::assertSame('field_fixture_scalar', $GLOBALS['nat_acf_scalar_meta'][1]['_fixture_scalar']);
            $adapter->restore(1, $column, $adapter->read(1, $column), $before);
            self::assertFalse($adapter->read(1, $column)->exists);
            self::assertArrayNotHasKey('_fixture_scalar', $GLOBALS['nat_acf_scalar_meta'][1]);
        }

        public function testEmptyNumberAndZeroRemainDifferentThroughUndo(): void
        {
            $adapter = new AcfAdapter();
            $column = $this->column();
            $adapter->write(1, $column, $adapter->validate($column, ''), new StoredValue(false, null));
            $empty = $adapter->read(1, $column);
            self::assertSame('empty_string', $empty->state());
            $adapter->write(1, $column, '0', $empty);
            self::assertSame('zero', $adapter->read(1, $column)->state());
            $adapter->restore(1, $column, $adapter->read(1, $column), $empty);
            self::assertTrue($adapter->read(1, $column)->equals($empty));
        }

        public function testBooleanStorageDoesNotCollapseEmptyIntoFalse(): void
        {
            $GLOBALS['nat_acf_scalar_field']['type'] = 'true_false';
            $adapter = new AcfAdapter();
            $column = $this->column('boolean');
            $adapter->write(1, $column, '', new StoredValue(false, null));
            $empty = $adapter->read(1, $column);
            $adapter->write(1, $column, $adapter->validate($column, '0'), $empty);
            self::assertSame('0', $adapter->read(1, $column)->value);
            self::assertNotSame($empty->hash(), $adapter->read(1, $column)->hash());
            $adapter->restore(1, $column, $adapter->read(1, $column), $empty);
            self::assertSame('', $adapter->read(1, $column)->value);
        }

        public function testRequiredBooleanMustBeTrue(): void
        {
            $GLOBALS['nat_acf_scalar_field']['type'] = 'true_false';
            $GLOBALS['nat_acf_scalar_field']['required'] = true;
            $this->expectException(InvalidArgumentException::class);
            (new AcfAdapter())->validate($this->column('boolean'), '0');
        }

        public function testRequiredNumberAllowsZero(): void
        {
            $GLOBALS['nat_acf_scalar_field']['required'] = true;
            self::assertSame('0', (new AcfAdapter())->validate($this->column(), '0'));
        }

        public function testStaleWriteDoesNotReachAcfApi(): void
        {
            $adapter = new AcfAdapter();
            $column = $this->column();
            $adapter->write(1, $column, '1', new StoredValue(false, null));
            try {
                $adapter->write(1, $column, '2', new StoredValue(false, null));
                self::fail('Stale write accepted.');
            } catch (RuntimeException) {
                self::assertSame(1, $GLOBALS['nat_acf_scalar_writes']);
                self::assertSame('1', $adapter->read(1, $column)->value);
            }
        }

        public function testOrphanedReferenceRefusesMutation(): void
        {
            $GLOBALS['nat_acf_scalar_meta'][1]['_fixture_scalar'] = 'field_fixture_scalar';
            $this->expectException(RuntimeException::class);
            (new AcfAdapter())->write(1, $this->column(), '1', new StoredValue(false, null));
        }

        public function testChangedRequiredRuleBlocksUndoToEmpty(): void
        {
            $adapter = new AcfAdapter();
            $column = $this->column();
            $adapter->write(1, $column, '1', new StoredValue(false, null));
            $GLOBALS['nat_acf_scalar_field']['required'] = true;
            $adapter->authorize(1, $column);
            $this->expectException(InvalidArgumentException::class);
            $adapter->restore(1, $column, $adapter->read(1, $column), new StoredValue(true, ''));
        }

        public function testPermissionRevokedBetweenRecordsIsEnforced(): void
        {
            $adapter = new AcfAdapter();
            $adapter->authorize(1, $this->column());
            $GLOBALS['nat_acf_scalar_allowed'] = false;
            $this->expectException(InvalidArgumentException::class);
            $adapter->authorize(2, $this->column());
        }

        public function testLiveBoundsRefreshBetweenRecords(): void
        {
            $adapter = new AcfAdapter();
            $column = $this->column();
            $adapter->authorize(1, $column);
            self::assertSame('12', $adapter->validate($column, '12'));
            $GLOBALS['nat_acf_scalar_field']['max'] = '10';
            $adapter->authorize(2, $column);
            $this->expectException(InvalidArgumentException::class);
            $adapter->validate($column, '12');
        }
    }
}
