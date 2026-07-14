<?php
defined('ABSPATH') || exit;

function snapbook_get_partial_block_days()
{
    return max(0, (int) get_option('fpb_partial_block_days', 0));
}

function snapbook_can_use_partial_payment_for_date($session_date)
{
    $session_date = sanitize_text_field((string) $session_date);
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
        return false;
    }

    $block_days = snapbook_get_partial_block_days();
    if ($block_days <= 0) {
        return true;
    }

    $parts = explode('-', $session_date);
    $event_dt = new DateTimeImmutable('now', wp_timezone());
    $event_dt = $event_dt->setDate((int) $parts[0], (int) $parts[1], (int) $parts[2])->setTime(0, 0, 0);
    $today_dt = new DateTimeImmutable('today', wp_timezone());

    $days_until = (int) floor(($event_dt->getTimestamp() - $today_dt->getTimestamp()) / DAY_IN_SECONDS);
    return $days_until >= $block_days;
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Load session types + packages + add-ons
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_get_data',        'snapbook_ajax_get_data');
add_action('wp_ajax_nopriv_snapbook_get_data', 'snapbook_ajax_get_data');
function snapbook_ajax_get_data()
{
    check_ajax_referer('snapbook_nonce', 'nonce');
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';

    $sessions = $wpdb->get_results("SELECT id, name, emoji, slug FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $packages = $wpdb->get_results("SELECT id, session_id, name, slug, price, duration, description, featured FROM {$pfx}packages WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $addons   = $wpdb->get_results("SELECT id, name, price, emoji, description, package_id, package_ids FROM {$pfx}addons WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore

    wp_send_json_success([
        'sessions' => $sessions,
        'packages' => $packages,
        'addons'   => $addons,
        'currency' => snapbook_get_currency_symbol(),
        'depositPct' => ((int) get_option('fpb_enable_partial_payment', 1) === 1 ? 50 : 100),
        'partialPaymentEnabled' => ((int) get_option('fpb_enable_partial_payment', 1) === 1),
        'partialBlockDays' => snapbook_get_partial_block_days(),
        'partialOptionLabel' => get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook')),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Live payment preview for booking summary
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_preview_payment',        'snapbook_ajax_preview_payment');
add_action('wp_ajax_nopriv_snapbook_preview_payment', 'snapbook_ajax_preview_payment');
function snapbook_ajax_preview_payment()
{
    check_ajax_referer('snapbook_nonce', 'nonce');

    $total = max(0, floatval(wp_unslash($_POST['total_raw'] ?? 0)));
    $session_date = sanitize_text_field(wp_unslash($_POST['session_date'] ?? ''));
    $use_deposit_requested = absint(wp_unslash($_POST['use_deposit'] ?? 0)) === 1;

    $partial_enabled = ((int) get_option('fpb_enable_partial_payment', 1) === 1);
    $is_eligible = $partial_enabled && snapbook_can_use_partial_payment_for_date($session_date);
    $pay_pct = ($partial_enabled && $use_deposit_requested && $is_eligible) ? 50 : 100;

    $due_today = round(($total * $pay_pct) / 100, 2);
    $balance_due = max(0, round($total - $due_today, 2));

    wp_send_json_success([
        'payPct' => $pay_pct,
        'isEligible' => $is_eligible,
        'dueToday' => $due_today,
        'balanceDue' => $balance_due,
        'total' => $total,
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Get booked/blocked dates for calendar
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_get_dates',        'snapbook_ajax_get_dates');
add_action('wp_ajax_nopriv_snapbook_get_dates', 'snapbook_ajax_get_dates');
function snapbook_ajax_get_dates()
{
    check_ajax_referer('snapbook_nonce', 'nonce');
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
add_action('wp_ajax_snapbook_get_payment_gateways',        'snapbook_ajax_get_payment_gateways');
add_action('wp_ajax_nopriv_snapbook_get_payment_gateways', 'snapbook_ajax_get_payment_gateways');
function snapbook_ajax_get_payment_gateways()
{
    check_ajax_referer('snapbook_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_success(['gateways' => []]);
    }

    // Session-dependent gateways (e.g. PayPal Payments) hide themselves
    // when no frontend session/cart is loaded, as on admin-ajax requests.
    if (function_exists('snapbook_ensure_wc_frontend_context')) {
        snapbook_ensure_wc_frontend_context();
    }

    $gateways = [];
    $payment_gateways = WC()->payment_gateways();
    if ($payment_gateways && method_exists($payment_gateways, 'get_available_payment_gateways')) {
        foreach ($payment_gateways->get_available_payment_gateways() as $gateway) {
            $gateways[] = [
                'id'          => sanitize_key($gateway->id),
                'title'       => wp_kses_post($gateway->get_title()),
                'description' => wp_kses_post($gateway->get_description()),
                'icon'        => wp_kses_post($gateway->get_icon()),
                'needs_payment_page' => ! snapbook_gateway_processes_offline($gateway),
            ];
        }
    }

    wp_send_json_success(['gateways' => $gateways]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Add to WooCommerce cart + return checkout URL
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_add_to_cart',        'snapbook_ajax_add_to_cart');
add_action('wp_ajax_nopriv_snapbook_add_to_cart', 'snapbook_ajax_add_to_cart');
function snapbook_ajax_add_to_cart()
{
    check_ajax_referer('snapbook_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_error(['message' => __('WooCommerce is required for online checkout.', 'snapbook')]);
    }

    $product_id = (int) get_option('fpb_wc_product_id', 0);
    if (! $product_id || get_post_status($product_id) === false) {
        snapbook_create_wc_product();
        $product_id = (int) get_option('fpb_wc_product_id', 0);
    }
    if (! $product_id) {
        wp_send_json_error(['message' => __('Booking product not configured. Please contact support.', 'snapbook')]);
    }

    $cur      = snapbook_get_currency_symbol();
    $total    = floatval(wp_unslash($_POST['total_raw'] ?? 0));
    $partial_enabled = ((int) get_option('fpb_enable_partial_payment', 1) === 1);
    $session_date = sanitize_text_field(wp_unslash($_POST['session_date'] ?? ''));
    $use_deposit_requested = absint(wp_unslash($_POST['use_deposit'] ?? 0)) === 1;
    $can_use_deposit = $partial_enabled && $use_deposit_requested && snapbook_can_use_partial_payment_for_date($session_date);
    $pay_pct  = $can_use_deposit ? 50 : 100;
    $deposit  = round(($total * $pay_pct) / 100, 2);

    $booking = [
        'product_id'    => $product_id,
        'session_type'  => sanitize_text_field(wp_unslash($_POST['session_type']  ?? '')),
        'package_name'  => sanitize_text_field(wp_unslash($_POST['package_name']  ?? '')),
        'package_id'    => absint(wp_unslash($_POST['package_id'] ?? 0)),
        'addons_label'  => sanitize_text_field(wp_unslash($_POST['addons_label']  ?? '')),
        'addons_total'  => floatval(wp_unslash($_POST['addons_total']  ?? 0)),
        'total'         => $total,
        'deposit'       => $deposit,
        'deposit_pct'   => $pay_pct,
        'client_name'   => sanitize_text_field(wp_unslash($_POST['client_name']   ?? '')),
        'client_email'  => sanitize_email(wp_unslash($_POST['client_email']  ?? '')),
        'client_phone'  => sanitize_text_field(wp_unslash($_POST['client_phone']  ?? '')),
        'client_country' => sanitize_text_field(wp_unslash($_POST['client_country'] ?? '')),
        'session_date'  => $session_date,
        'session_time'  => sanitize_text_field(wp_unslash($_POST['session_time']  ?? '')),
        'location_pref' => sanitize_text_field(wp_unslash($_POST['location_pref'] ?? '')),
        'notes'         => sanitize_textarea_field(wp_unslash($_POST['notes']     ?? '')),
        'signer_name'   => sanitize_text_field(wp_unslash($_POST['signer_name']   ?? '')),
        'address_1'     => sanitize_text_field(wp_unslash($_POST['address_1']     ?? '')),
        'city'          => sanitize_text_field(wp_unslash($_POST['city']          ?? '')),
        'postcode'      => sanitize_text_field(wp_unslash($_POST['postcode']      ?? '')),
        'participants'  => sanitize_text_field(wp_unslash($_POST['participants']  ?? '')),
        'room_number'   => sanitize_text_field(wp_unslash($_POST['room_number']   ?? '')),
        'stay_period'   => sanitize_text_field(wp_unslash($_POST['stay_period']   ?? '')),
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
add_action('template_redirect', 'snapbook_handle_checkout_redirect');
function snapbook_handle_checkout_redirect()
{
    $token = sanitize_text_field(wp_unslash($_GET['fpb_checkout'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (! $token || ! class_exists('WooCommerce')) {
        return;
    }

    if ((int) get_option('fpb_require_account_booking', 0) === 1 && ! is_user_logged_in()) {
        $return_url = add_query_arg('fpb_checkout', $token, home_url('/'));
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
        wp_safe_redirect(add_query_arg('redirect', $return_url, $account_url));
        exit;
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
add_action('wp_ajax_snapbook_submit',        'snapbook_ajax_submit');
add_action('wp_ajax_nopriv_snapbook_submit', 'snapbook_ajax_submit');
function snapbook_ajax_submit()
{
    check_ajax_referer('snapbook_nonce', 'nonce');

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
add_action('wp_ajax_snapbook_admin_save_settings', 'snapbook_admin_save_settings');

function snapbook_admin_save_settings()
{
    check_ajax_referer('snapbook_settings', 'snapbook_settings_nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    foreach (['fpb_step1_title', 'fpb_step1_sub', 'fpb_balance_reminder_subject', 'fpb_partial_option_label', 'fpb_whatsapp', 'fpb_success_title', 'fpb_success_msg', 'fpb_whatsapp_btn', 'fpb_confirm_title', 'fpb_confirm_msg', 'fpb_confirm_pending_title', 'fpb_confirm_pending_msg'] as $key) {
        update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
    }
    update_option('fpb_admin_email', sanitize_email(wp_unslash($_POST['fpb_admin_email'] ?? '')) ?: get_option('admin_email'));
    update_option('fpb_booking_page_id', absint(wp_unslash($_POST['fpb_booking_page_id'] ?? 0)));
    if (function_exists('snapbook_sanitize_custom_checkout_fields')) {
        update_option('fpb_checkout_custom_fields', snapbook_sanitize_custom_checkout_fields($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }
    if (function_exists('snapbook_default_theme_colors')) {
        $theme_defaults = snapbook_default_theme_colors();
        update_option('fpb_theme_primary', sanitize_hex_color(wp_unslash($_POST['fpb_theme_primary'] ?? '')) ?: $theme_defaults['primary']);
        update_option('fpb_theme_accent', sanitize_hex_color(wp_unslash($_POST['fpb_theme_accent'] ?? '')) ?: $theme_defaults['accent']);
    }

    update_option('fpb_enable_partial_payment', absint(wp_unslash($_POST['fpb_enable_partial_payment'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_partial_block_days', max(0, absint(wp_unslash($_POST['fpb_partial_block_days'] ?? 0))));
    update_option('fpb_require_account_booking', absint(wp_unslash($_POST['fpb_require_account_booking'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_enable_balance_reminders', absint(wp_unslash($_POST['fpb_enable_balance_reminders'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_balance_reminder_hours', max(1, absint(wp_unslash($_POST['fpb_balance_reminder_hours'] ?? 24))));
    update_option('fpb_balance_reminder_template', wp_kses_post(wp_unslash($_POST['fpb_balance_reminder_template'] ?? '')));

    if (function_exists('snapbook_sanitize_checkout_mode')) {
        update_option('fpb_checkout_mode', snapbook_sanitize_checkout_mode(sanitize_key(wp_unslash($_POST['fpb_checkout_mode'] ?? 'direct'))));
    }
    if (function_exists('snapbook_sanitize_checkout_field_config')) {
        update_option('fpb_checkout_form_fields', snapbook_sanitize_checkout_field_config($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    wp_send_json_success();
}

add_action('wp_ajax_snapbook_admin_send_balance_reminder', 'snapbook_admin_send_balance_reminder');
function snapbook_admin_send_balance_reminder()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    if (! function_exists('snapbook_send_balance_reminder_email')) {
        wp_send_json_error(['message' => 'Reminder function unavailable.']);
    }

    global $wpdb;
    $booking_id = absint(wp_unslash($_POST['id'] ?? 0));
    if ($booking_id < 1) {
        wp_send_json_error(['message' => 'Invalid booking id.']);
    }

    $booking = $wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}fpb_bookings WHERE id = %d", $booking_id)); // phpcs:ignore
    if (! $booking || empty($booking->order_id)) {
        wp_send_json_error(['message' => 'Booking order not found.']);
    }

    $sent = snapbook_send_balance_reminder_email((int) $booking->order_id, true);
    if (! $sent) {
        wp_send_json_error(['message' => 'Reminder was not sent (already paid or data missing).']);
    }

    wp_send_json_success(['message' => 'Reminder email sent.']);
}

add_action('wp_ajax_snapbook_admin_save_session',   'snapbook_admin_save_session');
add_action('wp_ajax_snapbook_admin_delete_session', 'snapbook_admin_delete_session');

function snapbook_admin_save_session()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
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

function snapbook_admin_delete_session()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';
    $id  = absint(wp_unslash($_POST['id'] ?? 0));
    $wpdb->delete("{$pfx}sessions", ['id' => $id]); // phpcs:ignore
    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — CRUD: Packages
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_admin_save_package',   'snapbook_admin_save_package');
add_action('wp_ajax_snapbook_admin_delete_package', 'snapbook_admin_delete_package');

function snapbook_admin_save_package()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $id   = absint(wp_unslash($_POST['id'] ?? 0));
    $data = [
        'session_id'  => absint(wp_unslash($_POST['session_id'] ?? 0)),
        'name'        => sanitize_text_field(wp_unslash($_POST['name']        ?? '')),
        'price'       => floatval(wp_unslash($_POST['price']       ?? 0)),
        'duration'    => sanitize_text_field(wp_unslash($_POST['duration']    ?? '')),
        // Rich text from the package editor — lists, bold, links, etc.
        'description' => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
        'featured'    => absint(wp_unslash($_POST['featured'] ?? 0)) === 1 ? 1 : 0,
        'sort_order'  => absint(wp_unslash($_POST['sort_order']  ?? 0)),
        'active'      => absint(wp_unslash($_POST['active'] ?? 0)) === 1 ? 1 : 0,
    ];
    if (empty($data['name']) || $data['session_id'] < 1) {
        wp_send_json_error(['message' => 'Session type and name are required.']);
    }
    if ($id) {
        // Share-link slug is stable: kept on rename, only filled when missing.
        $existing_slug = (string) $wpdb->get_var($wpdb->prepare("SELECT slug FROM {$pfx}packages WHERE id=%d", $id)); // phpcs:ignore
        if ($existing_slug === '') {
            $data['slug'] = snapbook_unique_package_slug($data['name'], $id);
        }
        $result = $wpdb->update("{$pfx}packages", $data, ['id' => $id]); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    } else {
        $data['slug'] = snapbook_unique_package_slug($data['name']);
        $result = $wpdb->insert("{$pfx}packages", $data); // phpcs:ignore
        if ($result === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
        $id = $wpdb->insert_id;
    }
    wp_send_json_success(['id' => $id]);
}

function snapbook_admin_delete_package()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'fpb_packages', ['id' => absint(wp_unslash($_POST['id'] ?? 0))]); // phpcs:ignore
    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — CRUD: Add-ons
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_admin_save_addon',   'snapbook_admin_save_addon');
add_action('wp_ajax_snapbook_admin_delete_addon', 'snapbook_admin_delete_addon');

function snapbook_admin_save_addon()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $id   = absint(wp_unslash($_POST['id'] ?? 0));

    // Multi-select package assignment. Selecting "All Packages" (value 0)
    // anywhere — or selecting nothing — makes the add-on global.
    $raw_pkg_ids = isset($_POST['package_ids']) ? (array) wp_unslash($_POST['package_ids']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $pkg_ids     = array_map('absint', $raw_pkg_ids);
    $is_global   = empty($pkg_ids) || in_array(0, $pkg_ids, true);
    $pkg_ids     = $is_global ? [] : array_values(array_unique(array_filter($pkg_ids)));

    $data = [
        'name'        => sanitize_text_field(wp_unslash($_POST['name']        ?? '')),
        'price'       => floatval(wp_unslash($_POST['price']       ?? 0)),
        'emoji'       => wp_encode_emoji(sanitize_text_field(wp_unslash($_POST['emoji']       ?? ''))),
        // Rich text from the add-on editor — lists, bold, links, etc.
        'description' => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
        'package_id'  => $pkg_ids ? $pkg_ids[0] : 0, // legacy single-package column
        'package_ids' => implode(',', $pkg_ids),
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

function snapbook_admin_delete_addon()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'fpb_addons', ['id' => absint(wp_unslash($_POST['id'] ?? 0))]); // phpcs:ignore
    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — Date Slots: get / toggle
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_admin_get_dates',    'snapbook_admin_get_dates');
add_action('wp_ajax_snapbook_admin_toggle_date',  'snapbook_admin_toggle_date');

function snapbook_admin_get_dates()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $rows = $wpdb->get_results("SELECT date_str, status, notes FROM {$pfx}dates ORDER BY date_str"); // phpcs:ignore
    $out  = [];
    foreach ($rows as $r) $out[$r->date_str] = $r->status;
    wp_send_json_success($out);
}

function snapbook_admin_toggle_date()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
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
add_action('wp_ajax_snapbook_admin_update_booking_status', 'snapbook_admin_update_booking_status');
function snapbook_admin_update_booking_status()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $id     = absint(wp_unslash($_POST['id'] ?? 0));
    $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
    if ($status === 'pending') {
        $status = 'pending_payment';
    }
    if (! in_array($status, ['pending_payment', 'confirmed', 'cancelled', 'completed'], true)) wp_send_json_error();

    $response = [
        'booking_id' => $id,
        'booking_status' => $status,
        'main_order_id' => 0,
        'main_order_status' => '',
        'due_order_id' => 0,
        'due_order_status' => '',
        'updated_order_id' => 0,
        'updated_order_status' => '',
    ];

    // Update booking status
    $wpdb->update($wpdb->prefix . 'fpb_bookings', ['status' => $status], ['id' => $id]); // phpcs:ignore

    // Get associated order and update WooCommerce order status.
    // For 50% bookings, update the balance order (second order), not the main paid order.
    $booking = $wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}fpb_bookings WHERE id = %d", $id)); // phpcs:ignore
    if ($booking && $booking->order_id && function_exists('wc_get_order')) {
        $main_order = wc_get_order((int) $booking->order_id);
        if ($main_order) {
            $response['main_order_id'] = (int) $main_order->get_id();
            $response['main_order_status'] = (string) $main_order->get_status();

            $target_order = $main_order;
            $due_order_id = (int) $main_order->get_meta('_fpb_due_order_id', true);
            if ($due_order_id > 0) {
                $due_order = wc_get_order($due_order_id);
                if ($due_order) {
                    $target_order = $due_order;
                    $response['due_order_id'] = (int) $due_order->get_id();
                    $response['due_order_status'] = (string) $due_order->get_status();
                }
            }

            $wc_status = 'pending';
            if ($status === 'confirmed') {
                $wc_status = 'processing';
            } elseif ($status === 'pending_payment') {
                $wc_status = 'pending';
            } elseif ($status === 'cancelled') {
                $wc_status = 'cancelled';
            } elseif ($status === 'completed') {
                $wc_status = 'completed';
            }

            $target_order->set_status($wc_status);
            $target_order->save();

            $response['updated_order_id'] = (int) $target_order->get_id();
            $response['updated_order_status'] = (string) $target_order->get_status();

            // Refresh statuses after save so UI can sync instantly without page reload.
            $ref_main_order = wc_get_order((int) $booking->order_id);
            if ($ref_main_order) {
                $response['main_order_id'] = (int) $ref_main_order->get_id();
                $response['main_order_status'] = (string) $ref_main_order->get_status();

                $ref_due_order_id = (int) $ref_main_order->get_meta('_fpb_due_order_id', true);
                if ($ref_due_order_id > 0) {
                    $ref_due_order = wc_get_order($ref_due_order_id);
                    if ($ref_due_order) {
                        $response['due_order_id'] = (int) $ref_due_order->get_id();
                        $response['due_order_status'] = (string) $ref_due_order->get_status();
                    }
                }
            }
        }
    }

    wp_send_json_success($response);
}

add_action('wp_ajax_snapbook_admin_update_wc_order_status', 'snapbook_admin_update_wc_order_status');
function snapbook_admin_update_wc_order_status()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    if (! function_exists('wc_get_order')) {
        wp_send_json_error(['message' => 'WooCommerce unavailable.']);
    }

    $order_id = absint(wp_unslash($_POST['order_id'] ?? 0));
    $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));

    if ($order_id < 1 || $status === '') {
        wp_send_json_error(['message' => 'Invalid order update request.']);
    }

    $allowed_statuses = array_keys((array) wc_get_order_statuses());
    $allowed_statuses = array_map(static function ($key) {
        return str_replace('wc-', '', (string) $key);
    }, $allowed_statuses);

    if (! in_array($status, $allowed_statuses, true)) {
        wp_send_json_error(['message' => 'Invalid WooCommerce order status.']);
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error(['message' => 'Order not found.']);
    }

    $order->set_status($status);
    $order->save();

    wp_send_json_success([
        'order_id' => $order_id,
        'status' => $status,
        'status_label' => wc_get_order_status_name($status),
    ]);
}
