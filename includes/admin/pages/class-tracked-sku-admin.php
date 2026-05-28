<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin\Pages;

use WebReadyNow\WooCommerceShipStationIntegration\Sync\Tracked_SKU_Registry;

defined( 'ABSPATH' ) || exit;

class Tracked_SKU_Admin {

    public function render(): void {
        $registry = new Tracked_SKU_Registry();
        $skus     = $registry->get_all();

        $status_labels = [
            'active'                    => __( 'Active', 'woocommerce-shipstation-integration-wr' ),
            'possible_zero_stock'       => __( 'Possible Zero Stock', 'woocommerce-shipstation-integration-wr' ),
            'confirmed_absent_candidate' => __( 'Confirmed Absent', 'woocommerce-shipstation-integration-wr' ),
            'zero_applied'              => __( 'Zero Applied', 'woocommerce-shipstation-integration-wr' ),
            'paused'                    => __( 'Paused', 'woocommerce-shipstation-integration-wr' ),
            'mapping_error'             => __( 'Mapping Error', 'woocommerce-shipstation-integration-wr' ),
        ];
        ?>
        <div class="wsi-wr-tracked-sku-page">
            <h2><?php esc_html_e( 'Tracked SKU Registry', 'woocommerce-shipstation-integration-wr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'SKUs seen in the ShipStation API or manually approved. Used to safely detect when a previously seen SKU disappears from the API response (possible zero stock).', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>

            <div id="wsi-wr-tracked-sku-notice" class="wsi-wr-tool-result"></div>

            <?php if ( empty( $skus ) ) : ?>
                <p><em><?php esc_html_e( 'No tracked SKUs yet. Run the SKU Alignment Report to populate this list automatically from your ShipStation inventory.', 'woocommerce-shipstation-integration-wr' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped wsi-wr-tracked-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'SKU', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'WC Product ID', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Source', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Last Available', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Last Seen', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Missing Count', 'woocommerce-shipstation-integration-wr' ); ?></th>
                            <th><?php esc_html_e( 'Sync', 'woocommerce-shipstation-integration-wr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $skus as $sku ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $sku['sku'] ); ?></strong></td>
                            <td>
                                <?php if ( $sku['woocommerce_product_id'] ) : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( (int) $sku['woocommerce_product_id'] ) ); ?>" target="_blank">
                                        <?php echo esc_html( $sku['woocommerce_product_id'] ); ?>
                                    </a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo $sku['is_variation'] ? esc_html__( 'Variation', 'woocommerce-shipstation-integration-wr' ) : esc_html__( 'Simple', 'woocommerce-shipstation-integration-wr' ); ?></td>
                            <td><?php echo esc_html( str_replace( '_', ' ', $sku['tracking_source'] ) ); ?></td>
                            <td>
                                <?php
                                $s = $sku['status'];
                                $label = $status_labels[ $s ] ?? esc_html( $s );
                                $class = in_array( $s, [ 'possible_zero_stock', 'confirmed_absent_candidate' ], true ) ? ' style="color:#cc0000;font-weight:600"' : '';
                                echo '<span' . $class . '>' . esc_html( $label ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                ?>
                            </td>
                            <td><?php echo null !== $sku['last_returned_available'] ? esc_html( $sku['last_returned_available'] ) : '&mdash;'; ?></td>
                            <td><?php echo $sku['last_seen_at'] ? esc_html( $sku['last_seen_at'] ) : '&mdash;'; ?></td>
                            <td>
                                <?php if ( (int) $sku['consecutive_missing_count'] > 0 ) : ?>
                                    <strong style="color:#cc0000;"><?php echo esc_html( $sku['consecutive_missing_count'] ); ?></strong>
                                <?php else : ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="button button-small wsi-wr-toggle-sync"
                                    data-id="<?php echo esc_attr( $sku['id'] ); ?>"
                                    data-enabled="<?php echo esc_attr( $sku['sync_enabled'] ); ?>"
                                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsi_wr_toggle_tracked_sku_' . $sku['id'] ) ); ?>"
                                >
                                    <?php echo $sku['sync_enabled'] ? esc_html__( 'Pause', 'woocommerce-shipstation-integration-wr' ) : esc_html__( 'Resume', 'woocommerce-shipstation-integration-wr' ); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h3><?php esc_html_e( 'Manually Approve a SKU', 'woocommerce-shipstation-integration-wr' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Register a SKU that may not appear in the ShipStation API (e.g. products currently at zero stock) so the plugin can track its absence.', 'woocommerce-shipstation-integration-wr' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wsi-wr-approve-sku"><?php esc_html_e( 'SKU', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                    <td><input type="text" id="wsi-wr-approve-sku" class="regular-text" placeholder="<?php esc_attr_e( 'Exact SKU', 'woocommerce-shipstation-integration-wr' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsi-wr-approve-product-id"><?php esc_html_e( 'WooCommerce Product / Variation ID', 'woocommerce-shipstation-integration-wr' ); ?></label></th>
                    <td><input type="number" id="wsi-wr-approve-product-id" class="small-text" min="1"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Type', 'woocommerce-shipstation-integration-wr' ); ?></th>
                    <td>
                        <label><input type="radio" name="wsi-wr-approve-type" value="0" checked> <?php esc_html_e( 'Simple product', 'woocommerce-shipstation-integration-wr' ); ?></label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="wsi-wr-approve-type" value="1"> <?php esc_html_e( 'Variation', 'woocommerce-shipstation-integration-wr' ); ?></label>
                    </td>
                </tr>
            </table>
            <p>
                <button
                    type="button"
                    id="wsi-wr-approve-sku-btn"
                    class="button button-secondary"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsi_wr_approve_tracked_sku' ) ); ?>"
                >
                    <?php esc_html_e( 'Add / Approve SKU', 'woocommerce-shipstation-integration-wr' ); ?>
                </button>
            </p>
        </div>
        <?php
    }
}
