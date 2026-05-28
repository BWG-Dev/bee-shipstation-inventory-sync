<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Sync;

defined( 'ABSPATH' ) || exit;

class Product_Matcher {

    private bool $auto_enable_manage_stock;

    public function __construct( bool $auto_enable_manage_stock = false ) {
        $this->auto_enable_manage_stock = $auto_enable_manage_stock;
    }

    /**
     * Matches an array of ShipStation inventory records against WooCommerce products.
     *
     * @param array[] $records Raw inventory records from the API.
     * @return array[] Match result for each record.
     */
    public function match_inventory_records( array $records ): array {
        return array_map( [ $this, 'match_single' ], $records );
    }

    /**
     * Matches one ShipStation inventory record to a WooCommerce product or variation.
     *
     * Rules:
     * - Trim surrounding whitespace only — no case change, no punctuation normalisation.
     * - Exact SKU match against wc_product_meta_lookup (simple products and variations).
     * - If multiple products share the SKU → ambiguous.
     * - If matched product has Manage Stock disabled and auto-enable is off → manage_stock_disabled.
     * - Never creates or modifies products (except optional manage-stock enable).
     *
     * @param array $record Single inventory record: { sku, on_hand, available, ... }
     * @return array Match result.
     */
    public function match_single( array $record ): array {
        $sku       = trim( (string) ( $record['sku'] ?? '' ) );
        $on_hand   = isset( $record['on_hand'] )   ? (int) $record['on_hand']   : null;
        $available = isset( $record['available'] ) ? (int) $record['available'] : null;

        if ( '' === $sku ) {
            return $this->result( 'skipped_empty_sku', $sku, null, false, $on_hand, $available, null,
                __( 'SKU is empty — skipped', 'woocommerce-shipstation-integration-wr' )
            );
        }

        $lookup_sku  = apply_filters( 'wsi_wr_sku_before_matching', $sku );
        $product_ids = $this->find_all_by_sku( $lookup_sku );

        if ( empty( $product_ids ) ) {
            $result = $this->result( 'unmatched', $sku, null, false, $on_hand, $available, null,
                __( 'No WooCommerce product or variation found with this exact SKU', 'woocommerce-shipstation-integration-wr' )
            );
            return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
        }

        if ( count( $product_ids ) > 1 ) {
            $result = $this->result( 'ambiguous', $sku, null, false, $on_hand, $available, null,
                sprintf(
                    /* translators: comma-separated list of product IDs */
                    __( 'Multiple WooCommerce products share this SKU (IDs: %s) — skipped to prevent incorrect update', 'woocommerce-shipstation-integration-wr' ),
                    implode( ', ', $product_ids )
                )
            );
            return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
        }

        $product_id = (int) $product_ids[0];
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            $result = $this->result( 'unmatched', $sku, null, false, $on_hand, $available, null,
                __( 'Matched product ID could not be loaded', 'woocommerce-shipstation-integration-wr' )
            );
            return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
        }

        // Only sync published or private products. Draft, pending, and trashed products are skipped.
        $allowed_statuses = [ 'publish', 'private' ];
        $check_product    = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
        if ( $check_product && ! in_array( $check_product->get_status(), $allowed_statuses, true ) ) {
            $result = $this->result(
                'skipped_not_published',
                $sku, $product_id, $product->is_type( 'variation' ), $on_hand, $available, null,
                sprintf(
                    /* translators: WooCommerce product post status */
                    __( 'Product status is "%s" — only published products are synced', 'woocommerce-shipstation-integration-wr' ),
                    $check_product->get_status()
                )
            );
            return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
        }

        if ( ! $product->get_manage_stock() ) {
            if ( $this->auto_enable_manage_stock ) {
                $product->set_manage_stock( true );
                $product->save();
            } else {
                $result = $this->result( 'manage_stock_disabled', $sku, $product_id, $product->is_type( 'variation' ), $on_hand, $available, null,
                    __( 'WooCommerce stock management is disabled for this product — skipped', 'woocommerce-shipstation-integration-wr' )
                );
                return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
            }
        }

        // For variations, get_manage_stock() silently follows the parent when the variation's
        // own manage_stock is set to 'no' (which WooCommerce treats as "use parent").
        // Detect this via the raw post meta — get_prop() is protected on WC_Data.
        // Product meta is always in wp_postmeta regardless of HPOS (HPOS only affects orders).
        if ( $product->is_type( 'variation' ) && 'yes' !== get_post_meta( $product->get_id(), '_manage_stock', true ) ) {
            $result = $this->result(
                'variation_parent_stock',
                $sku, $product_id, true, $on_hand, $available, null,
                __( 'Variation uses parent-managed stock — enable per-variation stock management, or map this SKU to the parent product instead', 'woocommerce-shipstation-integration-wr' )
            );
            return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
        }

        $is_variation  = $product->is_type( 'variation' );
        $current_stock = $product->get_stock_quantity();
        $result_type   = $is_variation ? 'matched_variation' : 'matched_simple';

        $result = $this->result( $result_type, $sku, $product_id, $is_variation, $on_hand, $available, $current_stock, '' );
        return apply_filters( 'wsi_wr_product_match_result', $result, $sku, $record );
    }

    /**
     * Returns all WooCommerce product/variation IDs that have the given exact SKU.
     * Uses wc_product_meta_lookup which WooCommerce maintains for all products in all storage modes.
     */
    public function find_all_by_sku( string $sku ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT product_id FROM {$wpdb->prefix}wc_product_meta_lookup WHERE sku = %s",
                $sku
            )
        );

        return array_map( 'intval', $ids ?: [] );
    }

    private function result(
        string $result_type,
        string $sku,
        ?int   $product_id,
        bool   $is_variation,
        ?int   $on_hand,
        ?int   $available,
        ?int   $current_stock,
        string $reason
    ): array {
        return [
            'sku'                   => $sku,
            'result_type'           => $result_type,
            'product_id'            => $product_id,
            'is_variation'          => $is_variation,
            'shipstation_on_hand'   => $on_hand,
            'shipstation_available' => $available,
            'current_stock'         => $current_stock,
            'reason'                => $reason,
        ];
    }
}
