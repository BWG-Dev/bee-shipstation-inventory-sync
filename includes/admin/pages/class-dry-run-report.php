<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class Dry_Run_Report {

    public function render(): void {
        $last_run = $this->get_last_run();
        ?>
        <div class="wsi-wr-dry-run-page">
            <h2><?php esc_html_e( 'Dry-Run Inventory Sync', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Fetches your current ShipStation inventory, compares it against WooCommerce stock, and calculates what a live sync would do. No stock values are changed.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <?php
            $dry_run_enabled = 'yes' === \WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration::get_setting( 'dry_run', 'yes' );
            if ( ! $dry_run_enabled ) :
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e( 'Dry-run mode is currently disabled in settings. Running this report will still be read-only, but live sync will write to WooCommerce when triggered.', 'woocommerce-shipstation-integration-wr' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p>
                <button type="button" id="wsi-wr-run-dry-run" class="button button-primary">
                    <?php esc_html_e( 'Run Dry-Run Sync', 'woocommerce-shipstation-integration-wr' ); ?>
                </button>
                <span id="wsi-wr-dry-run-spinner" class="spinner" style="float:none;visibility:hidden;margin-top:0;vertical-align:middle;"></span>
            </p>

            <div id="wsi-wr-dry-run-notice" class="wsi-wr-tool-result"></div>

            <div id="wsi-wr-dry-run-results" style="display:none;">
                <h3><?php esc_html_e( 'Dry-Run Results', 'woocommerce-shipstation-integration-wr' ); ?></h3>
                <div id="wsi-wr-dry-run-summary" class="wsi-wr-summary-box"></div>
                <div id="wsi-wr-dry-run-export" style="margin:12px 0;"></div>
                <div id="wsi-wr-dry-run-tables"></div>
            </div>

            <?php if ( $last_run ) : ?>
            <hr>
            <h3><?php esc_html_e( 'Previous Dry-Run', 'woocommerce-shipstation-integration-wr' ); ?></h3>
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
                        <th><?php esc_html_e( 'Proposed Increases', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Proposed Decreases', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'No Change', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Skipped (Order Protection)', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Missing Tracked SKUs', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        <th><?php esc_html_e( 'Unmatched', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $this->count_items_by_action( (int) $last_run['id'], 'proposed_increase' ) ); ?></td>
                        <td><?php echo esc_html( $this->count_items_by_action( (int) $last_run['id'], 'proposed_decrease' ) ); ?></td>
                        <td><?php echo esc_html( $this->count_items_by_action( (int) $last_run['id'], 'no_change' ) ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['recent_order_protected_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['possible_zero_stock_count'] ); ?></td>
                        <td><?php echo esc_html( (int) $last_run['unmatched_count'] ); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        window.WSI_WR_DryRun = {
            nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_run_dry_run' ) ); ?>',
            export_url_base: '<?php echo esc_js( admin_url( 'admin-post.php?action=wsi_wr_export_dry_run_csv' ) ); ?>',
            export_nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_export_dry_run_csv' ) ); ?>',
        };
        </script>
        <?php
    }

    private function get_last_run(): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}wsi_wr_sync_runs
             WHERE trigger_type = 'dry_run_manual'
             ORDER BY started_at DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    private function count_items_by_action( int $run_id, string $result_type ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wsi_wr_sync_items WHERE sync_run_id = %d AND result_type = %s",
                $run_id,
                $result_type
            )
        );
    }

    private function csv_export_url( int $run_id ): string {
        return add_query_arg( [
            'action' => 'wsi_wr_export_dry_run_csv',
            'run_id' => $run_id,
            'nonce'  => wp_create_nonce( 'wsi_wr_export_dry_run_csv' ),
        ], admin_url( 'admin-post.php' ) );
    }
}
