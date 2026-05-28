<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin;

defined( 'ABSPATH' ) || exit;

class Admin_Pages {

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menus' ] );
        add_action( 'admin_post_wsi_wr_export_alignment_csv',    [ __CLASS__, 'handle_csv_export' ] );
        add_action( 'admin_post_wsi_wr_export_dry_run_csv',      [ __CLASS__, 'handle_dry_run_csv_export' ] );
        add_action( 'admin_post_wsi_wr_export_manual_sync_csv',  [ __CLASS__, 'handle_manual_sync_csv_export' ] );
    }

    public static function add_menus(): void {
        add_submenu_page(
            'woocommerce',
            __( 'ShipStation Integration WR', 'woocommerce-shipstation-integration-wr' ),
            __( 'ShipStation WR', 'woocommerce-shipstation-integration-wr' ),
            'manage_woocommerce',
            'wsi-wr-reports',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page(): void {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'alignment'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $tabs = [
            'alignment'    => __( 'SKU Alignment Report', 'woocommerce-shipstation-integration-wr' ),
            'dry-run'      => __( 'Dry-Run Sync', 'woocommerce-shipstation-integration-wr' ),
            'manual-sync'  => __( 'Manual Sync', 'woocommerce-shipstation-integration-wr' ),
            'tracked-skus' => __( 'Tracked SKUs', 'woocommerce-shipstation-integration-wr' ),
        ];

        echo '<div class="wrap wsi-wr-admin-wrap">';
        echo '<h1>' . esc_html__( 'ShipStation Integration WR', 'woocommerce-shipstation-integration-wr' ) . '</h1>';

        // Tab navigation
        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ( $tabs as $tab_key => $tab_label ) {
            $url    = add_query_arg( [ 'page' => 'wsi-wr-reports', 'tab' => $tab_key ], admin_url( 'admin.php' ) );
            $active = ( $tab === $tab_key ) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $tab_label ) . '</a>';
        }
        echo '</nav>';

        echo '<div class="wsi-wr-tab-content">';

        if ( 'tracked-skus' === $tab ) {
            require_once WSI_WR_PLUGIN_DIR . 'includes/admin/pages/class-tracked-sku-admin.php';
            ( new Pages\Tracked_SKU_Admin() )->render();
        } elseif ( 'dry-run' === $tab ) {
            require_once WSI_WR_PLUGIN_DIR . 'includes/admin/pages/class-dry-run-report.php';
            ( new Pages\Dry_Run_Report() )->render();
        } elseif ( 'manual-sync' === $tab ) {
            require_once WSI_WR_PLUGIN_DIR . 'includes/admin/pages/class-manual-sync.php';
            ( new Pages\Manual_Sync() )->render();
        } else {
            require_once WSI_WR_PLUGIN_DIR . 'includes/admin/pages/class-sku-alignment-report.php';
            ( new Pages\SKU_Alignment_Report() )->render();
        }

        echo '</div>';
        echo '</div>';
    }

    public static function handle_csv_export(): void {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['nonce'] ), 'wsi_wr_export_alignment_csv' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'woocommerce-shipstation-integration-wr' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
        if ( ! $run_id ) {
            wp_die( esc_html__( 'Invalid run ID.', 'woocommerce-shipstation-integration-wr' ) );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sku, result_type, woocommerce_product_id, current_stock,
                        shipstation_on_hand, shipstation_available, reason
                 FROM {$wpdb->prefix}wsi_wr_sync_items
                 WHERE sync_run_id = %d
                 ORDER BY result_type, sku",
                $run_id
            ),
            ARRAY_A
        );

        if ( empty( $items ) ) {
            wp_die( esc_html__( 'No data found for this report run.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $filename = 'sku-alignment-report-' . date( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        // BOM for Excel UTF-8
        fputs( $output, "\xEF\xBB\xBF" );

        fputcsv( $output, [
            __( 'SKU', 'woocommerce-shipstation-integration-wr' ),
            __( 'Result', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Product ID', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Current Stock', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS On Hand', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS Available', 'woocommerce-shipstation-integration-wr' ),
            __( 'Notes', 'woocommerce-shipstation-integration-wr' ),
        ] );

        foreach ( $items as $item ) {
            fputcsv( $output, [
                $item['sku'],
                $item['result_type'],
                $item['woocommerce_product_id'] ?? '',
                $item['current_stock'] ?? '',
                $item['shipstation_on_hand'] ?? '',
                $item['shipstation_available'] ?? '',
                $item['reason'],
            ] );
        }

        fclose( $output );
        exit;
    }

    public static function handle_dry_run_csv_export(): void {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['nonce'] ), 'wsi_wr_export_dry_run_csv' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'woocommerce-shipstation-integration-wr' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
        if ( ! $run_id ) {
            wp_die( esc_html__( 'Invalid run ID.', 'woocommerce-shipstation-integration-wr' ) );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sku, result_type, woocommerce_product_id, current_stock,
                        shipstation_on_hand, shipstation_available, proposed_stock,
                        action_taken, reason
                 FROM {$wpdb->prefix}wsi_wr_sync_items
                 WHERE sync_run_id = %d
                 ORDER BY result_type, sku",
                $run_id
            ),
            ARRAY_A
        );

        if ( empty( $items ) ) {
            wp_die( esc_html__( 'No data found for this dry-run.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $filename = 'dry-run-report-' . date( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputs( $output, "\xEF\xBB\xBF" );

        fputcsv( $output, [
            __( 'SKU', 'woocommerce-shipstation-integration-wr' ),
            __( 'Proposed Action', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Product ID', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Current Stock', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS On Hand', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS Available', 'woocommerce-shipstation-integration-wr' ),
            __( 'Proposed Stock', 'woocommerce-shipstation-integration-wr' ),
            __( 'Notes', 'woocommerce-shipstation-integration-wr' ),
        ] );

        foreach ( $items as $item ) {
            fputcsv( $output, [
                $item['sku'],
                $item['result_type'],
                $item['woocommerce_product_id'] ?? '',
                $item['current_stock'] ?? '',
                $item['shipstation_on_hand'] ?? '',
                $item['shipstation_available'] ?? '',
                $item['proposed_stock'] ?? '',
                $item['reason'],
            ] );
        }

        fclose( $output );
        exit;
    }

    public static function handle_manual_sync_csv_export(): void {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['nonce'] ), 'wsi_wr_export_manual_sync_csv' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'woocommerce-shipstation-integration-wr' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
        if ( ! $run_id ) {
            wp_die( esc_html__( 'Invalid run ID.', 'woocommerce-shipstation-integration-wr' ) );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sku, result_type, action_taken, woocommerce_product_id, current_stock,
                        shipstation_on_hand, shipstation_available, proposed_stock, reason
                 FROM {$wpdb->prefix}wsi_wr_sync_items
                 WHERE sync_run_id = %d
                 ORDER BY action_taken DESC, result_type, sku",
                $run_id
            ),
            ARRAY_A
        );

        if ( empty( $items ) ) {
            wp_die( esc_html__( 'No data found for this sync run.', 'woocommerce-shipstation-integration-wr' ) );
        }

        $filename = 'manual-sync-' . date( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputs( $output, "\xEF\xBB\xBF" );

        fputcsv( $output, [
            __( 'SKU', 'woocommerce-shipstation-integration-wr' ),
            __( 'Proposed Action', 'woocommerce-shipstation-integration-wr' ),
            __( 'Action Taken', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Product ID', 'woocommerce-shipstation-integration-wr' ),
            __( 'WC Previous Stock', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS On Hand', 'woocommerce-shipstation-integration-wr' ),
            __( 'SS Available', 'woocommerce-shipstation-integration-wr' ),
            __( 'New Stock', 'woocommerce-shipstation-integration-wr' ),
            __( 'Notes', 'woocommerce-shipstation-integration-wr' ),
        ] );

        foreach ( $items as $item ) {
            fputcsv( $output, [
                $item['sku'],
                $item['result_type'],
                $item['action_taken'] ?? '',
                $item['woocommerce_product_id'] ?? '',
                $item['current_stock'] ?? '',
                $item['shipstation_on_hand'] ?? '',
                $item['shipstation_available'] ?? '',
                $item['proposed_stock'] ?? '',
                $item['reason'],
            ] );
        }

        fclose( $output );
        exit;
    }
}
