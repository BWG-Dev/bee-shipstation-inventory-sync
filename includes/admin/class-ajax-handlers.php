<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin;

use WebReadyNow\WooCommerceShipStationIntegration\API\API_Client;
use WebReadyNow\WooCommerceShipStationIntegration\Utilities\Logger;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Product_Matcher;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Sync_Service;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Tracked_SKU_Registry;
use WebReadyNow\WooCommerceShipStationIntegration\Export\Inventory_Location_Service;

defined( 'ABSPATH' ) || exit;

class Ajax_Handlers {

    public static function register(): void {
        add_action( 'wp_ajax_wsi_wr_test_connection',         [ __CLASS__, 'test_connection' ] );
        add_action( 'wp_ajax_wsi_wr_fetch_inventory_sample',  [ __CLASS__, 'fetch_inventory_sample' ] );
        add_action( 'wp_ajax_wsi_wr_run_alignment_report',    [ __CLASS__, 'run_alignment_report' ] );
        add_action( 'wp_ajax_wsi_wr_toggle_tracked_sku',      [ __CLASS__, 'toggle_tracked_sku' ] );
        add_action( 'wp_ajax_wsi_wr_approve_tracked_sku',     [ __CLASS__, 'approve_tracked_sku' ] );
        add_action( 'wp_ajax_wsi_wr_run_dry_run',             [ __CLASS__, 'run_dry_run' ] );
        add_action( 'wp_ajax_wsi_wr_run_manual_sync',              [ __CLASS__, 'run_manual_sync' ] );
        add_action( 'wp_ajax_wsi_wr_refresh_inventory_locations',  [ __CLASS__, 'refresh_inventory_locations' ] );
    }

    public static function test_connection(): void {
        check_ajax_referer( 'wsi_wr_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'You do not have permission to perform this action.', 'woocommerce-shipstation-integration-wr' ) ],
                403
            );
        }

        $api_key = Integration::get_api_key();

        if ( '' === $api_key ) {
            wp_send_json_error(
                [ 'message' => __( 'No ShipStation API key is configured. Enter your API key and save settings before testing.', 'woocommerce-shipstation-integration-wr' ) ],
                400
            );
        }

        $client = new API_Client( $api_key );
        $result = $client->test_connection();

        Logger::api_request(
            '/inventory',
            $result['http_status'],
            $result['duration_ms'] ?? 0,
            1,
            count( $result['records'] ?? [] ),
            $result['error_message'] ?? ''
        );

        if ( ! $result['success'] ) {
            wp_send_json_error( [
                'message'     => $result['error_message'],
                'http_status' => $result['http_status'],
            ] );
        }

        $first_record    = $result['records'][0] ?? [];
        $has_available   = isset( $first_record['available'] );

        wp_send_json_success( [
            'message'               => __( 'Connection successful. ShipStation API is accessible.', 'woocommerce-shipstation-integration-wr' ),
            'http_status'           => $result['http_status'],
            'total_records'         => $result['total'],
            'has_available_field'   => $has_available,
            'duration_ms'           => $result['duration_ms'] ?? 0,
        ] );
    }

    public static function fetch_inventory_sample(): void {
        check_ajax_referer( 'wsi_wr_fetch_inventory_sample', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'You do not have permission to perform this action.', 'woocommerce-shipstation-integration-wr' ) ],
                403
            );
        }

        $api_key = Integration::get_api_key();

        if ( '' === $api_key ) {
            wp_send_json_error(
                [ 'message' => __( 'No ShipStation API key is configured. Enter your API key and save settings before fetching inventory.', 'woocommerce-shipstation-integration-wr' ) ],
                400
            );
        }

        $client = new API_Client( $api_key );
        $result = $client->fetch_inventory_sample( 10 );

        Logger::api_request(
            '/inventory',
            $result['http_status'],
            $result['duration_ms'] ?? 0,
            1,
            count( $result['records'] ?? [] ),
            $result['error_message'] ?? ''
        );

        if ( ! $result['success'] ) {
            wp_send_json_error( [
                'message'     => $result['error_message'],
                'http_status' => $result['http_status'],
            ] );
        }

        // Return safe sanitized data only — no credentials, no internal IDs, no customer data
        $safe_records = array_map(
            function ( array $record ): array {
                return [
                    'sku'       => sanitize_text_field( $record['sku'] ?? '' ),
                    'on_hand'   => isset( $record['on_hand'] )   ? (int) $record['on_hand']   : null,
                    'available' => isset( $record['available'] ) ? (int) $record['available'] : null,
                ];
            },
            $result['records']
        );

        $first_record  = $safe_records[0] ?? [];
        $has_available = isset( $first_record['available'] );

        wp_send_json_success( [
            'records'             => $safe_records,
            'total'               => $result['total'],
            'page'                => $result['page'] ?? 1,
            'pages'               => $result['pages'] ?? 1,
            'has_available_field' => $has_available,
            'duration_ms'         => $result['duration_ms'] ?? 0,
        ] );
    }

    // ── Phase 3 handlers ─────────────────────────────────────────────────────

    public static function run_alignment_report(): void {
        check_ajax_referer( 'wsi_wr_run_alignment_report', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) ], 403 );
        }

        $api_key = Integration::get_api_key();
        if ( '' === $api_key ) {
            wp_send_json_error( [ 'message' => __( 'No ShipStation API key is configured.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }

        // Fetch all inventory pages
        $client     = new API_Client( $api_key );
        $api_result = $client->fetch_all_inventory();

        Logger::api_request(
            '/inventory',
            $api_result['http_status'],
            $api_result['duration_ms'] ?? 0,
            $api_result['pages_fetched'] ?? 1,
            count( $api_result['records'] ?? [] ),
            $api_result['error_message'] ?? ''
        );

        // Create sync run record
        global $wpdb;
        $started_at = current_time( 'mysql' );
        $run_uuid   = wp_generate_uuid4();

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'run_uuid'     => $run_uuid,
                'trigger_type' => 'sku_alignment_report',
                'api_mode'     => 'production',
                'dry_run'      => 1,
                'started_at'   => $started_at,
                'api_status'   => $api_result['success'] ? 'success' : 'failed',
                'pagination_complete' => $api_result['pagination_complete'] ? 1 : 0,
                'total_records' => count( $api_result['records'] ?? [] ),
                'created_at'   => $started_at,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
        );
        $run_id = (int) $wpdb->insert_id;

        if ( ! $api_result['success'] ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_runs',
                [
                    'completed_at'    => current_time( 'mysql' ),
                    'sanitized_summary' => sanitize_text_field( $api_result['error_message'] ),
                ],
                [ 'id' => $run_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            wp_send_json_error( [ 'message' => $api_result['error_message'] ] );
        }

        // Match all records
        $auto_enable = 'yes' === Integration::get_setting( 'auto_enable_manage_stock', 'no' );
        $matcher     = new Product_Matcher( $auto_enable );
        $match_results = $matcher->match_inventory_records( $api_result['records'] );

        // Tally counts
        $counts = [
            'matched_simple'          => 0,
            'matched_variation'       => 0,
            'unmatched'               => 0,
            'manage_stock_disabled'   => 0,
            'variation_parent_stock'  => 0,
            'ambiguous'               => 0,
            'skipped_empty_sku'       => 0,
            'skipped_not_published'   => 0,
        ];

        $registry = new Tracked_SKU_Registry();
        $now      = current_time( 'mysql' );

        foreach ( $match_results as $mr ) {
            $rt = $mr['result_type'];
            if ( isset( $counts[ $rt ] ) ) {
                $counts[ $rt ]++;
            }

            // Store sync item
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_items',
                [
                    'sync_run_id'            => $run_id,
                    'sku'                    => $mr['sku'],
                    'woocommerce_product_id' => $mr['product_id'],
                    'result_type'            => $rt,
                    'current_stock'          => $mr['current_stock'],
                    'shipstation_on_hand'    => $mr['shipstation_on_hand'],
                    'shipstation_available'  => $mr['shipstation_available'],
                    'reason'                 => $mr['reason'],
                    'created_at'             => $now,
                ],
                [ '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ]
            );

            // Update tracked SKU registry for confirmed matches
            if ( in_array( $rt, [ 'matched_simple', 'matched_variation' ], true )
                && $mr['product_id']
                && null !== $mr['shipstation_available']
            ) {
                $registry->record_api_seen(
                    $mr['sku'],
                    $mr['product_id'],
                    $mr['is_variation'],
                    (int) $mr['shipstation_available'],
                    (int) ( $mr['shipstation_on_hand'] ?? $mr['shipstation_available'] )
                );
            }
        }

        $matched_total = $counts['matched_simple'] + $counts['matched_variation'];

        // Finalise sync run
        $completed_at = current_time( 'mysql' );
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'completed_at'               => $completed_at,
                'matched_count'              => $matched_total,
                'unmatched_count'            => $counts['unmatched'],
                'manage_stock_disabled_count' => $counts['manage_stock_disabled'],
                'ambiguous_count'            => $counts['ambiguous'],
                'sanitized_summary'          => sprintf( 'matched=%d unmatched=%d ambiguous=%d manage_stock_disabled=%d', $matched_total, $counts['unmatched'], $counts['ambiguous'], $counts['manage_stock_disabled'] ),
            ],
            [ 'id' => $run_id ],
            [ '%s', '%d', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        Logger::sync_summary( 'sku_alignment_report', true, true, $matched_total, 0, 0, $counts['unmatched'] );

        wp_send_json_success( [
            'run_id'  => $run_id,
            'summary' => [
                'total_shipstation_records' => count( $api_result['records'] ),
                'matched_simple'            => $counts['matched_simple'],
                'matched_variation'         => $counts['matched_variation'],
                'unmatched'                 => $counts['unmatched'],
                'manage_stock_disabled'     => $counts['manage_stock_disabled'],
                'ambiguous'                 => $counts['ambiguous'],
                'pagination_complete'       => $api_result['pagination_complete'],
                'duration_ms'              => $api_result['duration_ms'] ?? 0,
            ],
            'results' => array_map( function ( array $mr ): array {
                return [
                    'sku'                   => sanitize_text_field( $mr['sku'] ),
                    'result_type'           => $mr['result_type'],
                    'product_id'            => $mr['product_id'],
                    'is_variation'          => $mr['is_variation'],
                    'current_stock'         => $mr['current_stock'],
                    'shipstation_available' => $mr['shipstation_available'],
                    'shipstation_on_hand'   => $mr['shipstation_on_hand'],
                    'reason'                => $mr['reason'],
                ];
            }, $match_results ),
        ] );
    }

    public static function toggle_tracked_sku(): void {
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || ! check_ajax_referer( 'wsi_wr_toggle_tracked_sku_' . $id, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) ], 403 );
        }

        $enabled  = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        $registry = new Tracked_SKU_Registry();
        $registry->set_sync_enabled( $id, $enabled );

        wp_send_json_success( [
            'id'      => $id,
            'enabled' => $enabled,
            'label'   => $enabled
                ? __( 'Pause', 'woocommerce-shipstation-integration-wr' )
                : __( 'Resume', 'woocommerce-shipstation-integration-wr' ),
        ] );
    }

    public static function approve_tracked_sku(): void {
        check_ajax_referer( 'wsi_wr_approve_tracked_sku', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) ], 403 );
        }

        $sku        = sanitize_text_field( trim( wp_unslash( $_POST['sku'] ?? '' ) ) );
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $is_variation = isset( $_POST['is_variation'] ) && '1' === $_POST['is_variation'];

        if ( '' === $sku ) {
            wp_send_json_error( [ 'message' => __( 'SKU is required.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'A valid WooCommerce product or variation ID is required.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }
        if ( ! wc_get_product( $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No WooCommerce product found with that ID.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }

        $registry = new Tracked_SKU_Registry();
        $success  = $registry->manually_approve( $sku, $product_id, $is_variation );

        if ( ! $success ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save. Check the database or try again.', 'woocommerce-shipstation-integration-wr' ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: SKU string */
                __( 'SKU "%s" has been approved and added to the tracked registry.', 'woocommerce-shipstation-integration-wr' ),
                esc_html( $sku )
            ),
        ] );
    }

    // ── Phase 4 handlers ─────────────────────────────────────────────────────

    public static function run_dry_run(): void {
        check_ajax_referer( 'wsi_wr_run_dry_run', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) ], 403 );
        }

        $api_key = Integration::get_api_key();
        if ( '' === $api_key ) {
            wp_send_json_error( [ 'message' => __( 'No ShipStation API key is configured.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }

        // Fetch all inventory pages.
        $client     = new API_Client( $api_key );
        $api_result = $client->fetch_all_inventory();

        Logger::api_request(
            '/inventory',
            $api_result['http_status'],
            $api_result['duration_ms'] ?? 0,
            $api_result['pages_fetched'] ?? 1,
            count( $api_result['records'] ?? [] ),
            $api_result['error_message'] ?? ''
        );

        // Create sync run record.
        global $wpdb;
        $started_at = current_time( 'mysql' );
        $run_uuid   = wp_generate_uuid4();

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'run_uuid'            => $run_uuid,
                'trigger_type'        => 'dry_run_manual',
                'api_mode'            => 'production',
                'dry_run'             => 1,
                'started_at'          => $started_at,
                'api_status'          => $api_result['success'] ? 'success' : 'failed',
                'pagination_complete' => $api_result['pagination_complete'] ? 1 : 0,
                'total_records'       => count( $api_result['records'] ?? [] ),
                'created_at'          => $started_at,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
        );
        $run_id = (int) $wpdb->insert_id;

        if ( ! $api_result['success'] ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_runs',
                [
                    'completed_at'      => current_time( 'mysql' ),
                    'sanitized_summary' => sanitize_text_field( $api_result['error_message'] ),
                ],
                [ 'id' => $run_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            wp_send_json_error( [ 'message' => $api_result['error_message'] ] );
        }

        // Match all records, then evaluate proposed actions.
        $auto_enable   = 'yes' === Integration::get_setting( 'auto_enable_manage_stock', 'no' );
        $matcher       = new Product_Matcher( $auto_enable );
        $match_results = $matcher->match_inventory_records( $api_result['records'] );

        $service      = new Sync_Service();
        $eval_results = $service->evaluate( $match_results, (bool) $api_result['pagination_complete'] );

        // Tally counts.
        $counts = [
            'proposed_increase'               => 0,
            'proposed_decrease'               => 0,
            'no_change'                       => 0,
            'skipped_recent_order'            => 0,
            'skipped_unmatched'               => 0,
            'skipped_manage_stock_disabled'   => 0,
            'skipped_variation_parent_stock'  => 0,
            'skipped_not_published'           => 0,
            'skipped_ambiguous'               => 0,
            'skipped_empty_sku'               => 0,
            'skipped_no_available'            => 0,
            'missing_tracked_sku'             => 0,
            'proposed_zero'                   => 0,
        ];

        $registry = new Tracked_SKU_Registry();
        $now      = current_time( 'mysql' );

        foreach ( $eval_results as $item ) {
            $action = $item['proposed_action'];
            if ( isset( $counts[ $action ] ) ) {
                $counts[ $action ]++;
            }

            // Store sync item row.
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_items',
                [
                    'sync_run_id'            => $run_id,
                    'sku'                    => $item['sku'],
                    'woocommerce_product_id' => $item['product_id'],
                    'result_type'            => $action,
                    'current_stock'          => $item['current_stock'],
                    'shipstation_on_hand'    => $item['shipstation_on_hand'],
                    'shipstation_available'  => $item['shipstation_available'],
                    'proposed_stock'         => $item['proposed_stock'],
                    'action_taken'           => 'dry_run',
                    'reason'                 => $item['reason'],
                    'created_at'             => $now,
                ],
                [ '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
            );

            // Update tracked SKU registry.
            if ( in_array( $item['match_type'], [ 'matched_simple', 'matched_variation' ], true )
                && $item['product_id']
                && null !== $item['shipstation_available']
            ) {
                $registry->record_api_seen(
                    $item['sku'],
                    (int) $item['product_id'],
                    (bool) $item['is_variation'],
                    (int) $item['shipstation_available'],
                    (int) ( $item['shipstation_on_hand'] ?? $item['shipstation_available'] )
                );
            }

            if ( $api_result['pagination_complete']
                && in_array( $action, [ 'missing_tracked_sku', 'proposed_zero' ], true )
            ) {
                $registry->record_missing( $item['sku'] );
            }
        }

        // Derived totals.
        $matched_total         = count( array_filter( $eval_results, fn( $i ) => in_array( $i['match_type'], [ 'matched_simple', 'matched_variation' ], true ) ) );
        $proposed_change_total = $counts['proposed_increase'] + $counts['proposed_decrease'] + $counts['proposed_zero'];

        // Finalise sync run.
        $completed_at = current_time( 'mysql' );
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'completed_at'                => $completed_at,
                'matched_count'               => $matched_total,
                'unchanged_count'             => $counts['no_change'],
                'proposed_change_count'       => $proposed_change_total,
                'unmatched_count'             => $counts['skipped_unmatched'],
                'manage_stock_disabled_count' => $counts['skipped_manage_stock_disabled'],
                'ambiguous_count'             => $counts['skipped_ambiguous'],
                'recent_order_protected_count' => $counts['skipped_recent_order'],
                'possible_zero_stock_count'   => $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                'sanitized_summary'           => sprintf(
                    'increase=%d decrease=%d no_change=%d order_protection=%d missing_tracked=%d unmatched=%d',
                    $counts['proposed_increase'],
                    $counts['proposed_decrease'],
                    $counts['no_change'],
                    $counts['skipped_recent_order'],
                    $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                    $counts['skipped_unmatched']
                ),
            ],
            [ 'id' => $run_id ],
            [ '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        Logger::sync_summary( 'dry_run_manual', true, true, $matched_total, 0, $proposed_change_total, $counts['skipped_unmatched'] );

        wp_send_json_success( [
            'run_id'  => $run_id,
            'summary' => [
                'total_shipstation_records' => count( $api_result['records'] ),
                'proposed_increase'         => $counts['proposed_increase'],
                'proposed_decrease'         => $counts['proposed_decrease'],
                'no_change'                 => $counts['no_change'],
                'skipped_recent_order'      => $counts['skipped_recent_order'],
                'skipped_unmatched'         => $counts['skipped_unmatched'],
                'skipped_manage_stock_disabled' => $counts['skipped_manage_stock_disabled'],
                'skipped_not_published'     => $counts['skipped_not_published'],
                'skipped_ambiguous'         => $counts['skipped_ambiguous'],
                'missing_tracked_sku'       => $counts['missing_tracked_sku'],
                'proposed_zero'             => $counts['proposed_zero'],
                'pagination_complete'       => $api_result['pagination_complete'],
                'duration_ms'               => $api_result['duration_ms'] ?? 0,
            ],
            'results' => array_map( function ( array $item ): array {
                return [
                    'sku'                   => sanitize_text_field( $item['sku'] ),
                    'match_type'            => $item['match_type'],
                    'proposed_action'       => $item['proposed_action'],
                    'product_id'            => $item['product_id'],
                    'is_variation'          => $item['is_variation'],
                    'current_stock'         => $item['current_stock'],
                    'shipstation_available' => $item['shipstation_available'],
                    'shipstation_on_hand'   => $item['shipstation_on_hand'],
                    'proposed_stock'        => $item['proposed_stock'],
                    'reason'                => $item['reason'],
                ];
            }, $eval_results ),
        ] );
    }

    // ── Export handlers ───────────────────────────────────────────────────────

    public static function refresh_inventory_locations(): void {
        check_ajax_referer( 'wsi_wr_refresh_inventory_locations', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'You do not have permission to perform this action.', 'woocommerce-shipstation-integration-wr' ) ],
                403
            );
        }

        $location_service = new Inventory_Location_Service();
        $result           = $location_service->fetch_and_cache();

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error_message'] ] );
        }

        $locations = $result['locations'];
        $count     = count( $locations );

        if ( 0 === $count ) {
            wp_send_json_error( [
                'message' => __( 'ShipStation returned 0 inventory locations. Check WooCommerce → Status → Logs (source: wsi-wr-shipstation) for the raw response structure.', 'woocommerce-shipstation-integration-wr' ),
            ] );
        }

        wp_send_json_success( [
            'locations'            => $locations,
            'selected_location_id' => $location_service->get_selected_location_id(),
            'auto_selected'        => $result['auto_selected'],
            /* translators: %d number of locations found */
            'message'              => sprintf( _n( '%d inventory location loaded.', '%d inventory locations loaded.', $count, 'woocommerce-shipstation-integration-wr' ), $count ),
        ] );
    }

    // ── Phase 5 handlers ─────────────────────────────────────────────────────

    public static function run_manual_sync(): void {
        check_ajax_referer( 'wsi_wr_run_manual_sync', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-shipstation-integration-wr' ) ], 403 );
        }

        $api_key = Integration::get_api_key();
        if ( '' === $api_key ) {
            wp_send_json_error( [ 'message' => __( 'No ShipStation API key is configured.', 'woocommerce-shipstation-integration-wr' ) ], 400 );
        }

        // Fetch all inventory pages.
        $client     = new API_Client( $api_key );
        $api_result = $client->fetch_all_inventory();

        Logger::api_request(
            '/inventory',
            $api_result['http_status'],
            $api_result['duration_ms'] ?? 0,
            $api_result['pages_fetched'] ?? 1,
            count( $api_result['records'] ?? [] ),
            $api_result['error_message'] ?? ''
        );

        global $wpdb;
        $started_at = current_time( 'mysql' );
        $run_uuid   = wp_generate_uuid4();

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'run_uuid'            => $run_uuid,
                'trigger_type'        => 'manual_sync',
                'api_mode'            => 'production',
                'dry_run'             => 0,
                'started_at'          => $started_at,
                'api_status'          => $api_result['success'] ? 'success' : 'failed',
                'pagination_complete' => $api_result['pagination_complete'] ? 1 : 0,
                'total_records'       => count( $api_result['records'] ?? [] ),
                'created_at'          => $started_at,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
        );
        $run_id = (int) $wpdb->insert_id;

        if ( ! $api_result['success'] ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_runs',
                [
                    'completed_at'      => current_time( 'mysql' ),
                    'sanitized_summary' => sanitize_text_field( $api_result['error_message'] ),
                ],
                [ 'id' => $run_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            wp_send_json_error( [ 'message' => $api_result['error_message'] ] );
        }

        // Match, evaluate, then apply live stock writes.
        $auto_enable   = 'yes' === Integration::get_setting( 'auto_enable_manage_stock', 'no' );
        $matcher       = new Product_Matcher( $auto_enable );
        $match_results = $matcher->match_inventory_records( $api_result['records'] );

        $service      = new Sync_Service();
        $eval_results = $service->evaluate( $match_results, (bool) $api_result['pagination_complete'] );
        $eval_results = $service->apply( $eval_results );

        $counts = [
            'proposed_increase'              => 0,
            'proposed_decrease'              => 0,
            'no_change'                      => 0,
            'skipped_recent_order'           => 0,
            'skipped_unmatched'              => 0,
            'skipped_manage_stock_disabled'  => 0,
            'skipped_variation_parent_stock' => 0,
            'skipped_not_published'          => 0,
            'skipped_ambiguous'              => 0,
            'skipped_empty_sku'              => 0,
            'skipped_no_available'           => 0,
            'missing_tracked_sku'            => 0,
            'proposed_zero'                  => 0,
        ];
        $applied_count = 0;
        $failed_count  = 0;

        $registry = new Tracked_SKU_Registry();
        $now      = current_time( 'mysql' );

        foreach ( $eval_results as $item ) {
            $action = $item['proposed_action'];
            if ( isset( $counts[ $action ] ) ) {
                $counts[ $action ]++;
            }
            if ( 'applied' === ( $item['action_taken'] ?? '' ) ) {
                $applied_count++;
            } elseif ( 'failed' === ( $item['action_taken'] ?? '' ) ) {
                $failed_count++;
            }

            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prefix . 'wsi_wr_sync_items',
                [
                    'sync_run_id'            => $run_id,
                    'sku'                    => $item['sku'],
                    'woocommerce_product_id' => $item['product_id'],
                    'result_type'            => $action,
                    'current_stock'          => $item['current_stock'],
                    'shipstation_on_hand'    => $item['shipstation_on_hand'],
                    'shipstation_available'  => $item['shipstation_available'],
                    'proposed_stock'         => $item['proposed_stock'],
                    'action_taken'           => $item['action_taken'] ?? 'skipped',
                    'reason'                 => $item['reason'],
                    'created_at'             => $now,
                ],
                [ '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
            );

            if ( in_array( $item['match_type'], [ 'matched_simple', 'matched_variation' ], true )
                && $item['product_id']
                && null !== $item['shipstation_available']
            ) {
                $registry->record_api_seen(
                    $item['sku'],
                    (int) $item['product_id'],
                    (bool) $item['is_variation'],
                    (int) $item['shipstation_available'],
                    (int) ( $item['shipstation_on_hand'] ?? $item['shipstation_available'] )
                );
            }

            if ( $api_result['pagination_complete']
                && in_array( $action, [ 'missing_tracked_sku', 'proposed_zero' ], true )
            ) {
                $registry->record_missing( $item['sku'] );
            }
        }

        $matched_total         = count( array_filter( $eval_results, fn( $i ) => in_array( $i['match_type'], [ 'matched_simple', 'matched_variation' ], true ) ) );
        $proposed_change_total = $counts['proposed_increase'] + $counts['proposed_decrease'] + $counts['proposed_zero'];

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'completed_at'                => current_time( 'mysql' ),
                'matched_count'               => $matched_total,
                'updated_count'               => $applied_count,
                'unchanged_count'             => $counts['no_change'],
                'proposed_change_count'       => $proposed_change_total,
                'unmatched_count'             => $counts['skipped_unmatched'],
                'manage_stock_disabled_count' => $counts['skipped_manage_stock_disabled'],
                'ambiguous_count'             => $counts['skipped_ambiguous'],
                'recent_order_protected_count' => $counts['skipped_recent_order'],
                'possible_zero_stock_count'   => $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                'error_count'                 => $failed_count,
                'sanitized_summary'           => sprintf(
                    'applied=%d failed=%d no_change=%d order_protection=%d missing_tracked=%d unmatched=%d',
                    $applied_count,
                    $failed_count,
                    $counts['no_change'],
                    $counts['skipped_recent_order'],
                    $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                    $counts['skipped_unmatched']
                ),
            ],
            [ 'id' => $run_id ],
            [ '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        Logger::sync_summary( 'manual_sync', false, true, $matched_total, $applied_count, $proposed_change_total, $counts['skipped_unmatched'] );

        wp_send_json_success( [
            'run_id'  => $run_id,
            'summary' => [
                'total_shipstation_records'     => count( $api_result['records'] ),
                'applied'                       => $applied_count,
                'failed'                        => $failed_count,
                'proposed_increase'             => $counts['proposed_increase'],
                'proposed_decrease'             => $counts['proposed_decrease'],
                'no_change'                     => $counts['no_change'],
                'skipped_recent_order'          => $counts['skipped_recent_order'],
                'skipped_unmatched'             => $counts['skipped_unmatched'],
                'skipped_manage_stock_disabled' => $counts['skipped_manage_stock_disabled'],
                'skipped_not_published'         => $counts['skipped_not_published'],
                'skipped_ambiguous'             => $counts['skipped_ambiguous'],
                'missing_tracked_sku'           => $counts['missing_tracked_sku'],
                'proposed_zero'                 => $counts['proposed_zero'],
                'pagination_complete'           => $api_result['pagination_complete'],
                'duration_ms'                   => $api_result['duration_ms'] ?? 0,
            ],
            'results' => array_map( function ( array $item ): array {
                return [
                    'sku'                   => sanitize_text_field( $item['sku'] ),
                    'match_type'            => $item['match_type'],
                    'proposed_action'       => $item['proposed_action'],
                    'action_taken'          => $item['action_taken'] ?? 'skipped',
                    'product_id'            => $item['product_id'],
                    'is_variation'          => $item['is_variation'],
                    'current_stock'         => $item['current_stock'],
                    'shipstation_available' => $item['shipstation_available'],
                    'shipstation_on_hand'   => $item['shipstation_on_hand'],
                    'proposed_stock'        => $item['proposed_stock'],
                    'reason'                => $item['reason'],
                ];
            }, $eval_results ),
        ] );
    }
}
