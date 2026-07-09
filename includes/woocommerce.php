<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   Enqueue checkout styles
═══════════════════════════════════════════════════════════════ */
add_action('wp_enqueue_scripts', 'snapbook_enqueue_checkout_styles', 99);
function snapbook_enqueue_checkout_styles()
{
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_style(
            'snapbook-checkout',
            SNAPBOOK_URL . 'assets/css/checkout.css',
            [],
            SNAPBOOK_VER
        );
    }
}

function snapbook_get_booking_from_cart()
{
    if (! function_exists('WC') || ! WC()->cart) return [];
    foreach (WC()->cart->get_cart() as $item) {
        if (! empty($item['fpb_booking']) && is_array($item['fpb_booking'])) {
            return $item['fpb_booking'];
        }
    }
    return [];
}

function snapbook_checkout_has_booking()
{
    return ! empty(snapbook_get_booking_from_cart());
}

function snapbook_partial_payment_enabled()
{
    return (int) get_option('fpb_enable_partial_payment', 1) === 1;
}

function snapbook_account_required_for_booking()
{
    return (int) get_option('fpb_require_account_booking', 0) === 1;
}

add_filter('woocommerce_checkout_registration_required', 'snapbook_force_checkout_registration_for_booking');
function snapbook_force_checkout_registration_for_booking($required)
{
    if (snapbook_checkout_has_booking() && snapbook_account_required_for_booking()) {
        return true;
    }

    return $required;
}

function snapbook_should_force_classic_checkout()
{
    if (is_admin() || ! function_exists('is_checkout') || ! is_checkout()) {
        return false;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return false;
    }

    return snapbook_checkout_has_booking();
}

/* ═══════════════════════════════════════════════════════════════
   Checkout block fallback — render classic checkout for FPB
═══════════════════════════════════════════════════════════════ */
add_filter('the_content', 'snapbook_force_classic_checkout_for_booking', 20);
function snapbook_force_classic_checkout_for_booking($content)
{
    if (! snapbook_should_force_classic_checkout()) {
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

add_filter('render_block', 'snapbook_replace_checkout_block_for_booking', 20, 2);
function snapbook_replace_checkout_block_for_booking($block_content, $block)
{
    if (! snapbook_should_force_classic_checkout()) {
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
add_filter('woocommerce_checkout_fields', 'snapbook_customize_checkout_fields', 20);
function snapbook_customize_checkout_fields($fields)
{
    if (! snapbook_checkout_has_booking()) return $fields;

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

    // Apply the admin-configured checkout form (labels / required / enabled)
    // so the same field manager drives both the booking form and this page.
    if (function_exists('snapbook_get_checkout_form_fields')) {
        $cfg = snapbook_get_checkout_form_fields();

        $core_map = [
            'first_name' => 'billing_first_name',
            'last_name'  => 'billing_last_name',
            'email'      => 'billing_email',
            'phone'      => 'billing_phone',
            'country'    => 'billing_country',
            'address_1'  => 'billing_address_1',
            'city'       => 'billing_city',
            'postcode'   => 'billing_postcode',
        ];
        foreach ($core_map as $key => $billing_key) {
            if (! isset($cfg[$key], $fields['billing'][$billing_key])) {
                continue;
            }
            $fields['billing'][$billing_key]['label']    = $cfg[$key]['label'];
            $fields['billing'][$billing_key]['required'] = ! empty($cfg[$key]['enabled']) && ! empty($cfg[$key]['required']);
        }

        $custom_map = [
            'event_time'   => 'billing_event_time',
            'hotel_place'  => 'billing_hotel_place',
            'participants' => 'billing_participants',
            'room_number'  => 'billing_room_number',
            'stay_period'  => 'billing_stay_period',
        ];
        foreach ($custom_map as $key => $billing_key) {
            if (! isset($cfg[$key])) {
                continue;
            }
            if (empty($cfg[$key]['enabled'])) {
                unset($fields['billing'][$billing_key]);
                continue;
            }
            if (isset($fields['billing'][$billing_key])) {
                $fields['billing'][$billing_key]['label']    = $cfg[$key]['label'];
                $fields['billing'][$billing_key]['required'] = ! empty($cfg[$key]['required']);
            }
        }
    }

    // Admin-created custom fields appear after the built-in ones.
    if (function_exists('snapbook_get_custom_checkout_fields')) {
        $priority = 150;
        foreach (snapbook_get_custom_checkout_fields() as $key => $cf) {
            $fields['billing']['billing_fpb_cf_' . $key] = [
                'type'        => $cf['type'] === 'textarea' ? 'textarea' : $cf['type'],
                'label'       => $cf['label'],
                'placeholder' => $cf['label'],
                'required'    => ! empty($cf['required']),
                'class'       => ['form-row-wide'],
                'priority'    => $priority,
            ];
            $priority += 10;
        }
    }

    return $fields;
}

add_filter('woocommerce_checkout_get_value', 'snapbook_prefill_checkout_values', 10, 2);
function snapbook_prefill_checkout_values($value, $input)
{
    if (! snapbook_checkout_has_booking()) return $value;
    if (! empty($value)) return $value;

    $booking = snapbook_get_booking_from_cart();
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

    $direct_map = [
        'billing_address_1'    => 'address_1',
        'billing_city'         => 'city',
        'billing_postcode'     => 'postcode',
        'billing_participants' => 'participants',
        'billing_room_number'  => 'room_number',
        'billing_stay_period'  => 'stay_period',
        'order_comments'       => 'notes',
    ];
    if (isset($direct_map[$input]) && ! empty($booking[$direct_map[$input]])) {
        return sanitize_text_field($booking[$direct_map[$input]]);
    }

    return $value;
}

add_action('woocommerce_after_checkout_validation', 'snapbook_validate_checkout_fields', 10, 2);
function snapbook_validate_checkout_fields($data, $errors)
{
    if (! snapbook_checkout_has_booking()) return;

    $cfg = function_exists('snapbook_get_checkout_form_fields') ? snapbook_get_checkout_form_fields() : [];

    $required = [
        'billing_event_date' => __('Date of the event is required.', 'snapbook'),
    ];
    $custom_map = [
        'event_time'   => 'billing_event_time',
        'hotel_place'  => 'billing_hotel_place',
        'participants' => 'billing_participants',
        'room_number'  => 'billing_room_number',
        'stay_period'  => 'billing_stay_period',
    ];
    foreach ($custom_map as $key => $billing_key) {
        if (! empty($cfg[$key]['enabled']) && ! empty($cfg[$key]['required'])) {
            /* translators: %s: field label */
            $required[$billing_key] = sprintf(__('%s is required.', 'snapbook'), $cfg[$key]['label']);
        }
    }
    if (function_exists('snapbook_get_custom_checkout_fields')) {
        foreach (snapbook_get_custom_checkout_fields() as $key => $cf) {
            if (! empty($cf['required'])) {
                /* translators: %s: field label */
                $required['billing_fpb_cf_' . $key] = sprintf(__('%s is required.', 'snapbook'), $cf['label']);
            }
        }
    }

    foreach ($required as $key => $message) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        if ($value === '') {
            $errors->add('validation', $message);
        }
    }

    if (! empty($cfg['participants']['enabled'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
        $participants = isset($_POST['billing_participants']) ? absint(wp_unslash($_POST['billing_participants'])) : 0;
        if ($participants < 1 && ! empty($cfg['participants']['required'])) {
            $errors->add('validation', __('Participants must be at least 1.', 'snapbook'));
        }
    }
}

add_action('woocommerce_checkout_create_order', 'snapbook_store_checkout_fields_on_order', 10, 2);
function snapbook_store_checkout_fields_on_order($order, $data)
{
    if (! snapbook_checkout_has_booking()) return;

    $map = [
        'billing_event_date'   => '_fpb_billing_event_date',
        'billing_event_time'   => '_fpb_billing_event_time',
        'billing_hotel_place'  => '_fpb_billing_hotel_place',
        'billing_participants' => '_fpb_billing_participants',
        'billing_room_number'  => '_fpb_billing_room_number',
        'billing_stay_period'  => '_fpb_billing_stay_period',
    ];
    if (function_exists('snapbook_get_custom_checkout_fields')) {
        foreach (snapbook_get_custom_checkout_fields() as $key => $cf) {
            $map['billing_fpb_cf_' . $key] = '_fpb_cf_' . $key;
        }
    }

    foreach ($map as $field => $meta_key) {
        if ($field === 'billing_participants') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
            $value = isset($_POST[$field]) ? absint(wp_unslash($_POST[$field])) : 0;
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }
        $order->update_meta_data($meta_key, $value);
    }
}

/* ═══════════════════════════════════════════════════════════════
   Set full booking price on cart item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_before_calculate_totals', 'snapbook_set_cart_item_price', 20);
function snapbook_set_cart_item_price($cart)
{
    if (is_admin() && ! defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;
    foreach ($cart->get_cart() as $item) {
        if (isset($item['fpb_booking']['deposit'])) {
            $item['data']->set_price((float) $item['fpb_booking']['deposit']);
        } elseif (isset($item['fpb_booking']['total'])) {
            $item['data']->set_price((float) $item['fpb_booking']['total']);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   Rename cart item line
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_cart_item_name', 'snapbook_cart_item_name', 10, 2);
function snapbook_cart_item_name($name, $cart_item)
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
add_filter('woocommerce_get_item_data', 'snapbook_get_item_data', 10, 2);
function snapbook_get_item_data($data, $cart_item)
{
    if (! isset($cart_item['fpb_booking'])) return $data;
    $b = $cart_item['fpb_booking'];
    $cur = $b['currency'] ?? snapbook_get_currency_symbol();

    if (! empty($b['session_type']))  $data[] = ['name' => __('Session',      'snapbook'), 'value' => esc_html($b['session_type'])];
    if (! empty($b['session_date']))  $data[] = ['name' => __('Date',         'snapbook'), 'value' => esc_html($b['session_date'])];
    if (! empty($b['client_name']))   $data[] = ['name' => __('Client',       'snapbook'), 'value' => esc_html($b['client_name'])];
    if (! empty($b['addons_label']))  $data[] = ['name' => __('Add-ons',      'snapbook'), 'value' => esc_html($b['addons_label'])];
    $due_amount = (float) ($b['total'] ?? 0);
    $due_today_raw = (float) ($b['deposit'] ?? $due_amount);
    $due_today = number_format($due_today_raw, 2);
    $data[] = ['name' => __('Due today', 'snapbook'), 'value' => esc_html($cur) . $due_today];
    if ($due_amount > $due_today_raw) {
        $data[] = ['name' => __('Remaining balance', 'snapbook'), 'value' => esc_html($cur) . number_format($due_amount - $due_today_raw, 2)];
    }

    return $data;
}

/* ═══════════════════════════════════════════════════════════════
   Save booking meta to order item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_checkout_create_order_line_item', 'snapbook_save_order_item_meta', 10, 4);
function snapbook_save_order_item_meta($item, $cart_item_key, $values, $order)
{
    if (! isset($values['fpb_booking'])) return;
    $b = $values['fpb_booking'];
    $cur = $b['currency'] ?? snapbook_get_currency_symbol();

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
        '_fpb_deposit'       => $b['deposit']       ?? '',
        '_fpb_deposit_pct'   => $b['deposit_pct']   ?? (snapbook_partial_payment_enabled() ? 50 : 100),
        '_fpb_balance_due'   => max(0, (float) ($b['total'] ?? 0) - (float) ($b['deposit'] ?? 0)),
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
add_action('woocommerce_payment_complete', 'snapbook_on_paid_order', 10, 1);
add_action('woocommerce_order_status_processing', 'snapbook_on_paid_order', 10, 1);
add_action('woocommerce_order_status_completed', 'snapbook_on_paid_order', 10, 1);
function snapbook_on_paid_order($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order) return;
    if ((int) $order->get_meta('_fpb_is_balance_order', true) === 1) return;

    snapbook_cleanup_checkout_draft_orders($order);

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

        $deposit_pct = (int) $item->get_meta('_fpb_deposit_pct');
        if ($deposit_pct < 1) {
            $deposit_pct = snapbook_partial_payment_enabled() ? 50 : 100;
        }
        $deposit     = (float) $item->get_meta('_fpb_deposit');
        $total       = (float) $item->get_meta('_fpb_total');
        if ($total <= 0 && $deposit > 0) {
            $total = round($deposit * 100 / max($deposit_pct, 1), 2);
        }
        if ($deposit <= 0) {
            $deposit = $total;
        }
        $balance_due = max(0, $total - $deposit);
        $booking_status = ($balance_due > 0.01) ? 'pending_payment' : 'confirmed';

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
            'status'        => $booking_status,
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

        if ($balance_due > 0.01) {
            $due_order_id = snapbook_create_balance_order($order, $item, $balance_due);
            if ($due_order_id > 0) {
                snapbook_schedule_balance_reminder($order_id);
            }
        }
    }
}

add_action('woocommerce_order_status_processing', 'snapbook_on_balance_order_paid', 20, 1);
add_action('woocommerce_order_status_completed', 'snapbook_on_balance_order_paid', 20, 1);
function snapbook_on_balance_order_paid($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    if ((int) $order->get_meta('_fpb_is_balance_order', true) !== 1) {
        return;
    }

    $parent_order_id = (int) $order->get_meta('_fpb_parent_order_id', true);
    if ($parent_order_id < 1) {
        return;
    }

    $parent_order = wc_get_order($parent_order_id);
    if ($parent_order && ! in_array($parent_order->get_status(), ['completed', 'cancelled', 'refunded'], true)) {
        $parent_order->set_status('completed');
        $parent_order->save();
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom bookings table, no core API available.
    $wpdb->update(
        $wpdb->prefix . 'fpb_bookings',
        ['status' => 'completed'],
        ['order_id' => $parent_order_id],
        ['%s'],
        ['%d']
    );
}

function snapbook_cleanup_checkout_draft_orders($paid_order)
{
    if (! $paid_order || ! function_exists('wc_get_orders')) {
        return;
    }

    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    if ($fpb_product < 1) {
        return;
    }

    $query = [
        'limit'   => 30,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
        'status'  => ['checkout-draft', 'auto-draft', 'draft'],
    ];

    $customer_id = (int) $paid_order->get_customer_id();
    if ($customer_id > 0) {
        $query['customer_id'] = $customer_id;
    } else {
        $billing_email = sanitize_email($paid_order->get_billing_email());
        if ($billing_email === '') {
            return;
        }

        $query['billing_email'] = $billing_email;
    }

    $draft_orders = wc_get_orders($query);
    if (empty($draft_orders)) {
        return;
    }

    $paid_order_id = (int) $paid_order->get_id();
    foreach ($draft_orders as $draft_order) {
        if (! $draft_order) {
            continue;
        }

        $draft_order_id = (int) $draft_order->get_id();
        if ($draft_order_id === $paid_order_id) {
            continue;
        }

        if ((int) $draft_order->get_meta('_fpb_is_balance_order', true) === 1) {
            continue;
        }

        $has_fpb_item = false;
        foreach ($draft_order->get_items() as $draft_item) {
            if ((int) $draft_item->get_product_id() === $fpb_product) {
                $has_fpb_item = true;
                break;
            }
        }

        if (! $has_fpb_item) {
            continue;
        }

        $draft_order->delete(true);
    }
}

function snapbook_create_balance_order($parent_order, $parent_item, $balance_due)
{
    if (! $parent_order || $balance_due <= 0) {
        return 0;
    }

    $existing_due_order_id = (int) $parent_order->get_meta('_fpb_due_order_id', true);
    if ($existing_due_order_id > 0) {
        return $existing_due_order_id;
    }

    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    if (! $fpb_product) {
        return 0;
    }

    $customer_id = (int) $parent_order->get_customer_id();
    $due_order = wc_create_order(['customer_id' => $customer_id]);
    if (! $due_order || is_wp_error($due_order)) {
        return 0;
    }

    $product = wc_get_product($fpb_product);
    if (! $product) {
        return 0;
    }

    $line_item = new WC_Order_Item_Product();
    $line_item->set_product($product);
    $line_item->set_quantity(1);
    $line_item->set_subtotal($balance_due);
    $line_item->set_total($balance_due);
    $line_item->add_meta_data('_fpb_is_balance_item', 1, true);
    $line_item->add_meta_data('_fpb_parent_order_id', (int) $parent_order->get_id(), true);
    $line_item->add_meta_data('_fpb_package_name', (string) $parent_item->get_meta('_fpb_package_name'), true);
    $line_item->add_meta_data('_fpb_session_date', (string) $parent_item->get_meta('_fpb_session_date'), true);
    $due_order->add_item($line_item);

    $due_order->set_currency($parent_order->get_currency());
    $due_order->set_address($parent_order->get_address('billing'), 'billing');
    $due_order->set_address($parent_order->get_address('shipping'), 'shipping');
    $due_order->update_meta_data('_fpb_is_balance_order', 1);
    $due_order->update_meta_data('_fpb_parent_order_id', (int) $parent_order->get_id());
    $due_order->update_meta_data('_fpb_balance_due', $balance_due);
    $due_order->update_meta_data('_fpb_package_name', (string) $parent_item->get_meta('_fpb_package_name'));
    $due_order->update_meta_data('_fpb_session_date', (string) $parent_item->get_meta('_fpb_session_date'));
    $due_order->calculate_totals(false);
    $due_order->set_status('pending');
    $due_order->save();

    $parent_order->update_meta_data('_fpb_due_order_id', (int) $due_order->get_id());
    $parent_order->save();

    return (int) $due_order->get_id();
}

add_action('fpb_send_balance_reminder_event', 'snapbook_send_scheduled_balance_reminder', 10, 1);
function snapbook_send_scheduled_balance_reminder($order_id)
{
    if ((int) get_option('fpb_enable_balance_reminders', 0) !== 1) {
        return;
    }

    snapbook_send_balance_reminder_email((int) $order_id, false);
}

function snapbook_schedule_balance_reminder($order_id)
{
    if ((int) get_option('fpb_enable_balance_reminders', 0) !== 1) {
        return;
    }

    $order_id = (int) $order_id;
    if ($order_id < 1) {
        return;
    }

    $hours = max(1, (int) get_option('fpb_balance_reminder_hours', 24));
    $timestamp = wp_next_scheduled('fpb_send_balance_reminder_event', [$order_id]);
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fpb_send_balance_reminder_event', [$order_id]);
    }

    wp_schedule_single_event(time() + ($hours * HOUR_IN_SECONDS), 'fpb_send_balance_reminder_event', [$order_id]);
}

function snapbook_send_balance_reminder_email($parent_order_id, $manual = false)
{
    $parent_order = wc_get_order((int) $parent_order_id);
    if (! $parent_order) {
        return false;
    }

    $due_order_id = (int) $parent_order->get_meta('_fpb_due_order_id', true);
    if ($due_order_id < 1) {
        return false;
    }

    $due_order = wc_get_order($due_order_id);
    if (! $due_order) {
        return false;
    }

    if (in_array($due_order->get_status(), ['processing', 'completed'], true)) {
        return false;
    }

    $to_email = sanitize_email($parent_order->get_billing_email());
    if (empty($to_email)) {
        return false;
    }

    $name = trim($parent_order->get_billing_first_name() . ' ' . $parent_order->get_billing_last_name());
    if ($name === '') {
        $name = __('Customer', 'snapbook');
    }

    $subject = get_option('fpb_balance_reminder_subject', __('Payment reminder for your booking', 'snapbook'));
    $template = get_option('fpb_balance_reminder_template', "Hi {customer_name},\n\nThis is a reminder that {balance_amount} is pending for your booking on {session_date}.\n\nPay now: {pay_link}\n\nThank you.");

    $currency = $parent_order->get_currency();
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : snapbook_get_currency_symbol();
    $balance_amount = $symbol . number_format((float) $due_order->get_total(), 2);
    $package_name = (string) $due_order->get_meta('_fpb_package_name', true);
    $session_date = (string) $due_order->get_meta('_fpb_session_date', true);
    if ($session_date === '') {
        $session_date = (string) $parent_order->get_meta('_fpb_billing_event_date', true);
    }

    $pay_link = $due_order->get_checkout_payment_url();
    $message = strtr((string) $template, [
        '{customer_name}' => $name,
        '{balance_amount}' => $balance_amount,
        '{session_date}' => $session_date ?: __('N/A', 'snapbook'),
        '{package_name}' => $package_name,
        '{pay_link}' => $pay_link,
        '{order_id}' => '#' . (int) $parent_order->get_id(),
    ]);

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $sent = wp_mail($to_email, sanitize_text_field($subject), nl2br(wp_kses_post($message)), $headers);
    if ($sent) {
        $parent_order->update_meta_data('_fpb_last_balance_reminder_sent', current_time('mysql'));
        $parent_order->update_meta_data('_fpb_last_balance_reminder_mode', $manual ? 'manual' : 'automatic');
        $parent_order->save();
    }

    return (bool) $sent;
}

/**
 * Whether an order is a SnapBook booking order (created by the plugin or
 * carrying the hidden booking product).
 */
function snapbook_is_booking_order($order)
{
    if (! $order) {
        return false;
    }
    if ($order->get_created_via() === 'snapbook') {
        return true;
    }
    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    if ($fpb_product < 1) {
        return false;
    }
    foreach ($order->get_items() as $item) {
        if ((int) $item->get_product_id() === $fpb_product) {
            return true;
        }
    }
    return false;
}

/**
 * Return the balance (remaining-payment) order for a parent booking order,
 * optionally creating it on demand from the booking line item. This lets the
 * "pay the rest" link appear in the very first confirmation email even if the
 * payment-complete hook that normally creates it hasn't run yet.
 *
 * @return WC_Order|null
 */
function snapbook_get_balance_order_for($parent_order, $create = false)
{
    if (! $parent_order || ! function_exists('wc_get_order')) {
        return null;
    }
    if ((int) $parent_order->get_meta('_fpb_is_balance_order', true) === 1) {
        return null; // never operate on a balance order itself
    }

    $due_order_id = (int) $parent_order->get_meta('_fpb_due_order_id', true);
    if ($due_order_id > 0) {
        $due_order = wc_get_order($due_order_id);
        if ($due_order) {
            return $due_order;
        }
    }

    if (! $create) {
        return null;
    }

    $fpb_product = (int) get_option('fpb_wc_product_id', 0);
    foreach ($parent_order->get_items() as $item) {
        if ($fpb_product > 0 && (int) $item->get_product_id() !== $fpb_product) {
            continue;
        }
        $total   = (float) $item->get_meta('_fpb_total');
        $deposit = (float) $item->get_meta('_fpb_deposit');
        $balance = (float) $item->get_meta('_fpb_balance_due');
        if ($balance <= 0) {
            $balance = max(0, round($total - $deposit, 2));
        }
        if ($balance > 0.01) {
            $due_order_id = snapbook_create_balance_order($parent_order, $item, $balance);
            if ($due_order_id > 0) {
                return wc_get_order($due_order_id);
            }
        }
        break;
    }

    return null;
}

/* ═══════════════════════════════════════════════════════════════
   Include the remaining-balance payment link in the customer's
   first booking email (order confirmation), not just later reminders.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_email_after_order_table', 'snapbook_email_append_balance_link', 15, 4);
function snapbook_email_append_balance_link($order, $sent_to_admin = false, $plain_text = false, $email = null)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return;
    }
    // Customer emails only, and only once the deposit itself has been paid —
    // no point offering the balance link before the first payment lands.
    if ($sent_to_admin) {
        return;
    }
    if ((int) $order->get_meta('_fpb_is_balance_order', true) === 1) {
        return;
    }
    if (! snapbook_is_booking_order($order) || ! $order->is_paid()) {
        return;
    }

    $balance_order = snapbook_get_balance_order_for($order, true);
    if (! $balance_order) {
        return;
    }
    if (in_array($balance_order->get_status(), ['processing', 'completed', 'cancelled', 'refunded'], true)) {
        return; // balance already settled or void
    }

    $pay_url = $balance_order->get_checkout_payment_url();
    $currency = $order->get_currency();
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : snapbook_get_currency_symbol();
    $balance_amount = $symbol . number_format((float) $balance_order->get_total(), 2);

    $session_date = (string) $balance_order->get_meta('_fpb_session_date', true);
    if ($session_date === '') {
        $session_date = (string) $order->get_meta('_fpb_billing_event_date', true);
    }

    if ($plain_text) {
        echo "\n----------------------------------------\n\n";
        echo esc_html__('Remaining balance to pay', 'snapbook') . ': ' . esc_html($balance_amount) . "\n";
        if ($session_date !== '') {
            /* translators: %s: session date */
            echo esc_html(sprintf(__('For your session on %s.', 'snapbook'), $session_date)) . "\n";
        }
        echo esc_html__('Pay the remaining balance here:', 'snapbook') . ' ' . esc_url_raw($pay_url) . "\n";
        return;
    }

    if ($session_date !== '') {
        /* translators: 1: balance amount, 2: session date */
        $intro = sprintf(__('A balance of %1$s is still due for your session on %2$s. You can settle it any time using the button below.', 'snapbook'), $balance_amount, $session_date);
    } else {
        /* translators: %s: balance amount */
        $intro = sprintf(__('A balance of %s is still due for your booking. You can settle it any time using the button below.', 'snapbook'), $balance_amount);
    }

    // Self-contained inline styles — email clients don't load the plugin CSS.
    echo '<div style="margin:24px 0;padding:20px 24px;border:2px solid #3d6b78;border-radius:8px;background:#eef4f6;">';
    echo '<h2 style="margin:0 0 8px;font-size:18px;line-height:1.3;color:#2e5562;">' . esc_html__('Pay your remaining balance', 'snapbook') . '</h2>';
    echo '<p style="margin:0 0 4px;font-size:14px;line-height:1.5;color:#1d2327;">' . esc_html($intro) . '</p>';
    echo '<p style="margin:8px 0 16px;font-size:24px;font-weight:700;color:#2e5562;">' . esc_html($balance_amount) . '</p>';
    echo '<a href="' . esc_url($pay_url) . '" style="display:inline-block;padding:12px 24px;background:#3d6b78;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:6px;">' . esc_html__('Pay Remaining Balance', 'snapbook') . '</a>';
    echo '<p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#50575e;word-break:break-all;">' . esc_html__('Or copy and paste this link into your browser:', 'snapbook') . '<br><a href="' . esc_url($pay_url) . '" style="color:#3d6b78;">' . esc_html($pay_url) . '</a></p>';
    echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   Direct checkout — create the order straight from the booking
   form (Details step) and send the customer to the WooCommerce
   order-payment page, skipping the checkout form page entirely.
═══════════════════════════════════════════════════════════════ */
/**
 * Whether a gateway completes payment without any customer interaction
 * (no secure card fields, no external approval step). Only these may be
 * processed straight from the booking form; everything else — PayPal,
 * card processors, redirect gateways — must finish on the WooCommerce
 * payment page where the gateway renders its own fields/buttons.
 */
function snapbook_gateway_processes_offline($gateway)
{
    $offline = apply_filters('snapbook_offline_payment_gateways', ['bacs', 'cheque', 'cod']);
    return empty($gateway->has_fields) && in_array($gateway->id, $offline, true);
}

/**
 * Whether the gateway's order-pay page can be embedded in the booking
 * form's payment iframe. Gateways with on-site fields and the PayPal
 * Payments family (popup-based) work embedded; unknown redirect
 * gateways get a full-page redirect instead, since external processors
 * usually refuse to load inside frames.
 */
function snapbook_gateway_embeds_payment($gateway)
{
    if (snapbook_gateway_processes_offline($gateway)) {
        return false;
    }

    $embeddable = ! empty($gateway->has_fields) || strpos((string) $gateway->id, 'ppcp') === 0;
    return (bool) apply_filters('snapbook_embed_payment_gateway', $embeddable, $gateway);
}

/**
 * Cancel an unpaid booking order that was superseded because the customer
 * went back and changed the booking before paying. The order key acts as
 * the authorization token, so only the customer who created the order
 * (and holds its secret key) can cancel it.
 */
function snapbook_cancel_superseded_booking_order($order_id, $order_key)
{
    $order_id  = absint($order_id);
    $order_key = (string) $order_key;
    if ($order_id < 1 || $order_key === '') {
        return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    if (! hash_equals($order->get_order_key(), $order_key)) {
        return;
    }
    if ($order->get_created_via() !== 'snapbook') {
        return;
    }
    if ((int) $order->get_meta('_fpb_is_balance_order', true) === 1) {
        return;
    }
    if (! in_array($order->get_status(), ['pending', 'failed'], true)) {
        return;
    }

    $order->update_status('cancelled', __('Superseded by a new booking attempt.', 'snapbook'));
}

/* ═══════════════════════════════════════════════════════════════
   WooCommerce PayPal Payments compatibility — the embedded pay page
   must always render the smart buttons / card fields, even when the
   merchant disabled the checkout button location in PayPal settings
   (the pay-order page reuses the "checkout" location internally).
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_paypal_payments_selected_button_locations', 'snapbook_force_ppcp_buttons_in_embed');
function snapbook_force_ppcp_buttons_in_embed($locations)
{
    if (
        isset($_GET['snapbook_embed']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only tweak on the embedded pay page.
        && function_exists('is_wc_endpoint_url')
        && is_wc_endpoint_url('order-pay')
    ) {
        $locations = array_unique(array_merge((array) $locations, ['checkout']));
    }

    return $locations;
}

/* ═══════════════════════════════════════════════════════════════
   Chrome-less order-pay page for the booking form's payment iframe
═══════════════════════════════════════════════════════════════ */
add_filter('template_include', 'snapbook_embedded_pay_template', 99);
function snapbook_embedded_pay_template($template)
{
    if (
        isset($_GET['snapbook_embed']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only template switch; WooCommerce validates the order key.
        && function_exists('is_wc_endpoint_url')
        && is_wc_endpoint_url('order-pay')
    ) {
        $embed = SNAPBOOK_DIR . 'templates/embed-pay.php';
        if (file_exists($embed)) {
            return $embed;
        }
    }

    return $template;
}

add_action('wp_ajax_snapbook_place_order',        'snapbook_ajax_place_booking_order');
add_action('wp_ajax_nopriv_snapbook_place_order', 'snapbook_ajax_place_booking_order');
function snapbook_ajax_place_booking_order()
{
    check_ajax_referer('snapbook_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_error(['message' => __('WooCommerce is required for online checkout.', 'snapbook')]);
    }

    if ((int) get_option('fpb_require_account_booking', 0) === 1 && ! is_user_logged_in()) {
        wp_send_json_error(['message' => __('Please log in or create an account before completing your booking.', 'snapbook')]);
    }

    $product_id = (int) get_option('fpb_wc_product_id', 0);
    if (! $product_id || get_post_status($product_id) === false) {
        snapbook_create_wc_product();
        $product_id = (int) get_option('fpb_wc_product_id', 0);
    }
    if (! $product_id) {
        wp_send_json_error(['message' => __('Booking product not configured. Please contact support.', 'snapbook')]);
    }

    $details = snapbook_sanitize_checkout_details(isset($_POST['details']) && is_array($_POST['details']) ? $_POST['details'] : []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    // Validate required fields against the admin-configured checkout form.
    $cf_fields = snapbook_get_checkout_form_fields();
    foreach ($cf_fields as $key => $f) {
        if (empty($f['enabled']) || empty($f['required'])) {
            continue;
        }
        if (! isset($details[$key]) || $details[$key] === '') {
            /* translators: %s: field label */
            wp_send_json_error(['message' => sprintf(__('%s is required.', 'snapbook'), $f['label'])]);
        }
    }
    foreach (snapbook_get_custom_checkout_fields() as $ckey => $cf) {
        if (empty($cf['required'])) {
            continue;
        }
        if (! isset($details['cf_' . $ckey]) || $details['cf_' . $ckey] === '') {
            /* translators: %s: field label */
            wp_send_json_error(['message' => sprintf(__('%s is required.', 'snapbook'), $cf['label'])]);
        }
    }
    if (! empty($details['email']) && ! is_email($details['email'])) {
        wp_send_json_error(['message' => __('Please enter a valid email address.', 'snapbook')]);
    }
    if (! empty($cf_fields['participants']['enabled']) && isset($details['participants']) && $details['participants'] !== '' && (int) $details['participants'] < 1) {
        wp_send_json_error(['message' => __('Participants must be at least 1.', 'snapbook')]);
    }

    $total        = floatval(wp_unslash($_POST['total_raw'] ?? 0));
    $session_date = sanitize_text_field(wp_unslash($_POST['session_date'] ?? ''));
    $partial_enabled       = ((int) get_option('fpb_enable_partial_payment', 1) === 1);
    $use_deposit_requested = absint(wp_unslash($_POST['use_deposit'] ?? 0)) === 1;
    $can_use_deposit = $partial_enabled && $use_deposit_requested && snapbook_can_use_partial_payment_for_date($session_date);
    $pay_pct  = $can_use_deposit ? 50 : 100;
    $deposit  = round(($total * $pay_pct) / 100, 2);

    $booking = [
        'product_id'    => $product_id,
        'session_type'  => sanitize_text_field(wp_unslash($_POST['session_type'] ?? '')),
        'package_name'  => sanitize_text_field(wp_unslash($_POST['package_name'] ?? '')),
        'package_id'    => absint(wp_unslash($_POST['package_id'] ?? 0)),
        'addons_label'  => sanitize_text_field(wp_unslash($_POST['addons_label'] ?? '')),
        'addons_total'  => floatval(wp_unslash($_POST['addons_total'] ?? 0)),
        'total'         => $total,
        'deposit'       => $deposit,
        'deposit_pct'   => $pay_pct,
        'session_date'  => $session_date,
        'currency'      => snapbook_get_currency_symbol(),
    ];

    if (empty($booking['package_name'])) {
        wp_send_json_error(['message' => __('Please select a package before checkout.', 'snapbook')]);
    }
    if (empty($booking['session_date'])) {
        wp_send_json_error(['message' => __('Please choose a session date before checkout.', 'snapbook')]);
    }

    $payment_method = sanitize_key(wp_unslash($_POST['payment_method'] ?? ''));

    // If the customer went back and changed the booking after an order was
    // already created, cancel that superseded order before creating the new one.
    $previous_order_id  = absint(wp_unslash($_POST['previous_order_id'] ?? 0));
    $previous_order_key = sanitize_text_field(wp_unslash($_POST['previous_order_key'] ?? ''));
    if ($previous_order_id > 0 && $previous_order_key !== '') {
        snapbook_cancel_superseded_booking_order($previous_order_id, $previous_order_key);
    }

    $order = snapbook_create_booking_order($booking, $details, $payment_method);
    if (! $order) {
        wp_send_json_error(['message' => __('Could not create your booking order. Please try again.', 'snapbook')]);
    }

    // Try to complete payment right here so the customer never leaves the
    // booking form. Gateways without payment fields (bank transfer, cash on
    // delivery, cheque, …) can process immediately; gateways that render
    // their own secure card fields still need the WooCommerce payment page.
    $processed     = false;
    $redirect_url  = '';
    $embed_url     = '';
    $gateway_title = '';

    if ($payment_method !== '' && WC()->payment_gateways()) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($available[$payment_method])) {
            $gateway       = $available[$payment_method];
            $gateway_title = wp_strip_all_tags((string) $gateway->get_title());

            if (snapbook_gateway_processes_offline($gateway)) {
                try {
                    $result = $gateway->process_payment($order->get_id());
                    if (is_array($result) && ($result['result'] ?? '') === 'success') {
                        $processed = true;
                        $order     = wc_get_order($order->get_id()); // refresh status

                        $redirect = (string) ($result['redirect'] ?? '');
                        $received = $order->get_checkout_order_received_url();
                        if ($redirect !== '' && $redirect !== $received && strpos($redirect, 'order-received') === false) {
                            // Gateway hands off to an external processor after all —
                            // the customer has to finish payment there.
                            $processed    = false;
                            $redirect_url = $redirect;
                        }
                    }
                } catch (\Throwable $e) {
                    $processed = false; // fall through to in-place pending state with a pay link
                }
            } else {
                // Interactive gateway (PayPal, card fields, …): never call
                // process_payment() server-side — for PayPal it returns the
                // paypal.com approval link and skips the on-site card form.
                // The order-pay page renders the gateway's own secure
                // fields/buttons exactly like the checkout page does.
                $redirect_url = $order->get_checkout_payment_url();
                if (snapbook_gateway_embeds_payment($gateway)) {
                    $embed_url = add_query_arg('snapbook_embed', '1', $redirect_url);
                }
            }
        }
    } elseif ($payment_method === '') {
        // No method chosen up front — the embedded pay page presents the
        // gateway list natively (icons, expanding card fields, Pay button),
        // exactly like the WooCommerce checkout payment section.
        $embed_url = add_query_arg('snapbook_embed', '1', $order->get_checkout_payment_url());
    }

    wp_send_json_success([
        'order_id'          => (int) $order->get_id(),
        'order_key'         => (string) $order->get_order_key(),
        'order_number'      => (string) $order->get_order_number(),
        'status'            => (string) $order->get_status(),
        'status_label'      => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status(),
        'due_now'           => (float) $order->get_total(),
        'currency'          => snapbook_get_currency_symbol(),
        'gateway_title'     => $gateway_title,
        'payment_processed' => $processed,
        'client_email'      => (string) $order->get_billing_email(),
        'pay_url'           => $order->get_checkout_payment_url(),
        'received_url'      => $order->get_checkout_order_received_url(),
        'redirect_url'      => $redirect_url,
        'embed_url'         => $embed_url,
    ]);
}

/**
 * Create a pending WooCommerce order carrying the same _fpb_* item meta
 * the classic checkout flow writes, so payment-complete hooks
 * (booking row, balance order, reminders) work identically.
 *
 * @return WC_Order|false
 */
function snapbook_create_booking_order($booking, $details, $payment_method = '')
{
    $product = wc_get_product((int) $booking['product_id']);
    if (! $product) {
        return false;
    }

    $order = wc_create_order([
        'customer_id' => get_current_user_id(),
        'created_via' => 'snapbook',
    ]);
    if (! $order || is_wp_error($order)) {
        return false;
    }

    $client_name  = trim(($details['first_name'] ?? '') . ' ' . ($details['last_name'] ?? ''));
    $client_email = $details['email'] ?? '';
    $client_phone = $details['phone'] ?? '';
    $country_raw  = (string) ($details['country'] ?? '');
    $country_code = strlen($country_raw) === 2 ? strtoupper($country_raw) : '';
    $balance_due  = max(0, (float) $booking['total'] - (float) $booking['deposit']);

    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(1);
    $item->set_subtotal((float) $booking['deposit']);
    $item->set_total((float) $booking['deposit']);
    if (! empty($booking['package_name'])) {
        $item->set_name(__('Photography Session', 'snapbook') . ' — ' . $booking['package_name']);
    }

    $meta_map = [
        '_fpb_session_type'  => $booking['session_type'],
        '_fpb_package_name'  => $booking['package_name'],
        '_fpb_total'         => $booking['total'],
        '_fpb_deposit'       => $booking['deposit'],
        '_fpb_deposit_pct'   => $booking['deposit_pct'],
        '_fpb_balance_due'   => $balance_due,
        '_fpb_addons_label'  => $booking['addons_label'],
        '_fpb_addons_total'  => $booking['addons_total'],
        '_fpb_client_name'   => $client_name,
        '_fpb_client_email'  => $client_email,
        '_fpb_client_phone'  => $client_phone,
        '_fpb_client_country' => $country_raw,
        '_fpb_session_date'  => $booking['session_date'],
        '_fpb_session_time'  => $details['event_time'] ?? '',
        '_fpb_location_pref' => $details['hotel_place'] ?? '',
        '_fpb_notes'         => $details['notes'] ?? '',
        '_fpb_signer_name'   => '',
        '_fpb_billing_event_date'   => $booking['session_date'],
        '_fpb_billing_event_time'   => $details['event_time'] ?? '',
        '_fpb_billing_hotel_place'  => $details['hotel_place'] ?? '',
        '_fpb_billing_participants' => $details['participants'] ?? '',
        '_fpb_billing_room_number'  => $details['room_number'] ?? '',
        '_fpb_billing_stay_period'  => $details['stay_period'] ?? '',
        '_fpb_currency'      => $booking['currency'],
    ];
    foreach ($meta_map as $key => $val) {
        $item->add_meta_data($key, $val, true);
    }
    // Admin-created custom checkout fields (details keys namespaced cf_{key}).
    foreach ($details as $dkey => $dval) {
        if (strpos($dkey, 'cf_') === 0) {
            $item->add_meta_data('_fpb_' . $dkey, $dval, true);
        }
    }
    $order->add_item($item);

    $order->set_address([
        'first_name' => $details['first_name'] ?? '',
        'last_name'  => $details['last_name'] ?? '',
        'email'      => $client_email,
        'phone'      => $client_phone,
        'country'    => $country_code,
        'address_1'  => $details['address_1'] ?? '',
        'city'       => $details['city'] ?? '',
        'postcode'   => $details['postcode'] ?? '',
    ], 'billing');

    $order->update_meta_data('_fpb_billing_event_date',   $booking['session_date']);
    $order->update_meta_data('_fpb_billing_event_time',   $details['event_time'] ?? '');
    $order->update_meta_data('_fpb_billing_hotel_place',  $details['hotel_place'] ?? '');
    $order->update_meta_data('_fpb_billing_participants', $details['participants'] ?? '');
    $order->update_meta_data('_fpb_billing_room_number',  $details['room_number'] ?? '');
    $order->update_meta_data('_fpb_billing_stay_period',  $details['stay_period'] ?? '');
    foreach ($details as $dkey => $dval) {
        if (strpos($dkey, 'cf_') === 0) {
            $order->update_meta_data('_fpb_' . $dkey, $dval);
        }
    }

    if (! empty($details['notes'])) {
        $order->set_customer_note($details['notes']);
    }

    if ($payment_method !== '' && WC()->payment_gateways()) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($available[$payment_method])) {
            $order->set_payment_method($available[$payment_method]);
        }
    }

    $order->calculate_totals();
    $order->update_status('pending');
    $order->add_order_note(__('Created via SnapBook booking form.', 'snapbook'));
    $order->save();

    return $order;
}

/* ═══════════════════════════════════════════════════════════════
   WhatsApp contact button on the order-received (thank you) page
   for booking orders — same label/number settings as the booking
   form's success screens.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_thankyou', 'snapbook_thankyou_whatsapp_button', 20);
function snapbook_thankyou_whatsapp_button($order_id)
{
    $number = preg_replace('/\D/', '', (string) get_option('fpb_whatsapp', ''));
    if ($number === '') {
        return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    $is_booking_order = $order->get_created_via() === 'snapbook';
    if (! $is_booking_order) {
        $fpb_product = (int) get_option('fpb_wc_product_id', 0);
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            if ((int) $item->get_product_id() === $fpb_product) {
                $is_booking_order = true;
                break;
            }
        }
    }
    if (! $is_booking_order) {
        return;
    }

    printf(
        '<p class="sb-thankyou-whatsapp"><a class="button" href="%s" target="_blank" rel="noopener">%s</a></p>',
        esc_url('https://wa.me/' . $number),
        esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp'))
    );
}

/* ═══════════════════════════════════════════════════════════════
   Hide FPB product from shop / search
═══════════════════════════════════════════════════════════════ */
add_action('pre_get_posts', 'snapbook_hide_product_from_catalog');
function snapbook_hide_product_from_catalog($q)
{
    if (is_admin() || ! $q->is_main_query()) return;
    $fpb_id = (int) get_option('fpb_wc_product_id', 0);
    if (! $fpb_id) return;
    $not_in   = (array) $q->get('post__not_in');
    $not_in[] = $fpb_id;
    $q->set('post__not_in', $not_in);
}