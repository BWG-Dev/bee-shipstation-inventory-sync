<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Sync;

defined( 'ABSPATH' ) || exit;

class Order_Protection {

    /**
     * Returns true if a WooCommerce order containing the given product was placed
     * within the protection window and has a status that may not yet be reflected
     * in ShipStation's available quantity.
     *
     * Uses wc_order_product_lookup (maintained by WooCommerce) for the product→order
     * join, then validates order status through wc_get_orders() which is HPOS-compatible.
     * No customer PII is accessed or stored.
     */
    public function has_recent_order( int $product_id, int $window_minutes ): bool {
        if ( $product_id <= 0 || $window_minutes <= 0 ) {
            return false;
        }

        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * 60 ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT order_id
                 FROM {$wpdb->prefix}wc_order_product_lookup
                 WHERE ( product_id = %d OR variation_id = %d )
                   AND date_created >= %s",
                $product_id,
                $product_id,
                $cutoff
            )
        );

        if ( empty( $order_ids ) ) {
            return false;
        }

        // Confirm at least one of those orders is in an unallocated status.
        $orders = wc_get_orders( [
            'include' => array_map( 'intval', $order_ids ),
            'status'  => [ 'processing', 'pending', 'on-hold' ],
            'limit'   => 1,
            'return'  => 'ids',
        ] );

        return ! empty( $orders );
    }
}
