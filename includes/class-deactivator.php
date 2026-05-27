<?php

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

class Deactivator {

    public static function deactivate(): void {
        // Remove only pending scheduled sync actions — preserve all data
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions(
                'wsi_wr_shipstation_inventory_pull_run_sync',
                [],
                'woocommerce_shipstation_integration_wr'
            );
        }
    }
}
