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

    wp_send_json_success(snapbook_get_catalog_data() + [
        'currency' => snapbook_get_currency_symbol(),
        'depositPct' => snapbook_deposit_enabled() ? snapbook_get_deposit_pct() : 100,
        'partialPaymentEnabled' => ((int) get_option('fpb_enable_partial_payment', 1) === 1),
        'partialBlockDays' => snapbook_get_partial_block_days(),
        'partialOptionLabel' => function_exists('snapbook_partial_option_label') ? snapbook_partial_option_label() : (string) get_option('fpb_partial_option_label', ''),
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

    // Priced from the database when the form sends the package (it always
    // does since 1.4.0), so the summary shows exactly what will be charged.
    $args = snapbook_quote_args_from_post(sanitize_key(wp_unslash($_POST['payment_method'] ?? '')));
    if ($args['package_id'] < 1) {
        // A page cached before 1.4.0: only a total to go on (display only).
        $total   = max(0, floatval(wp_unslash($_POST['total_raw'] ?? 0)));
        $fee_pct = snapbook_get_payment_fee_pct();
        $fee     = round($total * $fee_pct / 100, 2);
        $payable = round($total + $fee, 2);
        $pct     = (snapbook_deposit_enabled() && $args['use_deposit'] && snapbook_can_use_partial_payment_for_date($args['session_date'])) ? snapbook_get_deposit_pct() : 100;
        $due     = round($payable * $pct / 100, 2);
        wp_send_json_success([
            'payPct'         => $pct,
            'depositPct'     => snapbook_get_deposit_pct(),
            'isEligible'     => $pct < 100,
            'dueToday'       => $due,
            'balanceDue'     => max(0, round($payable - $due, 2)),
            'subtotal'       => $total,
            'discount'       => 0,
            'couponCode'     => '',
            'couponError'    => '',
            'total'          => $total,
            'feePct'         => $fee_pct,
            'feeLabel'       => snapbook_payment_fee_label(),
            'feeAmount'      => $fee,
            'payable'        => $payable,
            'balanceDueDate' => '',
        ]);
    }

    $coupon_error = '';
    $quote        = snapbook_quote_booking($args);
    if (is_wp_error($quote) && $quote->get_error_code() === 'snapbook_coupon_invalid') {
        // A bad promo code shouldn't blank the summary: price without it and
        // report the problem next to the code field.
        $coupon_error        = $quote->get_error_message();
        $args['coupon_code'] = '';
        $quote               = snapbook_quote_booking($args);
    }
    if (is_wp_error($quote)) {
        wp_send_json_error(['message' => $quote->get_error_message(), 'code' => $quote->get_error_code()]);
    }

    wp_send_json_success([
        'payPct'         => $quote['pay_pct'],
        'depositPct'     => $quote['deposit_pct'],
        'isEligible'     => $quote['deposit_eligible'],
        'dueToday'       => $quote['due_now'],
        'balanceDue'     => $quote['balance'],
        'subtotal'       => $quote['subtotal'],
        'discount'       => $quote['discount'],
        'couponCode'     => $quote['coupon_code'],
        'couponError'    => $coupon_error,
        'total'          => $quote['total'],
        'feePct'         => $quote['fee_pct'],
        'feeLabel'       => $quote['fee_label'],
        'feeAmount'      => $quote['fee_amount'],
        'payable'        => $quote['payable'],
        'balanceDueDate' => $quote['balance_due_date'],
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC — Get booked/blocked dates for calendar
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_snapbook_get_dates',        'snapbook_ajax_get_dates');
add_action('wp_ajax_nopriv_snapbook_get_dates', 'snapbook_ajax_get_dates');
function snapbook_ajax_get_dates()
{
    // No nonce: this is public, read-only data. With one, a page served from
    // a page cache after the nonce expired got a 403, and the calendar then
    // showed every booked date as free. Pages built since 1.5.0 call
    // snapbook_get_availability instead; this stays for cached older pages.
    wp_send_json_success(snapbook_get_availability_data()['unavailable']);
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

    $cur          = snapbook_get_currency_symbol();
    $session_date = sanitize_text_field(wp_unslash($_POST['session_date'] ?? ''));
    $hold_token   = snapbook_clean_hold_token(sanitize_text_field(wp_unslash($_POST['hold_token'] ?? '')));
    $session_time = snapbook_slots_enabled()
        ? snapbook_normalize_time(sanitize_text_field(wp_unslash($_POST['session_time'] ?? '')))
        : sanitize_text_field(wp_unslash($_POST['session_time'] ?? ''));
    if ($session_date === '') {
        wp_send_json_error(['message' => __('Please choose a session date before checkout.', 'snapbook'), 'code' => 'snapbook_date']);
    }
    $date_ok = snapbook_validate_booking_date($session_date, 0, $session_time, $hold_token);
    if (is_wp_error($date_ok)) {
        wp_send_json_error(['message' => $date_ok->get_error_message(), 'code' => $date_ok->get_error_code()]);
    }
    $contract = snapbook_contract_from_post();
    if (is_wp_error($contract)) {
        wp_send_json_error(['message' => $contract->get_error_message(), 'code' => $contract->get_error_code()]);
    }

    // Price from the database, never from the browser. The gateway is chosen
    // later on the checkout page, so the fee is included here and comes off
    // there for fee-free methods (snapbook_strip_fee_for_gateway()).
    $quote = snapbook_quote_booking(snapbook_quote_args_from_post());
    if (is_wp_error($quote)) {
        wp_send_json_error(['message' => $quote->get_error_message(), 'code' => $quote->get_error_code()]);
    }
    $priced = $quote;

    $booking = [
        'product_id'    => $product_id,
        'session_type'  => $priced['session_type'],
        'package_name'  => $priced['package_name'],
        'package_id'    => $priced['package_id'],
        'addon_ids'     => $priced['addon_ids'],
        'addons_label'  => $priced['addons_label'],
        'addons_total'  => $priced['addons_total'],
        'subtotal'      => $quote['subtotal'],
        'coupon_code'   => $quote['coupon_code'],
        'discount'      => $quote['discount'],
        'total'         => $quote['payable'],
        'fee_pct'       => $quote['fee_pct'],
        'fee_amount'    => $quote['fee_amount'],
        'deposit'       => $quote['due_now'],
        'deposit_pct'   => $quote['pay_pct'],
        'balance_due_date' => $quote['balance_due_date'],
        'hold_token'    => $hold_token,
        'contract'      => $contract,
        'client_name'   => sanitize_text_field(wp_unslash($_POST['client_name']   ?? '')),
        'client_email'  => sanitize_email(wp_unslash($_POST['client_email']  ?? '')),
        'client_phone'  => sanitize_text_field(wp_unslash($_POST['client_phone']  ?? '')),
        'client_country' => sanitize_text_field(wp_unslash($_POST['client_country'] ?? '')),
        'session_date'  => $session_date,
        'session_time'  => $session_time,
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
// Only the WooCommerce-less booking form uses this. With WooCommerce active it
// would just be a public endpoint that emails any address, so it isn't there.
if (! class_exists('WooCommerce')) {
    add_action('wp_ajax_snapbook_submit',        'snapbook_ajax_submit');
    add_action('wp_ajax_nopriv_snapbook_submit', 'snapbook_ajax_submit');
}
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
    $site_name   = get_bloginfo('name', 'display');
    $first_name  = explode(' ', trim($name))[0];

    /* ── Studio notification ── */
    $admin_body  = snapbook_email_pill(__('New request', 'snapbook'), 'primary');
    $admin_body .= snapbook_email_title(__('New booking request', 'snapbook'));
    /* translators: %s: client name */
    $admin_body .= snapbook_email_text(sprintf(__('%s just submitted the booking form. Their details are below.', 'snapbook'), $name !== '' ? $name : $email));
    $admin_body .= snapbook_email_divider(20);
    $admin_body .= snapbook_email_section_label(__('Session', 'snapbook'));
    $admin_body .= snapbook_email_facts([
        ['label' => __('Package', 'snapbook'), 'value' => $pkg],
        ['label' => __('Date', 'snapbook'), 'value' => $date, 'strong' => true],
        ['label' => __('Time', 'snapbook'), 'value' => $time],
        ['label' => __('Location', 'snapbook'), 'value' => $location],
        ['label' => __('Total', 'snapbook'), 'value' => $total, 'strong' => true],
    ]);
    $admin_body .= snapbook_email_divider(24);
    $admin_body .= snapbook_email_section_label(__('Client', 'snapbook'));
    $admin_body .= snapbook_email_facts([
        ['label' => __('Name', 'snapbook'), 'value' => $name],
        ['label' => __('Email', 'snapbook'), 'value' => $email],
        ['label' => __('Phone', 'snapbook'), 'value' => $phone],
        ['label' => __('Signer', 'snapbook'), 'value' => $signer],
    ]);
    if (trim($notes) !== '') {
        $admin_body .= snapbook_email_divider(24);
        $admin_body .= snapbook_email_section_label(__('Notes', 'snapbook'));
        $admin_body .= snapbook_email_text(nl2br(esc_html($notes)), true);
    }

    snapbook_email_send(
        $admin_email,
        /* translators: %s: client name */
        sprintf(__('New Booking Request — %s', 'snapbook'), $name),
        $admin_body,
        [
            'eyebrow'   => __('Booking request', 'snapbook'),
            /* translators: 1: package name, 2: session date */
            'preheader' => sprintf(__('%1$s — %2$s', 'snapbook'), $pkg, $date),
            'headers'   => is_email($email) ? ['Reply-To: ' . $name . ' <' . $email . '>'] : [],
        ]
    );

    /* ── Client acknowledgement ── */
    $client_body  = snapbook_email_pill(__('Request received', 'snapbook'));
    /* translators: %s: client first name */
    $client_body .= snapbook_email_title(sprintf(__('Thank you, %s', 'snapbook'), $first_name !== '' ? $first_name : __('there', 'snapbook')));
    $client_body .= snapbook_email_text(__('We have your booking request and will confirm it within 24 hours. Here is what you asked for:', 'snapbook'));
    $client_body .= snapbook_email_facts([
        ['label' => __('Package', 'snapbook'), 'value' => $pkg],
        ['label' => __('Date', 'snapbook'), 'value' => $date, 'strong' => true],
        ['label' => __('Time', 'snapbook'), 'value' => $time],
        ['label' => __('Total', 'snapbook'), 'value' => $total, 'strong' => true],
    ]);
    $client_body .= snapbook_email_spacer(22);
    $client_body .= snapbook_email_text(__('No payment is needed yet — simply reply to this email if anything needs changing.', 'snapbook'));

    snapbook_email_send(
        $email,
        /* translators: %s: site name */
        sprintf(__('Booking request received — %s', 'snapbook'), $site_name),
        $client_body,
        [
            'eyebrow'   => __('Booking request', 'snapbook'),
            'preheader' => __('We will confirm your session within 24 hours.', 'snapbook'),
            'headers'   => ['Reply-To: ' . $admin_email],
        ]
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

    foreach (['fpb_partial_option_label', 'fpb_whatsapp', 'fpb_success_title', 'fpb_success_msg', 'fpb_whatsapp_btn', 'fpb_confirm_title', 'fpb_confirm_msg', 'fpb_confirm_pending_title', 'fpb_confirm_pending_msg'] as $key) {
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
    update_option('fpb_payment_fee_pct', min(100, max(0, (float) sanitize_text_field(wp_unslash($_POST['fpb_payment_fee_pct'] ?? 0)))));
    update_option('fpb_require_account_booking', absint(wp_unslash($_POST['fpb_require_account_booking'] ?? 0)) === 1 ? 1 : 0);
    snapbook_save_balance_reminder_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.
    snapbook_save_extra_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.

    update_option('fpb_order_email_enable', absint(wp_unslash($_POST['fpb_order_email_enable'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_order_email_order_table', absint(wp_unslash($_POST['fpb_order_email_order_table'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_order_email_subject', sanitize_text_field(wp_unslash($_POST['fpb_order_email_subject'] ?? '')));
    update_option('fpb_order_email_heading', sanitize_text_field(wp_unslash($_POST['fpb_order_email_heading'] ?? '')));
    update_option('fpb_order_email_message', wp_kses_post(wp_unslash($_POST['fpb_order_email_message'] ?? '')));
    update_option('fpb_order_email_attachment_id', absint(wp_unslash($_POST['fpb_order_email_attachment_id'] ?? 0)));

    update_option('fpb_admin_email_enable', absint(wp_unslash($_POST['fpb_admin_email_enable'] ?? 0)) === 1 ? 1 : 0);
    update_option('fpb_admin_email_recipient', function_exists('snapbook_sanitize_email_list') ? snapbook_sanitize_email_list(wp_unslash($_POST['fpb_admin_email_recipient'] ?? '')) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    update_option('fpb_admin_email_subject', sanitize_text_field(wp_unslash($_POST['fpb_admin_email_subject'] ?? '')));
    update_option('fpb_admin_email_heading', sanitize_text_field(wp_unslash($_POST['fpb_admin_email_heading'] ?? '')));
    update_option('fpb_admin_email_intro', wp_kses_post(wp_unslash($_POST['fpb_admin_email_intro'] ?? '')));

    if (function_exists('snapbook_sanitize_checkout_mode')) {
        update_option('fpb_checkout_mode', snapbook_sanitize_checkout_mode(sanitize_key(wp_unslash($_POST['fpb_checkout_mode'] ?? 'direct'))));
    }
    if (function_exists('snapbook_sanitize_checkout_field_config')) {
        update_option('fpb_checkout_form_fields', snapbook_sanitize_checkout_field_config($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    // Google Calendar. Each field is only written when the settings screen
    // actually rendered it: the sync toggle exists only once connected, and the
    // key fields are hidden when the credentials come from wp-config constants.
    if (isset($_POST['fpb_gcal_enabled'])) {
        update_option('fpb_gcal_enabled', absint(wp_unslash($_POST['fpb_gcal_enabled'])) === 1 ? 1 : 0);
    }
    if (isset($_POST['fpb_gcal_client_id'])) {
        update_option('fpb_gcal_client_id', sanitize_text_field(wp_unslash($_POST['fpb_gcal_client_id'])));
    }
    if (isset($_POST['fpb_gcal_client_secret'])) {
        update_option('fpb_gcal_client_secret', sanitize_text_field(wp_unslash($_POST['fpb_gcal_client_secret'])));
    }

    wp_send_json_success();
}

add_action('wp_ajax_snapbook_admin_send_balance_reminder', 'snapbook_admin_send_balance_reminder');
function snapbook_admin_send_balance_reminder()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! snapbook_can_manage()) {
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

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fpb_bookings WHERE id = %d", $booking_id)); // phpcs:ignore
    if (! $booking || empty($booking->order_id)) {
        wp_send_json_error(['message' => 'Booking order not found.']);
    }

    // Say exactly why when a reminder can't go out (paid, cancelled, no
    // working payment link, no email) instead of a catch-all message.
    if (function_exists('snapbook_admin_prepare_booking')) {
        $check = clone $booking;
        snapbook_admin_prepare_booking($check);
        $pay = $check->fpb_payment;
        if (empty($pay['can_remind'])) {
            wp_send_json_error(['message' => $pay['remind_block'] !== '' ? $pay['remind_block'] : __('This booking has no unpaid balance to remind the customer about.', 'snapbook')]);
        }
    }

    $sent = snapbook_send_balance_reminder_email((int) $booking->order_id, true);
    if (! $sent) {
        wp_send_json_error(['message' => __('Reminder was not sent: the balance is already paid, the balance order is not awaiting payment (on hold, cancelled or refunded), the customer has no email address, or the email could not be sent.', 'snapbook')]);
    }

    // Fresh reminder details (count, last sent, next automatic) so the View
    // window can update without a reload.
    $data = ['message' => __('Reminder email sent.', 'snapbook')];
    if (function_exists('snapbook_admin_prepare_booking')) {
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fpb_bookings WHERE id = %d", $booking_id)); // phpcs:ignore
        if ($fresh) {
            snapbook_admin_prepare_booking($fresh);
            $data['payment'] = $fresh->fpb_payment;
            if ($fresh->fpb_payment['remind_email'] !== '') {
                /* translators: %s: customer email address */
                $data['message'] = sprintf(__('Reminder sent to %s.', 'snapbook'), $fresh->fpb_payment['remind_email']);
            }
        }
    }

    wp_send_json_success($data);
}

/**
 * "Run reminder check now" on the settings screen: the same sweep WP-Cron
 * runs every hour, on demand.
 */
add_action('wp_ajax_snapbook_admin_run_reminders', 'snapbook_admin_run_reminders');
function snapbook_admin_run_reminders()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! snapbook_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'snapbook')]);
    }
    if (! function_exists('snapbook_run_balance_reminders')) {
        wp_send_json_error(['message' => __('WooCommerce is not active.', 'snapbook')]);
    }

    $result = snapbook_run_balance_reminders('manual');
    if (($result['skipped'] ?? '') === 'disabled') {
        wp_send_json_error(['message' => __('Both automatic reminders are switched off. Turn one on and save the settings first.', 'snapbook')]);
    }
    if (($result['skipped'] ?? '') === 'locked') {
        wp_send_json_error(['message' => __('A reminder check is already running. Try again in a minute.', 'snapbook')]);
    }

    /* translators: 1: bookings checked, 2: reminders sent */
    $message = sprintf(_n('Checked %1$d booking with an unpaid balance, %2$d reminder sent.', 'Checked %1$d bookings with an unpaid balance, %2$d reminders sent.', (int) $result['checked'], 'snapbook'), (int) $result['checked'], (int) $result['sent']);
    if ((int) $result['failed'] > 0) {
        /* translators: %d: reminders that failed to send */
        $message .= ' ' . sprintf(_n('%d email could not be sent. Check your site\'s email delivery.', '%d emails could not be sent. Check your site\'s email delivery.', (int) $result['failed'], 'snapbook'), (int) $result['failed']);
    }

    wp_send_json_success(['message' => $message, 'result' => $result]);
}

add_action('wp_ajax_snapbook_admin_save_session',   'snapbook_admin_save_session');
add_action('wp_ajax_snapbook_admin_delete_session', 'snapbook_admin_delete_session');

function snapbook_admin_save_session()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! snapbook_can_manage()) wp_send_json_error();
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
    if (! snapbook_can_manage()) wp_send_json_error();
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';
    $id  = absint(wp_unslash($_POST['id'] ?? 0));

    // Refuse while packages still point at this session type -- deleting it
    // would orphan them (gone from the admin list, still in the catalog).
    $pkg_names = $wpdb->get_col($wpdb->prepare("SELECT name FROM {$pfx}packages WHERE session_id=%d ORDER BY sort_order, id", $id)); // phpcs:ignore
    if (! empty($pkg_names)) {
        $shown = array_slice($pkg_names, 0, 5);
        $list  = implode(', ', $shown) . (count($pkg_names) > count($shown) ? ', …' : '');
        wp_send_json_error([
            'message' => sprintf(
                /* translators: 1: number of packages, 2: comma-separated package names */
                _n(
                    'This session type still has %1$d package (%2$s). Move it to another session type or delete it first, then try again.',
                    'This session type still has %1$d packages (%2$s). Move them to another session type or delete them first, then try again.',
                    count($pkg_names),
                    'snapbook'
                ),
                count($pkg_names),
                $list
            ),
        ]);
    }

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
    if (! snapbook_can_manage()) wp_send_json_error();
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
        // The package's own deposit %: 1–99, or 0 (blank) = the global setting.
        'deposit_pct' => min(99, absint(wp_unslash($_POST['deposit_pct'] ?? 0))),
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
    if (! snapbook_can_manage()) wp_send_json_error();
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
    if (! snapbook_can_manage()) wp_send_json_error();
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
    if (! snapbook_can_manage()) wp_send_json_error();
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
    if (! snapbook_can_manage()) wp_send_json_error();
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
    if (! snapbook_can_manage()) wp_send_json_error();
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
    // Validate date format YYYY-MM-DD
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error(['message' => 'Invalid date.']);

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}dates WHERE date_str=%s", $date)); // phpcs:ignore

    // A date a customer actually booked is not the calendar's to change:
    // cycling it would reopen it for a double booking. Name who holds it.
    $holders = $wpdb->get_results($wpdb->prepare("SELECT id, order_id, client_name, client_email FROM {$pfx}bookings WHERE session_date=%s AND status NOT IN ('cancelled') ORDER BY id", $date)); // phpcs:ignore
    if (! empty($holders)) {
        $who = [];
        foreach ($holders as $h) {
            $name = trim((string) $h->client_name);
            if ($name === '') {
                $name = trim((string) $h->client_email) !== '' ? trim((string) $h->client_email) : __('a customer', 'snapbook');
            }
            /* translators: %d: WooCommerce order ID or SnapBook booking ID */
            $ref   = (int) $h->order_id > 0 ? sprintf(__('order #%d', 'snapbook'), (int) $h->order_id) : sprintf(__('booking #%d', 'snapbook'), (int) $h->id);
            $who[] = $name . ' (' . $ref . ')';
        }
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: client name(s) with their order/booking reference */
                _n(
                    'Booked by %s. Cancel the booking first to free this date.',
                    'Booked by %s. Cancel those bookings first to free this date.',
                    count($who),
                    'snapbook'
                ),
                implode(', ', $who)
            ),
            'date'    => $date,
            'status'  => $existing ? (string) $existing->status : 'available',
        ]);
    }

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
    if (! snapbook_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'snapbook')]);
    }

    $id     = absint(wp_unslash($_POST['id'] ?? 0));
    $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
    if ($status === 'pending') {
        $status = 'pending_payment';
    }
    if ($id < 1 || ! array_key_exists($status, snapbook_booking_statuses())) {
        wp_send_json_error(['message' => __('Unknown booking status.', 'snapbook')]);
    }

    // The rules (what may change, and what it does to the WooCommerce orders)
    // live in snapbook_admin_change_booking_status() -- admin-bookings.php. A
    // status change never invents a payment: money is logged with "Record
    // payment" (or arrives through WooCommerce).
    $result = snapbook_admin_change_booking_status($id, $status);
    if (is_wp_error($result)) {
        $data = (array) $result->get_error_data();
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
            'record'  => ! empty($data['record']),
        ]);
    }

    wp_send_json_success($result);
}

add_action('wp_ajax_snapbook_admin_update_wc_order_status', 'snapbook_admin_update_wc_order_status');
function snapbook_admin_update_wc_order_status()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! snapbook_can_manage()) {
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
