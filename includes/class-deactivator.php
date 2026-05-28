<?php

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

class Deactivator {

    public static function deactivate(): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }
        // Cancel no-args actions (current format) and time_slot-args actions (legacy format)
        as_unschedule_all_actions(
            'wsi_wr_shipstation_inventory_pull_run_sync',
            [],
            'woocommerce_shipstation_integration_wr'
        );
        foreach ( [ '07:00', '12:00', '18:00' ] as $time ) {
            as_unschedule_all_actions(
                'wsi_wr_shipstation_inventory_pull_run_sync',
                [ 'time_slot' => $time ],
                'woocommerce_shipstation_integration_wr'
            );
        }
    }
}
