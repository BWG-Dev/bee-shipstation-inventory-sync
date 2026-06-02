<?php
/**
 * Uninstall routine for WooCommerce ShipStation Integration WR.
 *
 * Runs when the plugin is deleted through the WordPress admin.
 * Removes all plugin options and database tables.
 * Does NOT remove tracked SKU registry or sync history during routine deactivation —
 * only on full uninstall/delete.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove all plugin options
$option_names = [
    'wsi_wr_db_version',
    'wsi_wr_api_key',
    'wsi_wr_enabled',
    'wsi_wr_dry_run',
    'wsi_wr_missing_sku_handling',
    'wsi_wr_order_protection_window',
    'wsi_wr_schedule_enabled',
    'wsi_wr_schedule_time_1',
    'wsi_wr_schedule_time_2',
    'wsi_wr_schedule_time_3',
    'wsi_wr_logging_enabled',
    'wsi_wr_log_retention_days',
    'wsi_wr_page_size',
    'wsi_wr_request_timeout',
    'woocommerce_wsi_wr_shipstation_settings',
    'wsi_wr_inventory_locations_cache',
    'wsi_wr_export_location_id',
];

foreach ( $option_names as $option ) {
    delete_option( $option );
}

// Remove pending scheduled actions
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions(
        'wsi_wr_shipstation_inventory_pull_run_sync',
        [],
        'woocommerce_shipstation_integration_wr'
    );
}

// Drop custom tables
$tables = [
    $wpdb->prefix . 'wsi_wr_sync_runs',
    $wpdb->prefix . 'wsi_wr_sync_items',
    $wpdb->prefix . 'wsi_wr_tracked_skus',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
