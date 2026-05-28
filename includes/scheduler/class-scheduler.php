<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Scheduler;

use WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration;
use WebReadyNow\WooCommerceShipStationIntegration\API\API_Client;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Product_Matcher;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Sync_Service;
use WebReadyNow\WooCommerceShipStationIntegration\Sync\Tracked_SKU_Registry;
use WebReadyNow\WooCommerceShipStationIntegration\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

class Scheduler {

    const HOOK  = 'wsi_wr_shipstation_inventory_pull_run_sync';
    const GROUP = 'woocommerce_shipstation_integration_wr';

    /**
     * Registers the Action Scheduler callback hook.
     */
    public static function register(): void {
        add_action( self::HOOK, [ __CLASS__, 'run_sync' ] );
    }

    /**
     * Creates (or replaces) three daily recurring actions at the configured times.
     * Always unschedules first, then re-schedules only if the schedule is enabled.
     * Hooked to the WC Integration settings save action.
     */
    public static function schedule(): void {
        self::unschedule();

        if ( 'yes' !== Integration::get_setting( 'schedule_enabled', 'no' ) ) {
            return;
        }

        $times = [
            Integration::get_setting( 'schedule_time_1', '07:00' ),
            Integration::get_setting( 'schedule_time_2', '12:00' ),
            Integration::get_setting( 'schedule_time_3', '18:00' ),
        ];

        foreach ( $times as $time ) {
            if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
                continue;
            }
            as_schedule_recurring_action(
                self::next_timestamp_for_time( $time ),
                DAY_IN_SECONDS,
                self::HOOK,
                [],
                self::GROUP
            );
        }
    }

    /**
     * Cancels all pending recurring actions for this plugin's sync hook.
     *
     * Cancels both no-args actions (current registration format) and time_slot-args
     * actions (legacy format used before this fix) to ensure a clean slate regardless
     * of which Action Scheduler version is installed — older versions only cancel
     * no-arg actions when $args = [] is passed.
     */
    public static function unschedule(): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }
        as_unschedule_all_actions( self::HOOK, [], self::GROUP );
        $times = array_unique( [
            '07:00', '12:00', '18:00',
            Integration::get_setting( 'schedule_time_1', '07:00' ),
            Integration::get_setting( 'schedule_time_2', '12:00' ),
            Integration::get_setting( 'schedule_time_3', '18:00' ),
        ] );
        foreach ( $times as $time ) {
            if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
                as_unschedule_all_actions( self::HOOK, [ 'time_slot' => $time ], self::GROUP );
            }
        }
    }

    /**
     * Scheduled sync callback — mirrors the manual sync pipeline.
     *
     * Respects wsi_wr_dry_run_mode: when enabled, evaluates but does not write stock.
     * Records a sync_run row with trigger_type 'scheduled' or 'scheduled_dry_run'.
     */
    public static function run_sync(): void {
        if ( 'yes' !== Integration::get_setting( 'enabled', 'no' ) ) {
            return;
        }

        $api_key = Integration::get_api_key();
        if ( '' === $api_key ) {
            Logger::log( 'Scheduled sync skipped: no ShipStation API key configured.', 'error' );
            return;
        }

        $is_dry_run   = 'yes' === Integration::get_setting( 'dry_run', 'yes' );
        $trigger_type = $is_dry_run ? 'scheduled_dry_run' : 'scheduled';

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

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_sync_runs',
            [
                'run_uuid'            => wp_generate_uuid4(),
                'trigger_type'        => $trigger_type,
                'api_mode'            => 'production',
                'dry_run'             => $is_dry_run ? 1 : 0,
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
            Logger::sync_summary( $trigger_type, $is_dry_run, false, 0, 0, 0, 0, $api_result['error_message'] );
            return;
        }

        $auto_enable   = 'yes' === Integration::get_setting( 'auto_enable_manage_stock', 'no' );
        $matcher       = new Product_Matcher( $auto_enable );
        $match_results = $matcher->match_inventory_records( $api_result['records'] );

        $service      = new Sync_Service();
        $eval_results = $service->evaluate( $match_results, (bool) $api_result['pagination_complete'] );

        if ( ! $is_dry_run ) {
            $eval_results = $service->apply( $eval_results );
        }

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

        $registry             = new Tracked_SKU_Registry();
        $now                  = current_time( 'mysql' );
        $action_taken_default = $is_dry_run ? 'dry_run' : 'skipped';

        foreach ( $eval_results as $item ) {
            $action = $item['proposed_action'];
            if ( isset( $counts[ $action ] ) ) {
                $counts[ $action ]++;
            }

            if ( ! $is_dry_run ) {
                $taken = $item['action_taken'] ?? '';
                if ( 'applied' === $taken ) {
                    $applied_count++;
                } elseif ( 'failed' === $taken ) {
                    $failed_count++;
                }
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
                    'action_taken'           => $item['action_taken'] ?? $action_taken_default,
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
                'completed_at'                 => current_time( 'mysql' ),
                'matched_count'                => $matched_total,
                'updated_count'                => $applied_count,
                'unchanged_count'              => $counts['no_change'],
                'proposed_change_count'        => $proposed_change_total,
                'unmatched_count'              => $counts['skipped_unmatched'],
                'manage_stock_disabled_count'  => $counts['skipped_manage_stock_disabled'],
                'ambiguous_count'              => $counts['skipped_ambiguous'],
                'recent_order_protected_count' => $counts['skipped_recent_order'],
                'possible_zero_stock_count'    => $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                'error_count'                  => $failed_count,
                'sanitized_summary'            => $is_dry_run
                    ? sprintf(
                        'increase=%d decrease=%d no_change=%d order_protection=%d missing_tracked=%d unmatched=%d',
                        $counts['proposed_increase'],
                        $counts['proposed_decrease'],
                        $counts['no_change'],
                        $counts['skipped_recent_order'],
                        $counts['missing_tracked_sku'] + $counts['proposed_zero'],
                        $counts['skipped_unmatched']
                    )
                    : sprintf(
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

        Logger::sync_summary( $trigger_type, $is_dry_run, true, $matched_total, $applied_count, $proposed_change_total, $counts['skipped_unmatched'] );
    }

    /**
     * Returns the Unix timestamp for the next upcoming occurrence of HH:MM
     * in the WordPress site timezone. If the time has already passed today,
     * returns the same time tomorrow.
     */
    private static function next_timestamp_for_time( string $hhmm ): int {
        $tz        = wp_timezone();
        $now       = new \DateTimeImmutable( 'now', $tz );
        [ $h, $m ] = explode( ':', $hhmm );
        $candidate = $now->setTime( (int) $h, (int) $m, 0 );
        if ( $candidate <= $now ) {
            $candidate = $candidate->modify( '+1 day' );
        }
        return $candidate->getTimestamp();
    }
}
