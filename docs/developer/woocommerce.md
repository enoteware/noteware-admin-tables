# WooCommerce order data table

This optional module offers a read-only table of orders or refunds. It uses `wc_get_orders()` and order getters rather than querying either legacy post storage or HPOS tables directly. The menu is absent when WooCommerce is unavailable.

Each row shows record ID, parent order ID, status, exact source total, and currency. Refunds appear in a separate query. Totals are not summed and currencies are never combined. A refund's source total keeps its sign. No formatted price is parsed, converted to a float, or used to calculate revenue.

The query fixes a page size of 50, caps page numbers at 10,000, orders by descending ID, and accepts only order/refund types and registered order statuses. Unknown status values, excessive pages, unexpected record types, duplicate IDs, invalid money, and oversized responses fail closed. Query counts within WooCommerce object hydration still require measurement; a bounded page is not proof of a constant database-query count.

Access requires `edit_shop_orders`. Every order also requires the object's `edit_shop_order` capability. Refunds require permission on the parent order. A denied record rejects the page rather than disclosing partial row data. The screen does not display customer personal information. Read-only filters do not mutate store data.

The consumer uses escaped WordPress table markup, labels, a scrollable keyboard-accessible region, and previous/next links. It needs real browser verification for narrow screens, focus, and light/dark contrast.

## Issue 10 completion matrix

| Scope | Module state |
| --- | --- |
| Orders/refunds | Bounded query and admin consumer implemented; real-store integration not yet verified |
| HPOS/legacy | Public storage-neutral query implemented; both real storage modes unverified |
| Money/currency | Exact source strings, separate currencies, no aggregations; unit contract tests passed |
| Products/variations | Not implemented |
| Coupons/reviews | Not implemented |
| Subscriptions/licensed add-ons | Not implemented; no licensed fixture available or simulated as a parity pass |
| Customer statistics/metrics | Not implemented |
| Generic column/view integration | Not implemented |
| Sort/filter | Fixed ID sort and registered-status filter only |
| Editing/export | Not implemented |
| Realistic variation/refund edge cases | Minimal disposable order/refund fixture supplied but unrun; full matrix remains outstanding |

## Public API references

WooCommerce order querying:
https://developer.woocommerce.com/docs/features/orders/wc-get-orders/

HPOS integration guidance:
https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/

WooCommerce order getter reference:
https://woocommerce.github.io/code-reference/classes/WC-Order.html
