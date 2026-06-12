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

        $server_time = current_time( 'Y-m-d H:i:s' );
        $timezone    = wp_timezone_string();
        ?>
        <div class="wsi-wr-report-page">
            <h2><?php esc_html_e( 'Order Diagnostic', 'woocommerce-shipstation-integration-wr' ); ?></h2>

            <p style="font-size:13px; color:#50575e; margin-top:-8px;">
                <?php esc_html_e( 'Read-only — no data is changed.', 'woocommerce-shipstation-integration-wr' ); ?>
                &nbsp;|&nbsp;
                <?php esc_html_e( 'Server time:', 'woocommerce-shipstation-integration-wr' ); ?>
                <strong><?php echo esc_html( $server_time ); ?></strong>
                <span style="color:#999;">(<?php echo esc_html( $timezone ); ?>)</span>
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

                    <?php if ( ! empty( $result['truncated'] ) ) : ?>
                        <div class="notice notice-warning" style="margin-bottom:16px;">
                            <p><?php esc_html_e( 'More than 500 orders match this window. Only the first 500 are shown. Narrow the date range for a complete view.', 'woocommerce-shipstation-integration-wr' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="wsi-wr-summary-box" style="margin-bottom:24px;">
                        <table style="border-collapse:collapse; min-width:420px;">
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Product', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <strong><?php echo esc_html( $result['product_name'] ); ?></strong>
                                    <span style="color:#999; margin-left:8px;"><?php echo esc_html( $result['sku'] ); ?> &mdash; ID <?php echo (int) $result['product_id']; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Current WC stock', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <?php if ( ! $result['manage_stock'] ) : ?>
                                        <span style="color:#999;"><?php esc_html_e( 'Stock management disabled', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                    <?php elseif ( null === $result['stock_qty'] ) : ?>
                                        <span style="color:#999;"><?php esc_html_e( '—', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                    <?php else : ?>
                                        <strong style="font-size:15px;"><?php echo (int) $result['stock_qty']; ?></strong>
                                        <?php if ( 'yes' === $result['backorders'] ) : ?>
                                            <span style="color:#d63638; margin-left:8px;"><?php esc_html_e( 'Backorders allowed', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                        <?php elseif ( 'notify' === $result['backorders'] ) : ?>
                                            <span style="color:#dba617; margin-left:8px;"><?php esc_html_e( 'Backorders allowed (notify)', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Window', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <strong><?php echo esc_html( $result['since_datetime'] ); ?></strong>
                                    <?php esc_html_e( '→ now', 'woocommerce-shipstation-integration-wr' ); ?>
                                    <span style="color:#999;">(<?php echo esc_html( $timezone ); ?>)</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Total units sold', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <strong style="font-size:15px;"><?php echo (int) $result['total_units']; ?></strong>
                                    <?php if ( ! empty( $result['status_breakdown'] ) ) : ?>
                                        <span style="color:#999; margin-left:8px; font-size:12px;">
                                            <?php
                                            $parts = [];
                                            foreach ( $result['status_breakdown'] as $status => $units ) {
                                                $parts[] = esc_html( $status ) . ': ' . (int) $units;
                                            }
                                            echo implode( ' &nbsp;·&nbsp; ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Awaiting shipment', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <strong style="font-size:15px; color:#00a32a;"><?php echo (int) $result['pending_units']; ?></strong>
                                    <span style="color:#999; margin-left:8px; font-size:12px;"><?php esc_html_e( 'processing + on-hold', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                </td>
                            </tr>
                        </table>
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

        $max_orders = 500;
        $use_hpos   = $this->is_hpos_enabled();

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

        $product      = wc_get_product( $product_id );
        $product_name = $product ? $product->get_name() : '—';
        $stock_qty    = $product ? $product->get_stock_quantity() : null;
        $manage_stock = $product ? $product->get_manage_stock() : false;
        $backorders   = $product ? $product->get_backorders() : 'no';

        // Fetch one extra row to detect truncation without a separate COUNT query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_id, SUM(product_qty) AS qty
                 FROM {$wpdb->prefix}wc_order_product_lookup
                 WHERE ( product_id = %d OR variation_id = %d )
                   AND date_created >= %s
                 GROUP BY order_id
                 LIMIT %d",
                $product_id,
                $product_id,
                $since_datetime,
                $max_orders + 1
            ),
            ARRAY_A
        );

        $truncated = count( $rows ) > $max_orders;
        if ( $truncated ) {
            $rows = array_slice( $rows, 0, $max_orders );
        }

        $empty_base = [
            'sku'              => $sku,
            'product_id'       => $product_id,
            'product_name'     => $product_name,
            'stock_qty'        => $stock_qty,
            'manage_stock'     => $manage_stock,
            'backorders'       => $backorders,
            'since_datetime'   => $since_datetime,
            'total_units'      => 0,
            'pending_units'    => 0,
            'status_breakdown' => [],
            'all_orders'       => [],
            'pending_orders'   => [],
            'truncated'        => false,
        ];

        if ( empty( $rows ) ) {
            return $empty_base;
        }

        $order_qty_map = [];
        foreach ( $rows as $row ) {
            $order_qty_map[ (int) $row['order_id'] ] = (int) $row['qty'];
        }
        $all_order_ids   = array_keys( $order_qty_map );
        $in_placeholders = implode( ',', array_fill( 0, count( $all_order_ids ), '%d' ) );

        // Fetch status, date, and order total for all orders in one query.
        if ( $use_hpos ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $order_meta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id AS order_id, status, date_created_gmt AS date_created, total_amount
                     FROM {$wpdb->prefix}wc_orders
                     WHERE id IN ($in_placeholders)
                       AND type = 'shop_order'",
                    ...$all_order_ids
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $order_meta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID AS order_id, p.post_status AS status, p.post_date AS date_created,
                            pm.meta_value AS total_amount
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
                     WHERE p.ID IN ($in_placeholders)
                       AND p.post_type = 'shop_order'",
                    ...$all_order_ids
                ),
                ARRAY_A
            );
        }

        $order_info_map = [];
        foreach ( $order_meta as $meta ) {
            $status = $meta['status'];
            if ( 'wc-' === substr( $status, 0, 3 ) ) {
                $status = substr( $status, 3 );
            }
            $total = ( isset( $meta['total_amount'] ) && '' !== $meta['total_amount'] )
                ? '$' . number_format( (float) $meta['total_amount'], 2 )
                : '—';
            $order_info_map[ (int) $meta['order_id'] ] = [
                'status' => $status,
                'date'   => $meta['date_created'] ? substr( $meta['date_created'], 0, 16 ) : '?',
                'total'  => $total,
            ];
        }

        $non_cancelled    = [ 'completed', 'processing', 'on-hold', 'refunded' ];
        $pending_set      = [ 'processing', 'on-hold' ];
        $total_units      = 0;
        $pending_units    = 0;
        $all_orders       = [];
        $pending_orders   = [];
        $status_breakdown = [];

        foreach ( $all_order_ids as $oid ) {
            $qty  = $order_qty_map[ $oid ] ?? 0;
            $info = $order_info_map[ $oid ] ?? null;

            if ( ! $info || ! in_array( $info['status'], $non_cancelled, true ) ) {
                continue;
            }

            $total_units += $qty;

            $status_breakdown[ $info['status'] ] = ( $status_breakdown[ $info['status'] ] ?? 0 ) + $qty;

            $edit_url = $use_hpos
                ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid )
                : admin_url( 'post.php?post=' . $oid . '&action=edit' );

            $row = [
                'order_id' => $oid,
                'qty'      => $qty,
                'status'   => $info['status'],
                'date'     => $info['date'],
                'total'    => $info['total'],
                'edit_url' => $edit_url,
            ];
            $all_orders[] = $row;

            if ( in_array( $info['status'], $pending_set, true ) ) {
                $pending_units  += $qty;
                $pending_orders[] = $row;
            }
        }

        return [
            'sku'              => $sku,
            'product_id'       => $product_id,
            'product_name'     => $product_name,
            'stock_qty'        => $stock_qty,
            'manage_stock'     => $manage_stock,
            'backorders'       => $backorders,
            'since_datetime'   => $since_datetime,
            'total_units'      => $total_units,
            'pending_units'    => $pending_units,
            'status_breakdown' => $status_breakdown,
            'all_orders'       => $all_orders,
            'pending_orders'   => $pending_orders,
            'truncated'        => $truncated,
        ];
    }

    private function is_hpos_enabled(): bool {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
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
                    <th><?php esc_html_e( 'Order Total', 'woocommerce-shipstation-integration-wr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $orders as $row ) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( $row['edit_url'] ); ?>" target="_blank">
                                <strong>#<?php echo (int) $row['order_id']; ?></strong>
                            </a>
                        </td>
                        <td><?php echo esc_html( $row['date'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><strong><?php echo (int) $row['qty']; ?></strong></td>
                        <td><?php echo esc_html( $row['total'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
