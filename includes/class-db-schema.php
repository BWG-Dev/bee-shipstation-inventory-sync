<?php

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

class DB_Schema {

    const SCHEMA_VERSION        = '1.0.0';
    const SCHEMA_VERSION_OPTION = 'wsi_wr_db_version';

    public static function install(): void {
        $installed = get_option( self::SCHEMA_VERSION_OPTION, '0.0.0' );
        if ( version_compare( $installed, self::SCHEMA_VERSION, '>=' ) ) {
            return;
        }
        self::create_tables();
        update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // One summary row per sync execution
        dbDelta( "CREATE TABLE {$wpdb->prefix}wsi_wr_sync_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_uuid VARCHAR(36) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            api_mode VARCHAR(16) NOT NULL DEFAULT 'production',
            dry_run TINYINT(1) NOT NULL DEFAULT 1,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            duration_ms INT UNSIGNED NULL,
            api_status VARCHAR(16) NOT NULL DEFAULT 'pending',
            pagination_complete TINYINT(1) NOT NULL DEFAULT 0,
            total_records INT UNSIGNED NOT NULL DEFAULT 0,
            matched_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_count INT UNSIGNED NOT NULL DEFAULT 0,
            unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
            proposed_change_count INT UNSIGNED NOT NULL DEFAULT 0,
            unmatched_count INT UNSIGNED NOT NULL DEFAULT 0,
            manage_stock_disabled_count INT UNSIGNED NOT NULL DEFAULT 0,
            ambiguous_count INT UNSIGNED NOT NULL DEFAULT 0,
            recent_order_protected_count INT UNSIGNED NOT NULL DEFAULT 0,
            possible_zero_stock_count INT UNSIGNED NOT NULL DEFAULT 0,
            zero_applied_count INT UNSIGNED NOT NULL DEFAULT 0,
            warning_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            sanitized_summary TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY run_uuid (run_uuid),
            KEY started_at (started_at),
            KEY trigger_type (trigger_type)
        ) {$charset_collate};" );

        // Per-SKU diagnostic rows per sync run
        dbDelta( "CREATE TABLE {$wpdb->prefix}wsi_wr_sync_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_run_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(255) NOT NULL,
            woocommerce_product_id BIGINT UNSIGNED NULL,
            result_type VARCHAR(32) NOT NULL,
            current_stock INT NULL,
            shipstation_on_hand INT UNSIGNED NULL,
            shipstation_available INT UNSIGNED NULL,
            proposed_stock INT NULL,
            action_taken VARCHAR(32) NULL,
            reason VARCHAR(512) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY sync_run_id (sync_run_id),
            KEY sku (sku(191)),
            KEY result_type (result_type)
        ) {$charset_collate};" );

        // Persistent SKU registry for zero-stock and mapping safety
        dbDelta( "CREATE TABLE {$wpdb->prefix}wsi_wr_tracked_skus (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(255) NOT NULL,
            woocommerce_product_id BIGINT UNSIGNED NULL,
            is_variation TINYINT(1) NOT NULL DEFAULT 0,
            tracking_source VARCHAR(32) NOT NULL DEFAULT 'api_seen',
            sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_returned_available INT UNSIGNED NULL,
            last_returned_on_hand INT UNSIGNED NULL,
            last_seen_at DATETIME NULL,
            first_missing_at DATETIME NULL,
            last_missing_at DATETIME NULL,
            consecutive_missing_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_filter_context_hash VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sku (sku(191)),
            KEY woocommerce_product_id (woocommerce_product_id),
            KEY status (status),
            KEY sync_enabled (sync_enabled)
        ) {$charset_collate};" );
    }
}
