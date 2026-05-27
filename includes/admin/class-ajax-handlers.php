<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin;

use WebReadyNow\WooCommerceShipStationIntegration\API\API_Client;
use WebReadyNow\WooCommerceShipStationIntegration\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

class Ajax_Handlers {

    public static function register(): void {
        add_action( 'wp_ajax_wsi_wr_test_connection',       [ __CLASS__, 'test_connection' ] );
        add_action( 'wp_ajax_wsi_wr_fetch_inventory_sample', [ __CLASS__, 'fetch_inventory_sample' ] );
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
}
