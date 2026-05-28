<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Sync;

defined( 'ABSPATH' ) || exit;

class Sync_Service {

    /**
     * Evaluates Product_Matcher results against safety rules and returns a proposed
     * action for every SKU — both those present in the API and tracked SKUs that are
     * absent from it.
     *
     * Does NOT write to WooCommerce. Safe to call for dry-run.
     *
     * @param array[] $match_results      Results from Product_Matcher::match_inventory_records().
     * @param bool    $pagination_complete Whether the API response completed without error.
     * @return array[]                    One evaluation item per input record, plus one per
     *                                    sync-enabled tracked SKU not in the current API response.
     */
    public function evaluate( array $match_results, bool $pagination_complete ): array {
        $protection     = new Order_Protection();
        $registry       = new Tracked_SKU_Registry();
        $missing_mode   = \WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration::get_setting( 'missing_sku_handling', 'report_only' );
        $window_minutes = (int) \WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration::get_setting( 'order_protection_window', 60 );

        $results      = [];
        $api_sku_seen = [];

        foreach ( $match_results as $mr ) {
            $sku = $mr['sku'];
            if ( '' !== $sku ) {
                $api_sku_seen[ $sku ] = true;
            }

            $item = [
                'sku'                   => $sku,
                'product_id'            => $mr['product_id'],
                'is_variation'          => $mr['is_variation'],
                'match_type'            => $mr['result_type'],
                'current_stock'         => $mr['current_stock'],
                'shipstation_available' => $mr['shipstation_available'],
                'shipstation_on_hand'   => $mr['shipstation_on_hand'],
                'proposed_action'       => '',
                'proposed_stock'        => null,
                'reason'                => $mr['reason'],
            ];

            if ( in_array( $mr['result_type'], [ 'matched_simple', 'matched_variation' ], true )
                && $mr['product_id']
            ) {
                $item = $this->evaluate_matched( $item, $protection, $window_minutes );
            } else {
                $item['proposed_action'] = $this->skip_action_for( $mr['result_type'] );
            }

            $results[] = $item;
        }

        // Evaluate tracked SKUs absent from the current API response.
        // Only safe when pagination completed — skip entirely otherwise.
        if ( $pagination_complete ) {
            foreach ( $registry->get_sync_enabled() as $tracked ) {
                if ( isset( $api_sku_seen[ $tracked['sku'] ] ) ) {
                    continue;
                }

                $current_stock = null;
                if ( $tracked['woocommerce_product_id'] ) {
                    $product = wc_get_product( (int) $tracked['woocommerce_product_id'] );
                    if ( $product ) {
                        $current_stock = $product->get_stock_quantity();
                    }
                }

                $missing_count = (int) $tracked['consecutive_missing_count'];
                $item = [
                    'sku'                   => $tracked['sku'],
                    'product_id'            => $tracked['woocommerce_product_id'] ? (int) $tracked['woocommerce_product_id'] : null,
                    'is_variation'          => (bool) $tracked['is_variation'],
                    'match_type'            => 'missing_tracked_sku',
                    'current_stock'         => $current_stock,
                    'shipstation_available' => null,
                    'shipstation_on_hand'   => null,
                    'proposed_stock'        => null,
                    'proposed_action'       => '',
                    'reason'                => '',
                ];

                if ( 'zero_after_two_confirmed_absent_syncs' === $missing_mode && $missing_count >= 2 ) {
                    $item['proposed_action'] = 'proposed_zero';
                    $item['proposed_stock']  = 0;
                    $item['reason']          = sprintf(
                        /* translators: number of consecutive complete syncs the SKU was absent */
                        __( 'Absent from %d consecutive complete syncs — zero stock proposed', 'woocommerce-shipstation-integration-wr' ),
                        $missing_count
                    );
                } else {
                    $item['proposed_action'] = 'missing_tracked_sku';
                    $item['reason']          = sprintf(
                        /* translators: number of consecutive complete syncs the SKU was absent */
                        __( 'Absent from API; missing %d consecutive complete sync(s) — reporting only', 'woocommerce-shipstation-integration-wr' ),
                        $missing_count
                    );
                }

                $results[] = $item;
            }
        }

        return $results;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Applies proposed stock changes to WooCommerce for a set of evaluation results.
     *
     * Only writes for proposed_increase, proposed_decrease, and proposed_zero.
     * Everything else is marked skipped. Returns the same array with action_taken set.
     *
     * @param array[] $eval_results Output from evaluate().
     * @return array[]              Same items with action_taken = 'applied'|'skipped'|'failed'.
     */
    public function apply( array $eval_results ): array {
        $writable = [ 'proposed_increase', 'proposed_decrease', 'proposed_zero' ];

        foreach ( $eval_results as &$item ) {
            if ( ! in_array( $item['proposed_action'], $writable, true )
                || ! $item['product_id']
                || null === $item['proposed_stock']
            ) {
                $item['action_taken'] = 'skipped';
                continue;
            }

            $product = wc_get_product( (int) $item['product_id'] );
            if ( ! $product || ! $product->get_manage_stock() ) {
                $item['action_taken'] = 'failed';
                $item['reason']       = __( 'Product could not be loaded or has stock management disabled.', 'woocommerce-shipstation-integration-wr' );
                continue;
            }

            wc_update_product_stock( $product, (int) $item['proposed_stock'], 'set' );
            $item['action_taken'] = 'applied';
        }
        unset( $item );

        return $eval_results;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function evaluate_matched( array $item, Order_Protection $protection, int $window_minutes ): array {
        if ( null === $item['shipstation_available'] ) {
            $item['proposed_action'] = 'skipped_no_available';
            $item['reason']          = __( 'ShipStation did not return an available quantity for this SKU', 'woocommerce-shipstation-integration-wr' );
            return $item;
        }

        $ss_qty = (int) $item['shipstation_available'];
        $wc_qty = (int) $item['current_stock'];

        if ( $ss_qty === $wc_qty ) {
            $item['proposed_action'] = 'no_change';
            $item['proposed_stock']  = $wc_qty;
            return $item;
        }

        if ( $ss_qty < $wc_qty ) {
            $item['proposed_action'] = 'proposed_decrease';
            $item['proposed_stock']  = $ss_qty;
            return $item;
        }

        // ss_qty > wc_qty — check recent-order protection before allowing increase.
        if ( $protection->has_recent_order( (int) $item['product_id'], $window_minutes ) ) {
            $item['proposed_action'] = 'skipped_recent_order';
            $item['reason']          = sprintf(
                /* translators: number of minutes */
                __( 'Stock increase blocked: a recent order for this product was placed within the last %d minutes', 'woocommerce-shipstation-integration-wr' ),
                $window_minutes
            );
            return $item;
        }

        $item['proposed_action'] = 'proposed_increase';
        $item['proposed_stock']  = $ss_qty;
        return $item;
    }

    private function skip_action_for( string $result_type ): string {
        $map = [
            'unmatched'             => 'skipped_unmatched',
            'manage_stock_disabled' => 'skipped_manage_stock_disabled',
            'ambiguous'             => 'skipped_ambiguous',
            'skipped_empty_sku'     => 'skipped_empty_sku',
            'skipped_not_published' => 'skipped_not_published',
        ];
        return $map[ $result_type ] ?? 'skipped_' . $result_type;
    }
}
