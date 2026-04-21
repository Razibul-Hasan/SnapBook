<?php

/**
 * Plugin Name:  Focus Photography Booking
 * Plugin URI:   https://focusphotography.ru
 * Description:  Multi-step photography booking with backend management and WooCommerce checkout. Shortcode: [focus_booking]
 * Version:      2.1.0
 * Author:       Focus Photography
 * Author URI:   https://focusphotography.ru
 * Text Domain:  focus-photography-booking
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('FPB_VER', '2.1.0');
define('FPB_URL', plugin_dir_url(__FILE__));
define('FPB_DIR', plugin_dir_path(__FILE__));

// install.php required early so activation callback exists at hook-time
require_once FPB_DIR . 'includes/install.php';

register_activation_hook(__FILE__, 'fpb_activate');
register_deactivation_hook(__FILE__, 'fpb_deactivate');

/**
 * Show an admin notice with an install/activate link when WooCommerce is missing.
 */
add_action('admin_notices', 'fpb_woocommerce_missing_notice');
function fpb_woocommerce_missing_notice()
{
    if (class_exists('WooCommerce')) {
        return;
    }

    $install_url  = wp_nonce_url(
        add_query_arg(
            ['action' => 'install-plugin', 'plugin' => 'woocommerce'],
            admin_url('update.php')
        ),
        'install-plugin_woocommerce'
    );

    $plugins      = get_plugins();
    $wc_installed = isset($plugins['woocommerce/woocommerce.php']);

    if ($wc_installed) {
        $action_url   = wp_nonce_url(
            add_query_arg(
                ['action' => 'activate', 'plugin' => 'woocommerce/woocommerce.php'],
                admin_url('plugins.php')
            ),
            'activate-plugin_woocommerce/woocommerce.php'
        );
        $action_label = __('Activate WooCommerce', 'focus-photography-booking');
    } else {
        $action_url   = $install_url;
        $action_label = __('Install WooCommerce', 'focus-photography-booking');
    }

    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
        esc_html__('Focus Photography Booking requires WooCommerce.', 'focus-photography-booking'),
        esc_html__('Please install and activate WooCommerce to use this plugin.', 'focus-photography-booking'),
        esc_url($action_url),
        esc_html($action_label)
    );
}

// Declare WooCommerce HPOS (Custom Order Tables) compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});


// Remaining modules loaded after all plugins, so WC is available
add_action('plugins_loaded', 'fpb_load');
function fpb_load()
{
    // Bail entirely if WooCommerce is not active; the admin notice handles messaging
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Auto-create / upgrade tables if the stored DB version doesn't match
    // This handles cases where activation hook didn't fire (e.g. manual install)
    if (get_option('fpb_db_version') !== FPB_VER) {
        fpb_create_tables();
        fpb_seed_defaults();
    }

    // One-time migration: fill blank emoji on existing rows
    fpb_migrate_emoji();

    require_once FPB_DIR . 'includes/admin.php';
    require_once FPB_DIR . 'includes/ajax.php';
    require_once FPB_DIR . 'includes/shortcode.php';
    if (class_exists('WooCommerce')) {
        require_once FPB_DIR . 'includes/woocommerce.php';
    }
}
