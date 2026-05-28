<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class Manual_Sync {

    public function render(): void {
        $last_run = $this->get_last_run();
        ?>
        <div class="wsi-wr-manual-sync-page">
            <h2><?php esc_html_e( 'Manual Live Stock Sync', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Fetches your current ShipStation inventory and writes stock quantities directly to WooCommerce products. Run a dry-run first to preview all changes before using this.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e( 'This operation writes to WooCommerce.', 'woocommerce-shipstation-integration-wr' ); ?></strong>
                    <?php esc_html_e( 'Stock will be updated for all matched products. Order-protection checks are applied before any stock increase.', 'woocommerce-shipstation-integration-wr' ); ?>
                </p>
            </div>

            <p>
                <button type="button" id="wsi-wr-run-manual-sync" class="button button-primary">
                    <?php esc_html_e( 'Run Manual Sync', 'woocommerce-shipstation-integration-wr' ); ?>
                </button>
                <span id="wsi-wr-manual-sync-spinner" class="spinner" style="float:none;visibility:hidden;margin-top:0;vertical-align:middle;"></span>
            </p>

            <div id="wsi-wr-manual-sync-notice" class="wsi-wr-tool-result"></div>

            <div id="wsi-wr-manual-sync-results" style="display:none;">
                <h3><?php esc_html_e( 'Sync Results', 'woocommerce-shipstation-integration-wr' ); ?></h3>
                <div id="wsi-wr-manual-sync-summary" class="wsi-wr-summary-box"></div>
                <div id="wsi-wr-manual-sync-export" style="margin:12px 0;"></div>
                <div id="wsi-wr-manual-sync-tables"></div>
            </div>

            <?php if ( $last_run ) : ?>
            <hr>
            <h3><?php esc_html_e( 'Previous Manual Sync', 'woocommerce-shipstation-integration-wr' ); ?></h3>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: date/time, 2: total API records */
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
                        <th><?php esc_html_e( 'Applied', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Failed', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'No Change', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Skipped (Order Protection)', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Missing Tracked SKUs', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Unmatched', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( (int) $last_run['updated_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['error_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['unchanged_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['recent_order_protected_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['possible_zero_stock_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['unmatched_count'] ); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        window.WSI_WR_ManualSync = {
            nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_run_manual_sync' ) ); ?>',
            export_url_base: '<?php echo esc_js( admin_url( 'admin-post.php?action=wsi_wr_export_manual_sync_csv' ) ); ?>',
            export_nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_export_manual_sync_csv' ) ); ?>',
        };
        </script>
        <?php
    }

    private function get_last_run(): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}wsi_wr_sync_runs
             WHERE trigger_type = 'manual_sync'
             ORDER BY started_at DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    private function csv_export_url( int $run_id ): string {
        return add_query_arg( [
            'action' => 'wsi_wr_export_manual_sync_csv',
            'run_id' => $run_id,
            'nonce'  => wp_create_nonce( 'wsi_wr_export_manual_sync_csv' ),
        ], admin_url( 'admin-post.php' ) );
    }
}
