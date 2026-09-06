<?php
/**
 * Read-only WooCommerce order/refund table consumer.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\Integration\WooCommerce;

use Throwable;

final class OrdersPage
{
    public function __construct(private readonly OrderQuery $query = new OrderQuery())
    {
    }

    public function register(): void
    {
        add_action('admin_menu', array($this, 'menu'));
    }

    public function menu(): void
    {
        if (function_exists('wc_get_orders')) {
            add_submenu_page('woocommerce', __('Order data table', 'noteware-admin-tables'), __('Order data table', 'noteware-admin-tables'), 'edit_shop_orders', 'nat-commerce-orders', array($this, 'render'));
        }
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You do not have permission to read store orders.', 'noteware-admin-tables'), '', array('response' => 403));
        }
        // Read-only filters contain no mutation, personal data, or arbitrary query args.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['nat_page']) && is_string($_GET['nat_page']) ? filter_var(wp_unslash($_GET['nat_page']), FILTER_VALIDATE_INT) : 1;
        $type = isset($_GET['nat_type']) && is_string($_GET['nat_type']) ? sanitize_key(wp_unslash($_GET['nat_type'])) : 'shop_order';
        $status = isset($_GET['nat_status']) && is_string($_GET['nat_status']) ? sanitize_key(wp_unslash($_GET['nat_status'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        try {
            $result = $this->query->page(is_int($page) ? $page : 0, $type, $status);
        } catch (Throwable $error) {
            echo '<div class="wrap"><h1>' . esc_html__('Order data table', 'noteware-admin-tables') . '</h1><div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div></div>';
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Order data table', 'noteware-admin-tables') . '</h1><p>' . esc_html__('Source totals are shown with each record currency. Refunds are listed separately. This table does not calculate sales or combine currencies.', 'noteware-admin-tables') . '</p><form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="nat-commerce-orders"><label>' . esc_html__('Record type', 'noteware-admin-tables') . ' <select name="nat_type">';
        foreach (array('shop_order' => __('Orders', 'noteware-admin-tables'), 'shop_order_refund' => __('Refunds', 'noteware-admin-tables')) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Order status (orders only)', 'noteware-admin-tables') . ' <select name="nat_status"><option value="">' . esc_html__('All statuses', 'noteware-admin-tables') . '</option>';
        foreach ($this->query->statuses() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button">' . esc_html__('Apply', 'noteware-admin-tables') . '</button></form><div role="region" aria-label="' . esc_attr__('Order records', 'noteware-admin-tables') . '" tabindex="0" style="overflow-x:auto"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__('Source order and refund values', 'noteware-admin-tables') . '</caption><thead><tr>';
        foreach (array(__('ID', 'noteware-admin-tables'), __('Parent order', 'noteware-admin-tables'), __('Status', 'noteware-admin-tables'), __('Source total', 'noteware-admin-tables'), __('Currency', 'noteware-admin-tables')) as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            echo '<tr><th scope="row">' . esc_html((string) $row['id']) . '</th><td>' . esc_html((string) $row['parent_id']) . '</td><td>' . esc_html((string) $row['status']) . '</td><td>' . esc_html((string) $row['total']) . '</td><td>' . esc_html((string) $row['currency']) . '</td></tr>';
        }
        if (! $result['rows']) {
            echo '<tr><td colspan="5">' . esc_html__('No records match these filters.', 'noteware-admin-tables') . '</td></tr>';
        }
        echo '</tbody></table></div><nav aria-label="' . esc_attr__('Order pages', 'noteware-admin-tables') . '">';
        foreach (array(-1 => __('Previous', 'noteware-admin-tables'), 1 => __('Next', 'noteware-admin-tables')) as $step => $label) {
            $target = $result['page'] + $step;
            if ($target >= 1 && $target <= min(10000, $result['pages'])) {
                $url = add_query_arg(array('page' => 'nat-commerce-orders', 'nat_page' => $target, 'nat_type' => $type, 'nat_status' => $status), admin_url('admin.php'));
                echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
            }
        }
        echo '</nav><p>' . esc_html(sprintf(__('Page %1$d of %2$d. %3$d records.', 'noteware-admin-tables'), $result['page'], max(1, $result['pages']), $result['total'])) . '</p></div>';
    }
}
