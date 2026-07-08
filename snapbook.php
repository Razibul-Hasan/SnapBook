<?php

/**
 * Plugin Name:  SnapBook
 * Plugin URI:   https://snapbookplugin.com
 * Description:  Multi-step photography booking with backend management and WooCommerce checkout. Shortcode: [snapbook]
 * Version:      2.1.0
 * Author:       SnapBook
 * Author URI:   https://snapbookplugin.com
 * Text Domain:  snapbook
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('SB_VER', '2.1.0');
define('SB_URL', plugin_dir_url(__FILE__));
define('SB_DIR', plugin_dir_path(__FILE__));

// Backward compatibility
define('FPB_VER', SB_VER);
define('FPB_URL', SB_URL);
define('FPB_DIR', SB_DIR);

// install.php required early so activation callback exists at hook-time
require_once SB_DIR . 'includes/install.php';

register_activation_hook(__FILE__, 'sb_activate');
register_deactivation_hook(__FILE__, 'sb_deactivate');

// Compatibility wrappers while internal function names remain fpb_*.
if (!function_exists('sb_activate')) {
    function sb_activate()
    {
        fpb_activate();
    }
}
if (!function_exists('sb_deactivate')) {
    function sb_deactivate()
    {
        fpb_deactivate();
    }
}
if (!function_exists('sb_create_tables')) {
    function sb_create_tables()
    {
        fpb_create_tables();
    }
}
if (!function_exists('sb_seed_defaults')) {
    function sb_seed_defaults()
    {
        fpb_seed_defaults();
    }
}
if (!function_exists('sb_migrate_emoji')) {
    function sb_migrate_emoji()
    {
        fpb_migrate_emoji();
    }
}

if (!function_exists('sb_get_currency_symbol')) {
    function sb_get_currency_symbol()
    {
        if (class_exists('WooCommerce') && function_exists('get_woocommerce_currency')) {
            $code = get_woocommerce_currency();
            if (function_exists('get_woocommerce_currency_symbol')) {
                $symbol = get_woocommerce_currency_symbol($code);
                if (! empty($symbol)) {
                    return $symbol;
                }
            }
        }

        return get_option('fpb_currency_sym', '€');
    }
}

/**
 * Show an admin notice with an install/activate link when WooCommerce is missing.
 */
add_action('admin_notices', 'sb_woocommerce_missing_notice');
function sb_woocommerce_missing_notice()
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
        $action_label = __('Activate WooCommerce', 'snapbook');
    } else {
        $action_url   = $install_url;
        $action_label = __('Install WooCommerce', 'snapbook');
    }

    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
        esc_html__('SnapBook requires WooCommerce.', 'snapbook'),
        esc_html__('Please install and activate WooCommerce to use this plugin.', 'snapbook'),
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
add_action('plugins_loaded', 'sb_load');
function sb_load()
{
    // Auto-create / upgrade tables if the stored DB version doesn't match
    // This handles cases where activation hook didn't fire (e.g. manual install)
    if (get_option('fpb_db_version') !== FPB_VER) {
        sb_create_tables();
        sb_seed_defaults();
    }

    // One-time migration: fill blank emoji on existing rows
    sb_migrate_emoji();

    require_once FPB_DIR . 'includes/admin.php';
    require_once FPB_DIR . 'includes/ajax.php';
    require_once FPB_DIR . 'includes/shortcode.php';
    require_once FPB_DIR . 'includes/gutenberg.php';
    require_once FPB_DIR . 'includes/elementor.php';
    if (class_exists('WooCommerce')) {
        require_once FPB_DIR . 'includes/woocommerce.php';
    }
}
