<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class SKU_Alignment_Report {

    public function render(): void {
        $last_run = $this->get_last_run();
        ?>
        <div class="wsi-wr-report-page">
            <h2><?php esc_html_e( 'SKU Alignment Report', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Fetches your current ShipStation inventory and compares it against WooCommerce products by exact SKU. No stock values are changed.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <p>
                <button type="button" id="wsi-wr-run-alignment-report" class="button button-primary">
                    <?php esc_html_e( 'Run SKU Alignment Report', 'woocommerce-shipstation-integration-wr' ); ?>
                </button>
                <span id="wsi-wr-alignment-spinner" class="spinner" style="float:none;visibility:hidden;margin-top:0;vertical-align:middle;"></span>
            </p>

            <div id="wsi-wr-alignment-notice" class="wsi-wr-tool-result"></div>

            <div id="wsi-wr-alignment-results" style="display:none;">
                <h3><?php esc_html_e( 'Report Results', 'woocommerce-shipstation-integration-wr' ); ?></h3>
                <div id="wsi-wr-alignment-summary" class="wsi-wr-summary-box"></div>
                <div id="wsi-wr-alignment-export" style="margin:12px 0;"></div>
                <div id="wsi-wr-alignment-tables"></div>
            </div>

            <?php if ( $last_run ) : ?>
            <hr>
            <h3><?php esc_html_e( 'Previous Report', 'woocommerce-shipstation-integration-wr' ); ?></h3>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: date/time, 2: total records */
                    esc_html__( 'Last run: %1$s — %2$d ShipStation records', 'woocommerce-shipstation-integration-wr' ),
                    esc_html( $last_run['started_at'] ),
                    (int) $last_run['total_records']
                );
                ?>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( $this->csv_export_url( (int) $last_run['id'] ) ); ?>">
                    <?php esc_html_e( 'Download CSV', 'woocommerce-shipstation-integration-wr' ); ?>
                </a>
            </p>
            <table class="widefat striped wsi-wr-prev-run-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Matched (Simple)', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Matched (Variation)', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Unmatched', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Manage Stock Disabled', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Ambiguous', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $last_run['matched_count'] ); ?></td>
                        <td><?php echo esc_html( $this->count_items_by_type( (int) $last_run['id'], 'matched_variation' ) ); ?></td>
                        <td><?php echo esc_html( $last_run['unmatched_count'] ); ?></td>
                        <td><?php echo esc_html( $last_run['manage_stock_disabled_count'] ); ?></td>
                        <td><?php echo esc_html( $last_run['ambiguous_count'] ); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        /* Localise run nonce inline — used only when on this specific page */
        window.WSI_WR_Alignment = {
            nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_run_alignment_report' ) ); ?>',
            export_url_base: '<?php echo esc_js( admin_url( 'admin-post.php?action=wsi_wr_export_alignment_csv' ) ); ?>',
            export_nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_export_alignment_csv' ) ); ?>',
        };
        </script>
        <?php
    }

    private function get_last_run(): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}wsi_wr_sync_runs
             WHERE trigger_type = 'sku_alignment_report'
             ORDER BY started_at DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    private function count_items_by_type( int $run_id, string $result_type ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wsi_wr_sync_items WHERE sync_run_id = %d AND result_type = %s",
                $run_id, $result_type
            )
        );
    }

    private function csv_export_url( int $run_id ): string {
        return add_query_arg( [
            'action' => 'wsi_wr_export_alignment_csv',
            'run_id' => $run_id,
            'nonce'  => wp_create_nonce( 'wsi_wr_export_alignment_csv' ),
        ], admin_url( 'admin-post.php' ) );
    }
}
