<?php
/**
 * Bounded, storage-independent WooCommerce order reads.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Integration\WooCommerce;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class OrderQuery
{
    public const PAGE_SIZE = 50;

    /** @param Closure(array<string, mixed>): mixed|null $query Optional test gateway. */
    public function __construct(private readonly ?Closure $query = null)
    {
    }

    /** @return array<string, string> */
    public function statuses(): array
    {
        if (! function_exists('wc_get_order_statuses')) {
            return array();
        }
        $statuses = wc_get_order_statuses();
        return is_array($statuses) ? $statuses : array();
    }

    /** @return array{rows: list<array<string, int|string>>, total: int, pages: int, page: int} */
    public function page(int $page = 1, string $type = 'shop_order', string $status = ''): array
    {
        if (! current_user_can('edit_shop_orders')) {
            throw new RuntimeException('You do not have permission to read store orders.');
        }
        if ($page < 1 || $page > 10000 || ! in_array($type, array('shop_order', 'shop_order_refund'), true)) {
            throw new InvalidArgumentException('Invalid order page or record type.');
        }
        if ('' !== $status && ('shop_order_refund' === $type || ! array_key_exists($status, $this->statuses()))) {
            throw new InvalidArgumentException('Choose an available order status.');
        }
        $args = array('limit' => self::PAGE_SIZE, 'paged' => $page, 'paginate' => true, 'return' => 'objects', 'type' => $type, 'orderby' => 'ID', 'order' => 'DESC');
        if ('' !== $status) {
            $args['status'] = $status;
        }
        if (null !== $this->query) {
            $result = ($this->query)($args);
        } else {
            if (! function_exists('wc_get_orders')) {
                throw new RuntimeException('Activate WooCommerce to read store orders.');
            }
                $result = wc_get_orders($args);
        }
        if (! is_object($result) || ! isset($result->orders, $result->total, $result->max_num_pages) || ! is_array($result->orders) || count($result->orders) > self::PAGE_SIZE) {
            throw new RuntimeException('WooCommerce returned an invalid or oversized order page.');
        }
        if (! is_int($result->total) || $result->total < 0 || ! is_int($result->max_num_pages) || $result->max_num_pages < 0) {
            throw new RuntimeException('WooCommerce returned invalid pagination.');
        }
        $rows = array();
        $seen = array();
        foreach ($result->orders as $order) {
            if (! is_object($order)) {
                throw new RuntimeException('WooCommerce returned an invalid order.');
            }
            $row = $this->snapshot($order, $type);
            if (isset($seen[$row['id']])) {
                throw new RuntimeException('WooCommerce returned a duplicate order.');
            }
            $seen[$row['id']] = true;
            $rows[] = $row;
        }
        return array('rows' => $rows, 'total' => $result->total, 'pages' => $result->max_num_pages, 'page' => $page);
    }

    /** @return array<string, int|string> */
    private function snapshot(object $order, string $type): array
    {
        $id = $this->property($order, 'get_id');
        $actualType = $this->property($order, 'get_type');
        $parent = $this->property($order, 'get_parent_id');
        if (! is_int($id) || $id < 1 || $actualType !== $type || ! is_int($parent) || $parent < 0) {
            throw new RuntimeException('WooCommerce returned an unexpected order identity.');
        }
        $permissionId = 'shop_order_refund' === $type ? $parent : $id;
        if ($permissionId < 1 || ! current_user_can('edit_shop_order', $permissionId)) {
            throw new RuntimeException('You do not have permission to read an order in this page.');
        }
        $total = $this->property($order, 'get_total');
        $currency = $this->property($order, 'get_currency');
        $status = $this->property($order, 'get_status');
        if (! is_string($total) || ! preg_match('/^-?\d{1,35}(?:\.\d{1,30})?$/', $total) || ! is_string($currency) || ! preg_match('/^[A-Z]{3,8}$/', $currency) || ! is_string($status) || strlen($status) > 64) {
            throw new RuntimeException('WooCommerce returned an unsupported order value.');
        }
        return array('id' => $id, 'type' => $type, 'parent_id' => $parent, 'total' => $total, 'currency' => $currency, 'status' => $status);
    }

    private function property(object $order, string $method): mixed
    {
        if (! is_callable(array($order, $method))) {
            throw new RuntimeException('The WooCommerce order API is unavailable.');
        }
        // Method names are fixed above, never supplied by a request. Edit
        // context returns source values instead of presentation-filter output.
        return in_array($method, array('get_id', 'get_type'), true)
            ? $order->{$method}()
            : $order->{$method}('edit');
    }
}
