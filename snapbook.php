<?php

/**
 * Plugin Name:  SnapBook
 * Plugin URI:   https://snapbookplugin.com
 * Description:  Multi-step photography booking with backend management and WooCommerce checkout. Shortcode: [snapbook]
 * Version:      2.4.7
 * Author:       Razibul Hasan
 * Author URI:   https://snapbookplugin.com
 * Text Domain:  snapbook
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('SB_VER', '2.4.7');
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
 * URL of the icon-font stylesheet loaded on the booking form and the
 * SnapBook admin pages. Filter 'sb_icon_library_url' to swap the
 * library or return '' to disable loading it.
 */
function sb_icon_library_url()
{
    return (string) apply_filters(
        'sb_icon_library_url',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css'
    );
}

/**
 * Render an icon value that may be an emoji or an icon-font class
 * (e.g. "fa-solid fa-camera" or "dashicons dashicons-camera").
 */
function sb_icon_html($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[a-z0-9 _-]+$/i', $value) && preg_match('/(^|\s)(fa-|dashicons)/', $value)) {
        return '<i class="' . esc_attr($value) . '" aria-hidden="true"></i>';
    }

    return esc_html($value);
}

/**
 * Plain-text variant of sb_icon_html() for contexts that cannot render
 * HTML (<option> elements, emails): emoji pass through unchanged, icon
 * classes are dropped so class names never show as literal text.
 */
function sb_icon_text($value)
{
    $value = trim((string) $value);
    if ($value !== '' && preg_match('/^[a-z0-9 _-]+$/i', $value) && preg_match('/(^|\s)(fa-|dashicons)/', $value)) {
        return '';
    }

    return $value;
}

/**
 * Find the page (or post) that hosts the booking form: shortcode in the
 * content, or the SnapBook Elementor widget / shortcode in Elementor data.
 */
function sb_detect_booking_page_id()
{
    global $wpdb;

    $id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT ID FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type IN ('page','post')
		   AND (post_content LIKE '%[snapbook%' OR post_content LIKE '%[focus_booking%')
		 ORDER BY post_type = 'page' DESC, ID ASC LIMIT 1"
    );
    if ($id) {
        return $id;
    }

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		 WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
		   AND (m.meta_value LIKE '%snapbook_booking_form%' OR m.meta_value LIKE '%[snapbook%' OR m.meta_value LIKE '%[focus_booking%')
		 ORDER BY p.post_type = 'page' DESC, p.ID ASC LIMIT 1"
    );
}

/**
 * URL of the booking page used to build shareable package links.
 * Admin choice (fpb_booking_page_id) wins; otherwise auto-detect.
 * Returns '' when no booking page could be found.
 */
function sb_get_booking_page_url()
{
    $page_id = (int) get_option('fpb_booking_page_id', 0);
    if ($page_id && 'publish' === get_post_status($page_id)) {
        return (string) get_permalink($page_id);
    }

    $detected = sb_detect_booking_page_id();
    return $detected ? (string) get_permalink($detected) : '';
}

/**
 * Shareable deep-link for a package row — slug preferred, ID as fallback,
 * so the link survives package renames.
 */
function sb_package_share_link($pkg, $booking_url = null)
{
    if (null === $booking_url) {
        $booking_url = sb_get_booking_page_url();
    }
    if ($booking_url === '') {
        $booking_url = home_url('/');
    }
    $ident = (isset($pkg->slug) && $pkg->slug !== '') ? $pkg->slug : (string) (int) $pkg->id;

    return add_query_arg('package', rawurlencode($ident), $booking_url);
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