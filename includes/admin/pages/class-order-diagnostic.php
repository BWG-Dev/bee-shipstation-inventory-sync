<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class Order_Diagnostic {

    public function render(): void {
        $default_sku  = 'P-74-30ML-TIF';
        $default_date = '2026-06-03';
        ?>
        <div class="wsi-wr-report-page">
            <h2><?php esc_html_e( 'SKU Order Diagnostic', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Look up WooCommerce order totals for any SKU since a given date. Read-only — no data is changed.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <table class="form-table" role="presentation" style="max-width:600px;">
                <tr>
                    <th scope="row"><label for="wsi-wr-diag-sku"><?php esc_html_e( 'SKU', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                    <td>
                        <input type="text" id="wsi-wr-diag-sku" class="regular-text"
                            value="<?php echo esc_attr( $default_sku ); ?>"
                            placeholder="e.g. P-74-30ML-TIF">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsi-wr-diag-since"><?php esc_html_e( 'Orders since', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                    <td>
                        <input type="datetime-local" id="wsi-wr-diag-since" class="regular-text"
                            value="<?php echo esc_attr( $default_date . 'T00:00' ); ?>">
                        <p class="description"><?php esc_html_e( 'Date and time in the server\'s local timezone. Use the plugin go-live date/time to capture all orders since launch, or a specific recount time for reconciliation.', 'woocommerce-shipstation-integration-wr' ); ?></p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="wsi-wr-run-order-diagnostic" class="button button-primary">
                    <?php esc_html_e( 'Run Diagnostic', 'woocommerce-shipstation-integration-wr' ); ?>
                </button>
                <span id="wsi-wr-diag-spinner" class="spinner" style="float:none;visibility:hidden;margin-top:0;vertical-align:middle;"></span>
            </p>

            <div id="wsi-wr-diag-notice" class="wsi-wr-tool-result"></div>

            <div id="wsi-wr-diag-results" style="display:none;">

                <div id="wsi-wr-diag-summary" class="wsi-wr-summary-box" style="margin-bottom:16px;"></div>

                <h3><?php esc_html_e( 'Orders Awaiting Shipment', 'woocommerce-shipstation-integration-wr' ); ?></h3>
                <div id="wsi-wr-diag-pending-wrap"></div>

                <h3 style="margin-top:24px;"><?php esc_html_e( 'All Orders in Window', 'woocommerce-shipstation-integration-wr' ); ?></h3>
                <div id="wsi-wr-diag-all-wrap"></div>

            </div>
        </div>

        <script type="text/javascript">
        window.WSI_WR_OrderDiagnostic = {
            nonce: '<?php echo esc_js( wp_create_nonce( 'wsi_wr_run_order_diagnostic' ) ); ?>'
        };
        </script>
        <?php
    }
}
