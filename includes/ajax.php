<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Load session types + packages + add-ons
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_sb_get_data',        'sb_ajax_get_data');
add_action('wp_ajax_nopriv_sb_get_data', 'sb_ajax_get_data');
add_action('wp_ajax_fpb_get_data',        'sb_ajax_get_data');
add_action('wp_ajax_nopriv_fpb_get_data', 'sb_ajax_get_data');
function sb_ajax_get_data()
{
    check_ajax_referer('fpb_nonce', 'nonce');
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';

    $sessions = $wpdb->get_results("SELECT id, name, emoji, slug FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $packages = $wpdb->get_results("SELECT id, session_id, name, price, duration, description, featured FROM {$pfx}packages WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $addons   = $wpdb->get_results("SELECT id, name, price, emoji, description, package_id FROM {$pfx}addons WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore

    wp_send_json_success([
        'sessions' => $sessions,
        'packages' => $packages,
        'addons'   => $addons,
        'currency' => sb_get_currency_symbol(),
        'depositPct' => (int) get_option('fpb_deposit_pct', 50),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Get booked/blocked dates for calendar
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_sb_get_dates',        'sb_ajax_get_dates');
add_action('wp_ajax_nopriv_sb_get_dates', 'sb_ajax_get_dates');
add_action('wp_ajax_fpb_get_dates',        'sb_ajax_get_dates');
add_action('wp_ajax_nopriv_fpb_get_dates', 'sb_ajax_get_dates');
function sb_ajax_get_dates()
{
    check_ajax_referer('fpb_nonce', 'nonce');
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $rows = $wpdb->get_results("SELECT date_str, status FROM {$pfx}dates WHERE status != 'available' ORDER BY date_str"); // phpcs:ignore
    $out  = [];
    foreach ($rows as $r) {
        $out[$r->date_str] = $r->status;
    }
    wp_send_json_success($out);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Get enabled WooCommerce payment gateways
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_sb_get_payment_gateways',        'sb_ajax_get_payment_gateways');
add_action('wp_ajax_nopriv_sb_get_payment_gateways', 'sb_ajax_get_payment_gateways');
add_action('wp_ajax_fpb_get_payment_gateways',        'sb_ajax_get_payment_gateways');
add_action('wp_ajax_nopriv_fpb_get_payment_gateways', 'sb_ajax_get_payment_gateways');
function sb_ajax_get_payment_gateways()
{
    check_ajax_referer('fpb_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_success(['gateways' => []]);
    }

    $gateways = [];
    $payment_gateways = WC()->payment_gateways();
    if ($payment_gateways && method_exists($payment_gateways, 'get_available_payment_gateways')) {
        foreach ($payment_gateways->get_available_payment_gateways() as $gateway) {
            $gateways[] = [
                'id'          => sanitize_key($gateway->id),
                'title'       => wp_kses_post($gateway->get_title()),
                'description' => wp_kses_post($gateway->get_description()),
            ];
        }
    }

    wp_send_json_success(['gateways' => $gateways]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Add to WooCommerce cart + return checkout URL
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_sb_add_to_cart',        'sb_ajax_add_to_cart');
add_action('wp_ajax_nopriv_sb_add_to_cart', 'sb_ajax_add_to_cart');
add_action('wp_ajax_fpb_add_to_cart',        'sb_ajax_add_to_cart');
add_action('wp_ajax_nopriv_fpb_add_to_cart', 'sb_ajax_add_to_cart');
function sb_ajax_add_to_cart()
{
    check_ajax_referer('fpb_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_error(['message' => __('WooCommerce is required for online checkout.', 'snapbook')]);
    }

    $product_id = (int) get_option('fpb_wc_product_id', 0);
    if (! $product_id || get_post_status($product_id) === false) {
        fpb_create_wc_product();
        $product_id = (int) get_option('fpb_wc_product_id', 0);
    }
    if (! $product_id) {
        wp_send_json_error(['message' => __('Booking product not configured. Please contact support.', 'snapbook')]);
    }

    $cur     = sb_get_currency_symbol();
    $total   = floatval(wp_unslash($_POST['total_raw'] ?? 0));
    $deposit = $total;

    $booking = [
        'product_id'    => $product_id,
        'session_type'  => sanitize_text_field(wp_unslash($_POST['session_type']  ?? '')),
        'package_name'  => sanitize_text_field(wp_unslash($_POST['package_name']  ?? '')),
        'package_id'    => absint(wp_unslash($_POST['package_id'] ?? 0)),
        'addons_label'  => sanitize_text_field(wp_unslash($_POST['addons_label']  ?? '')),
        'addons_total'  => floatval(wp_unslash($_POST['addons_total']  ?? 0)),
        'total'         => $total,
        'deposit'       => $deposit,
        'client_name'   => sanitize_text_field(wp_unslash($_POST['client_name']   ?? '')),
        'client_email'  => sanitize_email(wp_unslash($_POST['client_email']  ?? '')),
        'client_phone'  => sanitize_text_field(wp_unslash($_POST['client_phone']  ?? '')),
        'client_country' => sanitize_text_field(wp_unslash($_POST['client_country'] ?? '')),
        'session_date'  => sanitize_text_field(wp_unslash($_POST['session_date']  ?? '')),
        'session_time'  => sanitize_text_field(wp_unslash($_POST['session_time']  ?? '')),
        'location_pref' => sanitize_text_field(wp_unslash($_POST['location_pref'] ?? '')),
        'notes'         => sanitize_textarea_field(wp_unslash($_POST['notes']     ?? '')),
        'signer_name'   => sanitize_text_field(wp_unslash($_POST['signer_name']   ?? '')),
        'currency'      => $cur,
    ];

    if (empty($booking['package_name'])) {
        wp_send_json_error(['message' => __('Please select a package before checkout.', 'snapbook')]);
    }

    if (empty($booking['session_date'])) {
        wp_send_json_error(['message' => __('Please choose a session date before checkout.', 'snapbook')]);
    }

    // Save booking to a short-lived transient so the normal page request can
    // add it to the WooCommerce cart with a properly initialised session.
    $token = wp_generate_password(32, false);
    set_transient('fpb_checkout_' . $token, $booking, 10 * MINUTE_IN_SECONDS);

    $redirect = add_query_arg('fpb_checkout', $token, home_url('/'));
    wp_send_json_success(['checkout_url' => $redirect]);
}

/* ═══════════════════════════════════════════════════════════════
   FRONT-END — Intercept ?fpb_checkout=TOKEN, add to WC cart,
   then forward to the real WooCommerce checkout page.
═══════════════════════════════════════════════════════════════ */
add_action('template_redirect', 'sb_handle_checkout_redirect');
function sb_handle_checkout_redirect()
{
    $token = sanitize_text_field(wp_unslash($_GET['fpb_checkout'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (! $token || ! class_exists('WooCommerce')) {
        return;
    }

    $booking = get_transient('fpb_checkout_' . $token);
    if (! $booking) {
        wp_die(esc_html__('This booking link has expired. Please go back and try again.', 'snapbook'));
    }
    delete_transient('fpb_checkout_' . $token);

    $product_id = (int) ($booking['product_id'] ?? get_option('fpb_wc_product_id', 0));
    if (! $product_id) {
        wp_die(esc_html__('Booking product not found. Please contact support.', 'snapbook'));
    }

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($product_id, 1, 0, [], ['fpb_booking' => $booking]);

    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Fallback email submit (when WooCommerce not active)
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_sb_submit',        'sb_ajax_submit');
add_action('wp_ajax_nopriv_sb_submit', 'sb_ajax_submit');
add_action('wp_ajax_fpb_submit',        'sb_ajax_submit');
add_action('wp_ajax_nopriv_fpb_submit', 'sb_ajax_submit');
function sb_ajax_submit()
{
    check_ajax_referer('fpb_nonce', 'nonce');

    $name     = sanitize_text_field(wp_unslash($_POST['name']     ?? ''));
    $email    = sanitize_email(wp_unslash($_POST['email']    ?? ''));
    $phone    = sanitize_text_field(wp_unslash($_POST['phone']    ?? ''));
    $pkg      = sanitize_text_field(wp_unslash($_POST['pkg']      ?? ''));
    $total    = sanitize_text_field(wp_unslash($_POST['total']    ?? ''));
    $date     = sanitize_text_field(wp_unslash($_POST['date']     ?? ''));
    $time     = sanitize_text_field(wp_unslash($_POST['time']     ?? ''));
    $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
    $notes    = sanitize_textarea_field(wp_unslash($_POST['notes']    ?? ''));
    $signer   = sanitize_text_field(wp_unslash($_POST['signer']   ?? ''));

    if (! is_email($email)) {
        wp_send_json_error(['message' => __('Invalid email address.', 'snapbook')]);
    }

    $admin_email = get_option('fpb_admin_email', get_option('admin_email'));
    wp_mail(
        $admin_email,
        /* translators: %s: client name */
        sprintf(__('New Booking Request — %s', 'snapbook'), $name),
        sprintf(
            "New booking:\n\nName: %s\nEmail: %s\nPhone: %s\nPackage: %s\nTotal: %s\nDate: %s\nTime: %s\nLocation: %s\nSigner: %s\n\nNotes:\n%s",
            esc_html($name),
            esc_html($email),
            esc_html($phone),
            esc_html($pkg),
            esc_html($total),
            esc_html($date),
            esc_html($time),
            esc_html($location),
            esc_html($signer),
            esc_html($notes)
        )
    );
    wp_mail(
        $email,
        __('Booking request received — SnapBook', 'snapbook'),
        sprintf(
            "Hi %s,\n\nThank you for your booking request. We will confirm within 24 hours.\n\nPackage: %s\nDate: %s\nTotal: %s\n\nFocus Photography Mauritius",
            esc_html(explode(' ', $name)[0]),
            esc_html($pkg),
            esc_html($date),
            esc_html($total)
        )
    );

    wp_send_json_success([]);
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — CRUD: Session Types
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_fpb_admin_save_settings', 'fpb_admin_save_settings');

function fpb_admin_save_settings()
{
    check_ajax_referer('fpb_settings', 'fpb_settings_nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    foreach (['fpb_step1_title', 'fpb_step1_sub'] as $key) {
        update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
    }

    wp_send_json_success();
}

add_action('wp_ajax_fpb_admin_save_session',   'fpb_admin_save_session');
add_action('wp_ajax_fpb_admin_delete_session', 'fpb_admin_delete_session');

function fpb_admin_save_session()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $id   = absint(wp_unslash($_POST['id'] ?? 0));
    $data = [
        'name'       => sanitize_text_field(wp_unslash($_POST['name']       ?? '')),
        'emoji'      => wp_encode_emoji(sanitize_text_field(wp_unslash($_POST['emoji']      ?? ''))),
        'slug'       => sanitize_key(wp_unslash($_POST['slug']       ?? '')),
        'sort_order' => absint(wp_unslash($_POST['sort_order'] ?? 0)),
        'active'     => absint(wp_unslash($_POST['active'] ?? 0)) === 1 ? 1 : 0,
    ];
    if (empty($data['name']) || empty($data['slug'])) {
        wp_send_json_error(['message' => 'Name and slug are required.']);
    }
    if ($id) {
        // On update: only conflict if another row (not this one) has the same slug
        $conflict = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pfx}sessions WHERE slug=%s AND id != %d", $data['slug'], $id)); // phpcs:ignore
        if ($conflict > 0) {
            wp_send_json_error(['message' => 'Slug "' . esc_html($data['slug']) . '" is already used by another session. Please choose a different slug.']);
        }
        $result = $wpdb->update("{$pfx}sessions", $data, ['id' => $id]); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    } else {
        // Auto-suffix slug if it already exists so saves never fail silently
        $base_slug = $data['slug'];
        $counter   = 2;
        while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pfx}sessions WHERE slug=%s", $data['slug'])) > 0) { // phpcs:ignore
            $data['slug'] = $base_slug . '-' . $counter++;
        }
        $result = $wpdb->insert("{$pfx}sessions", $data); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Could not save. Database error: ' . $wpdb->last_error]);
        $id = $wpdb->insert_id;
    }
    wp_send_json_success(['id' => $id, 'slug' => $data['slug']]);
}

function sb_admin_delete_session()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';
    $id  = absint(wp_unslash($_POST['id'] ?? 0));
    $wpdb->delete("{$pfx}sessions", ['id' => $id]); // phpcs:ignore
    wp_send_json_success();
}

if (!function_exists('fpb_admin_delete_session')) {
    function fpb_admin_delete_session()
    {
        sb_admin_delete_session();
    }
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — CRUD: Packages
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_fpb_admin_save_package',   'fpb_admin_save_package');
add_action('wp_ajax_fpb_admin_delete_package', 'fpb_admin_delete_package');

function fpb_admin_save_package()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $id   = absint(wp_unslash($_POST['id'] ?? 0));
    $data = [
        'session_id'  => absint(wp_unslash($_POST['session_id'] ?? 0)),
        'name'        => sanitize_text_field(wp_unslash($_POST['name']        ?? '')),
        'price'       => floatval(wp_unslash($_POST['price']       ?? 0)),
        'duration'    => sanitize_text_field(wp_unslash($_POST['duration']    ?? '')),
        'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
        'featured'    => absint(wp_unslash($_POST['featured'] ?? 0)) === 1 ? 1 : 0,
        'sort_order'  => absint(wp_unslash($_POST['sort_order']  ?? 0)),
        'active'      => absint(wp_unslash($_POST['active'] ?? 0)) === 1 ? 1 : 0,
    ];
    if (empty($data['name']) || $data['session_id'] < 1) {
        wp_send_json_error(['message' => 'Session type and name are required.']);
    }
    if ($id) {
        $result = $wpdb->update("{$pfx}packages", $data, ['id' => $id]); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    } else {
        $result = $wpdb->insert("{$pfx}packages", $data); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
        $id = $wpdb->insert_id;
    }
    wp_send_json_success(['id' => $id]);
}

function sb_admin_delete_package()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'fpb_packages', ['id' => absint(wp_unslash($_POST['id'] ?? 0))]); // phpcs:ignore
    wp_send_json_success();
}

if (!function_exists('fpb_admin_delete_package')) {
    function fpb_admin_delete_package()
    {
        sb_admin_delete_package();
    }
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — CRUD: Add-ons
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_fpb_admin_save_addon',   'fpb_admin_save_addon');
add_action('wp_ajax_fpb_admin_delete_addon', 'fpb_admin_delete_addon');

function fpb_admin_save_addon()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $id   = absint(wp_unslash($_POST['id'] ?? 0));
    $data = [
        'name'        => sanitize_text_field(wp_unslash($_POST['name']        ?? '')),
        'price'       => floatval(wp_unslash($_POST['price']       ?? 0)),
        'emoji'       => wp_encode_emoji(sanitize_text_field(wp_unslash($_POST['emoji']       ?? ''))),
        'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
        'package_id'  => absint(wp_unslash($_POST['package_id']  ?? 0)),
        'sort_order'  => absint(wp_unslash($_POST['sort_order']  ?? 0)),
        'active'      => absint(wp_unslash($_POST['active'] ?? 0)) === 1 ? 1 : 0,
    ];
    if (empty($data['name'])) wp_send_json_error(['message' => 'Name is required.']);
    if ($id) {
        $result = $wpdb->update("{$pfx}addons", $data, ['id' => $id]); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    } else {
        $result = $wpdb->insert("{$pfx}addons", $data); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
        $id = $wpdb->insert_id;
    }
    wp_send_json_success(['id' => $id]);
}

function sb_admin_delete_addon()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'fpb_addons', ['id' => absint(wp_unslash($_POST['id'] ?? 0))]); // phpcs:ignore
    wp_send_json_success();
}

if (!function_exists('fpb_admin_delete_addon')) {
    function fpb_admin_delete_addon()
    {
        sb_admin_delete_addon();
    }
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — Date Slots: get / toggle
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_fpb_admin_get_dates',    'fpb_admin_get_dates');
add_action('wp_ajax_fpb_admin_toggle_date',  'fpb_admin_toggle_date');

function fpb_admin_get_dates()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $rows = $wpdb->get_results("SELECT date_str, status, notes FROM {$pfx}dates ORDER BY date_str"); // phpcs:ignore
    $out  = [];
    foreach ($rows as $r) $out[$r->date_str] = $r->status;
    wp_send_json_success($out);
}

function fpb_admin_toggle_date()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
    // Validate date format YYYY-MM-DD
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error(['message' => 'Invalid date.']);

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}dates WHERE date_str=%s", $date)); // phpcs:ignore
    if (! $existing) {
        // No entry → mark as booked
        $wpdb->insert("{$pfx}dates", ['date_str' => $date, 'status' => 'booked']); // phpcs:ignore
        $new_status = 'booked';
    } elseif ($existing->status === 'booked') {
        $wpdb->update("{$pfx}dates", ['status' => 'blocked'], ['date_str' => $date]); // phpcs:ignore
        $new_status = 'blocked';
    } else {
        // blocked → remove entirely (back to available)
        $wpdb->delete("{$pfx}dates", ['date_str' => $date]); // phpcs:ignore
        $new_status = 'available';
    }
    wp_send_json_success(['date' => $date, 'status' => $new_status]);
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — Update booking status
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_fpb_admin_update_booking_status', 'fpb_admin_update_booking_status');
function fpb_admin_update_booking_status()
{
    check_ajax_referer('fpb_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $id     = absint(wp_unslash($_POST['id'] ?? 0));
    $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
    if (! in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) wp_send_json_error();

    // Update booking status
    $wpdb->update($wpdb->prefix . 'fpb_bookings', ['status' => $status], ['id' => $id]); // phpcs:ignore

    // Get associated order and update WooCommerce order status
    $booking = $wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}fpb_bookings WHERE id = %d", $id)); // phpcs:ignore
    if ($booking && $booking->order_id) {
        $order = wc_get_order($booking->order_id);
        if ($order) {
            // Map booking status to WooCommerce order status
            $wc_status = 'pending';
            if ($status === 'confirmed') {
                $wc_status = 'processing';
            } elseif ($status === 'cancelled') {
                $wc_status = 'cancelled';
            } elseif ($status === 'completed') {
                $wc_status = 'completed';
            }
            $order->set_status($wc_status);
            $order->save();
        }
    }

    wp_send_json_success();
}
