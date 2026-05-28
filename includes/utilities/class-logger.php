<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Utilities;

defined( 'ABSPATH' ) || exit;

class Logger {

    const SOURCE = 'wsi-wr-shipstation';

    /**
     * Logs a message via WooCommerce logger. Respects the logging_enabled setting.
     * Never log API keys, auth headers, or customer PII.
     */
    public static function log( string $message, string $level = 'info' ): void {
        if ( 'yes' !== \WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration::get_setting( 'logging_enabled', 'yes' ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        wc_get_logger()->log(
            $level,
            $message,
            [ 'source' => self::SOURCE ]
        );
    }

    /**
     * Logs safe API request metadata — endpoint, status, duration, page, record count, and sanitized error.
     * Never includes the API key, full URL with auth, or any customer data.
     */
    public static function api_request(
        string $endpoint,
        int    $http_status,
        int    $duration_ms,
        int    $page = 0,
        int    $total_records = 0,
        string $error = ''
    ): void {
        $context = sprintf(
            'endpoint=%s http_status=%d duration=%dms page=%d records=%d',
            $endpoint,
            $http_status,
            $duration_ms,
            $page,
            $total_records
        );

        if ( '' !== $error ) {
            $context .= sprintf( ' error="%s"', substr( wp_strip_all_tags( $error ), 0, 120 ) );
        }

        $level = ( $http_status >= 400 || '' !== $error ) ? 'error' : 'info';
        self::log( $context, $level );
    }

    /**
     * Logs a sync event summary — safe counters only, no SKU-level customer data.
     */
    public static function sync_summary(
        string $trigger_type,
        bool   $dry_run,
        bool   $success,
        int    $matched,
        int    $updated,
        int    $unchanged,
        int    $unmatched,
        string $error = ''
    ): void {
        $context = sprintf(
            'trigger=%s dry_run=%s success=%s matched=%d updated=%d unchanged=%d unmatched=%d',
            $trigger_type,
            $dry_run ? 'yes' : 'no',
            $success ? 'yes' : 'no',
            $matched,
            $updated,
            $unchanged,
            $unmatched
        );

        if ( '' !== $error ) {
            $context .= sprintf( ' error="%s"', substr( wp_strip_all_tags( $error ), 0, 120 ) );
        }

        $level = $success ? 'info' : 'error';
        self::log( $context, $level );
    }
}
