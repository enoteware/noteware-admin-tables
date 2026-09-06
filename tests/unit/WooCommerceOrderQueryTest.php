<?php
/**
 * Order query contracts without treating doubles as WooCommerce parity proof.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Integration\WooCommerce {
    if (! function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability, mixed ...$args): bool
        {
            return $GLOBALS['nat_wc_test_allowed'] && ! in_array($args[0] ?? 0, $GLOBALS['nat_wc_test_denied_ids'], true);
        }
    }
}

namespace Noteware\AdminTables\Tests {
    use InvalidArgumentException;
    use Noteware\AdminTables\Integration\WooCommerce\OrderQuery;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class WooCommerceOrderQueryTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_wc_test_allowed'] = true;
            $GLOBALS['nat_wc_test_denied_ids'] = array();
        }

        private function record(string $total = '12345678901234567890.123456789', string $type = 'shop_order'): object
        {
            return new class ($total, $type) {
                public function __construct(private readonly string $total, private readonly string $type)
                {
                }
                public function get_id(): int
                {
                    return 15;
                }
                public function get_type(): string
                {
                    return $this->type;
                }
                public function get_parent_id(string $context): int
                {
                    return 'shop_order_refund' === $this->type ? 10 : 0;
                }
                public function get_total(string $context): string
                {
                    return $this->total;
                }
                public function get_currency(string $context): string
                {
                    return 'USD';
                }
                public function get_status(string $context): string
                {
                    return 'completed';
                }
            };
        }

        private function query(array $records): OrderQuery
        {
            return new OrderQuery(static fn (array $args) => (object) array('orders' => $records, 'total' => count($records), 'max_num_pages' => 1));
        }

        public function testMoneyIsReturnedWithoutFloatRounding(): void
        {
            $page = $this->query(array($this->record()))->page();
            self::assertSame('12345678901234567890.123456789', $page['rows'][0]['total']);
            self::assertSame('USD', $page['rows'][0]['currency']);
        }

        public function testQueryIsBoundedAndStorageIndependent(): void
        {
            $query = new OrderQuery(static function (array $args): object {
                self::assertSame(50, $args['limit']);
                self::assertSame(2, $args['paged']);
                self::assertSame('shop_order_refund', $args['type']);
                self::assertSame('ID', $args['orderby']);
                self::assertArrayNotHasKey('meta_query', $args);
                self::assertTrue($args['paginate']);
                return (object) array('orders' => array(), 'total' => 0, 'max_num_pages' => 0);
            });
            self::assertSame(array(), $query->page(2, 'shop_order_refund')['rows']);
        }

        public function testOrdersAndRefundsCannotMix(): void
        {
            $this->expectException(RuntimeException::class);
            $this->query(array($this->record('-1.00', 'shop_order_refund')))->page();
        }

        public function testRefundRetainsNegativeTotalAndParentIdentity(): void
        {
            $result = $this->query(array($this->record('-1.00', 'shop_order_refund')))->page(1, 'shop_order_refund');
            self::assertSame('-1.00', $result['rows'][0]['total']);
            self::assertSame(10, $result['rows'][0]['parent_id']);
        }

        public function testCapabilityDenialRunsBeforeQuery(): void
        {
            $GLOBALS['nat_wc_test_allowed'] = false;
            $query = new OrderQuery(static function (): never {
                self::fail('Unauthorized query reached WooCommerce.');
            });
            $this->expectException(RuntimeException::class);
            $query->page();
        }

        public function testRefundRequiresParentOrderCapability(): void
        {
            $GLOBALS['nat_wc_test_denied_ids'] = array(10);
            $this->expectException(RuntimeException::class);
            $this->query(array($this->record('-1.00', 'shop_order_refund')))->page(1, 'shop_order_refund');
        }

        public function testOversizedPagesFailClosed(): void
        {
            $this->expectException(RuntimeException::class);
            $this->query(array_fill(0, 51, $this->record()))->page();
        }

        public function testDuplicateIdsFailClosed(): void
        {
            $this->expectException(RuntimeException::class);
            $this->query(array($this->record(), $this->record()))->page();
        }

        public function testUnknownStatusCannotReachQuery(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->query(array())->page(1, 'shop_order', 'arbitrary');
        }

        public function testUnlimitedOrNegativePageIsRejected(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->query(array())->page(-1);
        }
    }
}
