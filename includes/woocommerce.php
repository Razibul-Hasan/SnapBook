<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   Cart item data — attach booking to cart item
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_add_cart_item_data', 'fpb_add_cart_item_data', 10, 2);
function fpb_add_cart_item_data($cart_item_data, $product_id)
{
    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    if ((int) $product_id !== $fpb_product) return $cart_item_data;

    // Remove session data so it doesn't carry over to a second add
    $booking = WC()->session->get('fpb_pending_booking');
    if ($booking) {
        $cart_item_data['fpb_booking'] = $booking;
        $cart_item_data['unique_key']  = uniqid('fpb_', true); // force unique item
        WC()->session->__unset('fpb_pending_booking');
    }
    return $cart_item_data;
}

/* ═══════════════════════════════════════════════════════════════
   Set deposit price on cart item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_before_calculate_totals', 'fpb_set_cart_item_price', 20);
function fpb_set_cart_item_price($cart)
{
    if (is_admin() && ! defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;
    foreach ($cart->get_cart() as $item) {
        if (isset($item['fpb_booking']['deposit'])) {
            $item['data']->set_price((float) $item['fpb_booking']['deposit']);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   Rename cart item line
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_cart_item_name', 'fpb_cart_item_name', 10, 2);
function fpb_cart_item_name($name, $cart_item)
{
    if (isset($cart_item['fpb_booking']['package_name'])) {
        return esc_html__('Photography Session', 'snapbook')
            . ' — ' . esc_html($cart_item['fpb_booking']['package_name']);
    }
    return $name;
}

/* ═══════════════════════════════════════════════════════════════
   Show booking summary in cart / checkout
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_get_item_data', 'fpb_get_item_data', 10, 2);
function fpb_get_item_data($data, $cart_item)
{
    if (! isset($cart_item['fpb_booking'])) return $data;
    $b = $cart_item['fpb_booking'];
    $cur = $b['currency'] ?? get_option('fpb_currency_sym', '€');

    if (! empty($b['session_type']))  $data[] = ['name' => __('Session',      'snapbook'), 'value' => esc_html($b['session_type'])];
    if (! empty($b['session_date']))  $data[] = ['name' => __('Date',         'snapbook'), 'value' => esc_html($b['session_date'])];
    if (! empty($b['client_name']))   $data[] = ['name' => __('Client',       'snapbook'), 'value' => esc_html($b['client_name'])];
    if (! empty($b['addons_label']))  $data[] = ['name' => __('Add-ons',      'snapbook'), 'value' => esc_html($b['addons_label'])];
    /* translators: 1: currency symbol, 2: deposit amount, 3: deposit percentage */
    $deposit_label = sprintf(__('%1$s%2$s (deposit, %3$d%%)', 'snapbook'), esc_html($cur), number_format($b['deposit'] ?? 0, 2), (int) get_option('fpb_deposit_pct', 50));
    $data[] = ['name' => __('Due today', 'snapbook'), 'value' => $deposit_label];

    return $data;
}

/* ═══════════════════════════════════════════════════════════════
   Save booking meta to order item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_checkout_create_order_line_item', 'fpb_save_order_item_meta', 10, 4);
function fpb_save_order_item_meta($item, $cart_item_key, $values, $order)
{
    if (! isset($values['fpb_booking'])) return;
    $b = $values['fpb_booking'];
    $cur = $b['currency'] ?? get_option('fpb_currency_sym', '€');

    $meta_map = [
        '_fpb_session_type'  => $b['session_type']  ?? '',
        '_fpb_package_name'  => $b['package_name']  ?? '',
        '_fpb_total'         => $b['total']         ?? '',
        '_fpb_deposit'       => $b['deposit']        ?? '',
        '_fpb_addons_label'  => $b['addons_label']  ?? '',
        '_fpb_addons_total'  => $b['addons_total']  ?? '',
        '_fpb_client_name'   => $b['client_name']   ?? '',
        '_fpb_client_email'  => $b['client_email']  ?? '',
        '_fpb_client_phone'  => $b['client_phone']  ?? '',
        '_fpb_client_country' => $b['client_country'] ?? '',
        '_fpb_session_date'  => $b['session_date']  ?? '',
        '_fpb_session_time'  => $b['session_time']  ?? '',
        '_fpb_location_pref' => $b['location_pref'] ?? '',
        '_fpb_notes'         => $b['notes']         ?? '',
        '_fpb_signer_name'   => $b['signer_name']   ?? '',
        '_fpb_currency'      => $cur,
    ];
    foreach ($meta_map as $key => $val) {
        $item->add_meta_data($key, $val, true);
    }
}

/* ═══════════════════════════════════════════════════════════════
   On payment complete — save booking to DB and block date
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_payment_complete', 'fpb_on_paid_order', 10, 1);
add_action('woocommerce_order_status_processing', 'fpb_on_paid_order', 10, 1);
add_action('woocommerce_order_status_completed', 'fpb_on_paid_order', 10, 1);
function fpb_on_paid_order($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order) return;

    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    foreach ($order->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        if ((int) $item->get_product_id() !== $fpb_product) continue;

        // Collect meta
        $session_type   = $item->get_meta('_fpb_session_type');
        $package_name   = $item->get_meta('_fpb_package_name');
        $session_date   = $item->get_meta('_fpb_session_date');

        // Avoid duplicate inserts (order payment_complete fires once, but let's be safe)
        global $wpdb;
        $pfx = $wpdb->prefix . 'fpb_';
        $already = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pfx}bookings WHERE order_id=%d LIMIT 1", $order_id)); // phpcs:ignore
        if ($already) return;

        $deposit_pct = (int) get_option('fpb_deposit_pct', 50);
        $deposit     = (float) $item->get_meta('_fpb_deposit');
        $total       = $deposit > 0 ? round($deposit * 100 / max($deposit_pct, 1), 2) : (float) $item->get_meta('_fpb_total');

        $wpdb->insert("{$pfx}bookings", [ // phpcs:ignore
            'order_id'      => $order_id,
            'session_type'  => sanitize_text_field($session_type),
            'package_name'  => sanitize_text_field($package_name),
            'package_price' => floatval($item->get_meta('_fpb_total')) - floatval($item->get_meta('_fpb_addons_total')),
            'addons_json'   => sanitize_text_field($item->get_meta('_fpb_addons_label')),
            'addons_total'  => floatval($item->get_meta('_fpb_addons_total')),
            'total'         => $total,
            'deposit'       => $deposit,
            'client_name'   => sanitize_text_field($item->get_meta('_fpb_client_name')),
            'client_email'  => sanitize_email($item->get_meta('_fpb_client_email')),
            'client_phone'  => sanitize_text_field($item->get_meta('_fpb_client_phone')),
            'client_country' => sanitize_text_field($item->get_meta('_fpb_client_country')),
            'session_date'  => $session_date ?: null,
            'session_time'  => sanitize_text_field($item->get_meta('_fpb_session_time')),
            'location_pref' => sanitize_text_field($item->get_meta('_fpb_location_pref')),
            'notes'         => sanitize_textarea_field($item->get_meta('_fpb_notes')),
            'signer_name'   => sanitize_text_field($item->get_meta('_fpb_signer_name')),
            'status'        => 'confirmed',
        ]);

        // Mark session date as booked
        if ($session_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pfx}dates WHERE date_str=%s", $session_date)); // phpcs:ignore
            if ($existing) {
                $wpdb->update("{$pfx}dates", ['status' => 'booked'], ['date_str' => $session_date]); // phpcs:ignore
            } else {
                $wpdb->insert("{$pfx}dates", ['date_str' => $session_date, 'status' => 'booked']); // phpcs:ignore
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   Hide FPB product from shop / search
═══════════════════════════════════════════════════════════════ */
add_action('pre_get_posts', 'fpb_hide_product_from_catalog');
function fpb_hide_product_from_catalog($q)
{
    if (is_admin() || ! $q->is_main_query()) return;
    $fpb_id = (int) get_option('fpb_wc_product_id', 0);
    if (! $fpb_id) return;
    $not_in   = (array) $q->get('post__not_in');
    $not_in[] = $fpb_id;
    $q->set('post__not_in', $not_in);
}
