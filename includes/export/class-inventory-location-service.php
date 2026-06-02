<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Export;

use WebReadyNow\WooCommerceShipStationIntegration\API\API_Client;
use WebReadyNow\WooCommerceShipStationIntegration\Admin\Integration;
use WebReadyNow\WooCommerceShipStationIntegration\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

class Inventory_Location_Service {

	const LOCATIONS_CACHE_OPTION   = 'wsi_wr_inventory_locations_cache';
	const SELECTED_LOCATION_OPTION = 'wsi_wr_export_location_id';

	/**
	 * Fetches inventory locations from ShipStation, normalizes, caches, and maybe auto-selects.
	 *
	 * Returns array with keys: success, locations, error_message, auto_selected.
	 */
	public function fetch_and_cache(): array {
		$api_key = Integration::get_api_key();
		if ( '' === $api_key ) {
			return [
				'success'       => false,
				'locations'     => [],
				'error_message' => __( 'No ShipStation API key is configured. Save your API key before refreshing locations.', 'woocommerce-shipstation-integration-wr' ),
				'auto_selected' => false,
			];
		}

		$client = new API_Client( $api_key );
		$result = $client->fetch_inventory_locations();

		Logger::api_request(
			'/inventory_locations',
			$result['http_status'],
			$result['duration_ms'] ?? 0,
			0,
			count( $result['locations'] ?? [] ),
			$result['error_message']
		);

		if ( ! $result['success'] ) {
			Logger::log(
				sprintf(
					'export_location_fetch_failed http_status=%d error="%s"',
					$result['http_status'],
					substr( $result['error_message'], 0, 120 )
				),
				'error'
			);
			return [
				'success'       => false,
				'locations'     => [],
				'error_message' => $result['error_message'],
				'auto_selected' => false,
			];
		}

		$normalized    = $this->normalize_locations( $result['locations'] );
		update_option( self::LOCATIONS_CACHE_OPTION, $normalized );

		$auto_selected = $this->maybe_auto_select_single_location( $normalized );

		Logger::log(
			sprintf(
				'export_location_fetch_success count=%d auto_selected=%s',
				count( $normalized ),
				$auto_selected ? 'yes' : 'no'
			)
		);

		return [
			'success'       => true,
			'locations'     => $normalized,
			'error_message' => '',
			'auto_selected' => $auto_selected,
		];
	}

	public function get_cached_locations(): array {
		$cached = get_option( self::LOCATIONS_CACHE_OPTION, [] );
		return is_array( $cached ) ? $cached : [];
	}

	public function get_selected_location_id(): string {
		return (string) get_option( self::SELECTED_LOCATION_OPTION, '' );
	}

	public function set_selected_location_id( string $id ): void {
		update_option( self::SELECTED_LOCATION_OPTION, sanitize_text_field( $id ) );
	}

	/**
	 * Returns true if the currently selected location ID appears in the cached list.
	 */
	public function validate_selected_location(): bool {
		$selected = $this->get_selected_location_id();
		if ( '' === $selected ) {
			return false;
		}
		foreach ( $this->get_cached_locations() as $loc ) {
			if ( $loc['inventory_location_id'] === $selected ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the human-readable name of the selected location, or the ID if the name is empty.
	 */
	public function get_selected_location_name(): string {
		$selected = $this->get_selected_location_id();
		if ( '' === $selected ) {
			return '';
		}
		foreach ( $this->get_cached_locations() as $loc ) {
			if ( $loc['inventory_location_id'] === $selected ) {
				return $loc['name'] !== '' ? $loc['name'] : $selected;
			}
		}
		return $selected;
	}

	/**
	 * Auto-selects when exactly one location is cached and no selection exists yet.
	 * Never overrides an existing selection.
	 */
	private function maybe_auto_select_single_location( array $locations ): bool {
		if ( '' !== $this->get_selected_location_id() ) {
			return false;
		}
		if ( 1 === count( $locations ) && ! empty( $locations[0]['inventory_location_id'] ) ) {
			$this->set_selected_location_id( $locations[0]['inventory_location_id'] );
			return true;
		}
		return false;
	}

	private function normalize_locations( array $raw ): array {
		$normalized = [];
		foreach ( $raw as $loc ) {
			if ( ! is_array( $loc ) || empty( $loc['inventory_location_id'] ) ) {
				continue;
			}
			$normalized[] = [
				'inventory_location_id'  => sanitize_text_field( $loc['inventory_location_id'] ),
				'name'                   => sanitize_text_field( $loc['name'] ?? '' ),
				'inventory_warehouse_id' => sanitize_text_field( $loc['inventory_warehouse_id'] ?? '' ),
			];
		}
		return $normalized;
	}
}
