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
                        <th scope="row"><label for="wsi-wr-diag-since"><?php esc_html_e( 'Paid or completed since', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                        <td>
                            <input type="datetime-local" id="wsi-wr-diag-since" name="since_date" class="regular-text"
                                value="<?php echo esc_attr( $default_date ); ?>">
                            <p class="description"><?php esc_html_e( 'Shows orders where payment date OR completion date falls on or after this time. This matches what ShipStation decremented: new paid orders and older orders completed/shipped after go-live.', 'woocommerce-shipstation-integration-wr' ); ?></p>
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
                                    <span style="color:#999; margin-left:8px; font-size:12px;">(<?php echo esc_html( $result['product_type'] ); ?>)</span>
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
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'All-time orders (SKU)', 'woocommerce-shipstation-integration-wr' ); ?></td>
                                <td style="padding:4px 0;">
                                    <strong><?php echo (int) $result['all_time_orders']; ?></strong>
                                    <span style="color:#999; margin-left:6px; font-size:12px;"><?php esc_html_e( 'all statuses, no date filter', 'woocommerce-shipstation-integration-wr' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:4px 16px 4px 0; color:#50575e; white-space:nowrap;"><?php esc_html_e( 'Window (paid or completed)', 'woocommerce-shipstation-integration-wr' ); ?></td>
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

                    <details style="margin-bottom:16px; font-size:12px; color:#50575e;">
                        <summary style="cursor:pointer; color:#2271b1;"><?php esc_html_e( 'Query debug info', 'woocommerce-shipstation-integration-wr' ); ?></summary>
                        <table style="margin-top:8px; border-collapse:collapse;">
                            <?php foreach ( $result['debug'] as $key => $val ) : ?>
                                <tr>
                                    <td style="padding:2px 12px 2px 0; font-weight:600; white-space:nowrap;"><?php echo esc_html( $key ); ?></td>
                                    <td style="padding:2px 0; font-family:monospace;"><?php echo esc_html( (string) $val ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </details>

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
        $product_type = $product ? $product->get_type() : '—';
        $id_meta_key  = ( $product && $product->is_type( 'variation' ) ) ? '_variation_id' : '_product_id';

        // Convert local since_datetime to UTC for HPOS (date_created_gmt is UTC).
        $since_ts  = (int) ( new \DateTime( $since_datetime, wp_timezone() ) )->getTimestamp();
        $since_gmt = gmdate( 'Y-m-d H:i:s', $since_ts );
        // For legacy post_date is local time — use since_datetime directly.

        // All-time order count — no date filter — shows scope vs. the window.
        if ( $use_hpos ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $all_time_orders = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT oi.order_id)
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta id_meta
                         ON id_meta.order_item_id = oi.order_item_id
                         AND id_meta.meta_key = %s
                         AND CAST(id_meta.meta_value AS UNSIGNED) = %d
                     INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id AND o.type = 'shop_order'
                     WHERE oi.order_item_type = 'line_item'",
                    $id_meta_key,
                    $product_id
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $all_time_orders = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT oi.order_id)
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta id_meta
                         ON id_meta.order_item_id = oi.order_item_id
                         AND id_meta.meta_key = %s
                         AND CAST(id_meta.meta_value AS UNSIGNED) = %d
                     INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
                     WHERE oi.order_item_type = 'line_item'",
                    $id_meta_key,
                    $product_id
                )
            );
        }

        // Include any order where EITHER the payment date OR the completed date falls within
        // the window. This matches what ShipStation decrements: new orders paid after go-live
        // AND old orders that were shipped/completed after go-live.
        if ( $use_hpos ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT oi.order_id, SUM(CAST(qty_meta.meta_value AS SIGNED)) AS qty
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta id_meta
                         ON id_meta.order_item_id = oi.order_item_id
                         AND id_meta.meta_key = %s
                         AND CAST(id_meta.meta_value AS UNSIGNED) = %d
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty_meta
                         ON qty_meta.order_item_id = oi.order_item_id
                         AND qty_meta.meta_key = '_qty'
                     INNER JOIN {$wpdb->prefix}wc_orders o
                         ON o.id = oi.order_id
                         AND o.type = 'shop_order'
                         AND (
                             ( o.date_paid_gmt IS NOT NULL AND o.date_paid_gmt >= %s )
                             OR
                             ( o.date_completed_gmt IS NOT NULL AND o.date_completed_gmt >= %s )
                         )
                     WHERE oi.order_item_type = 'line_item'
                     GROUP BY oi.order_id
                     LIMIT %d",
                    $id_meta_key,
                    $product_id,
                    $since_gmt,
                    $since_gmt,
                    $max_orders + 1
                ),
                ARRAY_A
            );
        } else {
            // _date_paid and _date_completed are stored as UTC Unix timestamps in postmeta.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT oi.order_id, SUM(CAST(qty_meta.meta_value AS SIGNED)) AS qty
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta id_meta
                         ON id_meta.order_item_id = oi.order_item_id
                         AND id_meta.meta_key = %s
                         AND CAST(id_meta.meta_value AS UNSIGNED) = %d
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty_meta
                         ON qty_meta.order_item_id = oi.order_item_id
                         AND qty_meta.meta_key = '_qty'
                     INNER JOIN {$wpdb->posts} p
                         ON p.ID = oi.order_id
                         AND p.post_type = 'shop_order'
                     LEFT JOIN {$wpdb->postmeta} pm_paid
                         ON pm_paid.post_id = p.ID AND pm_paid.meta_key = '_date_paid'
                     LEFT JOIN {$wpdb->postmeta} pm_completed
                         ON pm_completed.post_id = p.ID AND pm_completed.meta_key = '_date_completed'
                     WHERE oi.order_item_type = 'line_item'
                       AND (
                           ( pm_paid.meta_value IS NOT NULL AND pm_paid.meta_value != ''
                             AND CAST(pm_paid.meta_value AS UNSIGNED) >= %d )
                           OR
                           ( pm_completed.meta_value IS NOT NULL AND pm_completed.meta_value != ''
                             AND CAST(pm_completed.meta_value AS UNSIGNED) >= %d )
                       )
                     GROUP BY oi.order_id
                     LIMIT %d",
                    $id_meta_key,
                    $product_id,
                    $since_ts,
                    $since_ts,
                    $max_orders + 1
                ),
                ARRAY_A
            );
        }

        $truncated  = count( $rows ) > $max_orders;
        $raw_count  = count( $rows );
        $last_error = $wpdb->last_error;
        if ( $truncated ) {
            $rows = array_slice( $rows, 0, $max_orders );
        }

        $debug = [
            'use_hpos'        => $use_hpos ? 'yes' : 'no',
            'product_id'      => $product_id,
            'id_meta_key'     => $id_meta_key,
            'since_local'     => $since_datetime,
            'since_gmt'       => $since_gmt,
            'since_ts'        => $since_ts,
            'all_time_orders' => $all_time_orders,
            'windowed_rows'   => $raw_count,
            'db_error'        => $last_error ?: '(none)',
        ];

        $empty_base = [
            'sku'              => $sku,
            'product_id'       => $product_id,
            'product_name'     => $product_name,
            'product_type'     => $product_type,
            'stock_qty'        => $stock_qty,
            'manage_stock'     => $manage_stock,
            'backorders'       => $backorders,
            'since_datetime'   => $since_datetime,
            'all_time_orders'  => $all_time_orders,
            'total_units'      => 0,
            'pending_units'    => 0,
            'status_breakdown' => [],
            'all_orders'       => [],
            'pending_orders'   => [],
            'truncated'        => false,
            'debug'            => $debug,
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

        // Fetch status, payment date, order total, and order number for all matched orders.
        if ( $use_hpos ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $order_meta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id AS order_id, status, date_paid_gmt AS date_paid, total_amount
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
                    "SELECT p.ID AS order_id, p.post_status AS status,
                            pm_paid.meta_value AS date_paid_ts,
                            pm_total.meta_value AS total_amount,
                            pm_num.meta_value AS order_number
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm_paid
                         ON pm_paid.post_id = p.ID AND pm_paid.meta_key = '_date_paid'
                     LEFT JOIN {$wpdb->postmeta} pm_total
                         ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
                     LEFT JOIN {$wpdb->postmeta} pm_num
                         ON pm_num.post_id = p.ID AND pm_num.meta_key = '_order_number'
                     WHERE p.ID IN ($in_placeholders)
                       AND p.post_type = 'shop_order'",
                    ...$all_order_ids
                ),
                ARRAY_A
            );
        }

        $meta_db_error  = $wpdb->last_error ?: '(none)';
        $order_meta     = is_array( $order_meta ) ? $order_meta : [];
        $debug['meta_query_count'] = count( $order_meta );
        $debug['meta_db_error']    = $meta_db_error;
        $debug['status_sample']    = ! empty( $order_meta ) ? $order_meta[0]['status'] ?? '?' : '(empty)';

        $order_info_map = [];
        foreach ( $order_meta as $meta ) {
            $status = $meta['status'] ?? '';
            if ( 'wc-' === substr( $status, 0, 3 ) ) {
                $status = substr( $status, 3 );
            }

            if ( $use_hpos ) {
                $date_paid = ! empty( $meta['date_paid'] )
                    ? substr( $meta['date_paid'], 0, 16 )
                    : '?';
            } else {
                $date_paid = ! empty( $meta['date_paid_ts'] )
                    ? date_i18n( 'Y-m-d H:i', (int) $meta['date_paid_ts'] )
                    : '?';
            }

            $total = ( isset( $meta['total_amount'] ) && '' !== $meta['total_amount'] )
                ? '$' . number_format( (float) $meta['total_amount'], 2 )
                : '—';

            $oid_int = (int) $meta['order_id'];
            $order_info_map[ $oid_int ] = [
                'status'       => $status,
                'date_paid'    => $date_paid,
                'total'        => $total,
                'order_number' => (string) $oid_int,
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
                'order_id'     => $oid,
                'order_number' => $info['order_number'],
                'qty'          => $qty,
                'status'       => $info['status'],
                'date_paid'    => $info['date_paid'],
                'total'        => $info['total'],
                'edit_url'     => $edit_url,
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
            'product_type'     => $product_type,
            'stock_qty'        => $stock_qty,
            'manage_stock'     => $manage_stock,
            'backorders'       => $backorders,
            'since_datetime'   => $since_datetime,
            'all_time_orders'  => $all_time_orders,
            'total_units'      => $total_units,
            'pending_units'    => $pending_units,
            'status_breakdown' => $status_breakdown,
            'all_orders'       => $all_orders,
            'pending_orders'   => $pending_orders,
            'truncated'        => $truncated,
            'debug'            => $debug,
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
                    <th><?php esc_html_e( 'Paid', 'woocommerce-shipstation-integration-wr' ); ?></th>
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
                                <strong>#<?php echo esc_html( $row['order_number'] ); ?></strong>
                            </a>
                        </td>
                        <td><?php echo esc_html( $row['date_paid'] ); ?></td>
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
