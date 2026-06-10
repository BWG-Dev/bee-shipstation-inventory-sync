<?php
/**
 * Plugin Name: WooCommerce ShipStation Integration WR
 * Description: Controlled ShipStation inventory pull integration for WooCommerce with discovery reporting, dry-run synchronization, tracked SKU safety, and scheduled stock updates.
 * Version: 0.4.0
 * Author: Bennett Web Group
 * Text Domain: woocommerce-shipstation-integration-wr
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

namespace WebReadyNow\WooCommerceShipStationIntegration;

defined( 'ABSPATH' ) || exit;

define( 'WSI_WR_VERSION', '0.4.0' );
define( 'WSI_WR_PLUGIN_FILE', __FILE__ );
define( 'WSI_WR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSI_WR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// HPOS compatibility declaration — must run before WooCommerce initialises features
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// Show an admin notice when WooCommerce is not active
add_action( 'admin_notices', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<div class="notice notice-error"><p>' .
            esc_html__(
                'WooCommerce ShipStation Integration WR requires WooCommerce to be installed and active.',
                'woocommerce-shipstation-integration-wr'
            ) .
            '</p></div>';
    }
} );

// Bootstrap — only when WooCommerce is available
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    require_once WSI_WR_PLUGIN_DIR . 'includes/class-plugin.php';
    Plugin::instance();
} );

register_activation_hook( __FILE__, function () {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-activator.php';
    Activator::activate();
} );

register_deactivation_hook( __FILE__, function () {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-deactivator.php';
    Deactivator::deactivate();
} );
