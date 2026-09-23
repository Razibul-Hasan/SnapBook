<?php

/**
 * SnapBook uninstall.
 *
 * Runs when the plugin is deleted from the Plugins screen. Nothing is removed
 * unless the site opted in under SnapBook → Settings → Plugin data
 * ("Delete all SnapBook data when the plugin is deleted", option
 * fpb_delete_data_on_uninstall). WooCommerce orders — and the payments on
 * them — are never deleted.
 *
 * @package SnapBook
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Remove SnapBook's data from the current site, if the site asked for it.
 *
 * @return bool Whether anything was removed.
 */
function snapbook_uninstall_site()
{
    global $wpdb;

    if ((int) get_option('fpb_delete_data_on_uninstall', 0) !== 1) {
        return false;
    }

    // The hidden booking product (read before the options go).
    $product_id = (int) get_option('fpb_wc_product_id', 0);
    if ($product_id > 0 && 'product' === get_post_type($product_id)) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product) {
            $product->delete(true);
        } else {
            wp_delete_post($product_id, true);
        }
    }

    // Tables.
    foreach (['bookings', 'dates', 'addons', 'packages', 'sessions'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'fpb_' . $table) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table names; removing the plugin's own tables.
    }

    // Scheduled jobs (every instance, whatever its arguments).
    foreach (['snapbook_balance_reminder_sweep', 'snapbook_maintenance', 'snapbook_gcal_sync_event', 'snapbook_gcal_create_event', 'fpb_send_balance_reminder_event'] as $hook) {
        wp_unschedule_hook($hook);
    }

    // Options (fpb_* and snapbook_*) and SnapBook transients.
    $patterns = [
        'fpb\_%',
        'snapbook\_%',
        '\_transient\_fpb\_%',
        '\_transient\_timeout\_fpb\_%',
        '\_transient\_snapbook\_%',
        '\_transient\_timeout\_snapbook\_%',
    ];
    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
    wp_cache_flush();

    // The booking-management capability.
    $roles = function_exists('wp_roles') ? wp_roles() : null;
    if ($roles) {
        foreach (array_keys($roles->roles) as $role_name) {
            $role = get_role($role_name);
            if ($role && $role->has_cap('manage_snapbook')) {
                $role->remove_cap('manage_snapbook');
            }
        }
    }

    return true;
}

if (is_multisite()) {
    $snapbook_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($snapbook_site_ids as $snapbook_site_id) {
        switch_to_blog((int) $snapbook_site_id);
        snapbook_uninstall_site();
        restore_current_blog();
    }
} else {
    snapbook_uninstall_site();
}
