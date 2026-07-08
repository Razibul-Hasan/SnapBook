<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   Enqueue checkout styles
═══════════════════════════════════════════════════════════════ */
add_action('wp_enqueue_scripts', 'fpb_enqueue_checkout_styles', 99);
function fpb_enqueue_checkout_styles()
{
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_style(
            'fpb-checkout-css',
            SB_URL . 'assets/css/checkout.css',
            [],
            SB_VER
        );
    }
}

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

function fpb_get_booking_from_cart()
{
    if (! function_exists('WC') || ! WC()->cart) return [];
    foreach (WC()->cart->get_cart() as $item) {
        if (! empty($item['fpb_booking']) && is_array($item['fpb_booking'])) {
            return $item['fpb_booking'];
        }
    }
    return [];
}

function fpb_checkout_has_booking()
{
    return ! empty(fpb_get_booking_from_cart());
}

function fpb_should_force_classic_checkout()
{
    if (is_admin() || ! function_exists('is_checkout') || ! is_checkout()) {
        return false;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return false;
    }

    return fpb_checkout_has_booking();
}

/* ═══════════════════════════════════════════════════════════════
   Checkout block fallback — render classic checkout for FPB
═══════════════════════════════════════════════════════════════ */
add_filter('the_content', 'fpb_force_classic_checkout_for_booking', 20);
function fpb_force_classic_checkout_for_booking($content)
{
    if (! fpb_should_force_classic_checkout()) {
        return $content;
    }

    global $post;
    if (! $post || ! has_block('woocommerce/checkout', $post)) {
        return $content;
    }

    static $rendering = false;
    if ($rendering) {
        return $content;
    }

    $rendering = true;
    $classic_checkout = do_shortcode('[woocommerce_checkout]');
    $rendering = false;

    return ! empty($classic_checkout) ? $classic_checkout : $content;
}

add_filter('render_block', 'fpb_replace_checkout_block_for_booking', 20, 2);
function fpb_replace_checkout_block_for_booking($block_content, $block)
{
    if (! fpb_should_force_classic_checkout()) {
        return $block_content;
    }

    if (empty($block['blockName']) || $block['blockName'] !== 'woocommerce/checkout') {
        return $block_content;
    }

    static $rendering = false;
    if ($rendering) {
        return $block_content;
    }

    $rendering = true;
    $classic_checkout = do_shortcode('[woocommerce_checkout]');
    $rendering = false;

    return ! empty($classic_checkout) ? $classic_checkout : $block_content;
}

/* ═══════════════════════════════════════════════════════════════
   Checkout fields — add booking-specific billing details
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_checkout_fields', 'fpb_customize_checkout_fields', 20);
function fpb_customize_checkout_fields($fields)
{
    if (! fpb_checkout_has_booking()) return $fields;

    if (isset($fields['billing']['billing_first_name'])) {
        $fields['billing']['billing_first_name']['label'] = __('First name', 'snapbook');
        $fields['billing']['billing_first_name']['placeholder'] = __('Surname', 'snapbook');
        $fields['billing']['billing_first_name']['priority'] = 10;
        $fields['billing']['billing_first_name']['required'] = true;
    }

    if (isset($fields['billing']['billing_last_name'])) {
        $fields['billing']['billing_last_name']['label'] = __('Last name', 'snapbook');
        $fields['billing']['billing_last_name']['placeholder'] = __('Name', 'snapbook');
        $fields['billing']['billing_last_name']['priority'] = 20;
        $fields['billing']['billing_last_name']['required'] = true;
    }

    $fields['billing']['billing_event_date'] = [
        'type'        => 'date',
        'label'       => __('Date', 'snapbook'),
        'placeholder' => __('Date of the event', 'snapbook'),
        'required'    => true,
        'class'       => ['form-row-first'],
        'priority'    => 30,
    ];

    $fields['billing']['billing_event_time'] = [
        'type'        => 'time',
        'label'       => __('Start Time', 'snapbook'),
        'placeholder' => __('Time of the event', 'snapbook'),
        'required'    => true,
        'class'       => ['form-row-last'],
        'priority'    => 40,
    ];

    $fields['billing']['billing_hotel_place'] = [
        'type'        => 'text',
        'label'       => __('Hotel Name / Bungalow / Place Residence', 'snapbook'),
        'placeholder' => __('Hotel / Bungalow / Place', 'snapbook'),
        'required'    => true,
        'class'       => ['form-row-wide'],
        'priority'    => 50,
    ];

    $fields['billing']['billing_participants'] = [
        'type'        => 'number',
        'label'       => __('Participants', 'snapbook'),
        'placeholder' => __('Number of People', 'snapbook'),
        'required'    => true,
        'class'       => ['form-row-first'],
        'priority'    => 60,
        'custom_attributes' => [
            'min' => '1',
            'step' => '1',
        ],
    ];

    $fields['billing']['billing_room_number'] = [
        'type'        => 'number',
        'label'       => __('Room Number', 'snapbook'),
        'placeholder' => __('Room number', 'snapbook'),
        'required'    => false,
        'class'       => ['form-row-last'],
        'priority'    => 70,
    ];

    $fields['billing']['billing_stay_period'] = [
        'type'        => 'text',
        'label'       => __('Period of stay in Mauritius', 'snapbook'),
        'placeholder' => __('From - To', 'snapbook'),
        'required'    => true,
        'class'       => ['form-row-wide'],
        'priority'    => 80,
    ];

    if (isset($fields['billing']['billing_country'])) {
        $fields['billing']['billing_country']['label'] = __('Country / Region', 'snapbook');
        $fields['billing']['billing_country']['class'] = ['form-row-wide'];
        $fields['billing']['billing_country']['priority'] = 90;
        $fields['billing']['billing_country']['required'] = true;
    }

    if (isset($fields['billing']['billing_address_1'])) {
        $fields['billing']['billing_address_1']['label'] = __('Street address', 'snapbook');
        $fields['billing']['billing_address_1']['placeholder'] = __('House number and street name', 'snapbook');
        $fields['billing']['billing_address_1']['class'] = ['form-row-wide'];
        $fields['billing']['billing_address_1']['priority'] = 100;
        $fields['billing']['billing_address_1']['required'] = true;
    }

    if (isset($fields['billing']['billing_city'])) {
        $fields['billing']['billing_city']['label'] = __('Town / City', 'snapbook');
        $fields['billing']['billing_city']['class'] = ['form-row-first'];
        $fields['billing']['billing_city']['priority'] = 110;
        $fields['billing']['billing_city']['required'] = true;
    }

    if (isset($fields['billing']['billing_postcode'])) {
        $fields['billing']['billing_postcode']['label'] = __('Postcode / ZIP', 'snapbook');
        $fields['billing']['billing_postcode']['class'] = ['form-row-last'];
        $fields['billing']['billing_postcode']['priority'] = 120;
        $fields['billing']['billing_postcode']['required'] = true;
    }

    if (isset($fields['billing']['billing_phone'])) {
        $fields['billing']['billing_phone']['label'] = __('Whatsapp', 'snapbook');
        $fields['billing']['billing_phone']['class'] = ['form-row-first'];
        $fields['billing']['billing_phone']['priority'] = 130;
        $fields['billing']['billing_phone']['required'] = true;
    }

    if (isset($fields['billing']['billing_email'])) {
        $fields['billing']['billing_email']['label'] = __('Email address', 'snapbook');
        $fields['billing']['billing_email']['class'] = ['form-row-last'];
        $fields['billing']['billing_email']['priority'] = 140;
        $fields['billing']['billing_email']['required'] = true;
    }

    return $fields;
}

add_filter('woocommerce_checkout_get_value', 'fpb_prefill_checkout_values', 10, 2);
function fpb_prefill_checkout_values($value, $input)
{
    if (! fpb_checkout_has_booking()) return $value;
    if (! empty($value)) return $value;

    $booking = fpb_get_booking_from_cart();
    if (empty($booking)) return $value;

    if ($input === 'billing_event_date' && ! empty($booking['session_date'])) {
        return sanitize_text_field($booking['session_date']);
    }

    if ($input === 'billing_event_time' && ! empty($booking['session_time'])) {
        return sanitize_text_field($booking['session_time']);
    }

    if ($input === 'billing_hotel_place' && ! empty($booking['location_pref'])) {
        return sanitize_text_field($booking['location_pref']);
    }

    if ($input === 'billing_phone' && ! empty($booking['client_phone'])) {
        return sanitize_text_field($booking['client_phone']);
    }

    if ($input === 'billing_email' && ! empty($booking['client_email'])) {
        return sanitize_email($booking['client_email']);
    }

    if ($input === 'billing_country' && ! empty($booking['client_country'])) {
        $country = strtoupper(sanitize_text_field($booking['client_country']));
        if (strlen($country) === 2) {
            return $country;
        }
    }

    if ($input === 'billing_first_name' && ! empty($booking['client_name'])) {
        $name_parts = preg_split('/\s+/', trim((string) $booking['client_name']));
        return sanitize_text_field($name_parts[0] ?? '');
    }

    if ($input === 'billing_last_name' && ! empty($booking['client_name'])) {
        $name_parts = preg_split('/\s+/', trim((string) $booking['client_name']));
        array_shift($name_parts);
        return sanitize_text_field(implode(' ', $name_parts));
    }

    return $value;
}

add_action('woocommerce_after_checkout_validation', 'fpb_validate_checkout_fields', 10, 2);
function fpb_validate_checkout_fields($data, $errors)
{
    if (! fpb_checkout_has_booking()) return;

    $required = [
        'billing_event_date' => __('Date of the event is required.', 'snapbook'),
        'billing_event_time' => __('Time of the event is required.', 'snapbook'),
        'billing_hotel_place' => __('Hotel / bungalow / place is required.', 'snapbook'),
        'billing_participants' => __('Number of participants is required.', 'snapbook'),
        'billing_stay_period' => __('Period of stay in Mauritius is required.', 'snapbook'),
    ];

    foreach ($required as $key => $message) {
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        if ($value === '') {
            $errors->add('validation', $message);
        }
    }

    $participants = isset($_POST['billing_participants']) ? absint(wp_unslash($_POST['billing_participants'])) : 0;
    if ($participants < 1) {
        $errors->add('validation', __('Participants must be at least 1.', 'snapbook'));
    }
}

add_action('woocommerce_checkout_create_order', 'fpb_store_checkout_fields_on_order', 10, 2);
function fpb_store_checkout_fields_on_order($order, $data)
{
    if (! fpb_checkout_has_booking()) return;

    $map = [
        'billing_event_date'   => '_fpb_billing_event_date',
        'billing_event_time'   => '_fpb_billing_event_time',
        'billing_hotel_place'  => '_fpb_billing_hotel_place',
        'billing_participants' => '_fpb_billing_participants',
        'billing_room_number'  => '_fpb_billing_room_number',
        'billing_stay_period'  => '_fpb_billing_stay_period',
    ];

    foreach ($map as $field => $meta_key) {
        $raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
        $value = $field === 'billing_participants' ? absint($raw) : sanitize_text_field($raw);
        $order->update_meta_data($meta_key, $value);
    }
}

/* ═══════════════════════════════════════════════════════════════
   Set full booking price on cart item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_before_calculate_totals', 'fpb_set_cart_item_price', 20);
function fpb_set_cart_item_price($cart)
{
    if (is_admin() && ! defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;
    foreach ($cart->get_cart() as $item) {
        if (isset($item['fpb_booking']['total'])) {
            $item['data']->set_price((float) $item['fpb_booking']['total']);
        } elseif (isset($item['fpb_booking']['deposit'])) {
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
    $cur = $b['currency'] ?? sb_get_currency_symbol();

    if (! empty($b['session_type']))  $data[] = ['name' => __('Session',      'snapbook'), 'value' => esc_html($b['session_type'])];
    if (! empty($b['session_date']))  $data[] = ['name' => __('Date',         'snapbook'), 'value' => esc_html($b['session_date'])];
    if (! empty($b['client_name']))   $data[] = ['name' => __('Client',       'snapbook'), 'value' => esc_html($b['client_name'])];
    if (! empty($b['addons_label']))  $data[] = ['name' => __('Add-ons',      'snapbook'), 'value' => esc_html($b['addons_label'])];
    $due_today = number_format((float) ($b['total'] ?? ($b['deposit'] ?? 0)), 2);
    $data[] = ['name' => __('Due today', 'snapbook'), 'value' => esc_html($cur) . $due_today];

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
    $cur = $b['currency'] ?? sb_get_currency_symbol();

    $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $client_name = ! empty($b['client_name']) ? $b['client_name'] : $billing_name;
    $client_email = ! empty($b['client_email']) ? $b['client_email'] : $order->get_billing_email();
    $client_phone = ! empty($b['client_phone']) ? $b['client_phone'] : $order->get_billing_phone();
    $client_country = ! empty($b['client_country']) ? $b['client_country'] : $order->get_billing_country();
    $session_date = ! empty($b['session_date']) ? $b['session_date'] : $order->get_meta('_fpb_billing_event_date', true);
    $session_time = ! empty($b['session_time']) ? $b['session_time'] : $order->get_meta('_fpb_billing_event_time', true);
    $location_pref = ! empty($b['location_pref']) ? $b['location_pref'] : $order->get_meta('_fpb_billing_hotel_place', true);

    $meta_map = [
        '_fpb_session_type'  => $b['session_type']  ?? '',
        '_fpb_package_name'  => $b['package_name']  ?? '',
        '_fpb_total'         => $b['total']         ?? '',
        '_fpb_deposit'       => $b['deposit']        ?? '',
        '_fpb_addons_label'  => $b['addons_label']  ?? '',
        '_fpb_addons_total'  => $b['addons_total']  ?? '',
        '_fpb_client_name'   => $client_name,
        '_fpb_client_email'  => $client_email,
        '_fpb_client_phone'  => $client_phone,
        '_fpb_client_country' => $client_country,
        '_fpb_session_date'  => $session_date,
        '_fpb_session_time'  => $session_time,
        '_fpb_location_pref' => $location_pref,
        '_fpb_notes'         => $b['notes']         ?? '',
        '_fpb_signer_name'   => $b['signer_name']   ?? '',
        '_fpb_billing_event_date'   => $order->get_meta('_fpb_billing_event_date', true),
        '_fpb_billing_event_time'   => $order->get_meta('_fpb_billing_event_time', true),
        '_fpb_billing_hotel_place'  => $order->get_meta('_fpb_billing_hotel_place', true),
        '_fpb_billing_participants' => $order->get_meta('_fpb_billing_participants', true),
        '_fpb_billing_room_number'  => $order->get_meta('_fpb_billing_room_number', true),
        '_fpb_billing_stay_period'  => $order->get_meta('_fpb_billing_stay_period', true),
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
