<?php

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void {
        require_once WSI_WR_PLUGIN_DIR . 'includes/class-db-schema.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/utilities/class-logger.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/api/class-api-client.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/sync/class-product-matcher.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/sync/class-tracked-sku-registry.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/sync/class-order-protection.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/sync/class-sync-service.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/admin/class-integration.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/admin/class-ajax-handlers.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/admin/class-admin-pages.php';
        require_once WSI_WR_PLUGIN_DIR . 'includes/scheduler/class-scheduler.php';
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_filter( 'woocommerce_integrations', [ $this, 'add_integration' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        Admin\Ajax_Handlers::register();
        Admin\Admin_Pages::register();
        Scheduler\Scheduler::register();
        add_action( 'woocommerce_update_options_integration_wsi_wr_shipstation', [ Scheduler\Scheduler::class, 'schedule' ] );
        add_filter( 'wsi_wr_api_page_size', fn() => (int) Admin\Integration::get_setting( 'page_size', 100 ) );
        add_filter( 'wsi_wr_api_timeout', fn() => (int) Admin\Integration::get_setting( 'request_timeout', 30 ) );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'woocommerce-shipstation-integration-wr',
            false,
            dirname( plugin_basename( WSI_WR_PLUGIN_FILE ) ) . '/languages'
        );
    }

    public function add_integration( array $integrations ): array {
        $integrations[] = Admin\Integration::class;
        return $integrations;
    }

    public function enqueue_admin_assets( string $hook ): void {
        $on_settings_page = 'woocommerce_page_wc-settings' === $hook
            && 'integration' === ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'wsi_wr_shipstation' === ( isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $on_reports_page = 'woocommerce_page_wsi-wr-reports' === $hook;

        if ( ! $on_settings_page && ! $on_reports_page ) {
            return;
        }

        wp_enqueue_style(
            'wsi-wr-admin',
            WSI_WR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WSI_WR_VERSION
        );

        wp_enqueue_script(
            'wsi-wr-admin',
            WSI_WR_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WSI_WR_VERSION,
            true
        );

        $localize = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonces'   => [
                'test_connection' => wp_create_nonce( 'wsi_wr_test_connection' ),
                'fetch_sample'    => wp_create_nonce( 'wsi_wr_fetch_inventory_sample' ),
            ],
            'strings'  => [
                'test_connection_label'     => __( 'Test Connection', 'woocommerce-shipstation-integration-wr' ),
                'fetch_sample_label'        => __( 'Fetch Inventory Sample', 'woocommerce-shipstation-integration-wr' ),
                'run_report_label'          => __( 'Run SKU Alignment Report', 'woocommerce-shipstation-integration-wr' ),
                'testing'                   => __( 'Testing…', 'woocommerce-shipstation-integration-wr' ),
                'fetching'                  => __( 'Fetching…', 'woocommerce-shipstation-integration-wr' ),
                'running'                   => __( 'Running…', 'woocommerce-shipstation-integration-wr' ),
                'saving'                    => __( 'Saving…', 'woocommerce-shipstation-integration-wr' ),
                'success'                   => __( 'Success:', 'woocommerce-shipstation-integration-wr' ),
                'error'                     => __( 'Error:', 'woocommerce-shipstation-integration-wr' ),
                'request_failed'            => __( 'Request failed. Check your browser console and try again.', 'woocommerce-shipstation-integration-wr' ),
                'available_field_confirmed' => __( 'Available field confirmed in response', 'woocommerce-shipstation-integration-wr' ),
                'total_records'             => __( 'Total records in ShipStation', 'woocommerce-shipstation-integration-wr' ),
                'showing'                   => __( 'Showing', 'woocommerce-shipstation-integration-wr' ),
                'records'                   => __( 'records', 'woocommerce-shipstation-integration-wr' ),
                'page_label'                => __( 'Page', 'woocommerce-shipstation-integration-wr' ),
                'no_records'                => __( 'No inventory records returned.', 'woocommerce-shipstation-integration-wr' ),
                'on_hand'                   => __( 'On Hand', 'woocommerce-shipstation-integration-wr' ),
                'available'                 => __( 'Available (sync target)', 'woocommerce-shipstation-integration-wr' ),
                'download_csv'              => __( 'Download CSV', 'woocommerce-shipstation-integration-wr' ),
                'pagination_incomplete'     => __( '⚠ Pagination did not complete — report may be partial.', 'woocommerce-shipstation-integration-wr' ),
                'sku_col'                   => __( 'SKU', 'woocommerce-shipstation-integration-wr' ),
                'product_id_col'            => __( 'WC Product ID', 'woocommerce-shipstation-integration-wr' ),
                'current_stock_col'         => __( 'WC Stock', 'woocommerce-shipstation-integration-wr' ),
                'ss_available_col'          => __( 'SS Available', 'woocommerce-shipstation-integration-wr' ),
                'ss_on_hand_col'            => __( 'SS On Hand', 'woocommerce-shipstation-integration-wr' ),
                'notes_col'                 => __( 'Notes', 'woocommerce-shipstation-integration-wr' ),
                'approve_label'             => __( 'Add / Approve SKU', 'woocommerce-shipstation-integration-wr' ),
                'reload_page'               => __( 'Page will reload to show updated registry.', 'woocommerce-shipstation-integration-wr' ),
                'run_dry_run_label'         => __( 'Run Dry-Run Sync', 'woocommerce-shipstation-integration-wr' ),
                'run_manual_sync_label'     => __( 'Run Manual Sync', 'woocommerce-shipstation-integration-wr' ),
                'proposed_stock_col'        => __( 'Proposed Stock', 'woocommerce-shipstation-integration-wr' ),
                'new_stock_col'             => __( 'New Stock', 'woocommerce-shipstation-integration-wr' ),
            ],
        ];

        wp_localize_script( 'wsi-wr-admin', 'WSI_WR_Admin', $localize );
    }
}
