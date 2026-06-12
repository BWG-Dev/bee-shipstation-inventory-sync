<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class Order_Diagnostic {

    public function render(): void {
        $default_sku  = 'P-74-30ML-TIF';
        $default_date = '2026-06-03T00:00';
        $result       = null;
        $error        = '';

        if ( isset( $_POST['wsi_wr_order_diagnostic_submit'] ) ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wsi_wr_order_diagnostic' ) ) {
                $error = __( 'Security check failed. Please try again.', 'woocommerce-shipstation-integration-wr' );
            } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
                $error = __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' );
            } else {
                $sku        = sanitize_text_field( trim( wp_unslash( $_POST['sku'] ?? '' ) ) );
                $since_raw  = sanitize_text_field( trim( wp_unslash( $_POST['since_date'] ?? '' ) ) );
                $since_date = str_replace( 'T', ' ', $since_raw );

                // Pad to full MySQL datetime.
                if ( 10 === strlen( $since_date ) ) {
                    $since_datetime = $since_date . ' 00:00:00';
                } elseif ( 16 === strlen( $since_date ) ) {
                    $since_datetime = $since_date . ':00';
                } else {
                    $since_datetime = $since_date;
                }

                if ( '' === $sku ) {
                    $error = __( 'SKU is required.', 'woocommerce-shipstation-integration-wr' );
                } elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since_datetime ) ) {
                    $error = __( 'Invalid date/time format.', 'woocommerce-shipstation-integration-wr' );
                } else {
                    $result = $this->run_query( $sku, $since_datetime );
                }

                $default_sku  = $sku;
                $default_date = $since_raw ?: $default_date;
            }
        }
        ?>
        <div class="wsi-wr-report-page">
            <h2><?php esc_html_e( 'Order Diagnostic', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Look up WooCommerce order totals for any SKU since a given date and time. Read-only — no data is changed.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'wsi_wr_order_diagnostic' ); ?>
                <table class="form-table" role="presentation" style="max-width:600px;">
                    <tr>
                        <th scope="row"><label for="wsi-wr-diag-sku"><?php esc_html_e( 'SKU', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                        <td>
                            <input type="text" id="wsi-wr-diag-sku" name="sku" class="regular-text"
                                value="<?php echo esc_attr( $default_sku ); ?>"
                                placeholder="e.g. P-74-30ML-TIF">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsi-wr-diag-since"><?php esc_html_e( 'Orders since', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                        <td>
                            <input type="datetime-local" id="wsi-wr-diag-since" name="since_date" class="regular-text"
                                value="<?php echo esc_attr( $default_date ); ?>">
                            <p class="description"><?php esc_html_e( 'Server local time. Use the plugin go-live date/time, or a specific recount time for reconciliation.', 'woocommerce-shipstation-integration-wr' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="wsi_wr_order_diagnostic_submit" class="button button-primary">
                        <?php esc_html_e( 'Run Diagnostic', 'woocommerce-shipstation-integration-wr' ); ?>
                    </button>
                </p>
            </form>

            <?php if ( null !== $result ) : ?>
                <hr>
                <h3><?php esc_html_e( 'Results', 'woocommerce-shipstation-integration-wr' ); ?></h3>

                <?php if ( isset( $result['error'] ) ) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
                <?php else : ?>

                    <div class="wsi-wr-summary-box" style="margin-bottom:20px;">
                        <ul>
                            <li><?php esc_html_e( 'SKU:', 'woocommerce-shipstation-integration-wr' ); ?> <strong><?php echo esc_html( $result['sku'] ); ?></strong> <?php printf( esc_html__( '(WC product ID: %d)', 'woocommerce-shipstation-integration-wr' ), (int) $result['product_id'] ); ?></li>
                            <li><?php esc_html_e( 'Window:', 'woocommerce-shipstation-integration-wr' ); ?> <strong><?php echo esc_html( $result['since_datetime'] ); ?></strong> <?php esc_html_e( 'to now (server time)', 'woocommerce-shipstation-integration-wr' ); ?></li>
                            <li><strong><?php echo (int) $result['total_units']; ?></strong> <?php esc_html_e( 'total units in non-cancelled orders', 'woocommerce-shipstation-integration-wr' ); ?></li>
                            <li><strong style="color:#00a32a;"><?php echo (int) $result['pending_units']; ?></strong> <?php esc_html_e( 'units awaiting shipment (processing / on-hold)', 'woocommerce-shipstation-integration-wr' ); ?></li>
                        </ul>
                    </div>

                    <h4><?php esc_html_e( 'Orders Awaiting Shipment', 'woocommerce-shipstation-integration-wr' ); ?></h4>
                    <?php $this->render_order_table( $result['pending_orders'], __( 'No orders currently awaiting shipment.', 'woocommerce-shipstation-integration-wr' ) ); ?>

                    <h4 style="margin-top:24px;"><?php esc_html_e( 'All Orders in Window', 'woocommerce-shipstation-integration-wr' ); ?></h4>
                    <?php $this->render_order_table( $result['all_orders'], __( 'No orders found in this window.', 'woocommerce-shipstation-integration-wr' ) ); ?>

                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function run_query( string $sku, string $since_datetime ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $product_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT product_id FROM {$wpdb->prefix}wc_product_meta_lookup WHERE sku = %s LIMIT 1",
                $sku
            )
        );

        if ( ! $product_id ) {
            return [ 'error' => sprintf( __( 'No WooCommerce product found with SKU: %s', 'woocommerce-shipstation-integration-wr' ), $sku ) ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_id, SUM(product_qty) AS qty
                 FROM {$wpdb->prefix}wc_order_product_lookup
                 WHERE ( product_id = %d OR variation_id = %d )
                   AND date_created >= %s
                 GROUP BY order_id",
                $product_id,
                $product_id,
                $since_datetime
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [
                'sku'            => $sku,
                'product_id'     => $product_id,
                'since_datetime' => $since_datetime,
                'total_units'    => 0,
                'pending_units'  => 0,
                'all_orders'     => [],
                'pending_orders' => [],
            ];
        }

        $order_qty_map = [];
        foreach ( $rows as $row ) {
            $order_qty_map[ (int) $row['order_id'] ] = (int) $row['qty'];
        }
        $all_order_ids = array_keys( $order_qty_map );

        $sold_ids = wc_get_orders( [
            'include' => $all_order_ids,
            'status'  => [ 'completed', 'processing', 'on-hold', 'refunded', 'pending' ],
            'limit'   => -1,
            'return'  => 'ids',
            'type'    => 'shop_order',
        ] );

        $pending_ids    = wc_get_orders( [
            'include' => $all_order_ids,
            'status'  => [ 'processing', 'on-hold' ],
            'limit'   => -1,
            'return'  => 'ids',
            'type'    => 'shop_order',
        ] );
        $pending_id_set = array_flip( array_map( 'intval', $pending_ids ) );

        $total_units    = 0;
        $pending_units  = 0;
        $all_orders     = [];
        $pending_orders = [];

        foreach ( $sold_ids as $oid ) {
            $qty   = $order_qty_map[ $oid ] ?? 0;
            $order = wc_get_order( $oid );
            $total_units += $qty;

            $row = [
                'order_id' => $oid,
                'qty'      => $qty,
                'status'   => $order ? $order->get_status() : '?',
                'date'     => ( $order && $order->get_date_created() ) ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '?',
            ];
            $all_orders[] = $row;

            if ( isset( $pending_id_set[ $oid ] ) ) {
                $pending_units += $qty;
                $pending_orders[] = $row;
            }
        }

        return [
            'sku'            => $sku,
            'product_id'     => $product_id,
            'since_datetime' => $since_datetime,
            'total_units'    => $total_units,
            'pending_units'  => $pending_units,
            'all_orders'     => $all_orders,
            'pending_orders' => $pending_orders,
        ];
    }

    private function render_order_table( array $orders, string $empty_message ): void {
        if ( empty( $orders ) ) {
            echo '<p><em>' . esc_html( $empty_message ) . '</em></p>';
            return;
        }
        ?>
        <table class="wsi-wr-result-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order #', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    <th><?php esc_html_e( 'Units', 'woocommerce-shipstation-integration-wr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $orders as $row ) : ?>
                    <tr>
                        <td><strong>#<?php echo (int) $row['order_id']; ?></strong></td>
                        <td><?php echo esc_html( $row['date'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><strong><?php echo (int) $row['qty']; ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
