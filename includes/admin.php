<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', 'snapbook_admin_menu');
function snapbook_admin_menu()
{
    add_menu_page(
        __('SnapBook', 'snapbook'),
        __('SnapBook', 'snapbook'),
        'manage_options',
        'sb-bookings',
        'snapbook_page_bookings',
        'dashicons-camera',
        30
    );
    add_submenu_page('sb-bookings', __('All Bookings', 'snapbook'),    __('All Bookings', 'snapbook'),    'manage_options', 'sb-bookings',       'snapbook_page_bookings');
    add_submenu_page('sb-bookings', __('Session Types', 'snapbook'),   __('Session Types', 'snapbook'),   'manage_options', 'sb-sessions',       'snapbook_page_sessions');
    add_submenu_page('sb-bookings', __('Packages', 'snapbook'),        __('Packages', 'snapbook'),        'manage_options', 'sb-packages',       'snapbook_page_packages');
    add_submenu_page('sb-bookings', __('Add-ons', 'snapbook'),         __('Add-ons', 'snapbook'),         'manage_options', 'sb-addons',         'snapbook_page_addons');
    add_submenu_page('sb-bookings', __('Date Slots', 'snapbook'),      __('Date Slots', 'snapbook'),      'manage_options', 'sb-dates',          'snapbook_page_dates');
    add_submenu_page('sb-bookings', __('Settings', 'snapbook'),        __('Settings', 'snapbook'),        'manage_options', 'sb-settings',       'snapbook_page_settings');
}

/* ─── Admin assets ─────────────────────────────────────────── */
add_action('admin_enqueue_scripts', 'snapbook_admin_assets');
function snapbook_admin_assets($hook)
{
    if (strpos($hook, 'sb-') === false) return;
    wp_enqueue_style('snapbook-admin', SNAPBOOK_URL . 'assets/css/admin.css', [], SNAPBOOK_VER);
    $icon_lib = snapbook_icon_library_url();
    if ($icon_lib !== '') {
        wp_enqueue_style('snapbook-icons', $icon_lib, [], SNAPBOOK_VER);
    }
    wp_enqueue_script('snapbook-admin',    SNAPBOOK_URL . 'assets/js/admin.js',   [], SNAPBOOK_VER, true);
    wp_localize_script('snapbook-admin', 'snapbookAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('snapbook_admin_nonce'),
    ]);
}

function snapbook_is_plugin_admin_page()
{
    $page = sanitize_key(wp_unslash($_GET['page'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return strpos($page, 'sb-') === 0;
}

/**
 * Toggle-switch checkbox used by the add/edit forms. Same input name and
 * semantics as a plain checkbox, so form serialization stays unchanged.
 */
function snapbook_toggle_field($name, $label, $checked, $hint = '')
{
    $out  = '<label class="fpb-toggle">';
    $out .= '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
    $out .= '<span class="fpb-toggle-track" aria-hidden="true"></span>';
    $out .= '<span class="fpb-toggle-text">' . esc_html($label);
    if ($hint !== '') {
        $out .= '<small>' . esc_html($hint) . '</small>';
    }
    $out .= '</span></label>';

    return $out;
}

/**
 * Consistent empty-state panel for the list cards.
 */
function snapbook_empty_state($dashicon, $title, $hint = '')
{
    $out  = '<div class="fpb-empty">';
    $out .= '<span class="fpb-empty-icon dashicons ' . esc_attr($dashicon) . '" aria-hidden="true"></span>';
    $out .= '<span class="fpb-empty-title">' . esc_html($title) . '</span>';
    if ($hint !== '') {
        $out .= '<span class="fpb-empty-hint">' . esc_html($hint) . '</span>';
    }
    $out .= '</div>';

    return $out;
}

add_filter('admin_footer_text', 'snapbook_hide_admin_footer_text');
function snapbook_hide_admin_footer_text($footer_text)
{
    if (! snapbook_is_plugin_admin_page()) {
        return $footer_text;
    }
    return '';
}

add_filter('update_footer', 'snapbook_hide_admin_version_text', 999);
function snapbook_hide_admin_version_text($version_text)
{
    if (! snapbook_is_plugin_admin_page()) {
        return $version_text;
    }
    return '';
}

/* ═══════════════════════════════════════════════════════════════
   HELPER — shared page wrapper
═══════════════════════════════════════════════════════════════ */
function snapbook_wrap_open($title, $active_tab = '', $subtitle = '')
{
    $tabs = [
        'sb-bookings' => ['label' => __('Bookings', 'snapbook'),      'icon' => 'dashicons-clipboard'],
        'sb-sessions' => ['label' => __('Session Types', 'snapbook'), 'icon' => 'dashicons-category'],
        'sb-packages' => ['label' => __('Packages', 'snapbook'),      'icon' => 'dashicons-archive'],
        'sb-addons'   => ['label' => __('Add-ons', 'snapbook'),       'icon' => 'dashicons-star-filled'],
        'sb-dates'    => ['label' => __('Date Slots', 'snapbook'),    'icon' => 'dashicons-calendar-alt'],
        'sb-settings' => ['label' => __('Settings', 'snapbook'),      'icon' => 'dashicons-admin-generic'],
    ];
    echo '<div class="wrap fpb-admin-wrap">';
    echo '<div class="sb-topbar">';
    echo '<div class="sb-topbar-brand">';
    echo '<span class="sb-topbar-logo"><span class="dashicons dashicons-camera" aria-hidden="true"></span></span>';
    echo '<span class="sb-topbar-title">SnapBook</span>';
    echo '<span class="sb-topbar-ver">v' . esc_html(SNAPBOOK_VER) . '</span>';
    echo '</div>';
    echo '<nav class="sb-tabs" aria-label="' . esc_attr__('SnapBook sections', 'snapbook') . '">';
    foreach ($tabs as $slug => $tab) {
        $url    = admin_url('admin.php?page=' . $slug);
        $active = ($slug === $active_tab) ? ' fpb-active' : '';
        echo '<a href="' . esc_url($url) . '" class="sb-tab' . esc_attr($active) . '">';
        echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="sb-tab-label">' . esc_html($tab['label']) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
    echo '</div>';
    echo '<h1 class="sb-page-title">' . esc_html($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="sb-page-sub">' . esc_html($subtitle) . '</p>';
    }
    echo '<hr class="wp-header-end">';
    echo '<div class="fpb-admin-body">';
}
function snapbook_wrap_close()
{
    echo '</div></div>';
}

function snapbook_render_smart_layout_bar($base_url)
{
    echo '<div class="fpb-smart-bar">';
    echo '<a class="button button-primary fpb-smart-add" href="' . esc_url($base_url) . '">+ ' . esc_html__('Add New', 'snapbook') . '</a>';
    echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ALL BOOKINGS
═══════════════════════════════════════════════════════════════ */
function snapbook_page_bookings()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $cur      = snapbook_get_currency_symbol();
    // Decoded symbol for the balance pill / modal so a currency entity like
    // &euro; is not double-escaped into literal text.
    $cur_disp = html_entity_decode($cur, ENT_QUOTES, 'UTF-8');
    $status   = sanitize_text_field(wp_unslash($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($status === 'pending') {
        $status = 'pending_payment';
    }
    $where    = $status ? $wpdb->prepare('WHERE status = %s', $status) : '';
    $bookings = $wpdb->get_results("SELECT * FROM {$pfx}bookings {$where} ORDER BY created_at DESC LIMIT 200"); // phpcs:ignore

    snapbook_wrap_open('All Bookings', 'sb-bookings', __('Track and manage every session booking in one place.', 'snapbook'));

    // Status filter with live counts
    $count_rows = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$pfx}bookings GROUP BY status"); // phpcs:ignore
    $status_counts = [];
    $total_count = 0;
    foreach ($count_rows as $count_row) {
        $count_key = ($count_row->status === 'pending') ? 'pending_payment' : (string) $count_row->status;
        $status_counts[$count_key] = ($status_counts[$count_key] ?? 0) + (int) $count_row->c;
        $total_count += (int) $count_row->c;
    }

    // At-a-glance stats
    $booked_value = (float) $wpdb->get_var("SELECT COALESCE(SUM(total),0) FROM {$pfx}bookings WHERE status != 'cancelled'"); // phpcs:ignore
    $stat_cards = [
        ['icon' => 'dashicons-clipboard', 'tone' => 'teal',  'label' => __('Total Bookings', 'snapbook'),  'value' => (string) $total_count],
        ['icon' => 'dashicons-clock',     'tone' => 'gold',  'label' => __('Pending Payment', 'snapbook'), 'value' => (string) (int) ($status_counts['pending_payment'] ?? 0)],
        ['icon' => 'dashicons-yes-alt',   'tone' => 'green', 'label' => __('Completed', 'snapbook'),       'value' => (string) (int) ($status_counts['completed'] ?? 0)],
        ['icon' => 'dashicons-chart-bar', 'tone' => 'ink',   'label' => __('Booked Value', 'snapbook'),    'value' => $cur . number_format($booked_value, 0)],
    ];
    echo '<div class="sb-stats">';
    foreach ($stat_cards as $sc) {
        echo '<div class="sb-stat sb-stat-' . esc_attr($sc['tone']) . '">';
        echo '<span class="dashicons ' . esc_attr($sc['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="sb-stat-body"><span class="sb-stat-value">' . esc_html($sc['value']) . '</span><span class="sb-stat-label">' . esc_html($sc['label']) . '</span></span>';
        echo '</div>';
    }
    echo '</div>';

    $statuses = [
        '' => 'All',
        'pending_payment' => 'Pending Payment',
        'confirmed' => 'Processing',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ];
    echo '<ul class="subsubsub sb-filter-bar">';
    foreach ($statuses as $k => $v) {
        $url = admin_url('admin.php?page=sb-bookings' . ($k ? '&status=' . $k : ''));
        $cls = ($k === $status) ? ' current fpb-active' : '';
        $n   = ($k === '') ? $total_count : (int) ($status_counts[$k] ?? 0);
        echo '<li><a href="' . esc_url($url) . '" class="fpb-filter-btn' . esc_attr($cls) . '">' . esc_html($v) . '<span class="fpb-filter-count">' . (int) $n . '</span></a></li>';
    }
    echo '</ul><br class="clear" />';

    if (empty($bookings)) {
        echo snapbook_empty_state('dashicons-clipboard', __('No bookings found', 'snapbook'), __('New bookings appear here as soon as customers complete the booking form.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_empty_state.
    } else {
        echo '<div class="fpb-table-wrap"><table class="wp-list-table widefat fixed striped fpb-table"><thead><tr>';
        echo '<th>#</th><th>Client</th><th>Package</th><th>Date</th><th>Total</th><th>Deposit</th><th>Status</th><th>Order</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($bookings as $b) {
            $main_order = null;
            $due_order_id = 0;
            $due_pay_url = '';
            $due_edit_url = '';
            $main_order_status = '';
            $due_order_status = '';
            $b->checkout_fields = [];
            if (class_exists('WooCommerce') && ! empty($b->order_id) && function_exists('wc_get_order')) {
                $main_order = wc_get_order((int) $b->order_id);
                if ($main_order) {
                    $main_order_status = (string) $main_order->get_status();
                    $due_order_id = (int) $main_order->get_meta('_fpb_due_order_id', true);

                    $billing_country = (string) $main_order->get_billing_country();
                    $billing_country_name = $billing_country;
                    if (function_exists('WC') && WC() && isset(WC()->countries->countries[$billing_country])) {
                        $billing_country_name = (string) WC()->countries->countries[$billing_country];
                    }

                    $b->checkout_fields = [
                        'billing_first_name'      => (string) $main_order->get_billing_first_name(),
                        'billing_last_name'       => (string) $main_order->get_billing_last_name(),
                        'billing_company'         => (string) $main_order->get_billing_company(),
                        'billing_country'         => $billing_country,
                        'billing_country_name'    => $billing_country_name,
                        'billing_state'           => (string) $main_order->get_billing_state(),
                        'billing_city'            => (string) $main_order->get_billing_city(),
                        'billing_postcode'        => (string) $main_order->get_billing_postcode(),
                        'billing_address_1'       => (string) $main_order->get_billing_address_1(),
                        'billing_address_2'       => (string) $main_order->get_billing_address_2(),
                        'billing_phone'           => (string) $main_order->get_billing_phone(),
                        'billing_email'           => (string) $main_order->get_billing_email(),
                        'billing_event_date'      => (string) $main_order->get_meta('_fpb_billing_event_date', true),
                        'billing_event_time'      => (string) $main_order->get_meta('_fpb_billing_event_time', true),
                        'billing_hotel_place'     => (string) $main_order->get_meta('_fpb_billing_hotel_place', true),
                        'billing_participants'    => (string) $main_order->get_meta('_fpb_billing_participants', true),
                        'billing_room_number'     => (string) $main_order->get_meta('_fpb_billing_room_number', true),
                        'billing_stay_period'     => (string) $main_order->get_meta('_fpb_billing_stay_period', true),
                        'order_customer_note'     => (string) $main_order->get_customer_note(),
                    ];

                    // Admin-created custom checkout fields — label is used as the display key.
                    if (function_exists('snapbook_get_custom_checkout_fields')) {
                        foreach (snapbook_get_custom_checkout_fields() as $ckey => $cf) {
                            $b->checkout_fields[$cf['label']] = (string) $main_order->get_meta('_fpb_cf_' . $ckey, true);
                        }
                    }

                    if ($due_order_id > 0) {
                        $due_order = wc_get_order($due_order_id);
                        if ($due_order) {
                            $due_order_status = (string) $due_order->get_status();
                            $due_pay_url  = (string) $due_order->get_checkout_payment_url();
                            $due_edit_url = (string) $due_order->get_edit_order_url();
                        }
                    }
                }
            }

            // Partial-payment snapshot for the balance pill + View-modal panel.
            $pay_total    = (float) $b->total;
            $pay_deposit  = (float) $b->deposit;
            $pay_balance  = max(0, round($pay_total - $pay_deposit, 2));
            $balance_paid = in_array($due_order_status, ['processing', 'completed'], true);
            $b->fpb_payment = [
                'currency'         => $cur_disp,
                'total'            => $pay_total,
                'deposit'          => $pay_deposit,
                'balance'          => $pay_balance,
                'pct'              => $pay_total > 0 ? (int) round($pay_deposit / $pay_total * 100) : 100,
                'is_partial'       => $pay_balance > 0.01,
                'due_order_id'     => $due_order_id,
                'due_status'       => $due_order_status,
                'due_status_label' => ($due_order_status !== '' && function_exists('wc_get_order_status_name')) ? wc_get_order_status_name($due_order_status) : '',
                'balance_paid'     => $balance_paid,
                'pay_link'         => $due_pay_url,
                'edit_link'        => $due_edit_url,
                'last_reminder'    => $main_order ? (string) $main_order->get_meta('_fpb_last_balance_reminder_sent', true) : '',
            ];

            echo '<tr class="fpb-brow" data-status="' . esc_attr($b->status) . '">';
            echo '<td>' . (int) $b->id . '</td>';
            echo '<td><strong>' . esc_html($b->client_name) . '</strong><br><small>' . esc_html($b->client_email) . '</small></td>';
            echo '<td>' . esc_html($b->session_type) . '<br><small>' . esc_html($b->package_name) . '</small></td>';
            // Order date: the WooCommerce order's creation date when available,
            // falling back to when the booking row itself was created.
            $order_date = '';
            if ($main_order && $main_order->get_date_created()) {
                $order_date = wp_date(get_option('date_format'), $main_order->get_date_created()->getTimestamp());
            } elseif (! empty($b->created_at)) {
                $order_date = date_i18n(get_option('date_format'), strtotime($b->created_at));
            }
            echo '<td>' . esc_html($order_date !== '' ? $order_date : '—') . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $b->total, 2)) . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $b->deposit, 2));
            if ($pay_balance > 0.01) {
                if ($balance_paid) {
                    echo '<br><span class="fpb-balpill fpb-balpill-paid">' . esc_html__('Balance paid', 'snapbook') . '</span>';
                } else {
                    /* translators: %s: remaining balance amount */
                    echo '<br><span class="fpb-balpill fpb-balpill-due">' . esc_html(sprintf(__('Balance %s', 'snapbook'), $cur_disp . number_format($pay_balance, 2))) . '</span>';
                }
            }
            echo '</td>';
            $status_key = (string) $b->status;
            if ($status_key === 'pending') {
                $status_key = 'pending_payment';
            }
            $status_label = ($status_key === 'confirmed') ? 'Processing' : ucwords(str_replace('_', ' ', $status_key));
            echo '<td><span class="sb-badge sb-badge-' . esc_attr($b->status) . '">' . esc_html($status_label) . '</span></td>';

            $order_link = '—';
            if (! empty($b->order_id)) {
                $order_link = '<a href="' . esc_url(admin_url('post.php?post=' . (int) $b->order_id . '&action=edit')) . '">#' . (int) $b->order_id . '</a>';
                if ($due_order_id > 0) {
                    $order_link .= '<br><small>Balance: <a href="' . esc_url(admin_url('post.php?post=' . (int) $due_order_id . '&action=edit')) . '">#' . (int) $due_order_id . '</a>';
                    if ($due_order_status !== '') {
                        $order_link .= ' (' . esc_html(wc_get_order_status_name($due_order_status)) . ')';
                    }
                    $order_link .= '</small>';
                }
            }
            echo '<td>' . wp_kses_post($order_link) . '</td>';

            $status_target_order_id = $due_order_id > 0 ? $due_order_id : (int) $b->order_id;
            if ($due_order_id > 0) {
                /* translators: %d: WooCommerce balance order ID */
                $status_target_label = sprintf(__('Updates balance order #%d', 'snapbook'), $due_order_id);
            } else {
                /* translators: %d: WooCommerce order ID */
                $status_target_label = sprintf(__('Updates main order #%d', 'snapbook'), (int) $b->order_id);
            }
            $actions_menu_id = 'fpb-actions-menu-' . (int) $b->id;

            echo '<td class="fpb-actions-cell">';
            echo '<div class="fpb-row-actions">';
            echo '<button class="button button-secondary sb-btn-sm sb-btn-view" data-id="' . (int) $b->id . '">' . esc_html__('View', 'snapbook') . '</button>';
            echo '<button type="button" class="button button-secondary sb-btn-sm fpb-row-actions-toggle" aria-expanded="false" aria-controls="' . esc_attr($actions_menu_id) . '">' . esc_html__('Actions', 'snapbook') . ' <span class="fpb-row-actions-caret" aria-hidden="true">&#9662;</span></button>';

            echo '<div class="fpb-row-actions-menu" id="' . esc_attr($actions_menu_id) . '" hidden>';

            echo '<div class="fpb-row-actions-section">';
            echo '<div class="fpb-row-actions-label">' . esc_html__('Booking Status', 'snapbook') . '</div>';
            echo '<select class="sb-status-select" data-id="' . (int) $b->id . '" data-main-order-id="' . (int) $b->order_id . '" data-due-order-id="' . (int) $due_order_id . '" data-target-order-id="' . (int) $status_target_order_id . '">';
            foreach (['pending_payment', 'confirmed', 'cancelled', 'completed'] as $st) {
                $st_label = ($st === 'confirmed') ? 'Processing' : ucwords(str_replace('_', ' ', $st));
                echo '<option value="' . esc_attr($st) . '"' . selected($b->status, $st, false) . '>' . esc_html($st_label) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            if (! empty($b->order_id)) {
                echo '<div class="fpb-row-actions-section">';
                echo '<div class="fpb-row-actions-label">' . esc_html__('Main Order Status', 'snapbook') . '</div>';
                echo '<select class="fpb-order-status-select" data-order-id="' . (int) $b->order_id . '">';
                foreach (['pending', 'on-hold', 'processing', 'completed', 'cancelled'] as $wc_st) {
                    echo '<option value="' . esc_attr($wc_st) . '"' . selected($main_order_status, $wc_st, false) . '>' . esc_html(wc_get_order_status_name($wc_st)) . '</option>';
                }
                echo '</select>';
                echo '</div>';
            }

            if ($due_order_id > 0) {
                echo '<div class="fpb-row-actions-section">';
                echo '<div class="fpb-row-actions-label">' . esc_html__('Balance Order Status', 'snapbook') . '</div>';
                echo '<select class="fpb-order-status-select" data-order-id="' . (int) $due_order_id . '">';
                foreach (['pending', 'on-hold', 'processing', 'completed', 'cancelled'] as $wc_st) {
                    echo '<option value="' . esc_attr($wc_st) . '"' . selected($due_order_status, $wc_st, false) . '>' . esc_html(wc_get_order_status_name($wc_st)) . '</option>';
                }
                echo '</select>';
                echo '</div>';
            }

            echo '<div class="fpb-row-actions-section">';
            echo '<div class="fpb-row-actions-label">' . esc_html__('Quick Actions', 'snapbook') . '</div>';
            echo '<div class="fpb-payment-quick">';
            echo '<button type="button" class="button button-link fpb-quick-status fpb-quick-wait" data-id="' . (int) $b->id . '" data-status="pending_payment">&#8987; ' . esc_html__('Waiting Payment', 'snapbook') . '</button>';
            echo '<button type="button" class="button button-link fpb-quick-status fpb-quick-complete" data-id="' . (int) $b->id . '" data-status="completed">&#10003; ' . esc_html__('Mark Booking Complete', 'snapbook') . '</button>';
            echo '<button type="button" class="button button-link fpb-quick-status fpb-quick-cancel" data-id="' . (int) $b->id . '" data-status="cancelled">&#10005; ' . esc_html__('Cancel', 'snapbook') . '</button>';
            if ($due_pay_url !== '' && ! $balance_paid) {
                echo '<button type="button" class="button button-link fpb-copy-pay-link" data-link="' . esc_attr($due_pay_url) . '">&#128279; ' . esc_html__('Copy Payment Link', 'snapbook') . '</button>';
            }
            if ($due_order_id > 0 && ! $balance_paid) {
                echo '<button type="button" class="button button-link fpb-send-balance-reminder" data-id="' . (int) $b->id . '">&#9993; ' . esc_html__('Send Balance Email', 'snapbook') . '</button>';
            }
            echo '</div>';
            echo '</div>';

            echo '</div>';
            echo '</div>';

            echo '<div class="fpb-status-hint" data-for-booking="' . (int) $b->id . '">' . esc_html($status_target_label) . '</div>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // View modal
    echo '<div id="sb-booking-modal" class="sb-modal" style="display:none"><div class="sb-modal-inner"><div class="sb-modal-head"><span>Booking</span><button class="sb-modal-close" aria-label="Close">✕</button></div><div class="sb-modal-body"></div></div></div>';
    // Inline booking data for JS
    echo '<script>var snapbookBookings=' . wp_json_encode(array_values($bookings)) . ';</script>';
    snapbook_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SESSION TYPES
═══════════════════════════════════════════════════════════════ */
function snapbook_page_sessions()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions ORDER BY sort_order, id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    snapbook_wrap_open('Session Types', 'sb-sessions', __('Define the photography session types customers can choose.', 'snapbook'));
    snapbook_render_smart_layout_bar(admin_url('admin.php?page=sb-sessions'));

    // ── Add / Edit form ─
    echo '<div class="postbox fpb-form-card" id="fpb-sessions-add"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Session Type' : 'Add New Session Type') . '</h3>';
    echo '<form id="fpb-session-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-form-grid fpb-cols-2">';
    echo '<div class="fpb-field"><label>Name <span class="fpb-req">*</span></label><input class="regular-text" type="text" id="fpb-session-name" name="name" required placeholder="Holiday / Couple Photoshoot" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown as a tab at the top of the booking form.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Slug <span class="fpb-req">*</span></label><input class="regular-text" type="text" id="fpb-session-slug" name="slug" required placeholder="photo" value="' . esc_attr($edit_row->slug ?? '') . '">';
    echo '<p class="description">' . esc_html__('Unique identifier — filled automatically from the name.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Emoji / Icon</label><input class="regular-text" type="text" name="emoji" maxlength="100" placeholder="📷 or dashicons dashicons-camera" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji, or a Dashicons class like "dashicons dashicons-camera" (see developer.wordpress.org/resource/dashicons). Font Awesome classes also work when your theme loads Font Awesome.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Visible on the booking form', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo '</div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn" id="fpb-session-save">' . ($edit_row ? 'Update Session Type' : 'Add Session Type') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-sessions')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-session-msg"></div>';
    echo '</form></div></div>';

    // ── List ─
    echo '<div class="postbox fpb-list-card" id="fpb-sessions-list"><div class="inside">';
    if (empty($sessions)) {
        echo snapbook_empty_state('dashicons-category', __('No session types yet', 'snapbook'), __('Add your first session type with the form above.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_empty_state.
    } else {
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Emoji</th><th>Name</th><th>Slug</th><th>Order</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($sessions as $row) {
            echo '<tr>';
            echo '<td>' . snapbook_icon_html($row->emoji) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_icon_html.
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td><code>' . esc_html($row->slug) . '</code></td>';
            echo '<td>' . (int) $row->sort_order . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sb-sessions&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-session" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    snapbook_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — PACKAGES
═══════════════════════════════════════════════════════════════ */
function snapbook_page_packages()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $cur      = snapbook_get_currency_symbol();
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $filter   = isset($_GET['session']) ? (int) $_GET['session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $filter ? $wpdb->prepare('AND p.session_id = %d', $filter) : '';
    $packages = $wpdb->get_results("SELECT p.*, s.name AS sname, s.emoji AS semoji FROM {$pfx}packages p JOIN {$pfx}sessions s ON s.id=p.session_id WHERE 1=1 {$where} ORDER BY p.session_id, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}packages WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    snapbook_wrap_open('Packages', 'sb-packages', __('Create the packages offered under each session type.', 'snapbook'));
    snapbook_render_smart_layout_bar(admin_url('admin.php?page=sb-packages'));

    // ── Form ─
    echo '<div class="postbox fpb-form-card" id="fpb-packages-add"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Package' : 'Add New Package') . '</h3>';
    echo '<form id="fpb-package-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-form-grid fpb-cols-2">';
    echo '<div class="fpb-field"><label>Session Type <span class="fpb-req">*</span></label><select name="session_id" required>';
    foreach ($sessions as $s) {
        $sel = $edit_row ? selected((int) $edit_row->session_id, (int) $s->id, false) : '';
        echo '<option value="' . (int) $s->id . '"' . $sel . '>' . esc_html(trim(snapbook_icon_text($s->emoji) . ' ' . $s->name)) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';
    echo '<div class="fpb-field"><label>Package Name <span class="fpb-req">*</span></label><input class="regular-text" type="text" name="name" required placeholder="Golden Hour" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Price (' . esc_html($cur) . ') <span class="fpb-req">*</span></label><input class="small-text" type="number" name="price" required step="0.01" min="0" placeholder="199" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Duration</label><input class="regular-text" type="text" name="duration" placeholder="1hr · 30 photos" value="' . esc_attr($edit_row->duration ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-editor"><label>Description</label>';
    wp_editor(
        (string) ($edit_row->description ?? ''),
        'fpb_package_desc',
        [
            'textarea_name' => 'description',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description">' . esc_html__('Shown on the package card. Supports formatting, bullet and numbered lists.', 'snapbook') . '</p>';
    echo '</div>';
    echo '<div class="fpb-field fpb-field-half"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('featured', __('Featured', 'snapbook'), isset($edit_row->featured) && (int) $edit_row->featured === 1, __('Highlighted with a ★ Popular tag', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo '</div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Package' : 'Add Package') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-packages')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-package-msg"></div>';
    echo '</form></div></div>';

    // ── List with session filter ─
    $booking_url = snapbook_get_booking_page_url();

    echo '<div class="postbox fpb-list-card" id="fpb-packages-list"><div class="inside">';
    if ($booking_url === '' && ! empty($packages)) {
        echo '<div class="notice notice-warning inline"><p>';
        echo esc_html__('No booking page found, so "Copy Link" URLs point to your homepage. Add the [snapbook] shortcode to a page, or pick your booking page under SnapBook → Settings → General.', 'snapbook');
        echo '</p></div>';
    }
    echo '<ul class="subsubsub fpb-filter-bar">';
    echo '<li><a href="' . esc_url(admin_url('admin.php?page=sb-packages')) . '" class="fpb-filter-btn' . (! $filter ? ' current fpb-active' : '') . '">All</a></li>';
    foreach ($sessions as $s) {
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=sb-packages&session=' . (int) $s->id)) . '" class="fpb-filter-btn' . ($filter === (int) $s->id ? ' current fpb-active' : '') . '">' . snapbook_icon_html($s->emoji) . ' ' . esc_html($s->name) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_icon_html.
    }
    echo '</ul><br class="clear" />';
    if (empty($packages)) {
        echo snapbook_empty_state('dashicons-archive', __('No packages yet', 'snapbook'), __('Create your first package with the form above.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_empty_state.
    } else {
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Session</th><th>Name</th><th>Price</th><th>Duration</th><th>Featured</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($packages as $row) {
            echo '<tr>';
            echo '<td>' . snapbook_icon_html($row->semoji) . ' ' . esc_html($row->sname) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_icon_html.
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 0)) . '</td>';
            echo '<td>' . esc_html($row->duration) . '</td>';
            echo '<td>' . ($row->featured ? '⭐' : '—') . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            $share_url = snapbook_package_share_link($row, $booking_url);
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sb-packages&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button type="button" class="button button-small fpb-btn-sm fpb-copy-link" data-link="' . esc_attr($share_url) . '" title="' . esc_attr__('Copy the shareable booking link for this package', 'snapbook') . '">' . esc_html__('Copy Link', 'snapbook') . '</button> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-package" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '<div class="fpb-share-link" hidden><input type="text" class="fpb-share-link-field" readonly value="' . esc_attr($share_url) . '" onfocus="this.select()" aria-label="' . esc_attr__('Shareable package link', 'snapbook') . '"></div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    snapbook_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ADD-ONS
═══════════════════════════════════════════════════════════════ */
function snapbook_page_addons()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx     = $wpdb->prefix . 'fpb_';
    $cur     = snapbook_get_currency_symbol();
    $addons  = $wpdb->get_results("SELECT a.*, p.name AS pname FROM {$pfx}addons a LEFT JOIN {$pfx}packages p ON p.id=a.package_id ORDER BY a.sort_order, a.id"); // phpcs:ignore
    $all_packages = $wpdb->get_results("SELECT p.id, p.name, s.emoji AS semoji, s.name AS sname FROM {$pfx}packages p JOIN {$pfx}sessions s ON s.id=p.session_id WHERE p.active=1 ORDER BY s.sort_order, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}addons WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    snapbook_wrap_open('Add-ons', 'sb-addons', __('Optional extras customers can add to any package.', 'snapbook'));
    snapbook_render_smart_layout_bar(admin_url('admin.php?page=sb-addons'));

    echo '<div class="postbox fpb-form-card" id="fpb-addons-add"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Add-on' : 'Add New Add-on') . '</h3>';
    echo '<form id="fpb-addon-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-form-grid fpb-cols-2">';
    echo '<div class="fpb-field"><label>Name <span class="fpb-req">*</span></label><input class="regular-text" type="text" name="name" required placeholder="Drone aerial session" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown on the add-on card in step 2 of the booking form.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Price (' . esc_html($cur) . ') <span class="fpb-req">*</span></label><input class="small-text" type="number" name="price" required step="0.01" min="0" placeholder="150" value="' . esc_attr($edit_row->price ?? '') . '">';
    echo '<p class="description">' . esc_html__('Added on top of the package price.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Emoji / Icon</label><input class="regular-text" type="text" name="emoji" maxlength="100" placeholder="🚁 or dashicons dashicons-star-filled" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji, or a Dashicons class like "dashicons dashicons-star-filled".', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field fpb-field-editor"><label>Description</label>';
    wp_editor(
        (string) ($edit_row->description ?? ''),
        'fpb_addon_desc',
        [
            'textarea_name' => 'description',
            'textarea_rows' => 5,
            'media_buttons' => false,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description">' . esc_html__('Shown on the add-on card. Supports formatting, bullet and numbered lists.', 'snapbook') . '</p>';
    echo '</div>';

    // Applies-to checklist — "All Packages" ticked (or nothing ticked) =
    // global; otherwise the add-on is offered only with the ticked packages.
    $addon_pkg_ids = array_values(array_filter(array_map('absint', explode(',', (string) ($edit_row->package_ids ?? '')))));
    if (empty($addon_pkg_ids) && ! empty($edit_row->package_id)) {
        $addon_pkg_ids = [(int) $edit_row->package_id]; // legacy single-package rows
    }
    $is_global_addon = empty($addon_pkg_ids);
    echo '<div class="fpb-field fpb-field-wide"><label>Applies To</label>';
    echo '<div class="fpb-pkg-checklist" id="fpb-addon-pkg-list">';
    echo '<label class="fpb-pkg-check fpb-pkg-check-all"><input type="checkbox" name="package_ids[]" value="0"' . checked($is_global_addon, true, false) . '> <strong>' . esc_html__('All Packages (global)', 'snapbook') . '</strong></label>';
    foreach ($all_packages as $pkg) {
        $chk = checked(in_array((int) $pkg->id, $addon_pkg_ids, true), true, false);
        echo '<label class="fpb-pkg-check"><input type="checkbox" name="package_ids[]" value="' . (int) $pkg->id . '"' . $chk . '> ' . esc_html(trim(snapbook_icon_text($pkg->semoji) . ' ' . $pkg->sname . ' › ' . $pkg->name)) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
    echo '<p class="description">' . esc_html__('Tick the packages this add-on is offered with — one, several, or "All Packages" for every package.', 'snapbook') . '</p></div>';

    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo '</div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Add-on' : 'Add Add-on') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-addons')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-addon-msg"></div>';
    echo '</form></div></div>';

    echo '<div class="postbox fpb-list-card" id="fpb-addons-list"><div class="inside">';
    if (empty($addons)) {
        echo snapbook_empty_state('dashicons-star-filled', __('No add-ons yet', 'snapbook'), __('Create optional extras customers can add to their booking.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_empty_state.
    } else {
        $pkg_name_lookup = [];
        foreach ($all_packages as $pkg) {
            $pkg_name_lookup[(int) $pkg->id] = $pkg->sname . ' › ' . $pkg->name;
        }
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Emoji</th><th>Name</th><th>Price</th><th>Applies To</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($addons as $row) {
            $scope_ids = array_values(array_filter(array_map('absint', explode(',', (string) ($row->package_ids ?? '')))));
            if (empty($scope_ids) && (int) $row->package_id > 0) {
                $scope_ids = [(int) $row->package_id]; // legacy single-package rows
            }
            if (empty($scope_ids)) {
                $scope = '<span class="fpb-badge fpb-badge-confirmed">All Packages</span>';
            } else {
                $scope_names = [];
                foreach ($scope_ids as $spid) {
                    $scope_names[] = esc_html($pkg_name_lookup[$spid] ?? ('#' . $spid));
                }
                $scope = implode(', ', $scope_names);
            }
            echo '<tr>';
            echo '<td>' . snapbook_icon_html($row->emoji) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_icon_html.
            echo '<td><strong>' . esc_html($row->name) . '</strong>' . ($row->description ? '<br><small>' . wp_kses_post($row->description) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 0)) . '</td>';
            echo '<td>' . wp_kses_post($scope) . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sb-addons&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-addon" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    snapbook_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — DATE SLOTS
═══════════════════════════════════════════════════════════════ */
function snapbook_page_dates()
{
    if (! current_user_can('manage_options')) return;
    snapbook_wrap_open('Date Slots', 'sb-dates', __('Control which days are open for new bookings.', 'snapbook'));
?>
<p class="fpb-hint">Click any <strong>future date</strong> to toggle it between Available → Booked → Blocked.</p>
<div class="fpb-dates-toolbar" id="fpb-dates-toolbar">
    <button type="button" class="button fpb-cal-admin-nav" id="fpb-prev-month">‹ Prev</button>
    <span class="fpb-cal-admin-month" id="fpb-month-label">Loading…</span>
    <button type="button" class="button fpb-cal-admin-nav" id="fpb-next-month">Next ›</button>
</div>
<div class="fpb-dates-calendar">
    <div class="fpb-cal-admin-dh">
        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
    </div>
    <div class="fpb-cal-admin-grid" id="fpb-admin-calGrid"></div>
</div>
<div class="fpb-cal-legend">
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-available"></span> Available</span>
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-booked"></span> Booked</span>
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-blocked"></span> Blocked by Admin</span>
    <span class="fpb-leg"><span class="fpb-leg-dot" style="background:var(--fpb-border);opacity:.5"></span> Past</span>
</div>
<div id="fpb-dates-msg" class="fpb-dates-msg"></div>
<?php
    snapbook_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SETTINGS
═══════════════════════════════════════════════════════════════ */
function snapbook_page_settings()
{
    if (! current_user_can('manage_options')) return;

    if (isset($_POST['snapbook_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['snapbook_settings_nonce'])), 'snapbook_settings')) {
        foreach (['fpb_step1_title', 'fpb_step1_sub', 'fpb_balance_reminder_subject', 'fpb_partial_option_label', 'fpb_whatsapp', 'fpb_success_title', 'fpb_success_msg', 'fpb_whatsapp_btn', 'fpb_confirm_title', 'fpb_confirm_msg', 'fpb_confirm_pending_title', 'fpb_confirm_pending_msg'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        update_option('fpb_admin_email', sanitize_email(wp_unslash($_POST['fpb_admin_email'] ?? '')) ?: get_option('admin_email'));
        update_option('fpb_booking_page_id', absint(wp_unslash($_POST['fpb_booking_page_id'] ?? 0)));
        if (function_exists('snapbook_sanitize_custom_checkout_fields')) {
            update_option('fpb_checkout_custom_fields', snapbook_sanitize_custom_checkout_fields($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        update_option('fpb_enable_partial_payment', absint(wp_unslash($_POST['fpb_enable_partial_payment'] ?? 0)) === 1 ? 1 : 0);
        update_option('fpb_partial_block_days', max(0, absint(wp_unslash($_POST['fpb_partial_block_days'] ?? 0))));
        update_option('fpb_payment_fee_pct', min(100, max(0, (float) wp_unslash($_POST['fpb_payment_fee_pct'] ?? 0))));
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
        if (function_exists('snapbook_default_theme_colors')) {
            $theme_defaults = snapbook_default_theme_colors();
            update_option('fpb_theme_primary', sanitize_hex_color(wp_unslash($_POST['fpb_theme_primary'] ?? '')) ?: $theme_defaults['primary']);
            update_option('fpb_theme_accent', sanitize_hex_color(wp_unslash($_POST['fpb_theme_accent'] ?? '')) ?: $theme_defaults['accent']);
        }
        echo '<div class="notice notice-success is-dismissible inline"><p>Settings saved.</p></div>';
    }

    $step1_title = get_option('fpb_step1_title', 'Choose Your Date');
    $step1_sub = get_option('fpb_step1_sub', 'Select your preferred session date to begin your booking.');
    $admin_email = get_option('fpb_admin_email', get_option('admin_email'));
    $whatsapp = get_option('fpb_whatsapp', '');
    $success_title = get_option('fpb_success_title', 'Booking Requested!');
    $success_msg = get_option('fpb_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to");
    $whatsapp_btn = get_option('fpb_whatsapp_btn', 'Message us on WhatsApp');
    $confirm_title = get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook'));
    $confirm_msg = get_option('fpb_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'snapbook'));
    $confirm_pending_title = get_option('fpb_confirm_pending_title', __('Booking Received!', 'snapbook'));
    $confirm_pending_msg = get_option('fpb_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'snapbook'));
    $enable_partial_payment = (int) get_option('fpb_enable_partial_payment', 1);
    $partial_block_days = (int) get_option('fpb_partial_block_days', 0);
    $partial_option_label = get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook'));
    $payment_fee_pct = function_exists('snapbook_get_payment_fee_pct') ? snapbook_get_payment_fee_pct() : 0;
    $require_account_booking = (int) get_option('fpb_require_account_booking', 0);
    $enable_balance_reminders = (int) get_option('fpb_enable_balance_reminders', 0);
    $balance_reminder_hours = (int) get_option('fpb_balance_reminder_hours', 24);
    $balance_reminder_subject = get_option('fpb_balance_reminder_subject', __('Payment reminder for your booking', 'snapbook'));
    $balance_reminder_template = get_option('fpb_balance_reminder_template', "Hi {customer_name},\n\nThis is a reminder that {balance_amount} is pending for your booking on {session_date}.\n\nPay now: {pay_link}\n\nThank you.");

    snapbook_wrap_open('Settings', 'sb-settings', __('Configure checkout, payments, notifications, and form text.', 'snapbook'));
    echo '<form method="post" id="fpb-settings-form" class="fpb-settings-page">';
    wp_nonce_field('snapbook_settings', 'snapbook_settings_nonce');

    if (class_exists('WooCommerce') && function_exists('get_woocommerce_currency')) {
        $wc_code = get_woocommerce_currency();
        $wc_symbol = snapbook_get_currency_symbol();
        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__('SnapBook uses WooCommerce store currency automatically:', 'snapbook') . ' <strong>' . esc_html($wc_code . ' (' . $wc_symbol . ')') . '</strong>';
        echo '</p></div>';
    }

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Shortcode Reference', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Paste this shortcode into any page or post to display the booking form.', 'snapbook') . '</p>';
    echo '<div class="fpb-shortcode-copy-wrap">';
    echo '<code class="fpb-shortcode-code" id="fpb-sc-code">[snapbook]</code>';
    echo '<button type="button" class="button button-secondary" onclick="snapbookCopyShortcode()">' . esc_html__('Copy', 'snapbook') . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__('The form guides customers through a 4-step flow (Date, Package, Details, Payment) and sends them to WooCommerce checkout at the end.', 'snapbook') . '</p>';
    echo '</div>';

    // ── Appearance ─
    $theme_defaults = function_exists('snapbook_default_theme_colors') ? snapbook_default_theme_colors() : ['primary' => '#b8956a', 'accent' => '#3d6b78'];
    $theme_primary  = get_option('fpb_theme_primary', $theme_defaults['primary']);
    $theme_accent   = get_option('fpb_theme_accent', $theme_defaults['accent']);

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Appearance', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Match the booking form to your brand. Light and dark shades are derived automatically from each color.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="fpb-theme-primary">' . esc_html__('Primary color', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-theme-primary" type="color" name="fpb_theme_primary" value="' . esc_attr($theme_primary) . '" class="fpb-color-input">';
    echo '<span class="description fpb-color-desc">' . esc_html__('Buttons, active steps, selected dates and packages.', 'snapbook') . ' ' . esc_html__('Default:', 'snapbook') . ' ' . esc_html($theme_defaults['primary']) . '</span>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-theme-accent">' . esc_html__('Accent color', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-theme-accent" type="color" name="fpb_theme_accent" value="' . esc_attr($theme_accent) . '" class="fpb-color-input">';
    echo '<span class="description fpb-color-desc">' . esc_html__('Prices, totals, toggles and highlights.', 'snapbook') . ' ' . esc_html__('Default:', 'snapbook') . ' ' . esc_html($theme_defaults['accent']) . '</span>';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('General', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Contact settings used for booking notifications and the WhatsApp button.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="fpb-admin-email">' . esc_html__('Notification email', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-admin-email" class="regular-text" type="email" name="fpb_admin_email" value="' . esc_attr($admin_email) . '">';
    echo '<p class="description">' . esc_html__('Booking request emails are sent to this address.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-whatsapp">' . esc_html__('WhatsApp number', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-whatsapp" class="regular-text" type="text" name="fpb_whatsapp" value="' . esc_attr($whatsapp) . '" placeholder="23059355040">';
    echo '<p class="description">' . esc_html__('Digits only, with country code. Used for the WhatsApp button shown after a booking request.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-booking-page">' . esc_html__('Booking page', 'snapbook') . '</label></th><td>';
    wp_dropdown_pages([
        'id'                => 'fpb-booking-page',
        'name'              => 'fpb_booking_page_id',
        'selected'          => (int) get_option('fpb_booking_page_id', 0),
        'show_option_none'  => esc_html__('Auto-detect (page containing the booking form)', 'snapbook'),
        'option_none_value' => '0',
        'post_status'       => 'publish',
    ]);
    $detected_url = snapbook_get_booking_page_url();
    if ($detected_url !== '') {
        /* translators: %s: booking page URL */
        echo '<p class="description">' . sprintf(esc_html__('Package share links currently point to: %s', 'snapbook'), '<code>' . esc_html($detected_url) . '</code>') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__('No booking page detected yet — pick the page that contains the [snapbook] form.', 'snapbook') . '</p>';
    }
    echo '<p class="description">' . esc_html__('Used to build the "Copy Link" URLs on the Packages page.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Frontend Text', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Manage the text shown on the booking form and its success screen.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="fpb-step1-title">' . esc_html__('Step 1 heading', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-step1-title" class="regular-text" type="text" name="fpb_step1_title" value="' . esc_attr($step1_title) . '" placeholder="Choose Your Date">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-step1-sub">' . esc_html__('Step 1 subtitle', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-step1-sub" class="regular-text" type="text" name="fpb_step1_sub" value="' . esc_attr($step1_sub) . '" placeholder="Select your preferred session date to begin your booking.">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-success-title">' . esc_html__('Success heading', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-success-title" class="regular-text" type="text" name="fpb_success_title" value="' . esc_attr($success_title) . '" placeholder="Booking Requested!">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-success-msg">' . esc_html__('Success message', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-success-msg" class="large-text" type="text" name="fpb_success_msg" value="' . esc_attr($success_msg) . '">';
    echo '<p class="description">' . esc_html__("Shown on the success screen, followed by the customer's email address.", 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-whatsapp-btn">' . esc_html__('WhatsApp button text', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-whatsapp-btn" class="regular-text" type="text" name="fpb_whatsapp_btn" value="' . esc_attr($whatsapp_btn) . '" placeholder="Message us on WhatsApp">';
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<h3 class="fpb-ccf-heading">' . esc_html__('Order Confirmation Screen', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('Shown inside the booking form after the customer places their order. Use {email} to insert the customer\'s email address.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="fpb-confirm-title">' . esc_html__('Confirmed heading', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-confirm-title" class="regular-text" type="text" name="fpb_confirm_title" value="' . esc_attr($confirm_title) . '" placeholder="Booking Confirmed!">';
    echo '<p class="description">' . esc_html__('Used when the payment is completed immediately (e.g. bank transfer, cash on delivery).', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-confirm-msg">' . esc_html__('Confirmed message', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-confirm-msg" class="large-text" type="text" name="fpb_confirm_msg" value="' . esc_attr($confirm_msg) . '">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-confirm-pending-title">' . esc_html__('Payment pending heading', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-confirm-pending-title" class="regular-text" type="text" name="fpb_confirm_pending_title" value="' . esc_attr($confirm_pending_title) . '" placeholder="Booking Received!">';
    echo '<p class="description">' . esc_html__('Used when the booking is saved but the payment is not completed yet.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-confirm-pending-msg">' . esc_html__('Payment pending message', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-confirm-pending-msg" class="large-text" type="text" name="fpb_confirm_pending_msg" value="' . esc_attr($confirm_pending_msg) . '">';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Payment Controls', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Configure 50% booking payment and account requirements.', 'snapbook') . '</p>';
    echo '<input type="hidden" name="fpb_enable_partial_payment" value="0">';
    echo '<input type="hidden" name="fpb_require_account_booking" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('Partial Payment', 'snapbook') . '</th><td>';
    echo '<label><input type="checkbox" name="fpb_enable_partial_payment" value="1" ' . checked(1, $enable_partial_payment, false) . '> ' . esc_html__('Enable 50% payment for slot booking', 'snapbook') . '</label>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-partial-block-days">' . esc_html__('Disable partial within days', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-partial-block-days" class="small-text" type="number" min="0" step="1" name="fpb_partial_block_days" value="' . esc_attr($partial_block_days) . '">';
    echo '<p class="description">' . esc_html__('Example: set 5 to force full payment when event date is less than 5 days away.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-partial-option-label">' . esc_html__('Frontend 50% option text', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-partial-option-label" class="regular-text" type="text" name="fpb_partial_option_label" value="' . esc_attr($partial_option_label) . '">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-payment-fee-pct">' . esc_html__('PayPal fee', 'snapbook') . '</label> <span class="dashicons dashicons-editor-help" style="font-size:16px;width:16px;height:16px;color:#787c82;cursor:help;vertical-align:-2px;" title="' . esc_attr__('Percentage added on top of the booking total (package + add-ons). Customers see the breakdown on the payment step: Subtotal + PayPal fee = Total payable.', 'snapbook') . '"></span></th><td>';
    echo '<input id="fpb-payment-fee-pct" class="small-text" type="number" min="0" max="100" step="0.01" inputmode="decimal" name="fpb_payment_fee_pct" value="' . esc_attr(0 + $payment_fee_pct) . '" aria-describedby="fpb-payment-fee-desc"> <span aria-hidden="true">%</span>';
    echo '<p class="description" id="fpb-payment-fee-desc">' . esc_html__('Added on top of the booking total and shown on the payment step as: Subtotal + PayPal fee = Total payable.', 'snapbook') . '<br>' . esc_html__('Example: with a 3% fee, a 100.00 booking is charged 103.00. Set 0 to disable the fee.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Account Requirement', 'snapbook') . '</th><td>';
    echo '<label><input type="checkbox" name="fpb_require_account_booking" value="1" ' . checked(1, $require_account_booking, false) . '> ' . esc_html__('Require user account before booking checkout', 'snapbook') . '</label>';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    // ── Checkout form builder ─
    $checkout_mode = function_exists('snapbook_get_checkout_mode') ? snapbook_get_checkout_mode() : 'direct';
    $cf_catalog    = function_exists('snapbook_checkout_field_catalog') ? snapbook_checkout_field_catalog() : [];
    $cf_fields     = function_exists('snapbook_get_checkout_form_fields') ? snapbook_get_checkout_form_fields() : [];

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Checkout Form', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Choose how customers complete checkout, and which fields appear on the Details step of the booking form. The same field settings also apply to the WooCommerce checkout page.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="fpb-checkout-mode">' . esc_html__('Checkout mode', 'snapbook') . '</label></th><td>';
    echo '<select id="fpb-checkout-mode" name="fpb_checkout_mode">';
    echo '<option value="direct"' . selected('direct', $checkout_mode, false) . '>' . esc_html__('Multi-step form — details collected in the booking form, customer pays on the WooCommerce payment page', 'snapbook') . '</option>';
    echo '<option value="redirect"' . selected('redirect', $checkout_mode, false) . '>' . esc_html__('Classic — send customers to the WooCommerce checkout page to fill details and pay', 'snapbook') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Multi-step form is recommended: customers never leave the booking flow until payment.', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    if (! empty($cf_fields)) {
        echo '<table class="widefat striped fpb-cf-table"><thead><tr>';
        echo '<th>' . esc_html__('Field', 'snapbook') . '</th>';
        echo '<th class="fpb-cf-check-col">' . esc_html__('Show', 'snapbook') . '</th>';
        echo '<th class="fpb-cf-check-col">' . esc_html__('Required', 'snapbook') . '</th>';
        echo '<th>' . esc_html__('Label', 'snapbook') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($cf_fields as $key => $f) {
            $locked = ! empty($cf_catalog[$key]['locked']);
            echo '<tr>';
            echo '<td>' . esc_html($cf_catalog[$key]['label']) . ($locked ? ' <span class="description">(' . esc_html__('always on', 'snapbook') . ')</span>' : '') . '</td>';

            echo '<td class="fpb-cf-check-col">';
            if ($locked) {
                echo '<input type="hidden" name="fpb_cf_enabled[' . esc_attr($key) . ']" value="1"><input type="checkbox" checked disabled>';
            } else {
                echo '<input type="hidden" name="fpb_cf_enabled[' . esc_attr($key) . ']" value="0">';
                echo '<input type="checkbox" name="fpb_cf_enabled[' . esc_attr($key) . ']" value="1"' . checked(1, $f['enabled'], false) . '>';
            }
            echo '</td>';

            echo '<td class="fpb-cf-check-col">';
            if ($locked) {
                echo '<input type="hidden" name="fpb_cf_required[' . esc_attr($key) . ']" value="1"><input type="checkbox" checked disabled>';
            } else {
                echo '<input type="hidden" name="fpb_cf_required[' . esc_attr($key) . ']" value="0">';
                echo '<input type="checkbox" name="fpb_cf_required[' . esc_attr($key) . ']" value="1"' . checked(1, $f['required'], false) . '>';
            }
            echo '</td>';

            echo '<td><input type="text" class="regular-text fpb-cf-label-input" name="fpb_cf_label[' . esc_attr($key) . ']" value="' . esc_attr($f['label']) . '"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── Custom fields (admin can add / remove) ─
    $ccf_types  = function_exists('snapbook_custom_checkout_field_types') ? snapbook_custom_checkout_field_types() : [];
    $ccf_fields = function_exists('snapbook_get_custom_checkout_fields') ? snapbook_get_custom_checkout_fields() : [];

    $ccf_type_options = '';
    foreach ($ccf_types as $type_key => $type_label) {
        $ccf_type_options .= '<option value="' . esc_attr($type_key) . '">' . esc_html($type_label) . '</option>';
    }

    echo '<h3 class="fpb-ccf-heading">' . esc_html__('Custom Fields', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('Add your own fields to the checkout form. Removed fields are deleted when you save.', 'snapbook') . '</p>';
    echo '<table class="widefat striped fpb-cf-table" id="fpb-ccf-table"><thead><tr>';
    echo '<th>' . esc_html__('Label', 'snapbook') . '</th>';
    echo '<th class="fpb-ccf-type-col">' . esc_html__('Type', 'snapbook') . '</th>';
    echo '<th class="fpb-cf-check-col">' . esc_html__('Required', 'snapbook') . '</th>';
    echo '<th class="fpb-ccf-action-col">' . esc_html__('Action', 'snapbook') . '</th>';
    echo '</tr></thead><tbody id="fpb-ccf-rows">';
    foreach ($ccf_fields as $key => $f) {
        echo '<tr class="fpb-ccf-row">';
        echo '<td><input type="text" class="regular-text fpb-cf-label-input" name="fpb_ccf_label[' . esc_attr($key) . ']" value="' . esc_attr($f['label']) . '"></td>';
        echo '<td><select name="fpb_ccf_type[' . esc_attr($key) . ']">';
        foreach ($ccf_types as $type_key => $type_label) {
            echo '<option value="' . esc_attr($type_key) . '"' . selected($f['type'], $type_key, false) . '>' . esc_html($type_label) . '</option>';
        }
        echo '</select></td>';
        echo '<td class="fpb-cf-check-col">';
        echo '<input type="hidden" name="fpb_ccf_required[' . esc_attr($key) . ']" value="0">';
        echo '<input type="checkbox" name="fpb_ccf_required[' . esc_attr($key) . ']" value="1"' . checked(1, $f['required'], false) . '>';
        echo '</td>';
        echo '<td class="fpb-ccf-action-col"><button type="button" class="button button-link-delete fpb-ccf-remove">' . esc_html__('Remove', 'snapbook') . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="fpb-ccf-actions"><button type="button" class="button" id="fpb-ccf-add">+ ' . esc_html__('Add Field', 'snapbook') . '</button></p>';

    // Row template for the Add Field button (admin.js replaces __KEY__).
    echo '<script type="text/template" id="fpb-ccf-row-template">';
    echo '<tr class="fpb-ccf-row">';
    echo '<td><input type="text" class="regular-text fpb-cf-label-input" name="fpb_ccf_label[__KEY__]" value="" placeholder="' . esc_attr__('Field label', 'snapbook') . '"></td>';
    echo '<td><select name="fpb_ccf_type[__KEY__]">' . $ccf_type_options . '</select></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<td class="fpb-cf-check-col">';
    echo '<input type="hidden" name="fpb_ccf_required[__KEY__]" value="0">';
    echo '<input type="checkbox" name="fpb_ccf_required[__KEY__]" value="1">';
    echo '</td>';
    echo '<td class="fpb-ccf-action-col"><button type="button" class="button button-link-delete fpb-ccf-remove">' . esc_html__('Remove', 'snapbook') . '</button></td>';
    echo '</tr>';
    echo '</script>';
    echo '</div>';

    echo '<div class="card fpb-settings-card">';
    echo '<h2>' . esc_html__('Remaining Payment Reminder', 'snapbook') . '</h2>';
    echo '<p class="description">' . esc_html__('Send reminders for pending balance automatically and manually.', 'snapbook') . '</p>';
    echo '<input type="hidden" name="fpb_enable_balance_reminders" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('Automatic Reminder', 'snapbook') . '</th><td>';
    echo '<label><input type="checkbox" name="fpb_enable_balance_reminders" value="1" ' . checked(1, $enable_balance_reminders, false) . '> ' . esc_html__('Enable automatic reminder email for remaining balance', 'snapbook') . '</label>';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-balance-reminder-hours">' . esc_html__('Auto reminder delay (hours)', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-balance-reminder-hours" class="small-text" type="number" min="1" step="1" name="fpb_balance_reminder_hours" value="' . esc_attr($balance_reminder_hours) . '">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-balance-reminder-subject">' . esc_html__('Reminder email subject', 'snapbook') . '</label></th><td>';
    echo '<input id="fpb-balance-reminder-subject" class="regular-text" type="text" name="fpb_balance_reminder_subject" value="' . esc_attr($balance_reminder_subject) . '">';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="fpb-balance-reminder-template">' . esc_html__('Reminder email template', 'snapbook') . '</label></th><td>';
    echo '<textarea id="fpb-balance-reminder-template" class="large-text code" rows="7" name="fpb_balance_reminder_template">' . esc_textarea($balance_reminder_template) . '</textarea>';
    echo '<p class="description">' . esc_html__('Placeholders: {customer_name}, {balance_amount}, {session_date}, {pay_link}', 'snapbook') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<p class="submit">';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Save All Settings', 'snapbook') . '</button>';
    echo '</p>';
    echo '<div id="fpb-settings-msg" class="fpb-form-msg" aria-live="polite"></div>';
    echo '</form>';

    // Inline JS for copy shortcode button
    echo '<script>
function snapbookCopyShortcode(){
    var code = document.getElementById("fpb-sc-code");
    if (!code) return;
    navigator.clipboard.writeText(code.innerText).then(function(){
        var btn = code.nextElementSibling;
        var orig = btn.innerText;
        btn.innerText = "Copied!";
        setTimeout(function(){ btn.innerText = orig; }, 2000);
    });
}
</script>';

    snapbook_wrap_close();
}