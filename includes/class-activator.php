<?php

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

class Activator {

    public static function activate(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        require_once WSI_WR_PLUGIN_DIR . 'includes/class-db-schema.php';
        DB_Schema::install();

        self::set_defaults();
    }

    private static function set_defaults(): void {
        $defaults = [
            'wsi_wr_enabled'                 => 'no',
            'wsi_wr_dry_run'                 => 'yes',
            'wsi_wr_missing_sku_handling'    => 'report_only',
            'wsi_wr_order_protection_window' => 60,
            'wsi_wr_schedule_enabled'        => 'no',
            'wsi_wr_schedule_time_1'         => '07:00',
            'wsi_wr_schedule_time_2'         => '12:00',
            'wsi_wr_schedule_time_3'         => '18:00',
            'wsi_wr_logging_enabled'         => 'yes',
            'wsi_wr_log_retention_days'      => 30,
            'wsi_wr_page_size'               => 100,
            'wsi_wr_request_timeout'         => 30,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value, '', 'no' );
            }
        }
    }
}
