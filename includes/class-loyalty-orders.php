<?php
/**
 * Loyalty Orders
 *
 * Posts a transaction to the loyalty backend when an order is completed.
 *
 * Event type priority (highest first):
 *   first_order → featured_product_purchase → campaign_coupons → free_shipping → product_purchase
 */

if (!defined('ABSPATH')) {
    exit;
}

class Reloopin_Loyalty_Orders
{

    private Reloopin_Loyalty_API $api;
    private WC_Logger_Interface $logger;

    public function __construct(Reloopin_Loyalty_API $api)
    {
        $this->api = $api;
        $this->logger = wc_get_logger();

        add_action('woocommerce_payment_complete', [$this, 'post_transaction'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'post_transaction'], 10, 1);
    }

    public function post_transaction(int $order_id): void
    {
        reloopin_loyalty_debug("orders: post_transaction triggered", ['order_id' => $order_id]);

        $order = wc_get_order($order_id);

        if (!$order) {
            reloopin_loyalty_debug("orders: order #{$order_id} not found — skipping");
            return;
        }

        if ($order->get_meta('_loyalty_transaction_posted')) {
            reloopin_loyalty_debug("orders: order #{$order_id} already posted — skipping (idempotency)");
            return;
        }

        $customer_email = $order->get_billing_email();

        if (empty($customer_email)) {
            reloopin_loyalty_debug("orders: order #{$order_id} has no billing email — skipping");
            return;
        }

        $event_type = $this->resolve_event_type($order);
        reloopin_loyalty_debug("orders: resolved event_type for order #{$order_id}", $event_type);

        // Build line-item metadata.
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $items[] = [
                'sku' => $product ? ($product->get_sku() ?: (string) $product->get_id()) : '',
                'name' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'price' => (float) $order->get_item_subtotal($item, false, true),
                'featured' => $product ? $product->is_featured() : false,
            ];
        }

        $result = $this->api->create_transaction([
            'customer_email' => $customer_email,
            'customer_phone' => $order->get_billing_phone(),
            'order_id' => (string) $order->get_order_number(),
            'event_type' => $event_type,
            'total_amount' => number_format((float) $order->get_total(), 2, '.', ''),
            'transaction_status' => 'completed',
            'transaction_metadata' => [
                'items' => $items,
                'platform' => 'woocommerce',
            ],
        ]);

        if (is_wp_error($result)) {
            reloopin_loyalty_debug("orders: transaction failed for order #{$order_id}", $result->get_error_message());
            $this->logger->error(
                sprintf('Loyalty: transaction post failed for order #%d — %s', $order_id, $result->get_error_message()),
                ['source' => 'reloopin-loyalty']
            );
            return;
        }

        reloopin_loyalty_debug("orders: transaction posted for order #{$order_id}", [
            'event_type' => $event_type,
            'transaction_id' => $result['id'] ?? 'n/a',
        ]);

        $order->update_meta_data('_loyalty_transaction_posted', 1);
        $order->update_meta_data('_loyalty_transaction_id', $result['id'] ?? '');
        $order->update_meta_data('_loyalty_event_type', $event_type);
        $order->add_order_note(
            sprintf(
                __('reloopin Loyalty: transaction posted (event: %s). Points engine will award points.', 'reloopin-loyalty'),
                $event_type
            )
        );
        $order->save_meta_data();
    }

    // -----------------------------------------------------------------------

    private function resolve_event_type(WC_Order $order): string
    {
        // 1. first_order
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0 && wc_get_customer_order_count($customer_id) === 1) {
            reloopin_loyalty_debug("orders: event_type=first_order (customer #{$customer_id})");
            return 'first_order';
        }

        // 2. featured_product_purchase
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ($product && $product->is_featured()) {
                reloopin_loyalty_debug("orders: event_type=featured_product_purchase (product #{$product->get_id()})");
                return 'featured_product_purchase';
            }
        }

        // 3. campaign_coupons
        $coupons = $order->get_coupon_codes();
        if (count($coupons) > 0) {
            reloopin_loyalty_debug('orders: event_type=campaign_coupons', $coupons);
            return 'campaign_coupons';
        }

        // 4. free_shipping
        foreach ($order->get_shipping_methods() as $shipping_method) {
            /** @var WC_Order_Item_Shipping $shipping_method */
            if ($shipping_method->get_method_id() === 'free_shipping') {
                reloopin_loyalty_debug('orders: event_type=free_shipping');
                return 'free_shipping';
            }
        }

        // 5. fallback
        reloopin_loyalty_debug('orders: event_type=product_purchase (fallback)');
        return 'product_purchase';
    }
}
