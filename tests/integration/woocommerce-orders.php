<?php
/**
 * Free-core WooCommerce fixture. Run separately under HPOS and legacy storage.
 *
 * @package NotewareAdminTables
 * @license GPL-2.0-or-later
 */

use Noteware\AdminTables\Integration\WooCommerce\OrderQuery;
use Noteware\AdminTables\Integration\WooCommerce\OrdersPage;

if ('1' !== getenv('NAT_ISOLATED_WOOCOMMERCE_TEST') || ! function_exists('wc_create_order')) {
    throw new RuntimeException('This test requires an explicitly disposable WooCommerce store.');
}
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$originalUser = get_current_user_id();
$originalGet = $_GET;
$user = wp_insert_user(array('user_login' => 'nat_store_' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32), 'role' => 'shop_manager'));
if (is_wp_error($user)) {
    throw new RuntimeException('Could not create the isolated store manager.');
}
$records = array();
try {
    wp_set_current_user($user);
    foreach (array('USD' => '12.34', 'JPY' => '500') as $currency => $total) {
        $order = wc_create_order();
        if (is_wp_error($order)) {
            throw new RuntimeException('Could not create the isolated order.');
        }
        $order->set_currency($currency);
        $order->set_total($total);
        $order->save();
        $records[] = $order;
    }
    // No payment gateway is called. A refund object records source data only.
    $refund = new WC_Order_Refund();
    $refund->set_parent_id($records[0]->get_id());
    $refund->set_currency('USD');
    $refund->set_amount('1.23');
    $refund->set_total('-1.23');
    $refund->save();
    $records[] = $refund;
    $query = new OrderQuery();
    $orders = $query->page();
    $indexed = array_column($orders['rows'], null, 'id');
    foreach (array_slice($records, 0, 2) as $order) {
        $assert(isset($indexed[$order->get_id()]), 'Order missing from the table query.');
        $assert($order->get_total('edit') === $indexed[$order->get_id()]['total'], 'Order money changed during projection.');
        $assert($order->get_currency('edit') === $indexed[$order->get_id()]['currency'], 'Currency changed during projection.');
    }
    $assert(! isset($indexed[$refund->get_id()]), 'Refund was double-counted as an order.');
    $refunds = array_column($query->page(1, 'shop_order_refund')['rows'], null, 'id');
    $assert(isset($refunds[$refund->get_id()]), 'Refund missing from refund table.');
    $assert($refund->get_total('edit') === $refunds[$refund->get_id()]['total'], 'Refund source total changed.');
    $_GET = array();
    ob_start();
    (new OrdersPage($query))->render();
    $html = (string) ob_get_clean();
    $assert(str_contains($html, '<table') && str_contains($html, '12.34') && str_contains($html, 'JPY'), 'The admin consumer did not render the queried rows.');
    wp_set_current_user(0);
    $denied = false;
    try {
        $query->page();
    } catch (RuntimeException) {
        $denied = true;
    }
    $assert($denied, 'Unauthenticated caller could read orders.');
    echo "WooCommerce query, money, refund separation, capability and admin consumer checks passed.\n";
} finally {
    foreach (array_reverse($records) as $record) {
        $record->delete(true);
    }
    $_GET = $originalGet;
    wp_set_current_user($originalUser);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user);
}
