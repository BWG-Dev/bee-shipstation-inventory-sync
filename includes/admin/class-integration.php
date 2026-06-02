<?php

namespace WebReadyNow\WooCommerceShipStationIntegration\Admin;

use WebReadyNow\WooCommerceShipStationIntegration\Export\Inventory_Location_Service;

defined( 'ABSPATH' ) || exit;

class Integration extends \WC_Integration {

    // Separate option key for API key — stored with autoload disabled, never in main settings array
    const API_KEY_OPTION = 'wsi_wr_api_key';

    public function __construct() {
        $this->id                 = 'wsi_wr_shipstation';
        $this->method_title       = __( 'ShipStation Integration WR', 'woocommerce-shipstation-integration-wr' );
        $this->method_description = __( 'Controlled ShipStation inventory pull for WooCommerce. Retrieves inventory from ShipStation on a schedule and updates product stock by exact SKU match.', 'woocommerce-shipstation-integration-wr' );

        $this->init_form_fields();
        $this->init_settings();

        add_action(
            'woocommerce_update_options_integration_' . $this->id,
            [ $this, 'process_admin_options' ]
        );
    }

    /**
     * Reads a single setting from the WooCommerce Integration settings array.
     * All plugin code should use this instead of get_option('wsi_wr_*').
     */
    public static function get_setting( string $key, $default = '' ) {
        $settings = get_option( 'woocommerce_wsi_wr_shipstation_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
    }

    /**
     * Returns the configured API key.
     * Checks constant first, then the separately stored option.
     * Never reads from the main settings array (which never stores the key).
     */
    public static function get_api_key(): string {
        if ( defined( 'WSI_WR_SHIPSTATION_API_KEY' ) ) {
            return WSI_WR_SHIPSTATION_API_KEY;
        }
        return (string) get_option( self::API_KEY_OPTION, '' );
    }

    public function init_form_fields(): void {
        $this->form_fields = [

            // ── Connection ──────────────────────────────────────────────────

            'connection_section' => [
                'title' => __( 'Connection', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'title',
            ],

            'enabled' => [
                'title'   => __( 'Enable Synchronization', 'woocommerce-shipstation-integration-wr' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable ShipStation inventory pull synchronization', 'woocommerce-shipstation-integration-wr' ),
                'default' => 'no',
            ],

            'api_key' => [
                'title'       => __( 'ShipStation API Key', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'api_key',
                'description' => __( 'Enter your ShipStation V2 API key. The key is stored securely and will not be displayed after saving. Alternatively, define <code>WSI_WR_SHIPSTATION_API_KEY</code> in wp-config.php.', 'woocommerce-shipstation-integration-wr' ),
            ],

            'connection_tools' => [
                'title' => __( 'API Tools', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'connection_tools',
            ],

            // ── Synchronization Behaviour ────────────────────────────────

            'sync_section' => [
                'title' => __( 'Synchronization Behaviour', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'title',
            ],

            'dry_run' => [
                'title'       => __( 'Dry-Run Mode', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable dry-run mode (calculate and report proposed changes without updating stock)', 'woocommerce-shipstation-integration-wr' ),
                'description' => __( 'Enabled by default. Review a full dry-run report before disabling.', 'woocommerce-shipstation-integration-wr' ),
                'default'     => 'yes',
                'desc_tip'    => false,
            ],

            'quantity_source' => [
                'title'       => __( 'Quantity Source', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'info',
                'description' => __( 'WooCommerce stock is set using the ShipStation <strong>available</strong> quantity, not <em>on_hand</em>. On-hand values are stored in sync reports for diagnostics only.', 'woocommerce-shipstation-integration-wr' ),
            ],

            'missing_sku_handling' => [
                'title'       => __( 'Missing Previously Confirmed ShipStation SKU Handling', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'select',
                'description' => __( 'ShipStation omits zero-stock SKUs from the API response entirely. This setting controls what happens when a previously tracked SKU disappears from the response.', 'woocommerce-shipstation-integration-wr' ),
                'default'     => 'report_only',
                'options'     => [
                    'report_only'                         => __( 'Report only (default — never change stock due to absence)', 'woocommerce-shipstation-integration-wr' ),
                    'zero_after_two_confirmed_absent_syncs' => __( 'Set to zero after two consecutive fully successful absent syncs (use only after staging validation)', 'woocommerce-shipstation-integration-wr' ),
                    'ignore'                              => __( 'Ignore (skip missing tracked SKU detection entirely)', 'woocommerce-shipstation-integration-wr' ),
                ],
            ],

            'order_protection_window' => [
                'title'             => __( 'Recent Order Stock Increase Protection Window (minutes)', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'number',
                'description'       => __( 'If ShipStation reports a higher quantity than current WooCommerce stock, the plugin checks for recent WooCommerce orders within this window before allowing the increase. Prevents overselling when ShipStation has not yet allocated a new order.', 'woocommerce-shipstation-integration-wr' ),
                'default'           => 60,
                'custom_attributes' => [
                    'min'  => 0,
                    'max'  => 1440,
                    'step' => 1,
                ],
            ],

            'auto_enable_manage_stock' => [
                'title'       => __( 'Auto-Enable WooCommerce Manage Stock', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'checkbox',
                'label'       => __( 'Automatically enable stock management on matched products that have it disabled', 'woocommerce-shipstation-integration-wr' ),
                'description' => __( 'Disabled by default. When off, products with Manage Stock disabled are skipped and reported.', 'woocommerce-shipstation-integration-wr' ),
                'default'     => 'no',
            ],

            // ── Scheduling ──────────────────────────────────────────────────

            'schedule_section' => [
                'title' => __( 'Scheduling', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'title',
                'description' => __( 'Scheduled sync uses WooCommerce Action Scheduler. For reliable execution on production, configure a server-level cron job to trigger WordPress cron — see the plugin README for instructions.', 'woocommerce-shipstation-integration-wr' ),
            ],

            'schedule_enabled' => [
                'title'   => __( 'Enable Scheduled Synchronization', 'woocommerce-shipstation-integration-wr' ),
                'type'    => 'checkbox',
                'label'   => __( 'Run inventory synchronization on the configured daily schedule', 'woocommerce-shipstation-integration-wr' ),
                'default' => 'no',
            ],

            'schedule_time_1' => [
                'title'             => __( 'Sync Time 1', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'text',
                'description'       => __( 'First daily sync time in HH:MM format (24-hour, WordPress site timezone).', 'woocommerce-shipstation-integration-wr' ),
                'default'           => '07:00',
                'custom_attributes' => [ 'pattern' => '[0-2][0-9]:[0-5][0-9]', 'placeholder' => '07:00' ],
            ],

            'schedule_time_2' => [
                'title'             => __( 'Sync Time 2', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'text',
                'description'       => __( 'Second daily sync time in HH:MM format (24-hour, WordPress site timezone).', 'woocommerce-shipstation-integration-wr' ),
                'default'           => '12:00',
                'custom_attributes' => [ 'pattern' => '[0-2][0-9]:[0-5][0-9]', 'placeholder' => '12:00' ],
            ],

            'schedule_time_3' => [
                'title'             => __( 'Sync Time 3', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'text',
                'description'       => __( 'Third daily sync time in HH:MM format (24-hour, WordPress site timezone).', 'woocommerce-shipstation-integration-wr' ),
                'default'           => '18:00',
                'custom_attributes' => [ 'pattern' => '[0-2][0-9]:[0-5][0-9]', 'placeholder' => '18:00' ],
            ],

            // ── Processing ──────────────────────────────────────────────────

            'processing_section' => [
                'title' => __( 'Processing', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'title',
            ],

            'page_size' => [
                'title'             => __( 'API Page Size', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'number',
                'description'       => __( 'Number of inventory records to request per API page.', 'woocommerce-shipstation-integration-wr' ),
                'default'           => 100,
                'custom_attributes' => [
                    'min'  => 10,
                    'max'  => 500,
                    'step' => 10,
                ],
            ],

            'request_timeout' => [
                'title'             => __( 'API Request Timeout (seconds)', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'number',
                'description'       => __( 'Maximum seconds to wait for a ShipStation API response.', 'woocommerce-shipstation-integration-wr' ),
                'default'           => 30,
                'custom_attributes' => [
                    'min'  => 5,
                    'max'  => 120,
                    'step' => 1,
                ],
            ],

            // ── Logging ─────────────────────────────────────────────────────

            'logging_section' => [
                'title' => __( 'Logging & Reports', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'title',
            ],

            'logging_enabled' => [
                'title'   => __( 'Enable Logging', 'woocommerce-shipstation-integration-wr' ),
                'type'    => 'checkbox',
                'label'   => __( 'Record sync run metadata and per-SKU results', 'woocommerce-shipstation-integration-wr' ),
                'default' => 'yes',
            ],

            'log_retention_days' => [
                'title'             => __( 'Log Retention (days)', 'woocommerce-shipstation-integration-wr' ),
                'type'              => 'number',
                'description'       => __( 'Sync run and item records older than this many days will be cleaned up. Tracked SKU registry records are never automatically deleted.', 'woocommerce-shipstation-integration-wr' ),
                'default'           => 30,
                'custom_attributes' => [
                    'min'  => 1,
                    'max'  => 365,
                    'step' => 1,
                ],
            ],

            // ── ShipStation Inventory Export (WooCommerce → ShipStation) ────────

            'export_section' => [
                'title'       => __( 'ShipStation Inventory Export (WooCommerce → ShipStation)', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'title',
                'description' => __( 'When a WooCommerce order payment is confirmed, this feature sends a decrement to ShipStation so its inventory reflects the sale. Uses the <code>decrement</code> transaction type — never <code>adjust</code> — so concurrent channel activity is not overwritten.', 'woocommerce-shipstation-integration-wr' ),
            ],

            'export_double_decrement_warning' => [
                'title'       => __( 'Important Warning', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'info',
                'description' => __( '<strong>Only enable live decrement after confirming ShipStation is not already deducting inventory for WooCommerce orders.</strong> Enabling live mode while ShipStation also deducts inventory can cause double inventory reduction.', 'woocommerce-shipstation-integration-wr' ),
            ],

            'export_decrement_mode' => [
                'title'       => __( 'Export Decrement Mode', 'woocommerce-shipstation-integration-wr' ),
                'type'        => 'select',
                'description' => __( 'Controls whether paid WooCommerce orders trigger a ShipStation inventory decrement. Start with Dry Run to verify logging before enabling Live.', 'woocommerce-shipstation-integration-wr' ),
                'default'     => 'disabled',
                'options'     => [
                    'disabled' => __( 'Disabled — no ShipStation decrement calls (default)', 'woocommerce-shipstation-integration-wr' ),
                    'dry_run'  => __( 'Dry Run — log what would be sent without calling the ShipStation API', 'woocommerce-shipstation-integration-wr' ),
                    'live'     => __( 'Live — send actual decrement calls to ShipStation on payment confirmation', 'woocommerce-shipstation-integration-wr' ),
                ],
            ],

            'export_location_tools' => [
                'title' => __( 'Inventory Location', 'woocommerce-shipstation-integration-wr' ),
                'type'  => 'export_location_tools',
            ],

        ];
    }

    // ── Custom field: api_key ────────────────────────────────────────────────

    /**
     * Renders the API key field.
     * The input is always empty on load — the stored key is never echoed.
     */
    public function generate_api_key_html( string $key, array $data ): string {
        $field_key      = $this->get_field_key( $key );
        $has_constant   = defined( 'WSI_WR_SHIPSTATION_API_KEY' );
        $has_saved_key  = ! $has_constant && '' !== get_option( self::API_KEY_OPTION, '' );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>">
                    <?php echo esc_html( $data['title'] ); ?>
                </label>
            </th>
            <td class="forminp">
                <?php if ( $has_constant ) : ?>
                    <p class="description">
                        <strong><?php esc_html_e( 'API key configured via the WSI_WR_SHIPSTATION_API_KEY constant in wp-config.php.', 'woocommerce-shipstation-integration-wr' ); ?></strong>
                    </p>
                    <input type="hidden" name="<?php echo esc_attr( $field_key ); ?>" value="">
                <?php else : ?>
                    <input
                        type="password"
                        id="<?php echo esc_attr( $field_key ); ?>"
                        name="<?php echo esc_attr( $field_key ); ?>"
                        value=""
                        class="regular-text"
                        autocomplete="new-password"
                        placeholder="<?php echo $has_saved_key
                            ? esc_attr__( 'Key saved — enter a new value to replace it', 'woocommerce-shipstation-integration-wr' )
                            : esc_attr__( 'Enter ShipStation V2 API key', 'woocommerce-shipstation-integration-wr' ); ?>"
                    >
                    <?php if ( $has_saved_key ) : ?>
                        <p class="description">
                            <?php esc_html_e( 'A ShipStation API key is currently saved. Leave this field blank to keep the existing key.', 'woocommerce-shipstation-integration-wr' ); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( ! empty( $data['description'] ) ) : ?>
                    <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Validates the api_key field on save.
     * If a non-empty value was submitted, saves it separately with autoload disabled.
     * Always returns empty string so the key is never stored in the main settings array.
     */
    public function validate_api_key_field( string $key, $value ): string {
        if ( ! defined( 'WSI_WR_SHIPSTATION_API_KEY' ) ) {
            $value = sanitize_text_field( trim( (string) $value ) );
            if ( '' !== $value ) {
                update_option( self::API_KEY_OPTION, $value, false );
            }
        }
        return '';
    }

    // ── Custom field: info ───────────────────────────────────────────────────

    /**
     * Renders a read-only informational row (no input).
     */
    public function generate_info_html( string $key, array $data ): string {
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <?php echo esc_html( $data['title'] ); ?>
            </th>
            <td class="forminp">
                <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function validate_info_field( string $key, $value ): string {
        return '';
    }

    // ── Schedule time validation ─────────────────────────────────────────────

    public function validate_schedule_time_1_field( string $key, $value ): string {
        return $this->validate_time_string( $value, '07:00' );
    }

    public function validate_schedule_time_2_field( string $key, $value ): string {
        return $this->validate_time_string( $value, '12:00' );
    }

    public function validate_schedule_time_3_field( string $key, $value ): string {
        return $this->validate_time_string( $value, '18:00' );
    }

    private function validate_time_string( $value, string $default ): string {
        $value = sanitize_text_field( trim( (string) $value ) );
        if ( preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) {
            return $value;
        }
        return $default;
    }

    // ── Numeric field validation ─────────────────────────────────────────────

    public function validate_order_protection_window_field( string $key, $value ): int {
        return max( 0, min( 1440, (int) $value ) );
    }

    public function validate_page_size_field( string $key, $value ): int {
        return max( 10, min( 500, (int) $value ) );
    }

    public function validate_request_timeout_field( string $key, $value ): int {
        return max( 5, min( 120, (int) $value ) );
    }

    public function validate_log_retention_days_field( string $key, $value ): int {
        return max( 1, min( 365, (int) $value ) );
    }

    // ── Custom field: connection_tools ───────────────────────────────────────

    /**
     * Renders the Test Connection and Fetch Inventory Sample buttons with result containers.
     * Buttons are wired up via admin.js — no form submission.
     */
    public function generate_connection_tools_html( string $key, array $data ): string {
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <?php echo esc_html( $data['title'] ); ?>
            </th>
            <td class="forminp">
                <p>
                    <button
                        type="button"
                        id="wsi-wr-test-connection"
                        class="button button-secondary"
                    ><?php esc_html_e( 'Test Connection', 'woocommerce-shipstation-integration-wr' ); ?></button>

                    <button
                        type="button"
                        id="wsi-wr-fetch-sample"
                        class="button button-secondary"
                        style="margin-left: 8px;"
                    ><?php esc_html_e( 'Fetch Inventory Sample', 'woocommerce-shipstation-integration-wr' ); ?></button>
                </p>
                <div id="wsi-wr-test-connection-result" class="wsi-wr-tool-result"></div>
                <div id="wsi-wr-fetch-sample-result" class="wsi-wr-sample-result"></div>
                <p class="description">
                    <?php esc_html_e( 'Test Connection sends a minimal read-only request to confirm API access. Fetch Inventory Sample retrieves the first page of inventory to verify the data structure and confirm the available field is present.', 'woocommerce-shipstation-integration-wr' ); ?>
                </p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function validate_connection_tools_field( string $key, $value ): string {
        return '';
    }

    // ── Export section: select field ─────────────────────────────────────────

    public function validate_export_decrement_mode_field( string $key, $value ): string {
        $allowed = [ 'disabled', 'dry_run', 'live' ];
        $value   = sanitize_text_field( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : 'disabled';
    }

    // ── Export section: custom location tools field ───────────────────────────

    /**
     * Renders the inventory location select + refresh button.
     * The select submits via the standard WC settings form save.
     * The refresh button triggers an AJAX call to fetch/cache locations without a full form save.
     */
    public function generate_export_location_tools_html( string $key, array $data ): string {
        $location_service = new Inventory_Location_Service();
        $cached_locations = $location_service->get_cached_locations();
        $selected_id      = $location_service->get_selected_location_id();
        $selected_name    = $location_service->get_selected_location_name();
        $current_mode     = self::get_setting( 'export_decrement_mode', 'disabled' );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php esc_html_e( 'Inventory Location', 'woocommerce-shipstation-integration-wr' ); ?></label>
            </th>
            <td class="forminp">

                <?php if ( empty( $cached_locations ) ) : ?>
                    <div class="notice notice-warning inline wsi-wr-export-notice"><p>
                        <?php esc_html_e( 'No inventory locations are loaded. Click "Refresh ShipStation Inventory Locations" to fetch available locations from ShipStation.', 'woocommerce-shipstation-integration-wr' ); ?>
                    </p></div>
                <?php elseif ( 'live' === $current_mode && '' === $selected_id ) : ?>
                    <div class="notice notice-error inline wsi-wr-export-notice"><p>
                        <?php esc_html_e( 'Live export mode is active but no inventory location is selected. Decrement calls will not be sent until a location is selected and settings are saved.', 'woocommerce-shipstation-integration-wr' ); ?>
                    </p></div>
                <?php elseif ( count( $cached_locations ) > 1 && '' === $selected_id ) : ?>
                    <div class="notice notice-warning inline wsi-wr-export-notice"><p>
                        <?php esc_html_e( 'Multiple inventory locations are available. Select one before enabling live export mode.', 'woocommerce-shipstation-integration-wr' ); ?>
                    </p></div>
                <?php endif; ?>

                <p>
                    <select
                        name="wsi_wr_export_location_select"
                        id="wsi-wr-export-location-select"
                        class="wc-enhanced-select"
                        style="min-width: 350px;"
                    >
                        <option value=""><?php esc_html_e( '— Select a location —', 'woocommerce-shipstation-integration-wr' ); ?></option>
                        <?php foreach ( $cached_locations as $loc ) : ?>
                            <option
                                value="<?php echo esc_attr( $loc['inventory_location_id'] ); ?>"
                                <?php selected( $selected_id, $loc['inventory_location_id'] ); ?>
                            >
                                <?php echo esc_html(
                                    ( $loc['name'] !== '' ? $loc['name'] : $loc['inventory_location_id'] )
                                    . ' — ' . $loc['inventory_location_id']
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <?php if ( '' !== $selected_id ) : ?>
                    <p class="description">
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: location name 2: location ID */
                            __( 'Currently selected: <strong>%1$s</strong> &mdash; ID: <code>%2$s</code>', 'woocommerce-shipstation-integration-wr' ),
                            esc_html( $selected_name ),
                            esc_html( $selected_id )
                        ) );
                        ?>
                    </p>
                <?php endif; ?>

                <p>
                    <button
                        type="button"
                        id="wsi-wr-refresh-locations"
                        class="button button-secondary"
                    ><?php esc_html_e( 'Refresh ShipStation Inventory Locations', 'woocommerce-shipstation-integration-wr' ); ?></button>
                </p>
                <div id="wsi-wr-refresh-locations-result" class="wsi-wr-tool-result"></div>

                <p class="description">
                    <?php esc_html_e( 'Select the ShipStation inventory location to deduct from when a WooCommerce order is paid. If only one location exists, it is auto-selected on first refresh. Save settings after selecting a location.', 'woocommerce-shipstation-integration-wr' ); ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'This plugin dynamically fetches locations from ShipStation and does not hardcode any location ID. Use the refresh button to discover locations for any ShipStation account.', 'woocommerce-shipstation-integration-wr' ); ?>
                </p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Saves the selected inventory location ID from the form dropdown.
     * Reads a custom POST field (not the WC settings array field) and stores it in a separate option.
     * Returns '' so nothing is stored in the main settings array for this field.
     */
    public function validate_export_location_tools_field( string $key, $value ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WC_Integration framework before this runs
        if ( ! isset( $_POST['wsi_wr_export_location_select'] ) ) {
            return '';
        }

        $location_id      = sanitize_text_field( trim( wp_unslash( (string) $_POST['wsi_wr_export_location_select'] ) ) );
        $location_service = new Inventory_Location_Service();

        if ( '' === $location_id ) {
            update_option( Inventory_Location_Service::SELECTED_LOCATION_OPTION, '' );
        } else {
            $cached    = $location_service->get_cached_locations();
            $valid_ids = array_column( $cached, 'inventory_location_id' );
            if ( in_array( $location_id, $valid_ids, true ) ) {
                $location_service->set_selected_location_id( $location_id );
            }
        }

        return '';
    }
}
