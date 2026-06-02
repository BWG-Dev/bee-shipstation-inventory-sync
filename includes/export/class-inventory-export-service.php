<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Export;

use WebReadyNow\WooCommerceShipStationIntegration\API\API_Client;
use WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration;
use WebReadyNow\WooCommerceShipStationIntegration\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles WooCommerce → ShipStation inventory decrement on payment confirmation.
 *
 * Only uses the 'decrement' transaction_type — never 'adjust'.
 * 'decrement' is a relative delta so concurrent channel activity is not overwritten.
 *
 * TODO: Refunds and cancellations are out of scope for this phase.
 * If the client needs automatic inventory restoration, add a follow-on task to increment
 * ShipStation inventory when eligible refunds or cancellations occur.
 */
class Inventory_Export_Service {

	const PROCESSED_META   = '_ss_export_decrement_processed';
	const MODE_META        = '_ss_export_decrement_mode';
	const TIMESTAMP_META   = '_ss_export_decrement_timestamp';
	const LOCATION_META    = '_ss_export_decrement_location_id';
	const SUMMARY_META     = '_ss_export_decrement_summary';
	const ITEM_META_PREFIX = '_ss_export_item_';

	/**
	 * Primary trigger — woocommerce_payment_complete( $order_id ).
	 */
	public function handle_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$this->process_order_decrement( $order, 'payment_complete' );
	}

	/**
	 * Fallback trigger — woocommerce_order_status_{processing|completed}( $order_id, $order ).
	 * Only processes if the order is paid and has not already been exported.
	 * This guards against gateways that do not reliably fire woocommerce_payment_complete.
	 */
	public function handle_status_fallback( int $order_id, \WC_Order $order ): void {
		if ( ! $order->is_paid() ) {
			return;
		}
		if ( $this->has_order_been_processed( $order ) ) {
			return;
		}
		$this->process_order_decrement( $order, 'status_fallback' );
	}

	public function process_order_decrement( \WC_Order $order, string $trigger ): void {
		$mode = Integration::get_setting( 'export_decrement_mode', 'disabled' );

		if ( 'disabled' === $mode ) {
			return;
		}

		if ( $this->has_order_been_processed( $order ) ) {
			$this->log_export_event( [
				'trigger'      => $trigger,
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'mode'         => $mode,
				'outcome'      => 'skipped_already_processed',
			] );
			return;
		}

		if ( ! $this->is_order_exportable( $order ) ) {
			$this->log_export_event( [
				'trigger'      => $trigger,
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'mode'         => $mode,
				'outcome'      => 'skipped_not_exportable',
				'skip_reason'  => 'order_not_paid',
			] );
			return;
		}

		$location_service = new Inventory_Location_Service();
		$location_id      = $location_service->get_selected_location_id();

		// Live mode pre-flight: abort immediately if credentials or location are missing
		if ( 'live' === $mode ) {
			$api_key = Integration::get_api_key();
			if ( '' === $api_key ) {
				$this->log_export_event( [
					'trigger'      => $trigger,
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'mode'         => $mode,
					'outcome'      => 'aborted',
					'skip_reason'  => 'no_api_key_configured',
				] );
				return;
			}
			if ( '' === $location_id ) {
				$this->log_export_event( [
					'trigger'      => $trigger,
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'mode'         => $mode,
					'outcome'      => 'aborted',
					'skip_reason'  => 'no_inventory_location_selected',
				] );
				return;
			}
		}

		// Dry-run without a location is allowed but flagged as invalid
		if ( 'dry_run' === $mode && '' === $location_id ) {
			Logger::log(
				sprintf(
					'export_dry_run_warning order_id=%d no_inventory_location_selected — dry-run proceeds but result is invalid',
					$order->get_id()
				),
				'warning'
			);
		}

		$order_id     = $order->get_id();
		$order_number = $order->get_order_number();
		$item_results = [];

		$client = 'live' === $mode ? new API_Client( Integration::get_api_key() ) : null;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$item_id_int  = (int) $item_id;
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();

			// Item-level idempotency: never re-decrement a line item that already succeeded
			$existing_status = $order->get_meta( self::ITEM_META_PREFIX . $item_id_int );
			if ( 'success' === $existing_status ) {
				$item_results[ $item_id_int ] = [ 'status' => 'skipped_already_processed', 'sku' => '', 'qty' => 0 ];
				$this->log_export_event( [
					'trigger'      => $trigger,
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'line_item_id' => $item_id_int,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'mode'         => $mode,
					'location_id'  => $location_id,
					'outcome'      => 'skipped_already_processed',
				] );
				continue;
			}

			$sku = $this->resolve_item_sku( $item );

			if ( '' === $sku ) {
				$item_results[ $item_id_int ] = [ 'status' => 'skipped_no_sku', 'sku' => '', 'qty' => 0 ];
				$order->update_meta_data( self::ITEM_META_PREFIX . $item_id_int, 'skipped_no_sku' );
				$this->log_export_event( [
					'trigger'      => $trigger,
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'line_item_id' => $item_id_int,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'mode'         => $mode,
					'location_id'  => $location_id,
					'outcome'      => 'skipped_no_sku',
					'skip_reason'  => 'no_sku_on_product_or_variation',
				] );
				continue;
			}

			$qty = (int) $item->get_quantity();

			if ( $qty <= 0 ) {
				$item_results[ $item_id_int ] = [ 'status' => 'skipped_invalid_qty', 'sku' => $sku, 'qty' => $qty ];
				$order->update_meta_data( self::ITEM_META_PREFIX . $item_id_int, 'skipped_invalid_qty' );
				$this->log_export_event( [
					'trigger'      => $trigger,
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'line_item_id' => $item_id_int,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'sku'          => $sku,
					'quantity'     => $qty,
					'mode'         => $mode,
					'location_id'  => $location_id,
					'outcome'      => 'skipped_invalid_qty',
					'skip_reason'  => 'quantity_zero_or_negative',
				] );
				continue;
			}

			$payload = $this->build_decrement_payload( $order, $sku, $qty, $location_id );

			if ( 'dry_run' === $mode ) {
				$item_results[ $item_id_int ] = [ 'status' => 'dry_run', 'sku' => $sku, 'qty' => $qty ];
				$order->update_meta_data( self::ITEM_META_PREFIX . $item_id_int, 'dry_run' );
				$this->log_export_event( [
					'trigger'          => $trigger,
					'order_id'         => $order_id,
					'order_number'     => $order_number,
					'line_item_id'     => $item_id_int,
					'product_id'       => $product_id,
					'variation_id'     => $variation_id,
					'sku'              => $sku,
					'quantity'         => $qty,
					'mode'             => 'dry_run',
					'location_id'      => $location_id,
					'dry_run_payload'  => wp_json_encode( $payload ),
					'outcome'          => 'dry_run',
				] );
				continue;
			}

			// Live mode: send the decrement — never block order flow on failure
			$result  = $client->post_inventory_decrement( $payload );
			$outcome = $result['success'] ? 'success' : 'failed';

			$order->update_meta_data( self::ITEM_META_PREFIX . $item_id_int, $outcome );
			$item_results[ $item_id_int ] = [
				'status'      => $outcome,
				'sku'         => $sku,
				'qty'         => $qty,
				'http_status' => $result['http_status'],
			];

			$this->log_export_event( [
				'trigger'       => $trigger,
				'order_id'      => $order_id,
				'order_number'  => $order_number,
				'line_item_id'  => $item_id_int,
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'sku'           => $sku,
				'quantity'      => $qty,
				'mode'          => 'live',
				'location_id'   => $location_id,
				'payload'       => wp_json_encode( $payload ),
				'http_status'   => $result['http_status'],
				'response_body' => substr( $result['response_body'], 0, 500 ),
				'outcome'       => $outcome,
				'error_message' => $result['error_message'],
			] );
		}

		// Persist all item-level meta and order-level summary in one save
		$this->mark_order_processed( $order, $mode, $location_id, $item_results );
	}

	private function build_decrement_payload( \WC_Order $order, string $sku, int $qty, string $location_id ): array {
		$description = sprintf( 'WooCommerce order #%s', $order->get_order_number() );
		return [
			'transaction_type'      => 'decrement',
			'inventory_location_id' => $location_id,
			'sku'                   => $sku,
			'quantity'              => $qty,
			'reason'                => $description,
			'description'           => $description,
			'notes'                 => $description,
		];
	}

	private function resolve_item_sku( \WC_Order_Item_Product $item ): string {
		// Prefer variation SKU when the item is a variation
		$variation_id = (int) $item->get_variation_id();
		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$sku = trim( $variation->get_sku() );
				if ( '' !== $sku ) {
					return $sku;
				}
			}
		}

		// Fall back to parent product SKU
		$product_id = (int) $item->get_product_id();
		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$sku = trim( $product->get_sku() );
				if ( '' !== $sku ) {
					return $sku;
				}
			}
		}

		return '';
	}

	private function is_order_exportable( \WC_Order $order ): bool {
		return $order->is_paid();
	}

	private function has_order_been_processed( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::PROCESSED_META );
	}

	private function mark_order_processed( \WC_Order $order, string $mode, string $location_id, array $item_results ): void {
		$order->update_meta_data( self::PROCESSED_META, 'yes' );
		$order->update_meta_data( self::MODE_META, $mode );
		$order->update_meta_data( self::TIMESTAMP_META, current_time( 'c' ) );
		$order->update_meta_data( self::LOCATION_META, $location_id );
		$order->update_meta_data( self::SUMMARY_META, wp_json_encode( $item_results ) );
		$order->save();

		$order->add_order_note( $this->build_order_note( $mode, $location_id, $item_results ) );

		Logger::log( sprintf(
			'export_order_marked_processed order_id=%d mode=%s location=%s item_count=%d',
			$order->get_id(),
			$mode,
			$location_id ?: 'none',
			count( $item_results )
		) );
	}

	private function build_order_note( string $mode, string $location_id, array $item_results ): string {
		if ( 'dry_run' === $mode ) {
			$mode_label = 'Dry Run';
		} elseif ( 'live' === $mode ) {
			$mode_label = 'Live';
		} else {
			$mode_label = ucfirst( $mode );
		}

		$lines   = [];
		$lines[] = sprintf( 'ShipStation inventory export — %s', $mode_label );

		if ( '' !== $location_id ) {
			$lines[] = sprintf( 'Location: %s', $location_id );
		}

		foreach ( $item_results as $item ) {
			$status = $item['status'] ?? '';
			$sku    = $item['sku'] ?? '';
			$qty    = (int) ( $item['qty'] ?? 0 );

			$item_label = ( '' !== $sku )
				? sprintf( '%s × %d', $sku, $qty )
				: '(no SKU)';

			if ( 'dry_run' === $status ) {
				$outcome = 'dry run — would decrement';
			} elseif ( 'success' === $status ) {
				$outcome = 'decremented ✓';
			} elseif ( 'failed' === $status ) {
				$http = isset( $item['http_status'] ) ? sprintf( ' (HTTP %d)', $item['http_status'] ) : '';
				$outcome = 'FAILED' . $http;
			} elseif ( 'skipped_no_sku' === $status ) {
				$outcome = 'skipped — no SKU';
			} elseif ( 'skipped_invalid_qty' === $status ) {
				$outcome = 'skipped — invalid quantity';
			} elseif ( 'skipped_already_processed' === $status ) {
				$outcome = 'skipped — already processed';
			} else {
				$outcome = sprintf( 'skipped (%s)', $status );
			}

			$lines[] = sprintf( '• %s → %s', $item_label, $outcome );
		}

		if ( 'dry_run' === $mode ) {
			$lines[] = 'No ShipStation API calls made.';
		}

		return implode( "\n", $lines );
	}

	private function log_export_event( array $context ): void {
		$parts = [];
		foreach ( $context as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$parts[] = $key . '=' . $value;
		}

		$outcome = $context['outcome'] ?? '';
		$level   = in_array( $outcome, [ 'failed', 'aborted' ], true ) ? 'error' : 'info';

		Logger::log( 'export_event: ' . implode( ' ', $parts ), $level );
	}
}
