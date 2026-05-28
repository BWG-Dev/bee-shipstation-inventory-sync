<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Sync;

defined( 'ABSPATH' ) || exit;

class Tracked_SKU_Registry {

    // ── Read ─────────────────────────────────────────────────────────────────

    public function get_all(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wsi_wr_tracked_skus ORDER BY sku ASC",
            ARRAY_A
        ) ?: [];
    }

    public function get_by_sku( string $sku ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsi_wr_tracked_skus WHERE sku = %s LIMIT 1",
                $sku
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function get_by_id( int $id ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsi_wr_tracked_skus WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Returns all sync-enabled tracked SKUs for missing-SKU evaluation. */
    public function get_sync_enabled(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wsi_wr_tracked_skus WHERE sync_enabled = 1 ORDER BY sku ASC",
            ARRAY_A
        ) ?: [];
    }

    // ── Write — called by sync service after a successful API run ────────────

    /**
     * Creates or updates a tracked SKU record from a successful API match.
     * Resets missing count and marks the SKU as active.
     */
    public function record_api_seen( string $sku, int $product_id, bool $is_variation, int $available, int $on_hand ): void {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = $this->get_by_sku( $sku );

        if ( null === $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}wsi_wr_tracked_skus
                        (sku, woocommerce_product_id, is_variation, tracking_source, sync_enabled,
                         last_returned_available, last_returned_on_hand, last_seen_at,
                         consecutive_missing_count, status, created_at, updated_at)
                     VALUES (%s, %d, %d, 'api_seen', 1, %d, %d, %s, 0, 'active', %s, %s)",
                    $sku, $product_id, (int) $is_variation,
                    $available, $on_hand, $now, $now, $now
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}wsi_wr_tracked_skus
                     SET woocommerce_product_id = %d,
                         last_returned_available = %d,
                         last_returned_on_hand = %d,
                         last_seen_at = %s,
                         consecutive_missing_count = 0,
                         first_missing_at = NULL,
                         last_missing_at = NULL,
                         status = 'active',
                         updated_at = %s
                     WHERE sku = %s",
                    $product_id, $available, $on_hand, $now, $now, $sku
                )
            );
        }
    }

    /**
     * Called after a complete successful sync when a tracked SKU is absent from the response.
     * Increments missing count and updates status.
     */
    public function record_missing( string $sku ): void {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = $this->get_by_sku( $sku );

        if ( null === $existing ) {
            return;
        }

        $new_count = (int) $existing['consecutive_missing_count'] + 1;
        $new_status = $new_count >= 2 ? 'confirmed_absent_candidate' : 'possible_zero_stock';

        $first_missing = $existing['first_missing_at'] ?? null;
        if ( null === $first_missing ) {
            $first_missing = $now;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wsi_wr_tracked_skus
                 SET consecutive_missing_count = %d,
                     first_missing_at = %s,
                     last_missing_at = %s,
                     status = %s,
                     updated_at = %s
                 WHERE sku = %s",
                $new_count, $first_missing, $now, $new_status, $now, $sku
            )
        );
    }

    // ── Write — manual admin operations ─────────────────────────────────────

    /**
     * Manually registers a SKU for tracking.
     * Used for products that may be at zero stock and thus never appear in the API.
     */
    public function manually_approve( string $sku, int $product_id, bool $is_variation ): bool {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = $this->get_by_sku( $sku );

        if ( null === $existing ) {
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}wsi_wr_tracked_skus
                        (sku, woocommerce_product_id, is_variation, tracking_source, sync_enabled,
                         consecutive_missing_count, status, created_at, updated_at)
                     VALUES (%s, %d, %d, 'manually_approved', 1, 0, 'active', %s, %s)",
                    $sku, $product_id, (int) $is_variation, $now, $now
                )
            );
            return false !== $result;
        }

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wsi_wr_tracked_skus
                 SET woocommerce_product_id = %d,
                     tracking_source = 'manually_approved',
                     updated_at = %s
                 WHERE sku = %s",
                $product_id, $now, $sku
            )
        );
        return false !== $result;
    }

    public function set_sync_enabled( int $id, bool $enabled ): void {
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_tracked_skus',
            [
                'sync_enabled' => $enabled ? 1 : 0,
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
    }

    public function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'wsi_wr_tracked_skus',
            [ 'id' => $id ],
            [ '%d' ]
        );
    }
}
