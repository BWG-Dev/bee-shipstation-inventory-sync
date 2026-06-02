<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\API;

defined( 'ABSPATH' ) || exit;

class API_Client {

    const BASE_URL                      = 'https://api.shipstation.com/v2';
    const INVENTORY_ENDPOINT            = '/inventory';
    const INVENTORY_LOCATIONS_ENDPOINT  = '/inventory_locations';
    const MAX_PAGES                     = 100; // Safety limit — prevents infinite pagination loops

    private string $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = $api_key;
    }

    /**
     * Tests API connectivity with a minimal single-record request.
     * Safe to call any time — read-only, pageSize=1.
     */
    public function test_connection(): array {
        $start  = microtime( true );
        $result = $this->request( [ 'page' => 1, 'pageSize' => 1 ] );
        $result['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

        do_action(
            'wsi_wr_api_connection_tested',
            $result['success'],
            $result['http_status'],
            $result['duration_ms']
        );

        return $result;
    }

    /**
     * Fetches one page of inventory for admin display.
     * Never used for stock sync — display only.
     */
    public function fetch_inventory_sample( int $page_size = 10 ): array {
        $page_size = max( 1, min( 100, (int) apply_filters( 'wsi_wr_api_page_size', $page_size ) ) );
        $start     = microtime( true );
        $result    = $this->request( [ 'page' => 1, 'pageSize' => $page_size ] );
        $result['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
        return $result;
    }

    /**
     * Fetches all inventory pages. Sets pagination_complete=false on any failure.
     * Callers MUST check pagination_complete before evaluating missing-SKU absence.
     *
     * @param array $extra_params Additional query params (e.g. warehouse filter).
     */
    public function fetch_all_inventory( array $extra_params = [] ): array {
        $all_records = [];
        $page        = 1;
        $known_pages = null;
        $total       = 0;
        $start       = microtime( true );
        $page_size   = max( 10, min( 500, (int) apply_filters( 'wsi_wr_api_page_size', 100 ) ) );

        do {
            $params = array_merge( $extra_params, [
                'page'     => $page,
                'pageSize' => $page_size,
            ] );

            $result = $this->request( $params );

            if ( ! $result['success'] ) {
                do_action( 'wsi_wr_api_inventory_fetch_failed', $result['http_status'], $result['error_message'] );
                return [
                    'success'             => false,
                    'pagination_complete' => false,
                    'records'             => $all_records,
                    'total'               => $total,
                    'pages_fetched'       => $page - 1,
                    'pages_total'         => $known_pages,
                    'http_status'         => $result['http_status'],
                    'error_message'       => $result['error_message'],
                    'duration_ms'         => (int) round( ( microtime( true ) - $start ) * 1000 ),
                ];
            }

            $all_records  = array_merge( $all_records, $result['records'] );
            $total        = $result['total'];
            $known_pages  = $result['pages'];
            $page++;

        } while ( $page <= $known_pages && ( $page - 1 ) < self::MAX_PAGES );

        $pagination_complete = ( $known_pages !== null ) && ( ( $page - 1 ) >= $known_pages );

        do_action( 'wsi_wr_api_inventory_fetched', count( $all_records ), $pagination_complete );

        return [
            'success'             => true,
            'pagination_complete' => $pagination_complete,
            'records'             => $all_records,
            'total'               => $total,
            'pages_fetched'       => $page - 1,
            'pages_total'         => $known_pages,
            'http_status'         => 200,
            'error_message'       => '',
            'duration_ms'         => (int) round( ( microtime( true ) - $start ) * 1000 ),
        ];
    }

    /**
     * Makes a single authenticated GET request to the inventory endpoint.
     * Auth header is added AFTER wsi_wr_api_request_args filter to prevent exposure.
     */
    private function request( array $params = [] ): array {
        $base_url = apply_filters( 'wsi_wr_api_base_url', self::BASE_URL );
        $timeout  = max( 5, min( 120, (int) apply_filters( 'wsi_wr_api_timeout', 30 ) ) );
        $url      = rtrim( $base_url, '/' ) . self::INVENTORY_ENDPOINT;

        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        // Filter receives non-auth args only — auth header added below, never passed through filter
        $args = apply_filters( 'wsi_wr_api_request_args', [
            'timeout'    => $timeout,
            'user-agent' => 'WooCommerce ShipStation Integration WR/' . WSI_WR_VERSION,
            'headers'    => [],
        ] );

        // Auth and content-type headers set after filter to prevent exposure through filter callbacks
        $args['headers']['api-key'] = $this->api_key;
        $args['headers']['Accept']  = 'application/json';

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $error_msg = $this->sanitize_error( $response->get_error_message() );
            do_action( 'wsi_wr_api_request_failed', 0, $error_msg );
            return $this->error_result( 0, $error_msg );
        }

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( 401 === $http_status ) {
            $msg = __( 'Authentication failed. Check your ShipStation API key.', 'woocommerce-shipstation-integration-wr' );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        if ( 403 === $http_status ) {
            $msg = __( 'Access forbidden. Confirm your API key has inventory access and the inventory add-on is active in ShipStation.', 'woocommerce-shipstation-integration-wr' );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        if ( 429 === $http_status ) {
            $msg = __( 'ShipStation rate limit reached. The request was not completed.', 'woocommerce-shipstation-integration-wr' );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        if ( $http_status >= 500 ) {
            /* translators: %d HTTP status code */
            $msg = sprintf( __( 'ShipStation server error (HTTP %d). Try again later.', 'woocommerce-shipstation-integration-wr' ), $http_status );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        if ( 200 !== $http_status ) {
            /* translators: %d HTTP status code */
            $msg = sprintf( __( 'Unexpected HTTP response: %d', 'woocommerce-shipstation-integration-wr' ), $http_status );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        $data = json_decode( $body, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
            $msg = __( 'Invalid JSON received from ShipStation API.', 'woocommerce-shipstation-integration-wr' );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        if ( ! isset( $data['inventory'] ) || ! is_array( $data['inventory'] ) ) {
            $msg = __( 'Unexpected response structure: inventory array missing.', 'woocommerce-shipstation-integration-wr' );
            do_action( 'wsi_wr_api_request_failed', $http_status, $msg );
            return $this->error_result( $http_status, $msg );
        }

        $records = $data['inventory'];
        $total   = isset( $data['total'] ) ? (int) $data['total'] : count( $records );
        $page    = isset( $data['page'] )  ? (int) $data['page']  : 1;
        $pages   = isset( $data['pages'] ) ? max( 1, (int) $data['pages'] ) : 1;

        do_action( 'wsi_wr_api_request_success', $http_status, count( $records ), $page, $pages );

        return [
            'success'             => true,
            'pagination_complete' => false, // fetch_all_inventory() determines this across pages
            'records'             => $records,
            'total'               => $total,
            'page'                => $page,
            'pages'               => $pages,
            'http_status'         => $http_status,
            'error_message'       => '',
        ];
    }

    /**
     * Fetches all inventory locations from GET /v2/inventory_locations.
     * Returns ['success', 'locations', 'error_message', 'http_status', 'duration_ms'].
     */
    public function fetch_inventory_locations(): array {
        $start    = microtime( true );
        $base_url = apply_filters( 'wsi_wr_api_base_url', self::BASE_URL );
        $timeout  = max( 5, min( 120, (int) apply_filters( 'wsi_wr_api_timeout', 30 ) ) );
        $url      = rtrim( $base_url, '/' ) . self::INVENTORY_LOCATIONS_ENDPOINT;

        $args = apply_filters( 'wsi_wr_api_request_args', [
            'timeout'    => $timeout,
            'user-agent' => 'WooCommerce ShipStation Integration WR/' . WSI_WR_VERSION,
            'headers'    => [],
        ] );

        $args['headers']['api-key'] = $this->api_key;
        $args['headers']['Accept']  = 'application/json';

        $response    = wp_remote_get( $url, $args );
        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return $this->locations_error( 0, $this->sanitize_error( $response->get_error_message() ), $duration_ms );
        }

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( 401 === $http_status ) {
            return $this->locations_error( $http_status, __( 'Authentication failed. Check your ShipStation API key.', 'woocommerce-shipstation-integration-wr' ), $duration_ms );
        }
        if ( 403 === $http_status ) {
            return $this->locations_error( $http_status, __( 'Access forbidden. Confirm your API key has inventory access.', 'woocommerce-shipstation-integration-wr' ), $duration_ms );
        }
        if ( 429 === $http_status ) {
            return $this->locations_error( $http_status, __( 'ShipStation rate limit reached.', 'woocommerce-shipstation-integration-wr' ), $duration_ms );
        }
        if ( $http_status >= 500 ) {
            /* translators: %d HTTP status code */
            return $this->locations_error( $http_status, sprintf( __( 'ShipStation server error (HTTP %d).', 'woocommerce-shipstation-integration-wr' ), $http_status ), $duration_ms );
        }
        if ( 200 !== $http_status ) {
            /* translators: %d HTTP status code */
            return $this->locations_error( $http_status, sprintf( __( 'Unexpected HTTP response: %d', 'woocommerce-shipstation-integration-wr' ), $http_status ), $duration_ms );
        }

        $data = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
            return $this->locations_error( $http_status, __( 'Invalid JSON received from ShipStation API.', 'woocommerce-shipstation-integration-wr' ), $duration_ms );
        }

        // Try all common ShipStation v2 response structures
        $locations = [];

        // 1. Wrapped with a known key
        foreach ( [ 'inventory_locations', 'locations', 'results', 'items', 'data' ] as $ck ) {
            if ( isset( $data[ $ck ] ) && is_array( $data[ $ck ] ) ) {
                $locations = $data[ $ck ];
                break;
            }
        }

        // 2. Root-level array (response IS the location list)
        if ( empty( $locations ) && isset( $data[0] ) ) {
            $locations = $data;
        }

        // When the response parsed but no locations were found, log the raw body so the
        // structure can be diagnosed from WC → Status → Logs without another code deploy.
        if ( empty( $locations ) && function_exists( 'wc_get_logger' ) ) {
            $structure_keys = implode( ', ', array_keys( $data ) );
            wc_get_logger()->warning(
                sprintf(
                    'inventory_locations_parse_failed: 200_ok top_keys=[%s] body_sample=%s',
                    $structure_keys,
                    substr( wp_strip_all_tags( $body ), 0, 600 )
                ),
                [ 'source' => 'wsi-wr-shipstation' ]
            );
        }

        return [
            'success'       => true,
            'locations'     => $locations,
            'error_message' => '',
            'http_status'   => $http_status,
            'duration_ms'   => $duration_ms,
        ];
    }

    /**
     * Sends a single inventory decrement via POST /v2/inventory.
     *
     * Always uses 'decrement' transaction_type, never 'adjust'.
     * 'decrement' is a relative delta — safe when other channels may update concurrently.
     *
     * Returns ['success', 'http_status', 'response_body', 'error_message', 'duration_ms'].
     */
    public function post_inventory_decrement( array $payload ): array {
        $start    = microtime( true );
        $base_url = apply_filters( 'wsi_wr_api_base_url', self::BASE_URL );
        $timeout  = max( 5, min( 120, (int) apply_filters( 'wsi_wr_api_timeout', 30 ) ) );
        $url      = rtrim( $base_url, '/' ) . self::INVENTORY_ENDPOINT;

        $args = [
            'timeout'    => $timeout,
            'user-agent' => 'WooCommerce ShipStation Integration WR/' . WSI_WR_VERSION,
            'headers'    => [
                'api-key'      => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode( $payload ),
        ];

        $response    = wp_remote_post( $url, $args );
        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return [
                'success'       => false,
                'http_status'   => 0,
                'response_body' => '',
                'error_message' => $this->sanitize_error( $response->get_error_message() ),
                'duration_ms'   => $duration_ms,
            ];
        }

        $http_status   = (int) wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( in_array( $http_status, [ 200, 201, 204 ], true ) ) {
            return [
                'success'       => true,
                'http_status'   => $http_status,
                'response_body' => $response_body,
                'error_message' => '',
                'duration_ms'   => $duration_ms,
            ];
        }

        $error_msg = '';
        if ( 401 === $http_status ) {
            $error_msg = __( 'Authentication failed. Check your ShipStation API key.', 'woocommerce-shipstation-integration-wr' );
        } elseif ( 403 === $http_status ) {
            $error_msg = __( 'Access forbidden. Confirm your API key has inventory write access.', 'woocommerce-shipstation-integration-wr' );
        } elseif ( 429 === $http_status ) {
            $error_msg = __( 'ShipStation rate limit reached.', 'woocommerce-shipstation-integration-wr' );
        } elseif ( $http_status >= 500 ) {
            /* translators: %d HTTP status code */
            $error_msg = sprintf( __( 'ShipStation server error (HTTP %d).', 'woocommerce-shipstation-integration-wr' ), $http_status );
        } else {
            /* translators: %d HTTP status code */
            $error_msg = sprintf( __( 'Unexpected HTTP response: %d', 'woocommerce-shipstation-integration-wr' ), $http_status );
        }

        return [
            'success'       => false,
            'http_status'   => $http_status,
            'response_body' => $response_body,
            'error_message' => $error_msg,
            'duration_ms'   => $duration_ms,
        ];
    }

    private function locations_error( int $http_status, string $message, int $duration_ms = 0 ): array {
        return [
            'success'       => false,
            'locations'     => [],
            'error_message' => $message,
            'http_status'   => $http_status,
            'duration_ms'   => $duration_ms,
        ];
    }

    private function error_result( int $http_status, string $message ): array {
        return [
            'success'             => false,
            'pagination_complete' => false,
            'records'             => [],
            'total'               => 0,
            'page'                => 0,
            'pages'               => 0,
            'http_status'         => $http_status,
            'error_message'       => $message,
        ];
    }

    // Strip potential credential leaks from WP_Error messages (connection strings, URLs)
    private function sanitize_error( string $message ): string {
        return wp_strip_all_tags( substr( $message, 0, 200 ) );
    }
}
