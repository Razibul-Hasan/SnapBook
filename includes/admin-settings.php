<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   SETTINGS SCREENS
   SnapBook → Settings (plugin-wide, manage_options) and
   SnapBook → Booking Form (the form's steps and sidebar cards).

   Settings is ONE form split into sections by a side menu: admin.js
   shows one section at a time (without JS they all show) and its
   search box filters rows across every section. The whole form is
   still saved in one go by snapbook_admin_save_settings() (ajax.php),
   so a field can sit in any section as long as its name is unchanged.
═══════════════════════════════════════════════════════════════ */

/**
 * Sections of the Settings screen, in menu order.
 */
function snapbook_settings_sections()
{
    return [
        'general'      => [
            'label'  => __('General', 'snapbook'),
            'hint'   => __('Setup, contact, colors', 'snapbook'),
            'icon'   => 'dashicons-admin-home',
            'intro'  => __('Get the booking form live, tell SnapBook how to reach you, and match the form to your brand.', 'snapbook'),
            'render' => 'snapbook_render_settings_general',
        ],
        'payments'     => [
            'label'  => __('Payments', 'snapbook'),
            'hint'   => __('Deposit, fees, promo codes', 'snapbook'),
            'icon'   => 'dashicons-money-alt',
            'intro'  => __('How much customers pay to book, when the rest is due, and any fee or promo codes.', 'snapbook'),
            'render' => 'snapbook_render_settings_payments',
        ],
        'availability' => [
            'label'  => __('Availability', 'snapbook'),
            'hint'   => __('Bookable days and times', 'snapbook'),
            'icon'   => 'dashicons-calendar-alt',
            'intro'  => __('Which days and times customers can book, and how far ahead.', 'snapbook'),
            'render' => 'snapbook_render_settings_availability',
        ],
        'checkout'     => [
            'label'  => __('Checkout', 'snapbook'),
            'hint'   => __('Form fields and messages', 'snapbook'),
            'icon'   => 'dashicons-cart',
            'intro'  => __('The details customers fill in, and what they see once their booking is placed.', 'snapbook'),
            'render' => 'snapbook_render_settings_checkout',
        ],
        'customers'    => [
            'label'  => __('Customers', 'snapbook'),
            'hint'   => __('Accounts and requests', 'snapbook'),
            'icon'   => 'dashicons-admin-users',
            'intro'  => __('What customers can see and do with their bookings in their account.', 'snapbook'),
            'render' => 'snapbook_render_settings_customers',
        ],
        'emails'       => [
            'label'  => __('Emails', 'snapbook'),
            'hint'   => __('Confirmation, alerts, reminders', 'snapbook'),
            'icon'   => 'dashicons-email-alt',
            'intro'  => __('The confirmation your customer gets, the alert you get for each new booking, and automatic balance reminders.', 'snapbook'),
            'render' => 'snapbook_render_settings_emails',
        ],
        'calendar'     => [
            'label'  => __('Google Calendar', 'snapbook'),
            'hint'   => __('Sync bookings', 'snapbook'),
            'icon'   => 'dashicons-google',
            'intro'  => __('Put every paid booking in your Google Calendar automatically.', 'snapbook'),
            'render' => 'snapbook_render_gcal_card',
        ],
        'advanced'     => [
            'label'  => __('Advanced', 'snapbook'),
            'hint'   => __('Plugin data', 'snapbook'),
            'icon'   => 'dashicons-admin-tools',
            'intro'  => __('Options you will rarely need.', 'snapbook'),
            'render' => 'snapbook_render_plugin_data_card',
        ],
    ];
}

/* ─── Shared building blocks ─────────────────────────────────── */

/**
 * Opens a settings card. Cards sit under a section heading (h2), so
 * their own title is an h3.
 */
function snapbook_settings_card_open($title, $desc = '', $id = '', $extra_class = '')
{
    echo '<div class="card fpb-settings-card' . ($extra_class !== '' ? ' ' . esc_attr($extra_class) : '') . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
    echo '<h3>' . esc_html($title) . '</h3>';
    if ($desc !== '') {
        echo '<p class="description">' . esc_html($desc) . '</p>';
    }
}

function snapbook_settings_card_close()
{
    echo '</div>';
}

/**
 * Opens a settings-table row: the label (tied to the field $for when
 * given), its help tip, then the value cell.
 *
 * $requires names a control this row depends on: while that checkbox is
 * off (or that number is 0) the row is dimmed and $requires_note shows
 * under the label. Prefix the name with "!" to invert it (dimmed while
 * that field has a value). admin.js does the dimming; the fields stay
 * editable either way, so their values still save.
 */
function snapbook_setting_row_open($label, $tip = '', $for = '', $requires = '', $requires_note = '')
{
    echo '<tr' . ($requires !== '' ? ' data-fpb-requires="' . esc_attr($requires) . '"' : '') . '>';
    echo '<th scope="row"><div class="fpb-th">';
    if ($for !== '') {
        echo '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>';
    } else {
        echo '<span class="fpb-th-label">' . esc_html($label) . '</span>';
    }
    if ($tip !== '') {
        echo snapbook_help_tip($tip); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
    }
    echo '</div>';
    if ($requires_note !== '') {
        echo '<small class="fpb-parked-note">' . esc_html($requires_note) . '</small>';
    }
    echo '</th><td>';
}

function snapbook_setting_row_close()
{
    echo '</td></tr>';
}

/**
 * Sticky bar with the save button. Shows "Unsaved changes" while the
 * form has edits (admin.js) and the save result in $msg_id.
 */
function snapbook_render_savebar($button_label, $msg_id = '', $note = '')
{
    echo '<div class="fpb-savebar">';
    echo '<span class="fpb-savebar-dirty"><span class="fpb-savebar-dot" aria-hidden="true"></span>' . esc_html__('Unsaved changes', 'snapbook') . '</span>';
    if ($note !== '') {
        echo '<span class="fpb-savebar-note">' . esc_html($note) . '</span>';
    }
    if ($msg_id !== '') {
        echo '<div id="' . esc_attr($msg_id) . '" class="fpb-form-msg" aria-live="polite"></div>';
    }
    echo '<button type="submit" class="button button-primary fpb-savebar-btn">' . esc_html($button_label) . '</button>';
    echo '</div>';
}

/**
 * One "number" input with text around it, e.g. "Send it [3] days before".
 */
function snapbook_settings_inline_number($name, $value, $min, $max, $before = '', $after = '', $id = '')
{
    $id  = $id !== '' ? $id : str_replace('_', '-', $name);
    $out = '<label class="fpb-inline-num" for="' . esc_attr($id) . '">';
    if ($before !== '') {
        $out .= '<span>' . esc_html($before) . '</span> ';
    }
    $out .= '<input id="' . esc_attr($id) . '" class="small-text" type="number" name="' . esc_attr($name) . '" min="' . (int) $min . '" max="' . (int) $max . '" step="1" value="' . esc_attr($value) . '">';
    if ($after !== '') {
        $out .= ' <span>' . esc_html($after) . '</span>';
    }
    $out .= '</label>';

    return $out;
}

/* ─── Setup checklist ───────────────────────────────────────── */

/**
 * What still has to be done before the site can take bookings. Shown in
 * Settings → General and (while unfinished) as a nudge on All Bookings.
 * Each item: label, hint, done, url, action, optional.
 */
function snapbook_setup_checklist()
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }

    global $wpdb;
    $sessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fpb_sessions WHERE active=1"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $packages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fpb_packages WHERE active=1"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wc       = class_exists('WooCommerce');
    $page_url = function_exists('snapbook_get_booking_page_url') ? snapbook_get_booking_page_url() : '';

    $items = [];

    $items['woocommerce'] = [
        'label'  => __('WooCommerce is active', 'snapbook'),
        'hint'   => $wc
            ? __('Deposits and payments go through WooCommerce.', 'snapbook')
            : __('Needed to take deposits and payments. Without it, the booking form only sends you enquiry emails.', 'snapbook'),
        'done'   => $wc,
        'url'    => admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'),
        'action' => __('Get WooCommerce', 'snapbook'),
    ];

    $items['sessions'] = [
        'label'  => __('Add a session type', 'snapbook'),
        'hint'   => $sessions > 0
            /* translators: %d: number of active session types */
            ? sprintf(_n('%d active session type.', '%d active session types.', $sessions, 'snapbook'), $sessions)
            : __('For example Wedding, Portrait or Family. Each one is a tab on the booking form.', 'snapbook'),
        'done'   => $sessions > 0,
        'url'    => admin_url('admin.php?page=sb-sessions'),
        'action' => __('Add session type', 'snapbook'),
    ];

    $items['packages'] = [
        'label'  => __('Add a package', 'snapbook'),
        'hint'   => $packages > 0
            /* translators: %d: number of active packages */
            ? sprintf(_n('%d active package.', '%d active packages.', $packages, 'snapbook'), $packages)
            : __('What customers actually book and pay for, with a price.', 'snapbook'),
        'done'   => $packages > 0,
        'url'    => admin_url('admin.php?page=sb-packages'),
        'action' => __('Add package', 'snapbook'),
    ];

    $items['page'] = [
        'label'  => __('Put the booking form on a page', 'snapbook'),
        'hint'   => $page_url !== ''
            /* translators: %s: booking page URL */
            ? sprintf(__('Live at %s', 'snapbook'), $page_url)
            : __('Add the [snapbook] shortcode to any page.', 'snapbook'),
        'done'   => $page_url !== '',
        'url'    => admin_url('post-new.php?post_type=page'),
        'action' => __('Create a page', 'snapbook'),
    ];

    if ($wc) {
        $gateways = 0;
        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            foreach ((array) WC()->payment_gateways()->payment_gateways() as $gateway) {
                if (is_object($gateway) && isset($gateway->enabled) && 'yes' === $gateway->enabled) {
                    $gateways++;
                }
            }
        }
        $items['gateways'] = [
            'label'  => __('Turn on a payment method', 'snapbook'),
            'hint'   => $gateways > 0
                /* translators: %d: number of payment methods */
                ? sprintf(_n('%d payment method is on.', '%d payment methods are on.', $gateways, 'snapbook'), $gateways)
                : __('Card, PayPal, bank transfer… switch one on in WooCommerce → Settings → Payments.', 'snapbook'),
            'done'   => $gateways > 0,
            'url'    => admin_url('admin.php?page=wc-settings&tab=checkout'),
            'action' => __('Payment methods', 'snapbook'),
        ];
    }

    $items['gcal'] = [
        'label'    => __('Connect Google Calendar', 'snapbook'),
        'hint'     => __('Adds every paid booking to your calendar.', 'snapbook'),
        'done'     => function_exists('snapbook_gcal_is_connected') && snapbook_gcal_is_connected(),
        'url'      => admin_url('admin.php?page=sb-settings#calendar'),
        'action'   => __('Set up', 'snapbook'),
        'optional' => true,
    ];

    return $items;
}

/**
 * Required checklist steps still to do.
 */
function snapbook_setup_steps_left()
{
    $left = 0;
    foreach (snapbook_setup_checklist() as $item) {
        if (empty($item['optional']) && ! $item['done']) {
            $left++;
        }
    }
    return $left;
}

function snapbook_render_setup_checklist_card()
{
    $items = snapbook_setup_checklist();
    $total = 0;
    $done  = 0;
    foreach ($items as $item) {
        if (empty($item['optional'])) {
            $total++;
            $done += $item['done'] ? 1 : 0;
        }
    }
    $complete = $done === $total;

    echo '<div class="card fpb-settings-card fpb-setup-card' . ($complete ? ' is-complete' : '') . '" id="fpb-setup">';
    echo '<h3>' . esc_html($complete ? __('Setup complete', 'snapbook') : __('Setup checklist', 'snapbook')) . '</h3>';
    echo '<p class="description">' . esc_html($complete ? __('Everything needed to take bookings is in place.', 'snapbook') : __('Finish these steps and you are ready to take bookings.', 'snapbook')) . '</p>';

    $pct = $total > 0 ? (int) round($done / $total * 100) : 100;
    echo '<div class="fpb-setup-progress">';
    echo '<div class="fpb-setup-bar" role="progressbar" aria-label="' . esc_attr__('Setup progress', 'snapbook') . '" aria-valuemin="0" aria-valuemax="' . (int) $total . '" aria-valuenow="' . (int) $done . '"><span style="width:' . (int) $pct . '%"></span></div>';
    /* translators: 1: steps done, 2: total steps */
    echo '<span class="fpb-setup-count">' . esc_html(sprintf(__('%1$d of %2$d done', 'snapbook'), $done, $total)) . '</span>';
    echo '</div>';

    echo '<ul class="fpb-setup-list">';
    foreach ($items as $item) {
        $cls = $item['done'] ? ' is-done' : '';
        $cls .= empty($item['optional']) ? '' : ' is-optional';
        echo '<li class="fpb-setup-item' . esc_attr($cls) . '">';
        echo '<span class="fpb-setup-icon dashicons ' . ($item['done'] ? 'dashicons-yes-alt' : 'dashicons-marker') . '" aria-hidden="true"></span>';
        echo '<span class="fpb-setup-text"><span class="fpb-setup-title"><strong>' . esc_html($item['label']) . '</strong>';
        if (! empty($item['optional'])) {
            echo ' <span class="fpb-setup-tag">' . esc_html__('Optional', 'snapbook') . '</span>';
        }
        echo '<span class="screen-reader-text"> — ' . esc_html($item['done'] ? __('done', 'snapbook') : __('not done yet', 'snapbook')) . '</span></span>';
        echo '<small>' . esc_html($item['hint']) . '</small></span>';
        if (! $item['done'] && $item['url'] !== '') {
            echo '<a class="button button-small fpb-setup-action" href="' . esc_url($item['url']) . '">' . esc_html($item['action']) . '</a>';
        }
        echo '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

/**
 * All Bookings: a one-line reminder while required setup steps are left.
 */
function snapbook_render_setup_nudge()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $left = snapbook_setup_steps_left();
    if ($left < 1) {
        return;
    }
    echo '<div class="notice notice-info inline fpb-setup-nudge"><p><span class="dashicons dashicons-flag" aria-hidden="true"></span> ';
    echo '<strong>' . esc_html__('Finish setting up SnapBook', 'snapbook') . '</strong> — ';
    /* translators: %d: number of setup steps left */
    echo esc_html(sprintf(_n('%d step left before you can take bookings.', '%d steps left before you can take bookings.', $left, 'snapbook'), $left)) . ' ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=sb-settings#general')) . '">' . esc_html__('Open the setup checklist', 'snapbook') . '</a>';
    echo '</p></div>';
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SETTINGS
═══════════════════════════════════════════════════════════════ */
function snapbook_page_settings()
{
    if (! current_user_can('manage_options')) return;

    // No-JS fallback: admin.js normally saves this form over AJAX.
    $saved = false;
    if (isset($_POST['snapbook_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['snapbook_settings_nonce'])), 'snapbook_settings')) {
        foreach (['fpb_partial_option_label', 'fpb_whatsapp', 'fpb_success_title', 'fpb_success_msg', 'fpb_whatsapp_btn', 'fpb_confirm_title', 'fpb_confirm_msg', 'fpb_confirm_pending_title', 'fpb_confirm_pending_msg'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        update_option('fpb_admin_email', sanitize_email(wp_unslash($_POST['fpb_admin_email'] ?? '')) ?: get_option('admin_email'));
        update_option('fpb_booking_page_id', absint(wp_unslash($_POST['fpb_booking_page_id'] ?? 0)));
        if (function_exists('snapbook_sanitize_custom_checkout_fields')) {
            update_option('fpb_checkout_custom_fields', snapbook_sanitize_custom_checkout_fields($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
        // Each field is only written when the settings screen actually rendered
        // it: the sync toggle exists only once connected, and the key fields are
        // hidden while the credentials come from wp-config constants.
        if (isset($_POST['fpb_gcal_enabled'])) {
            update_option('fpb_gcal_enabled', absint(wp_unslash($_POST['fpb_gcal_enabled'])) === 1 ? 1 : 0);
        }
        if (isset($_POST['fpb_gcal_client_id'])) {
            update_option('fpb_gcal_client_id', sanitize_text_field(wp_unslash($_POST['fpb_gcal_client_id'])));
        }
        if (isset($_POST['fpb_gcal_client_secret'])) {
            update_option('fpb_gcal_client_secret', sanitize_text_field(wp_unslash($_POST['fpb_gcal_client_secret'])));
        }
        if (function_exists('snapbook_default_theme_colors')) {
            $theme_defaults = snapbook_default_theme_colors();
            update_option('fpb_theme_primary', sanitize_hex_color(wp_unslash($_POST['fpb_theme_primary'] ?? '')) ?: $theme_defaults['primary']);
            update_option('fpb_theme_accent', sanitize_hex_color(wp_unslash($_POST['fpb_theme_accent'] ?? '')) ?: $theme_defaults['accent']);
        }
        $saved = true;
    }

    $sections = snapbook_settings_sections();

    // Menu badges: what is left to set up, and whether Google is connected.
    $left = snapbook_setup_steps_left();
    if ($left > 0) {
        /* translators: %d: setup steps left */
        $sections['general']['badge'] = ['tone' => 'warn', 'text' => sprintf(_n('%d to do', '%d to do', $left, 'snapbook'), $left)];
    }
    $gcal_on = function_exists('snapbook_gcal_is_connected') && snapbook_gcal_is_connected();
    $sections['calendar']['badge'] = $gcal_on
        ? ['tone' => 'ok', 'text' => __('On', 'snapbook')]
        : ['tone' => 'off', 'text' => __('Off', 'snapbook')];

    // Coming back from the Google connect flow lands on its section.
    $current = isset($_GET['sb_gcal']) ? 'calendar' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

    snapbook_wrap_open(__('Settings', 'snapbook'), 'sb-settings', __('Choose a section on the left. Hover or tap the ? beside any setting to see what it does.', 'snapbook'));

    if ($saved) {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Settings saved.', 'snapbook') . '</p></div>';
    }
    snapbook_render_gcal_return_notice();

    echo '<form method="post" id="fpb-settings-form" class="fpb-settings-page fpb-set-layout">';
    wp_nonce_field('snapbook_settings', 'snapbook_settings_nonce');

    // ── Side menu ──
    echo '<nav class="fpb-set-nav" aria-label="' . esc_attr__('Settings sections', 'snapbook') . '">';
    echo '<div class="fpb-set-search"><span class="dashicons dashicons-search" aria-hidden="true"></span>';
    echo '<input type="search" id="fpb-set-search" placeholder="' . esc_attr__('Find a setting…', 'snapbook') . '" aria-label="' . esc_attr__('Find a setting', 'snapbook') . '" autocomplete="off" spellcheck="false"></div>';
    echo '<ul class="fpb-set-nav-list">';
    foreach ($sections as $key => $s) {
        $is = $key === $current;
        echo '<li><a class="fpb-set-nav-link' . ($is ? ' is-active' : '') . '" href="#' . esc_attr($key) . '" data-section="' . esc_attr($key) . '"' . ($is ? ' aria-current="true"' : '') . '>';
        echo '<span class="dashicons ' . esc_attr($s['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="fpb-set-nav-text"><span class="fpb-set-nav-label">' . esc_html($s['label']) . '</span><span class="fpb-set-nav-hint">' . esc_html($s['hint']) . '</span></span>';
        if (! empty($s['badge'])) {
            echo '<span class="fpb-set-nav-badge is-' . esc_attr($s['badge']['tone']) . '">' . esc_html($s['badge']['text']) . '</span>';
        }
        echo '</a></li>';
    }
    echo '</ul>';
    echo '</nav>';

    // ── Sections ──
    echo '<div class="fpb-set-main">';
    foreach ($sections as $key => $s) {
        echo '<section class="fpb-set-panel' . ($key === $current ? ' is-active' : '') . '" id="fpb-section-' . esc_attr($key) . '" data-section="' . esc_attr($key) . '" aria-labelledby="fpb-section-' . esc_attr($key) . '-title">';
        echo '<header class="fpb-set-panel-head"><span class="dashicons ' . esc_attr($s['icon']) . '" aria-hidden="true"></span><div>';
        echo '<h2 id="fpb-section-' . esc_attr($key) . '-title">' . esc_html($s['label']) . '</h2>';
        echo '<p>' . esc_html($s['intro']) . '</p>';
        echo '</div></header>';
        call_user_func($s['render']);
        echo '</section>';
    }
    echo '<div class="fpb-set-noresults" id="fpb-set-noresults" hidden><span class="dashicons dashicons-search" aria-hidden="true"></span>';
    echo '<p>' . esc_html__('No settings match your search. Try a shorter or different word.', 'snapbook') . '</p></div>';
    snapbook_render_savebar(__('Save All Settings', 'snapbook'), 'fpb-settings-msg', __('Saves every section at once.', 'snapbook'));
    echo '</div>';

    echo '</form>';

    snapbook_wrap_close();
}

/**
 * Result of the Google connect / disconnect round trip (?sb_gcal=).
 */
function snapbook_render_gcal_return_notice()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
    $gcal_notice = isset($_GET['sb_gcal']) ? sanitize_key(wp_unslash($_GET['sb_gcal'])) : '';
    if ($gcal_notice === 'connected') {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Google Calendar connected. New bookings will be added automatically.', 'snapbook') . '</p></div>';
    } elseif ($gcal_notice === 'disconnected') {
        echo '<div class="notice notice-info is-dismissible inline"><p>' . esc_html__('Google Calendar disconnected.', 'snapbook') . '</p></div>';
    } elseif ($gcal_notice === 'error') {
        $gcal_reason = isset($_GET['reason']) ? sanitize_key(wp_unslash($_GET['reason'])) : '';
        $gcal_reasons = [
            'denied'        => __('Connection cancelled on the Google consent screen.', 'snapbook'),
            'access_denied' => __('Google blocked the sign-in (Error 403: access_denied). Your Google app is still in “Testing”, so only approved testers can use it. In Google Cloud Console open Google Auth Platform → Audience and either click Publish app, or add this Google account under Test users. Then connect again.', 'snapbook'),
            'state'         => __('The connection could not be verified. Please try connecting again.', 'snapbook'),
            'network'       => __('Could not reach Google. Please try again in a moment.', 'snapbook'),
            'exchange'      => __('Google rejected the connection — check the redirect URI is registered and the Client Secret is correct, then try again.', 'snapbook'),
            'nocreds'       => __('Enter your Google Client ID and Client Secret below and click Save All Settings first, then Connect.', 'snapbook'),
        ];
        $gcal_reason_msg = $gcal_reasons[$gcal_reason] ?? __('Google Calendar connection failed. Please try again.', 'snapbook');
        echo '<div class="notice notice-error is-dismissible inline"><p>' . esc_html($gcal_reason_msg) . '</p></div>';
    }
    // phpcs:enable
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — GENERAL
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_general()
{
    snapbook_render_setup_checklist_card();

    // ── Booking form ─
    snapbook_settings_card_open(__('Booking form', 'snapbook'), __('Where the booking form appears on your site.', 'snapbook'), 'fpb-booking-form-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Shortcode', 'snapbook'),
        __('Paste this into any page or post (in the block editor, use a Shortcode block) to show the booking form there. Optional extras: package="your-package-slug" opens the form with that package already picked, and primary="#hex" / accent="#hex" change the colors of that one form.', 'snapbook')
    );
    echo '<div class="fpb-shortcode-copy-wrap">';
    echo '<code class="fpb-shortcode-code" id="fpb-sc-code">[snapbook]</code>';
    echo '<button type="button" class="button button-secondary fpb-copy-btn" data-copy="[snapbook]">' . esc_html__('Copy', 'snapbook') . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__('Customers pick a package, enter their details and pay, choosing their date from the calendar beside the form.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Booking page', 'snapbook'),
        __('The page your booking form is on. SnapBook uses it for package share links (Packages → Copy Link) and the “Book a session” button in My Account. Leave it on Auto-detect unless the form is on more than one page.', 'snapbook'),
        'fpb-booking-page'
    );
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
        echo '<p class="description">' . sprintf(esc_html__('Share links currently point to: %s', 'snapbook'), '<code>' . esc_html($detected_url) . '</code>') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__('No booking page found yet — add the [snapbook] shortcode to a page, then pick it here.', 'snapbook') . '</p>';
    }
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();

    // ── Contact ─
    $admin_email  = get_option('fpb_admin_email', get_option('admin_email'));
    $whatsapp     = get_option('fpb_whatsapp', '');
    $whatsapp_btn = get_option('fpb_whatsapp_btn', 'Message us on WhatsApp');

    snapbook_settings_card_open(__('Contact', 'snapbook'), __('How SnapBook reaches you, and how customers can reach you.', 'snapbook'), 'fpb-contact-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Notification email', 'snapbook'),
        __('Your studio address for SnapBook alerts: booking enquiries (when WooCommerce is off), customers asking to reschedule or cancel, and double-booking warnings. Customers who reply to SnapBook emails reach this address too. The WooCommerce “new order” alert has its own setting under Emails.', 'snapbook'),
        'fpb-admin-email'
    );
    echo '<input id="fpb-admin-email" class="regular-text" type="email" name="fpb_admin_email" value="' . esc_attr($admin_email) . '">';
    echo '<p class="description">' . esc_html__('Leave empty to use the site admin email.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('WhatsApp number', 'snapbook'),
        __('Your WhatsApp number in international format, digits only — e.g. 447911123456 for a UK mobile (44 is the country code). When set, customers see a WhatsApp button after booking and on the order thank-you page. Leave empty to hide the button.', 'snapbook'),
        'fpb-whatsapp'
    );
    echo '<input id="fpb-whatsapp" class="regular-text" type="text" name="fpb_whatsapp" value="' . esc_attr($whatsapp) . '" placeholder="23059355040" inputmode="numeric">';
    echo '<p class="description">' . esc_html__('Digits only, starting with the country code.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('WhatsApp button text', 'snapbook'),
        __('The wording on the WhatsApp button.', 'snapbook'),
        'fpb-whatsapp-btn',
        'fpb_whatsapp',
        __('Used only when a WhatsApp number is set.', 'snapbook')
    );
    echo '<input id="fpb-whatsapp-btn" class="regular-text" type="text" name="fpb_whatsapp_btn" value="' . esc_attr($whatsapp_btn) . '" placeholder="Message us on WhatsApp">';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();

    // ── Appearance ─
    $theme_defaults = function_exists('snapbook_default_theme_colors') ? snapbook_default_theme_colors() : ['primary' => '#b8956a', 'accent' => '#3d6b78'];
    $theme_primary  = get_option('fpb_theme_primary', $theme_defaults['primary']);
    $theme_accent   = get_option('fpb_theme_accent', $theme_defaults['accent']);

    snapbook_settings_card_open(__('Appearance', 'snapbook'), __('Match the booking form and emails to your brand. Lighter and darker shades are made from each color automatically.', 'snapbook'), 'fpb-appearance-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Primary color', 'snapbook'),
        __('Your main brand color: buttons, the current step, selected dates and packages on the booking form, and the accents in SnapBook emails.', 'snapbook'),
        'fpb-theme-primary'
    );
    echo '<input id="fpb-theme-primary" type="color" name="fpb_theme_primary" value="' . esc_attr($theme_primary) . '" class="fpb-color-input">';
    echo '<span class="description fpb-color-desc">' . esc_html__('Default:', 'snapbook') . ' ' . esc_html($theme_defaults['primary']) . '</span>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Accent color', 'snapbook'),
        __('Your second brand color: prices, totals, switches and highlights.', 'snapbook'),
        'fpb-theme-accent'
    );
    echo '<input id="fpb-theme-accent" type="color" name="fpb_theme_accent" value="' . esc_attr($theme_accent) . '" class="fpb-color-input">';
    echo '<span class="description fpb-color-desc">' . esc_html__('Default:', 'snapbook') . ' ' . esc_html($theme_defaults['accent']) . '</span>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — PAYMENTS
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_payments()
{
    if (class_exists('WooCommerce') && function_exists('get_woocommerce_currency')) {
        echo '<div class="notice notice-info inline"><p>';
        /* translators: %s: currency code and symbol, e.g. "USD ($)" */
        echo esc_html(sprintf(__('Prices use your WooCommerce store currency: %s. Change it under WooCommerce → Settings → General.', 'snapbook'), get_woocommerce_currency() . ' (' . html_entity_decode((string) snapbook_get_currency_symbol(), ENT_QUOTES, 'UTF-8') . ')'));
        echo '</p></div>';
    }

    snapbook_render_deposit_card();
    snapbook_render_payment_fee_card();
    snapbook_render_offline_payments_card();
    snapbook_render_promo_codes_card();
}

/**
 * Deposit on/off and %, when full payment is forced, the deposit option
 * text and the balance deadline.
 */
function snapbook_render_deposit_card()
{
    $enable_partial = (int) get_option('fpb_enable_partial_payment', 1);
    $block_days     = (int) get_option('fpb_partial_block_days', 0);
    // The saved text, or the default (which carries {deposit_pct}); the old
    // "50%" default text is upgraded by the helper too.
    $option_label   = function_exists('snapbook_partial_option_label') ? snapbook_partial_option_label() : (string) get_option('fpb_partial_option_label', '');
    $deposit_pct    = (int) snapbook_opt('fpb_deposit_pct');
    $due_enable     = (int) snapbook_opt('fpb_balance_due_enable') === 1;
    $due_days       = (int) snapbook_opt('fpb_balance_due_days');
    $needs_deposit  = __('Used only while the deposit option is on.', 'snapbook');

    snapbook_settings_card_open(__('Deposit', 'snapbook'), __('Let customers secure their date with part of the price and pay the rest later.', 'snapbook'), 'fpb-deposit-card');
    echo '<input type="hidden" name="fpb_enable_partial_payment" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Deposit', 'snapbook'),
        __('On: customers choose between paying a deposit now (the rest later) or paying everything up front. Off: every booking is paid in full at checkout.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_enable_partial_payment', __('Let customers pay a deposit now and the rest later', 'snapbook'), $enable_partial === 1, __('When off, every booking is paid in full up front.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Deposit amount', 'snapbook'),
        __('The part of the booking total paid up front. Example: 30 means a 1,000 booking takes 300 now and leaves 700 to pay later. A package can have its own deposit (Packages → Deposit %).', 'snapbook'),
        'fpb-deposit-pct',
        'fpb_enable_partial_payment',
        $needs_deposit
    );
    echo snapbook_settings_inline_number('fpb_deposit_pct', $deposit_pct, 1, 99, '', __('% of the booking total', 'snapbook'), 'fpb-deposit-pct'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('Between 1 and 99.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Full payment close to the date', 'snapbook'),
        __('Hides the deposit option for last-minute sessions, so they are paid in full. Example: with 7, a session less than 7 days away must be paid in full. 0 = always offer the deposit.', 'snapbook'),
        'fpb-partial-block-days',
        'fpb_enable_partial_payment',
        $needs_deposit
    );
    echo snapbook_settings_inline_number('fpb_partial_block_days', $block_days, 0, 365, __('No deposit option when the session is fewer than', 'snapbook'), __('days away', 'snapbook'), 'fpb-partial-block-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = always offer the deposit.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Deposit option text', 'snapbook'),
        __('The wording of the deposit choice on the booking form. {deposit_pct} is replaced with the deposit percentage.', 'snapbook'),
        'fpb-partial-option-label',
        'fpb_enable_partial_payment',
        $needs_deposit
    );
    echo '<input id="fpb-partial-option-label" class="regular-text" type="text" name="fpb_partial_option_label" value="' . esc_attr($option_label) . '">';
    echo '<p class="description">' . wp_kses(
        __('e.g. “Pay a <code>{deposit_pct}</code>% deposit to book”.', 'snapbook'),
        ['code' => []]
    ) . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Balance deadline', 'snapbook'),
        __('Tells customers when the rest is due, e.g. “Balance due 14 days before your shoot”. It shows on the booking form, in emails ({balance_due_date}) and in My Account. Nobody is charged automatically — balance reminders (Emails section) do the chasing.', 'snapbook'),
        '',
        'fpb_enable_partial_payment',
        $needs_deposit
    );
    echo '<input type="hidden" name="fpb_balance_due_enable" value="0">';
    echo snapbook_toggle_field('fpb_balance_due_enable', __('Tell customers when the rest is due', 'snapbook'), $due_enable, __('Shown on the booking form, in emails and in My Account.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo '<div class="fpb-set-sub" data-fpb-requires="fpb_balance_due_enable">';
    echo snapbook_settings_inline_number('fpb_balance_due_days', $due_days, 0, 365, __('Due', 'snapbook'), __('days before the shoot', 'snapbook'), 'fpb-balance-due-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = on the day of the shoot.', 'snapbook') . '</p>';
    echo '</div>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/**
 * Payment fee: %, its name, and the payment methods that don't pay it.
 */
function snapbook_render_payment_fee_card()
{
    $fee_pct   = function_exists('snapbook_get_payment_fee_pct') ? snapbook_get_payment_fee_pct() : 0;
    $fee_label = (string) snapbook_opt('fpb_payment_fee_label');
    $exempt    = (array) snapbook_opt('fpb_payment_fee_exempt_gateways');
    $needs_fee = __('Used only when the fee is above 0.', 'snapbook');

    snapbook_settings_card_open(__('Payment fee', 'snapbook'), __('Pass card or PayPal processing costs on to the customer.', 'snapbook'), 'fpb-fee-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Payment fee', 'snapbook'),
        __('An extra percentage added on top to cover card or PayPal charges. Example: with 3, a 100.00 booking costs 103.00. Customers see it as its own line before paying. 0 = no fee.', 'snapbook'),
        'fpb-payment-fee-pct'
    );
    echo '<label class="fpb-inline-num" for="fpb-payment-fee-pct"><input id="fpb-payment-fee-pct" class="small-text" type="number" min="0" max="100" step="0.01" inputmode="decimal" name="fpb_payment_fee_pct" value="' . esc_attr(0 + $fee_pct) . '"> <span>' . esc_html__('% added on top of the booking total', 'snapbook') . '</span></label>';
    echo '<p class="description">' . esc_html__('0 turns the fee off.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Fee name', 'snapbook'),
        __('What customers see next to the fee, e.g. “Card processing fee”.', 'snapbook'),
        'fpb-payment-fee-label',
        'fpb_payment_fee_pct',
        $needs_fee
    );
    echo '<input id="fpb-payment-fee-label" class="regular-text" type="text" name="fpb_payment_fee_label" value="' . esc_attr($fee_label) . '" placeholder="' . esc_attr__('Payment fee', 'snapbook') . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('No fee for these payment methods', 'snapbook'),
        __('Customers who pay with a ticked method are not charged the fee — usually bank transfer, cheque and cash, which cost you nothing to accept. Only methods switched on in WooCommerce → Settings → Payments are listed.', 'snapbook'),
        '',
        'fpb_payment_fee_pct',
        $needs_fee
    );
    echo '<input type="hidden" name="fpb_payment_fee_exempt_gateways[]" value="">';
    $gateways = [];
    if (function_exists('WC') && WC() && WC()->payment_gateways()) {
        foreach ((array) WC()->payment_gateways()->payment_gateways() as $gid => $gateway) {
            if (is_object($gateway) && isset($gateway->enabled) && 'yes' === $gateway->enabled) {
                $gateways[(string) $gid] = wp_strip_all_tags((string) $gateway->get_title());
            }
        }
    }
    if ($gateways) {
        echo '<div class="fpb-checklist">';
        foreach ($gateways as $gid => $title) {
            echo '<label class="fpb-checklist-item"><input type="checkbox" name="fpb_payment_fee_exempt_gateways[]" value="' . esc_attr($gid) . '"' . checked(in_array($gid, $exempt, true), true, false) . '> ' . esc_html($title !== '' ? $title : $gid) . ' <code>' . esc_html($gid) . '</code></label>';
        }
        echo '</div>';
    } else {
        echo '<p class="description">' . esc_html__('No payment methods are switched on in WooCommerce yet.', 'snapbook') . '</p>';
    }
    // Methods that are switched off right now keep their setting.
    foreach ($exempt as $gid) {
        if (! isset($gateways[$gid])) {
            echo '<input type="hidden" name="fpb_payment_fee_exempt_gateways[]" value="' . esc_attr($gid) . '">';
        }
    }
    echo '<p class="description">' . esc_html__('When a customer pays with a ticked method, the fee comes off before they pay.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/**
 * Offline payments: how long an unpaid bank transfer / cheque / cash booking
 * keeps its date.
 */
function snapbook_render_offline_payments_card()
{
    $days = (int) snapbook_opt('fpb_offline_hold_days');

    snapbook_settings_card_open(__('Offline payments', 'snapbook'), __('Bookings paid by bank transfer, cheque or cash hold their date as “Awaiting payment” until you record the payment.', 'snapbook'), 'fpb-offline-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Unpaid bookings', 'snapbook'),
        __('A bank transfer, cheque or cash booking keeps its date while you wait for the money (record it from Bookings → Actions → Record payment). Set a number of days to cancel it automatically — freeing the date — if it is still unpaid by then. 0 = never cancel automatically.', 'snapbook'),
        'fpb-offline-hold-days'
    );
    echo snapbook_settings_inline_number('fpb_offline_hold_days', $days, 0, 90, __('Cancel unpaid bank-transfer, cheque and cash bookings after', 'snapbook'), __('days', 'snapbook'), 'fpb-offline-hold-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = never. Bookings you add by hand as unpaid are never cancelled automatically.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

function snapbook_render_promo_codes_card()
{
    $coupons_on = (int) snapbook_opt('fpb_coupons_enable') === 1;

    snapbook_settings_card_open(__('Promo codes', 'snapbook'), __('Discount codes customers can enter while booking.', 'snapbook'), 'fpb-coupons-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Promo codes', 'snapbook'),
        __('Adds a “Promo code” box to the booking form. Codes are ordinary WooCommerce coupons — create them under Marketing → Coupons. Each code\'s discount and usage is recorded on the order.', 'snapbook')
    );
    echo '<input type="hidden" name="fpb_coupons_enable" value="0">';
    echo snapbook_toggle_field('fpb_coupons_enable', __('Show a promo code field on the booking form', 'snapbook'), $coupons_on, __('Codes are your WooCommerce coupons (Marketing → Coupons).', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    if (function_exists('wc_coupons_enabled') && ! wc_coupons_enabled()) {
        echo '<p class="fpb-set-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <span>' . sprintf(
            /* translators: %s: link to WooCommerce → Settings → General */
            esc_html__('Coupons are switched off in WooCommerce, so no promo code field shows until you turn on “Enable the use of coupon codes” in %s.', 'snapbook'),
            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">' . esc_html__('WooCommerce → Settings → General', 'snapbook') . '</a>'
        ) . '</span></p>';
    }
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — AVAILABILITY
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_availability()
{
    global $wp_locale;
    $capacity   = (int) snapbook_opt('fpb_daily_capacity');
    $slots      = (array) snapbook_opt('fpb_time_slots');
    $min_notice = (int) snapbook_opt('fpb_min_notice_days');
    $max_adv    = (int) snapbook_opt('fpb_max_advance_days');
    $closed     = array_map('intval', (array) snapbook_opt('fpb_closed_weekdays'));
    $hold       = (int) snapbook_opt('fpb_hold_minutes');
    $week_start = (int) get_option('start_of_week', 0);

    echo '<p class="fpb-set-pointer"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <span>' . sprintf(
        /* translators: %s: link to SnapBook → Date Slots */
        esc_html__('These rules apply every week. To close one particular date — a holiday, say — click it under %s.', 'snapbook'),
        '<a href="' . esc_url(admin_url('admin.php?page=sb-dates')) . '">' . esc_html__('Date Slots', 'snapbook') . '</a>'
    ) . '</span></p>';

    // ── Days and times ─
    snapbook_settings_card_open(__('Days and times', 'snapbook'), __('When you take sessions, and how many.', 'snapbook'), 'fpb-availability-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Start times', 'snapbook'),
        __('Offer fixed start times, e.g. 09:00, 13:00, 17:30. Customers then choose a time, and each time can be booked once per day. Leave empty to book whole days (no time choice).', 'snapbook'),
        'fpb-time-slots'
    );
    echo '<input id="fpb-time-slots" class="regular-text" type="text" name="fpb_time_slots" value="' . esc_attr(implode(', ', $slots)) . '" placeholder="09:00, 13:00, 17:30" autocomplete="off">';
    echo '<p class="description">' . esc_html__('Separate times with commas (2pm also works). Leave empty for whole-day bookings.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Bookings per day', 'snapbook'),
        __('How many sessions you accept on the same date. Once a date has this many bookings it shows as fully booked. Only used when you don\'t offer start times.', 'snapbook'),
        'fpb-daily-capacity',
        '!fpb_time_slots',
        __('Not used while start times are set — each start time is one booking.', 'snapbook')
    );
    echo snapbook_settings_inline_number('fpb_daily_capacity', $capacity, 1, 50, '', __('booking(s) on the same date', 'snapbook'), 'fpb-daily-capacity'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Closed weekdays', 'snapbook'),
        __('Days of the week you never work. Customers can\'t pick them on the calendar. You can still add a booking on them yourself.', 'snapbook')
    );
    echo '<input type="hidden" name="fpb_closed_weekdays[]" value="">';
    echo '<div class="fpb-checklist fpb-checklist-inline">';
    for ($i = 0; $i < 7; $i++) {
        $day  = ($week_start + $i) % 7;
        $name = $wp_locale ? $wp_locale->get_weekday($day) : gmdate('l', strtotime('Sunday +' . $day . ' days'));
        echo '<label class="fpb-checklist-item"><input type="checkbox" name="fpb_closed_weekdays[]" value="' . (int) $day . '"' . checked(in_array($day, $closed, true), true, false) . '> ' . esc_html($name) . '</label>';
    }
    echo '</div>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();

    // ── Booking window ─
    snapbook_settings_card_open(__('Booking window', 'snapbook'), __('How soon and how far ahead customers can book.', 'snapbook'), 'fpb-window-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Minimum notice', 'snapbook'),
        __('Stops last-minute bookings. Example: with 2, the earliest date a customer can pick is the day after tomorrow. 0 = same-day bookings allowed.', 'snapbook'),
        'fpb-min-notice-days'
    );
    echo snapbook_settings_inline_number('fpb_min_notice_days', $min_notice, 0, 365, __('Book at least', 'snapbook'), __('days ahead', 'snapbook'), 'fpb-min-notice-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 allows bookings for today.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Furthest bookable day', 'snapbook'),
        __('How far into the future customers can book. Example: 365 = up to a year ahead; later dates are greyed out. 0 = no limit.', 'snapbook'),
        'fpb-max-advance-days'
    );
    echo snapbook_settings_inline_number('fpb_max_advance_days', $max_adv, 0, 1095, __('Up to', 'snapbook'), __('days ahead', 'snapbook'), 'fpb-max-advance-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = no limit.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Hold a date while the customer pays', 'snapbook'),
        __('Reserves the date (or start time) while a customer is on the payment step, so two people can\'t pay for the same slot. The hold ends when they pay, or after this many minutes. 0 = no hold.', 'snapbook'),
        'fpb-hold-minutes'
    );
    echo snapbook_settings_inline_number('fpb_hold_minutes', $hold, 0, 1440, '', __('minutes', 'snapbook'), 'fpb-hold-minutes'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = no hold.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — CHECKOUT
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_checkout()
{
    $checkout_mode = function_exists('snapbook_get_checkout_mode') ? snapbook_get_checkout_mode() : 'direct';
    $cf_catalog    = function_exists('snapbook_checkout_field_catalog') ? snapbook_checkout_field_catalog() : [];
    $cf_fields     = function_exists('snapbook_get_checkout_form_fields') ? snapbook_get_checkout_form_fields() : [];

    // ── Checkout style ─
    snapbook_settings_card_open(__('Checkout style', 'snapbook'), __('Where customers enter their details and pay.', 'snapbook'), 'fpb-checkout-card');
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Checkout mode', 'snapbook'),
        __('Multi-step form (recommended): customers type their details inside the booking form and only see WooCommerce\'s payment screen at the end. Classic: customers are sent to your normal WooCommerce checkout page to fill in their details and pay.', 'snapbook'),
        'fpb-checkout-mode'
    );
    echo '<select id="fpb-checkout-mode" name="fpb_checkout_mode">';
    echo '<option value="direct"' . selected('direct', $checkout_mode, false) . '>' . esc_html__('Multi-step form — details collected in the booking form, customer pays on the WooCommerce payment page', 'snapbook') . '</option>';
    echo '<option value="redirect"' . selected('redirect', $checkout_mode, false) . '>' . esc_html__('Classic — send customers to the WooCommerce checkout page to fill details and pay', 'snapbook') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Multi-step is recommended: customers never leave the booking flow until they pay.', 'snapbook') . '</p>';
    snapbook_setting_row_close();
    echo '</tbody></table>';
    snapbook_settings_card_close();

    // ── Details form fields ─
    snapbook_settings_card_open(__('Details form', 'snapbook'), __('The fields customers fill in on the Details step. The same choices apply to the WooCommerce checkout page.', 'snapbook'), 'fpb-fields-card');

    if (! empty($cf_fields)) {
        echo '<table class="widefat striped fpb-cf-table"><thead><tr>';
        echo '<th>' . esc_html__('Field', 'snapbook') . '</th>';
        echo '<th class="fpb-cf-check-col"><span class="fpb-th-inline">' . esc_html__('Show', 'snapbook') . snapbook_help_tip(__('Untick to remove the field from the form. Fields marked “always on” are needed to create the order.', 'snapbook')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
        echo '<th class="fpb-cf-check-col"><span class="fpb-th-inline">' . esc_html__('Required', 'snapbook') . snapbook_help_tip(__('Customers can\'t continue until a required field is filled in.', 'snapbook')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
        echo '<th><span class="fpb-th-inline">' . esc_html__('Label', 'snapbook') . snapbook_help_tip(__('The field\'s name as customers see it. Rename it to suit you, e.g. “Phone” → “WhatsApp number”.', 'snapbook')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
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
                echo '<input type="checkbox" name="fpb_cf_enabled[' . esc_attr($key) . ']" value="1"' . checked(1, $f['enabled'], false) . ' aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('Show %s', 'snapbook'), $cf_catalog[$key]['label'])) . '">';
            }
            echo '</td>';

            echo '<td class="fpb-cf-check-col">';
            if ($locked) {
                echo '<input type="hidden" name="fpb_cf_required[' . esc_attr($key) . ']" value="1"><input type="checkbox" checked disabled>';
            } else {
                echo '<input type="hidden" name="fpb_cf_required[' . esc_attr($key) . ']" value="0">';
                echo '<input type="checkbox" name="fpb_cf_required[' . esc_attr($key) . ']" value="1"' . checked(1, $f['required'], false) . ' aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('%s is required', 'snapbook'), $cf_catalog[$key]['label'])) . '">';
            }
            echo '</td>';

            echo '<td><input type="text" class="regular-text fpb-cf-label-input" name="fpb_cf_label[' . esc_attr($key) . ']" value="' . esc_attr($f['label']) . '" aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('Label for %s', 'snapbook'), $cf_catalog[$key]['label'])) . '"></td>';
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

    echo '<h4 class="fpb-ccf-heading">' . esc_html__('Your own fields', 'snapbook');
    echo snapbook_help_tip(__('Ask for anything extra, e.g. “Shoot location” or “How did you hear about us?”. Answers are saved with the order. Removing a field here deletes it when you save.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
    echo '</h4>';
    echo '<p class="description">' . esc_html__('Add questions of your own to the Details step.', 'snapbook') . '</p>';
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
    snapbook_settings_card_close();

    // ── Messages after booking ─
    $confirm_title         = get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook'));
    $confirm_msg           = get_option('fpb_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'snapbook'));
    $confirm_pending_title = get_option('fpb_confirm_pending_title', __('Booking Received!', 'snapbook'));
    $confirm_pending_msg   = get_option('fpb_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'snapbook'));
    $success_title         = get_option('fpb_success_title', 'Booking Requested!');
    $success_msg           = get_option('fpb_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to");

    snapbook_settings_card_open(__('Messages after booking', 'snapbook'), __('What customers read on screen once their booking is placed. {email} becomes the customer\'s email address.', 'snapbook'), 'fpb-messages-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Paid — heading', 'snapbook'),
        __('Headline customers see once their booking is paid, e.g. straight after a card payment.', 'snapbook'),
        'fpb-confirm-title'
    );
    echo '<input id="fpb-confirm-title" class="regular-text" type="text" name="fpb_confirm_title" value="' . esc_attr($confirm_title) . '" placeholder="Booking Confirmed!">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Paid — message', 'snapbook'),
        __('The text under that headline. {email} becomes the customer\'s email address.', 'snapbook'),
        'fpb-confirm-msg'
    );
    echo '<input id="fpb-confirm-msg" class="large-text" type="text" name="fpb_confirm_msg" value="' . esc_attr($confirm_msg) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Not paid yet — heading', 'snapbook'),
        __('Headline when the booking is saved but the money comes later — bank transfer, cheque or cash. Your payment instructions show underneath.', 'snapbook'),
        'fpb-confirm-pending-title'
    );
    echo '<input id="fpb-confirm-pending-title" class="regular-text" type="text" name="fpb_confirm_pending_title" value="' . esc_attr($confirm_pending_title) . '" placeholder="Booking Received!">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Not paid yet — message', 'snapbook'),
        __('The text under that headline. {email} becomes the customer\'s email address.', 'snapbook'),
        'fpb-confirm-pending-msg'
    );
    echo '<input id="fpb-confirm-pending-msg" class="large-text" type="text" name="fpb_confirm_pending_msg" value="' . esc_attr($confirm_pending_msg) . '">';
    snapbook_setting_row_close();

    echo '</tbody></table>';

    echo '<h4 class="fpb-ccf-heading">' . esc_html__('Enquiry mode', 'snapbook');
    echo snapbook_help_tip(__('Only used when WooCommerce is off: the booking form then sends you an enquiry email instead of taking a payment, and shows this screen.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
    echo '</h4>';
    echo '<p class="description">' . esc_html__('Shown after an enquiry is sent (only when WooCommerce is off).', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Enquiry sent — heading', 'snapbook'),
        __('Headline customers see after sending an enquiry.', 'snapbook'),
        'fpb-success-title'
    );
    echo '<input id="fpb-success-title" class="regular-text" type="text" name="fpb_success_title" value="' . esc_attr($success_title) . '" placeholder="Booking Requested!">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Enquiry sent — message', 'snapbook'),
        __('The text under that headline. The customer\'s email address is added at the end automatically.', 'snapbook'),
        'fpb-success-msg'
    );
    echo '<input id="fpb-success-msg" class="large-text" type="text" name="fpb_success_msg" value="' . esc_attr($success_msg) . '">';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — CUSTOMERS
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_customers()
{
    $require_account = (int) get_option('fpb_require_account_booking', 0);
    $tab_on          = (int) snapbook_opt('fpb_account_bookings_enable') === 1;
    $req_on          = (int) snapbook_opt('fpb_customer_requests_enable') === 1;

    snapbook_settings_card_open(__('Customer account', 'snapbook'), __('What customers can see and do with their bookings in WooCommerce → My Account.', 'snapbook'), 'fpb-account-card');
    echo '<input type="hidden" name="fpb_require_account_booking" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Account required', 'snapbook'),
        __('Customers must log in or create an account before they can book, so every booking is tied to an account. It adds a step, so leave it off for the quickest booking.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_require_account_booking', __('Customers must log in or register before they book', 'snapbook'), $require_account === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Bookings tab', 'snapbook'),
        __('Adds a “Bookings” tab to WooCommerce → My Account that lists the customer\'s sessions with what is paid and what is still due.', 'snapbook')
    );
    echo '<input type="hidden" name="fpb_account_bookings_enable" value="0">';
    echo snapbook_toggle_field('fpb_account_bookings_enable', __('Show a “Bookings” tab in My Account', 'snapbook'), $tab_on, __('Lists the customer\'s sessions with what is paid and what is still due.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Change requests', 'snapbook'),
        __('Customers can ask to reschedule or cancel from their booking. You get an email and the request shows on the booking. Nothing changes until you change it yourself.', 'snapbook')
    );
    echo '<input type="hidden" name="fpb_customer_requests_enable" value="0">';
    echo snapbook_toggle_field('fpb_customer_requests_enable', __('Let customers ask to reschedule or cancel', 'snapbook'), $req_on, __('You get an email and the request shows on the booking.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — EMAILS
═══════════════════════════════════════════════════════════════ */
function snapbook_render_settings_emails()
{
    $placeholders = '<code>{customer_name}</code> <code>{first_name}</code> <code>{package_name}</code> <code>{addons}</code> <code>{session_type}</code> <code>{session_date}</code> <code>{order_id}</code> <code>{total}</code> <code>{site_name}</code>';

    // ── Customer booking confirmation ─
    $order_email      = snapbook_get_order_email_settings();
    $order_email_file = snapbook_order_email_attachment_label($order_email['attachment_id']);
    $needs_custom     = __('Used only while “Custom email” is on.', 'snapbook');

    snapbook_settings_card_open(__('Customer booking confirmation', 'snapbook'), __('The email your customer receives after booking. When your own email is on, it replaces WooCommerce\'s wording — the customer gets your email only, not both.', 'snapbook'), 'fpb-order-email-card');
    echo '<input type="hidden" name="fpb_order_email_enable" value="0">';
    echo '<input type="hidden" name="fpb_order_email_order_table" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Custom email', 'snapbook'),
        __('On: replace WooCommerce\'s standard order-confirmation wording with your own message for booking orders. Leave the message below empty and the standard email is sent instead.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_order_email_enable', __('Use my own content for the booking confirmation email', 'snapbook'), (int) $order_email['enable'] === 1, __('Admin alerts and balance reminders are not affected.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Subject', 'snapbook'),
        __('The subject line. Placeholders such as {order_id} work here. Leave blank to keep WooCommerce\'s subject.', 'snapbook'),
        'fpb-order-email-subject',
        'fpb_order_email_enable',
        $needs_custom
    );
    echo '<input id="fpb-order-email-subject" class="large-text" type="text" name="fpb_order_email_subject" value="' . esc_attr($order_email['subject']) . '">';
    echo '<p class="description">' . esc_html__('Leave blank to keep the WooCommerce subject.', 'snapbook') . '</p>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Heading', 'snapbook'),
        __('The large title at the top of the email. Leave blank to keep WooCommerce\'s heading.', 'snapbook'),
        'fpb-order-email-heading',
        'fpb_order_email_enable',
        $needs_custom
    );
    echo '<input id="fpb-order-email-heading" class="regular-text" type="text" name="fpb_order_email_heading" value="' . esc_attr($order_email['heading']) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Email content', 'snapbook'),
        __('Your message. Placeholders are swapped for the booking\'s details when it is sent, e.g. “Hi {first_name}, see you on {session_date}!”.', 'snapbook'),
        'fpb_order_email_message',
        'fpb_order_email_enable',
        $needs_custom
    );
    // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
    wp_editor(
        $order_email['message'],
        'fpb_order_email_message',
        [
            'textarea_name' => 'fpb_order_email_message',
            'textarea_rows' => 12,
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description"><strong>' . esc_html__('Placeholders', 'snapbook') . ':</strong> ' . $placeholders . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Order details table', 'snapbook'),
        __('Adds the standard summary under your message: package, add-ons, totals and customer details. Untick for a completely custom email. The “pay remaining balance” button shows either way.', 'snapbook'),
        '',
        'fpb_order_email_enable',
        $needs_custom
    );
    echo '<label><input type="checkbox" name="fpb_order_email_order_table" value="1" ' . checked(1, (int) $order_email['order_table'], false) . '> ' . esc_html__('Include the booking summary table (package, add-ons, totals, customer details)', 'snapbook') . '</label>';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Attached file', 'snapbook'),
        __('A file sent with every booking confirmation — your Terms of Service PDF or a “how to prepare” guide, say. It is attached whether or not “Custom email” is on, but never to admin alerts or reminders.', 'snapbook')
    );
    echo '<div class="fpb-media-field">';
    echo '<input type="hidden" id="fpb-order-email-attachment-id" name="fpb_order_email_attachment_id" value="' . esc_attr($order_email['attachment_id']) . '">';
    echo '<button type="button" class="button" id="fpb-order-email-attachment-pick">' . esc_html__('Choose or upload file', 'snapbook') . '</button> ';
    echo '<button type="button" class="button-link button-link-delete" id="fpb-order-email-attachment-remove"' . ($order_email['attachment_id'] ? '' : ' style="display:none"') . '>' . esc_html__('Remove', 'snapbook') . '</button>';
    echo '<p id="fpb-order-email-attachment-name" class="description"><strong>' . esc_html($order_email_file) . '</strong></p>';
    echo '</div>';
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();

    // ── Studio new-booking alert ─
    $admin_order_email = snapbook_get_admin_email_settings();
    $needs_admin       = __('Used only while “Branded alert” is on.', 'snapbook');

    snapbook_settings_card_open(__('New-booking alert for you', 'snapbook'), __('The email you (the studio) get for each new booking.', 'snapbook'), 'fpb-admin-email-card');
    echo '<input type="hidden" name="fpb_admin_email_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    snapbook_setting_row_open(
        __('Branded alert', 'snapbook'),
        __('Replaces WooCommerce\'s plain “New order” email for booking orders with a branded summary: session and add-ons, the customer\'s contact details, deposit taken vs balance due, their note, and a button to open the order. Other shop orders keep the normal email.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_admin_email_enable', __('Use SnapBook\'s branded email for the admin New Order notification', 'snapbook'), (int) $admin_order_email['enable'] === 1, __('Booking orders only. WooCommerce sends it once payment is placed.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Send to', 'snapbook'),
        __('Who receives the alert. Separate several addresses with commas. Leave blank to use WooCommerce\'s “New order” recipient (WooCommerce → Settings → Emails).', 'snapbook'),
        'fpb-admin-email-recipient',
        'fpb_admin_email_enable',
        $needs_admin
    );
    echo '<input id="fpb-admin-email-recipient" class="large-text" type="text" name="fpb_admin_email_recipient" value="' . esc_attr($admin_order_email['recipient']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Subject', 'snapbook'),
        __('The subject line. Placeholders such as {order_id} and {package_name} work here. Leave blank to keep WooCommerce\'s subject.', 'snapbook'),
        'fpb-admin-email-subject',
        'fpb_admin_email_enable',
        $needs_admin
    );
    echo '<input id="fpb-admin-email-subject" class="large-text" type="text" name="fpb_admin_email_subject" value="' . esc_attr($admin_order_email['subject']) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Heading', 'snapbook'),
        __('The large title at the top of the email. Leave blank to keep WooCommerce\'s heading.', 'snapbook'),
        'fpb-admin-email-heading',
        'fpb_admin_email_enable',
        $needs_admin
    );
    echo '<input id="fpb-admin-email-heading" class="regular-text" type="text" name="fpb_admin_email_heading" value="' . esc_attr($admin_order_email['heading']) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Intro note', 'snapbook'),
        __('A short note shown above the booking details, e.g. a reminder to confirm the date with the client. Leave blank for none.', 'snapbook'),
        'fpb_admin_email_intro',
        'fpb_admin_email_enable',
        $needs_admin
    );
    // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
    wp_editor(
        $admin_order_email['intro'],
        'fpb_admin_email_intro',
        [
            'textarea_name' => 'fpb_admin_email_intro',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description"><strong>' . esc_html__('Placeholders', 'snapbook') . ':</strong> ' . $placeholders . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
    snapbook_setting_row_close();

    echo '</tbody></table>';
    snapbook_settings_card_close();

    snapbook_render_balance_reminder_card();
}

/**
 * Balance reminders: live status, the two automatic schedules (before the
 * shoot / until paid), and the email wording. Saved by
 * snapbook_save_balance_reminder_settings() (emails.php).
 */
function snapbook_render_balance_reminder_card()
{
    $cfg      = snapbook_get_balance_reminder_settings();
    $tz_label = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';

    snapbook_settings_card_open(__('Balance reminders', 'snapbook'), __('Emails customers who paid a deposit a link to pay the rest. Reminders stop by themselves as soon as the balance is paid or the booking is cancelled.', 'snapbook'), 'fpb-reminders', 'fpb-rem-card');

    snapbook_render_balance_reminder_status($cfg);

    echo '<div class="fpb-rem-rules">';

    // ── Before the photoshoot ──
    echo '<div class="fpb-rem-rule' . ($cfg['before_enable'] ? '' : ' is-off') . '">';
    echo '<input type="hidden" name="fpb_enable_balance_reminders" value="0">';
    echo '<div class="fpb-rem-head">';
    echo '<label class="fpb-toggle">';
    echo '<input type="checkbox" name="fpb_enable_balance_reminders" value="1"' . checked($cfg['before_enable'], true, false) . '>';
    echo '<span class="fpb-toggle-track" aria-hidden="true"></span>';
    echo '<span class="fpb-toggle-text">' . esc_html__('Reminder before the photoshoot', 'snapbook') . '<small>' . esc_html__('One email a set number of days before the shoot date.', 'snapbook') . '</small></span>';
    echo '</label>';
    echo snapbook_help_tip(__('Sends one email, with a link to pay, to customers who still owe a balance a set number of days before their session. It goes out at 09:00 your time.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
    echo '</div>';
    echo '<div class="fpb-rem-fields">';
    echo '<label class="fpb-rem-inline">' . esc_html__('Send it', 'snapbook') . ' <input class="small-text" type="number" min="0" max="60" step="1" name="fpb_balance_reminder_days_before" value="' . esc_attr($cfg['days_before']) . '"> ' . esc_html__('day(s) before the photoshoot, at 09:00', 'snapbook') . '</label>';
    echo '<p class="description">' . esc_html__('0 sends it on the morning of the shoot. A customer who books later than that still gets it, at least 12 hours after paying the deposit, as long as the shoot is still ahead.', 'snapbook') . '</p>';
    echo '</div>';
    echo '</div>';

    // ── Until the balance is paid ──
    echo '<div class="fpb-rem-rule' . ($cfg['repeat_enable'] ? '' : ' is-off') . '">';
    echo '<input type="hidden" name="fpb_balance_reminder_repeat_enable" value="0">';
    echo '<div class="fpb-rem-head">';
    echo '<label class="fpb-toggle">';
    echo '<input type="checkbox" name="fpb_balance_reminder_repeat_enable" value="1"' . checked($cfg['repeat_enable'], true, false) . '>';
    echo '<span class="fpb-toggle-track" aria-hidden="true"></span>';
    echo '<span class="fpb-toggle-text">' . esc_html__('Keep reminding until the balance is paid', 'snapbook') . '<small>' . esc_html__('Repeats on a fixed interval until the customer pays.', 'snapbook') . '</small></span>';
    echo '</label>';
    echo snapbook_help_tip(__('Keeps emailing customers who still owe a balance every few days until they pay — or until the limit you set is reached, or (if ticked) the shoot date has passed. Works alongside the reminder before the photoshoot.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
    echo '</div>';
    echo '<div class="fpb-rem-fields">';
    echo '<label class="fpb-rem-inline">' . esc_html__('Send a reminder every', 'snapbook') . ' <input class="small-text" type="number" min="1" max="60" step="1" name="fpb_balance_reminder_repeat_days" value="' . esc_attr($cfg['repeat_days']) . '"> ' . esc_html__('day(s)', 'snapbook') . '</label>';
    echo '<label class="fpb-rem-inline">' . esc_html__('Stop after', 'snapbook') . ' <input class="small-text" type="number" min="0" max="100" step="1" name="fpb_balance_reminder_repeat_max" value="' . esc_attr($cfg['repeat_max']) . '"> ' . esc_html__('reminders', 'snapbook') . ' <span class="fpb-rem-hint">' . esc_html__('(0 = no limit, keep going until paid)', 'snapbook') . '</span></label>';
    echo '<input type="hidden" name="fpb_balance_reminder_repeat_stop_after_shoot" value="0">';
    echo '<label class="fpb-rem-inline"><input type="checkbox" name="fpb_balance_reminder_repeat_stop_after_shoot" value="1"' . checked($cfg['repeat_stop_after_shoot'], true, false) . '> ' . esc_html__('Stop once the photoshoot date has passed', 'snapbook') . '</label>';
    echo '<p class="description">' . esc_html__('The first one goes out that many days after the deposit, or after the last reminder. When you switch this on, customers who already owe a balance get their first one that many days from now, not all at once.', 'snapbook') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '</div>';

    echo '<p class="description fpb-rem-note">' . sprintf(
        /* translators: %s: site timezone, linked to Settings → General */
        esc_html__('Reminders go out between 09:00 and 21:00 in your site timezone (%s) and never twice to the same customer within 12 hours. A reminder you send by hand from Bookings restarts the countdown.', 'snapbook'),
        '<a href="' . esc_url(admin_url('options-general.php')) . '">' . esc_html($tz_label) . '</a>'
    ) . '</p>';

    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Reminder email subject', 'snapbook'),
        __('Subject line of every reminder. The placeholders listed below work here too.', 'snapbook'),
        'fpb-balance-reminder-subject'
    );
    echo '<input id="fpb-balance-reminder-subject" class="regular-text" type="text" name="fpb_balance_reminder_subject" value="' . esc_attr($cfg['subject']) . '">';
    snapbook_setting_row_close();

    snapbook_setting_row_open(
        __('Reminder email template', 'snapbook'),
        __('The reminder text. {pay_link} becomes the customer\'s personal payment link — keep it in so they can pay in one click. {balance_due_date} is empty unless a balance deadline is set (Payments → Deposit).', 'snapbook'),
        'fpb-balance-reminder-template'
    );
    echo '<textarea id="fpb-balance-reminder-template" class="large-text code" rows="7" name="fpb_balance_reminder_template">' . esc_textarea($cfg['template']) . '</textarea>';
    echo '<p class="description">' . esc_html__('Placeholders (subject and message): {customer_name}, {balance_amount}, {balance_due_date}, {session_date}, {package_name}, {addons}, {pay_link}, {order_id}', 'snapbook') . '</p>';
    snapbook_setting_row_close();
    echo '</tbody></table>';

    snapbook_settings_card_close();
}

/**
 * Status strip at the top of the reminder card: is anything being chased,
 * what goes out next, and did the last automatic check actually run.
 * Needs WooCommerce (the engine lives in woocommerce.php).
 */
function snapbook_render_balance_reminder_status($cfg)
{
    if (! function_exists('snapbook_balance_reminder_status')) {
        return;
    }

    $st     = snapbook_balance_reminder_status();
    $now    = time();
    $active = $cfg['before_enable'] || $cfg['repeat_enable'];
    $last   = $st['last'];
    $stale  = $active && $last['ts'] > 0 && ($now - $last['ts']) > 3 * HOUR_IN_SECONDS;
    $fmt    = get_option('date_format') . ' ' . get_option('time_format');

    if (! $active) {
        $state = 'is-off';
        $title = __('Automatic reminders are off', 'snapbook');
    } elseif ($stale) {
        $state = 'is-warn';
        $title = __('Reminders may not be going out', 'snapbook');
    } else {
        $state = 'is-ok';
        $title = __('Automatic reminders are on', 'snapbook');
    }

    $lines = [];
    if ($st['outstanding'] > 0) {
        /* translators: %d: number of bookings */
        $lines[] = sprintf(_n('%d booking has an unpaid balance.', '%d bookings have an unpaid balance.', $st['outstanding'], 'snapbook'), $st['outstanding']);
    } else {
        $lines[] = __('No bookings have an unpaid balance right now.', 'snapbook');
    }

    if ($active) {
        if ($st['upcoming']) {
            $up   = $st['upcoming'];
            $when = $up['ts'] <= $now ? __('due now, goes out with the next check', 'snapbook') : wp_date($fmt, $up['ts']);
            $kind = $up['type'] === 'before' ? __('before the photoshoot', 'snapbook') : __('repeating until paid', 'snapbook');
            /* translators: 1: date/time, 2: customer name, 3: order number, 4: which schedule */
            $lines[] = sprintf(__('Next reminder: %1$s, to %2$s (order #%3$d, %4$s).', 'snapbook'), $when, $up['name'] !== '' ? $up['name'] : __('customer', 'snapbook'), $up['order_id'], $kind);
        } elseif ($st['outstanding'] > 0) {
            $lines[] = __('No reminder is scheduled: the shoot dates have passed or the reminder limit was reached.', 'snapbook');
        }

        if ($last['ts'] > 0) {
            $triggers = [
                'cron'     => __('by WP-Cron', 'snapbook'),
                'fallback' => __('during a page visit', 'snapbook'),
                'manual'   => __('run by hand', 'snapbook'),
            ];
            /* translators: 1: time since, 2: how it ran, 3: bookings checked, 4: reminders sent */
            $lines[] = sprintf(__('Last check: %1$s ago (%2$s). %3$d checked, %4$d sent.', 'snapbook'), human_time_diff($last['ts'], $now), $triggers[$last['trigger']] ?? $last['trigger'], (int) $last['checked'], (int) $last['sent']);
        } else {
            $lines[] = __('No check has run yet. The first runs within the hour, or use the button.', 'snapbook');
        }
    }

    $warn = '';
    if ($stale) {
        /* translators: %s: time since the last check */
        $warn = sprintf(__('The last check was %s ago. Checks run hourly when the site gets visits, so this can happen on a quiet site. If it keeps happening, WP-Cron is probably blocked: ask your host to call wp-cron.php every 5–15 minutes.', 'snapbook'), human_time_diff($last['ts'], $now));
    } elseif ($active && $st['cron_disabled']) {
        $warn = __('WP-Cron is switched off on this site (DISABLE_WP_CRON), so checks rely on your server\'s cron job. Without one, SnapBook still checks during normal page visits.', 'snapbook');
    }

    echo '<div class="fpb-rem-status ' . esc_attr($state) . '">';
    echo '<div class="fpb-rem-status-main">';
    echo '<strong class="fpb-rem-status-title">' . esc_html($title) . '</strong>';
    echo '<ul class="fpb-rem-status-list">';
    foreach ($lines as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul>';
    if ($warn !== '') {
        echo '<p class="fpb-rem-status-warn">' . esc_html($warn) . '</p>';
    }
    echo '<p class="fpb-rem-run-msg" id="fpb-rem-run-msg" aria-live="polite"></p>';
    echo '</div>';
    if ($active) {
        echo '<div class="fpb-rem-status-actions"><button type="button" class="button" id="fpb-rem-run">' . esc_html__('Run reminder check now', 'snapbook') . '</button>';
        echo snapbook_help_tip(__('Checks right away for customers who are due a reminder and sends them, instead of waiting for the next hourly check. Nobody gets a duplicate.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_help_tip.
        echo '</div>';
    }
    echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — GOOGLE CALENDAR
═══════════════════════════════════════════════════════════════ */
function snapbook_render_gcal_card()
{
    $gcal_connected   = function_exists('snapbook_gcal_is_connected') && snapbook_gcal_is_connected();
    $gcal_conn        = function_exists('snapbook_gcal_get_connection') ? snapbook_gcal_get_connection() : [];
    $gcal_enabled     = (int) get_option('fpb_gcal_enabled', 1);
    $gcal_error       = get_option('fpb_gcal_last_error', '');
    $gcal_has_creds   = function_exists('snapbook_gcal_has_credentials') && snapbook_gcal_has_credentials();
    $gcal_creds_const = function_exists('snapbook_gcal_creds_from_constant') && snapbook_gcal_creds_from_constant();
    $gcal_client_id   = get_option('fpb_gcal_client_id', '');
    $gcal_client_sec  = get_option('fpb_gcal_client_secret', '');
    $gcal_redirect    = function_exists('snapbook_gcal_redirect_uri') ? snapbook_gcal_redirect_uri() : '';

    snapbook_settings_card_open(__('Google Calendar', 'snapbook'), __('Each paid booking becomes a calendar event: the package and order number as the title, the client invited as a guest, their location, an alert 2 hours before, and the session and contact details in the notes. Connect once with a single click — there are no access tokens to copy.', 'snapbook'), 'fpb-gcal');

    if ($gcal_connected) {
        $gcal_email     = isset($gcal_conn['email']) ? $gcal_conn['email'] : '';
        $disconnect_url = wp_nonce_url(admin_url('admin-post.php?action=snapbook_gcal_disconnect'), 'snapbook_gcal_disconnect');

        echo '<div class="fpb-gcal-panel is-connected">';
        echo '<div class="fpb-gcal-status">';
        echo '<span class="fpb-gcal-dot is-on" aria-hidden="true"></span>';
        echo '<div class="fpb-gcal-status-text"><strong>' . esc_html__('Connected', 'snapbook') . '</strong>';
        if ($gcal_email !== '') {
            echo '<span>' . esc_html($gcal_email) . '</span>';
        }
        echo '</div>';
        echo '<a class="button fpb-gcal-disconnect" href="' . esc_url($disconnect_url) . '">' . esc_html__('Disconnect', 'snapbook') . '</a>';
        echo '</div>';
        if ($gcal_error !== '') {
            echo '<p class="fpb-gcal-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($gcal_error) . '</p>';
        }
        echo '</div>';

        echo '<table class="form-table" role="presentation"><tbody>';
        snapbook_setting_row_open(
            __('Sync new bookings', 'snapbook'),
            __('Pause or resume adding new paid bookings to your calendar, without disconnecting. Events already in the calendar stay put.', 'snapbook')
        );
        echo '<input type="hidden" name="fpb_gcal_enabled" value="0">';
        echo snapbook_toggle_field('fpb_gcal_enabled', __('Add new paid bookings to Google Calendar', 'snapbook'), $gcal_enabled === 1, __('Turn off to pause syncing without disconnecting.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Test connection', 'snapbook'),
            __('Adds a sample event to today in your calendar so you can check everything works. Delete it afterwards.', 'snapbook')
        );
        echo '<button type="button" class="button button-secondary" id="fpb-gcal-test">' . esc_html__('Send a test event', 'snapbook') . '</button>';
        echo '<span id="fpb-gcal-test-msg" class="fpb-gcal-test-msg" aria-live="polite"></span>';
        snapbook_setting_row_close();
        echo '</tbody></table>';
    } else {
        $connect_url = wp_nonce_url(admin_url('admin-post.php?action=snapbook_gcal_connect'), 'snapbook_gcal_connect');
        echo '<div class="fpb-gcal-panel is-disconnected">';
        echo '<div class="fpb-gcal-status">';
        echo '<span class="fpb-gcal-dot" aria-hidden="true"></span>';
        echo '<div class="fpb-gcal-status-text"><strong>' . esc_html__('Not connected', 'snapbook') . '</strong><span>' . esc_html__('Bookings are not being added to Google Calendar yet.', 'snapbook') . '</span></div>';
        echo '</div>';
        // Static Google "G" mark (literal SVG — no dynamic data to escape).
        echo '<a class="fpb-gcal-connect' . ($gcal_has_creds ? '' : ' is-disabled') . '" href="' . esc_url($connect_url) . '">';
        echo '<span class="fpb-gcal-g" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg></span>';
        echo '<span>' . esc_html__('Connect with Google', 'snapbook') . '</span>';
        echo '</a>';
        echo '</div>';
        if ($gcal_error !== '') {
            echo '<p class="fpb-gcal-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($gcal_error) . '</p>';
        }

        // Google app credentials, saved right here in the backend. Hidden when
        // set via wp-config constants (nothing to edit then).
        if ($gcal_creds_const) {
            echo '<p class="fpb-gcal-hint"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__('Your Google app is set in wp-config.php. Just click Connect with Google.', 'snapbook') . '</p>';
        } else {
            echo '<table class="form-table fpb-gcal-creds" role="presentation"><tbody>';
            snapbook_setting_row_open(
                __('Google Client ID', 'snapbook'),
                __('From your Google Cloud project: APIs & Services → Credentials → your OAuth client of type “Web application”. The setup steps below walk you through it.', 'snapbook'),
                'fpb-gcal-client-id'
            );
            echo '<input id="fpb-gcal-client-id" class="large-text code" type="text" name="fpb_gcal_client_id" value="' . esc_attr($gcal_client_id) . '" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__('Paste your Client ID', 'snapbook') . '">';
            snapbook_setting_row_close();

            snapbook_setting_row_open(
                __('Google Client Secret', 'snapbook'),
                __('Shown next to the Client ID in Google Cloud Console; it starts with GOCSPX-. Keep it private.', 'snapbook'),
                'fpb-gcal-client-secret'
            );
            echo '<input id="fpb-gcal-client-secret" class="large-text code" type="password" name="fpb_gcal_client_secret" value="' . esc_attr($gcal_client_sec) . '" autocomplete="off" spellcheck="false" placeholder="GOCSPX-…">';
            echo '<p class="description">' . esc_html__('Paste both, click Save All Settings, then Connect with Google. Nothing else to edit.', 'snapbook') . '</p>';
            snapbook_setting_row_close();
            echo '</tbody></table>';
        }

        // Setup checklist — shown whichever way the credentials are supplied,
        // because steps 3 and 4 are configured on the Google app itself and are
        // the usual cause of a refused connection.
        echo '<div class="fpb-gcal-setupnote">';
        echo '<p><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . sprintf(
            /* translators: %s: Google Cloud Console link */
            esc_html__('Set up once in Google Cloud Console (about 5 minutes) — %s:', 'snapbook'),
            '<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('open Google Cloud Console', 'snapbook') . '</a>'
        ) . '</p>';
        echo '<ol class="fpb-gcal-steps">';
        echo '<li>' . esc_html__('Create an OAuth client of type "Web application" and paste its Client ID and Client Secret above.', 'snapbook') . '</li>';
        echo '<li>' . esc_html__('Add this exact redirect URI to that client (click the box below to copy).', 'snapbook') . '</li>';
        echo '<li>' . esc_html__('In APIs & Services → Library, enable the Google Calendar API for the project.', 'snapbook') . '</li>';
        echo '<li>' . wp_kses(
            __('In <strong>Google Auth Platform → Audience</strong>, click <strong>Publish app</strong>. Left in "Testing", Google blocks sign-in with <em>Error 403: access_denied</em> for anyone not listed under Test users, and the connection expires every 7 days.', 'snapbook'),
            ['strong' => [], 'em' => []]
        ) . '</li>';
        echo '</ol>';
        echo '<p class="description">' . esc_html__('Redirect URI for this site (click to copy):', 'snapbook') . '</p>';
        echo '<input type="text" class="large-text code fpb-gcal-redirect" readonly value="' . esc_attr($gcal_redirect) . '" onclick="this.select();document.execCommand(&quot;copy&quot;);">';
        if (0 !== strpos($gcal_redirect, 'https://') && ! preg_match('#^https?://(localhost|127\.0\.0\.1)#', $gcal_redirect)) {
            echo '<p class="description fpb-gcal-httpsnote"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Google needs an https site (or localhost). On this plain-http address the connection will be refused — connect from the live https site.', 'snapbook') . '</p>';
        }
        echo '</div>';
    }
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — ADVANCED
═══════════════════════════════════════════════════════════════ */

/**
 * Plugin data: whether uninstalling removes everything (uninstall.php).
 */
function snapbook_render_plugin_data_card()
{
    $on = (int) snapbook_opt('fpb_delete_data_on_uninstall') === 1;

    snapbook_settings_card_open(__('Plugin data', 'snapbook'), __('What happens to SnapBook\'s data when you delete the plugin from the Plugins screen. Deactivating never deletes anything.', 'snapbook'), 'fpb-data-card', 'fpb-danger-card');
    echo '<input type="hidden" name="fpb_delete_data_on_uninstall" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('On uninstall', 'snapbook'),
        __('Only matters when you delete SnapBook from the Plugins screen. On: all SnapBook data is erased for good. Off: your data stays, so reinstalling picks up where you left off.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_delete_data_on_uninstall', __('Delete all SnapBook data when the plugin is deleted', 'snapbook'), $on); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo '<div class="fpb-set-danger"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div>';
    echo '<strong>' . esc_html__('This cannot be undone.', 'snapbook') . '</strong> ';
    echo esc_html__('Deleting the plugin then permanently removes every booking record, session type, package, add-on, date slot and setting, the hidden booking product, and the Google Calendar connection. WooCommerce orders (and their payments) are kept. Leave this off if you might reinstall SnapBook.', 'snapbook');
    echo '</div></div>';
    snapbook_setting_row_close();
    echo '</tbody></table>';
    snapbook_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — BOOKING FORM (slug sb-frontend)
   The form's steps (optional Contract step) and the cards shown beside
   it (How it works / Booking date calendar / Deposit). Posts normally.
═══════════════════════════════════════════════════════════════ */
function snapbook_page_frontend()
{
    if (! snapbook_can_manage()) return;

    $defaults = function_exists('snapbook_frontend_sidebar_defaults') ? snapbook_frontend_sidebar_defaults() : [];
    $saved    = false;

    if (isset($_POST['snapbook_frontend_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['snapbook_frontend_nonce'])), 'snapbook_frontend')) {
        // Checkbox toggles (absent = 0).
        foreach (['fpb_fe_hiw_enable', 'fpb_fe_deposit_enable', 'fpb_fe_loader_enable', 'fpb_fe_contract_enable'] as $key) {
            update_option($key, absint(wp_unslash($_POST[$key] ?? 0)) === 1 ? 1 : 0);
        }
        // Single-line text titles.
        foreach (['fpb_fe_hiw_title', 'fpb_fe_date_title', 'fpb_fe_date_sub', 'fpb_fe_deposit_title', 'fpb_fe_contract_step_label', 'fpb_fe_contract_title', 'fpb_fe_contract_sub', 'fpb_fe_contract_accept_label'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        // Multi-line text areas.
        foreach (['fpb_fe_hiw_steps', 'fpb_fe_deposit_text'] as $key) {
            update_option($key, sanitize_textarea_field(wp_unslash($_POST[$key] ?? '')));
        }
        // Rich text — the contract body keeps its formatting.
        update_option('fpb_fe_contract_text', wp_kses_post(wp_unslash($_POST['fpb_fe_contract_text'] ?? '')));
        snapbook_save_extra_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.
        $saved = true;
    }

    $s = function_exists('snapbook_get_frontend_sidebar') ? snapbook_get_frontend_sidebar() : $defaults;
    $c = function_exists('snapbook_get_contract_settings') ? snapbook_get_contract_settings() : [];

    snapbook_wrap_open(__('Booking Form', 'snapbook'), 'sb-frontend', __('Change the steps customers go through and the cards shown beside the form. Hover or tap the ? beside any setting to see what it does.', 'snapbook'));

    if ($saved) {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Booking form settings saved.', 'snapbook') . '</p></div>';
    }

    echo '<form method="post" id="fpb-frontend-form" class="fpb-settings-page fpb-fe-page">';
    wp_nonce_field('snapbook_frontend', 'snapbook_frontend_nonce');

    // ── The flow at a glance ─
    $contract_on = $c && (int) $c['enable'] === 1;
    echo '<div class="card fpb-settings-card fpb-flow-card">';
    echo '<h2>' . esc_html__('What customers go through', 'snapbook') . '</h2>';
    echo '<ol class="fpb-flow" aria-label="' . esc_attr__('Booking form steps', 'snapbook') . '">';
    echo '<li class="fpb-flow-step"><span class="fpb-flow-num">1</span><span class="fpb-flow-name">' . esc_html__('Package', 'snapbook') . '</span><small>' . esc_html__('Package, add-ons, date', 'snapbook') . '</small></li>';
    echo '<li class="fpb-flow-step"><span class="fpb-flow-num">2</span><span class="fpb-flow-name">' . esc_html__('Details', 'snapbook') . '</span><small>' . esc_html__('Name, email, phone…', 'snapbook') . '</small></li>';
    if ($c) {
        echo '<li class="fpb-flow-step fpb-flow-contract' . ($contract_on ? '' : ' is-off') . '"><span class="fpb-flow-num">3</span><span class="fpb-flow-name">' . esc_html($c['step_label'] !== '' ? $c['step_label'] : __('Contract', 'snapbook')) . '</span><small>' . esc_html__('Optional — see below', 'snapbook') . '</small></li>';
    }
    echo '<li class="fpb-flow-step"><span class="fpb-flow-num fpb-flow-pay-num" data-on="4" data-off="3">' . ($contract_on ? '4' : '3') . '</span><span class="fpb-flow-name">' . esc_html__('Payment', 'snapbook') . '</span><small>' . esc_html__('Deposit or full amount', 'snapbook') . '</small></li>';
    echo '</ol>';
    echo '<p class="description">' . esc_html__('Customers choose their date from the calendar card beside the form. The fields on the Details step, the messages after booking and the colors are in Settings.', 'snapbook');
    if (current_user_can('manage_options')) {
        echo ' <a href="' . esc_url(admin_url('admin.php?page=sb-settings#checkout')) . '">' . esc_html__('Checkout settings', 'snapbook') . '</a> &middot; <a href="' . esc_url(admin_url('admin.php?page=sb-settings#general')) . '">' . esc_html__('Colors', 'snapbook') . '</a>';
    }
    echo '</p>';
    echo '</div>';

    // ── Contract step ─
    if ($c) {
        $needs_contract = __('Used only while the contract step is on.', 'snapbook');

        echo '<h2 class="fpb-group-title">' . esc_html__('Steps', 'snapbook') . '</h2>';
        echo '<div class="card fpb-settings-card" id="fpb-contract-card">';
        echo '<h3>' . esc_html__('Contract step', 'snapbook') . '</h3>';
        echo '<p class="description">' . esc_html__('A step between Details and Payment where the customer reads your Terms & Conditions and must accept them before paying.', 'snapbook') . '</p>';
        echo '<input type="hidden" name="fpb_fe_contract_enable" value="0">';
        echo '<table class="form-table" role="presentation"><tbody>';

        snapbook_setting_row_open(
            __('Show step', 'snapbook'),
            __('Adds a step before payment where the customer reads your terms and ticks a box to accept them. They can\'t pay until they do. Off by default.', 'snapbook')
        );
        echo snapbook_toggle_field('fpb_fe_contract_enable', __('Add the contract step to the booking form', 'snapbook'), (int) $c['enable'] === 1, __('When off, the form is Package → Details → Payment.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Step name', 'snapbook'),
            __('The short name in the step indicator at the top of the form, e.g. “Contract” or “Terms”.', 'snapbook'),
            'fpb-fe-contract-step-label',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="fpb-fe-contract-step-label" class="regular-text" type="text" name="fpb_fe_contract_step_label" value="' . esc_attr($c['step_label']) . '">';
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Heading', 'snapbook'),
            __('The title at the top of the contract step.', 'snapbook'),
            'fpb-fe-contract-title',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="fpb-fe-contract-title" class="regular-text" type="text" name="fpb_fe_contract_title" value="' . esc_attr($c['title']) . '">';
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Subtitle', 'snapbook'),
            __('A line under the heading. Leave blank to hide it.', 'snapbook'),
            'fpb-fe-contract-sub',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="fpb-fe-contract-sub" class="regular-text" type="text" name="fpb_fe_contract_sub" value="' . esc_attr($c['sub']) . '">';
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Terms & Conditions', 'snapbook'),
            __('Your full agreement. It shows in a scrollable box on the booking form. If you change it later, each booking still records which version its customer accepted.', 'snapbook'),
            'fpb_fe_contract_text',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
        wp_editor(
            $c['text'],
            'fpb_fe_contract_text',
            [
                'textarea_name' => 'fpb_fe_contract_text',
                'textarea_rows' => 14,
                'media_buttons' => false,
                'quicktags'     => true,
            ]
        );
        snapbook_setting_row_close();

        snapbook_setting_row_open(
            __('Acceptance text', 'snapbook'),
            __('The label beside the box the customer must tick to continue.', 'snapbook'),
            'fpb-fe-contract-accept',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="fpb-fe-contract-accept" class="large-text" type="text" name="fpb_fe_contract_accept_label" value="' . esc_attr($c['accept_label']) . '">';
        snapbook_setting_row_close();

        $sig_on = function_exists('snapbook_opt') && (int) snapbook_opt('fpb_fe_contract_signature') === 1;
        snapbook_setting_row_open(
            __('Signature', 'snapbook'),
            __('Also ask the customer to type their full name as a signature. Every booking keeps a record of the acceptance: date and time, the version of the wording, the signature and the IP address — see the booking\'s View window.', 'snapbook'),
            '',
            'fpb_fe_contract_enable',
            $needs_contract
        );
        echo '<input type="hidden" name="fpb_fe_contract_signature" value="0">';
        echo snapbook_toggle_field('fpb_fe_contract_signature', __('Require a typed signature', 'snapbook'), $sig_on, __('The customer types their full name to sign, as well as ticking the box.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
        snapbook_setting_row_close();

        echo '</tbody></table>';
        echo '</div>';
    }

    echo '<h2 class="fpb-group-title">' . esc_html__('Cards beside the form', 'snapbook') . '</h2>';

    // ── How it works ─
    echo '<div class="card fpb-settings-card" id="fpb-hiw-card">';
    echo '<h3>' . esc_html__('How it works', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('A short numbered list explaining how booking works.', 'snapbook') . '</p>';
    echo '<input type="hidden" name="fpb_fe_hiw_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Show card', 'snapbook'),
        __('Shows a numbered “How it works” list beside the booking form. Turn it off to hide the card.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_fe_hiw_enable', __('Show this card', 'snapbook'), (int) $s['hiw_enable'] === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();
    snapbook_setting_row_open(
        __('Title', 'snapbook'),
        __('The card\'s heading.', 'snapbook'),
        'fpb-fe-hiw-title',
        'fpb_fe_hiw_enable',
        __('Used only while this card is shown.', 'snapbook')
    );
    echo '<input id="fpb-fe-hiw-title" class="regular-text" type="text" name="fpb_fe_hiw_title" value="' . esc_attr($s['hiw_title']) . '">';
    snapbook_setting_row_close();
    snapbook_setting_row_open(
        __('Steps', 'snapbook'),
        __('Write one step per line — they are numbered automatically. {deposit_pct} becomes your deposit percentage.', 'snapbook'),
        'fpb-fe-hiw-steps',
        'fpb_fe_hiw_enable',
        __('Used only while this card is shown.', 'snapbook')
    );
    echo '<textarea id="fpb-fe-hiw-steps" class="large-text code" rows="6" name="fpb_fe_hiw_steps">' . esc_textarea($s['hiw_steps']) . '</textarea>';
    echo '<p class="description">' . esc_html__('One step per line.', 'snapbook') . '</p>';
    snapbook_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Booking date (calendar card) ─
    echo '<div class="card fpb-settings-card" id="fpb-date-card">';
    echo '<h3>' . esc_html__('Booking date', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('The calendar card where customers pick their session date. It is always shown; only its text can be changed.', 'snapbook') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Title', 'snapbook'),
        __('Heading of the calendar card, e.g. “Choose your date”.', 'snapbook'),
        'fpb-fe-date-title'
    );
    echo '<input id="fpb-fe-date-title" class="regular-text" type="text" name="fpb_fe_date_title" value="' . esc_attr($s['date_title']) . '">';
    snapbook_setting_row_close();
    snapbook_setting_row_open(
        __('Subtitle', 'snapbook'),
        __('A line under the heading. Leave blank to hide it.', 'snapbook'),
        'fpb-fe-date-sub'
    );
    echo '<input id="fpb-fe-date-sub" class="regular-text" type="text" name="fpb_fe_date_sub" value="' . esc_attr($s['date_sub']) . '">';
    snapbook_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Deposit card ─
    $needs_deposit_card = __('Used only while this card is shown.', 'snapbook');
    echo '<div class="card fpb-settings-card" id="fpb-deposit-info-card">';
    echo '<h3>' . esc_html__('Deposit card', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('A highlighted card explaining your deposit.', 'snapbook');
    if (current_user_can('manage_options')) {
        echo ' ' . sprintf(
            /* translators: %s: link to Settings → Payments */
            esc_html__('The deposit amount itself is set in %s.', 'snapbook'),
            '<a href="' . esc_url(admin_url('admin.php?page=sb-settings#payments')) . '">' . esc_html__('Settings → Payments', 'snapbook') . '</a>'
        );
    }
    echo '</p>';
    echo '<input type="hidden" name="fpb_fe_deposit_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Show card', 'snapbook'),
        __('Shows a highlighted card beside the form that explains your deposit. Turn it off to hide the card.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_fe_deposit_enable', __('Show this card', 'snapbook'), (int) $s['deposit_enable'] === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();
    snapbook_setting_row_open(
        __('Title', 'snapbook'),
        __('The card\'s heading. {deposit_pct} becomes your deposit percentage, e.g. “{deposit_pct}% deposit to confirm”.', 'snapbook'),
        'fpb-fe-deposit-title',
        'fpb_fe_deposit_enable',
        $needs_deposit_card
    );
    echo '<input id="fpb-fe-deposit-title" class="regular-text" type="text" name="fpb_fe_deposit_title" value="' . esc_attr($s['deposit_title']) . '">';
    snapbook_setting_row_close();
    snapbook_setting_row_open(
        __('Text', 'snapbook'),
        __('A few lines explaining how the deposit works. {deposit_pct} works here too.', 'snapbook'),
        'fpb-fe-deposit-text',
        'fpb_fe_deposit_enable',
        $needs_deposit_card
    );
    echo '<textarea id="fpb-fe-deposit-text" class="large-text" rows="4" name="fpb_fe_deposit_text">' . esc_textarea($s['deposit_text']) . '</textarea>';
    snapbook_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Loading placeholders ─
    echo '<h2 class="fpb-group-title">' . esc_html__('Loading', 'snapbook') . '</h2>';
    echo '<div class="card fpb-settings-card" id="fpb-loader-card">';
    echo '<h3>' . esc_html__('Loading placeholders', 'snapbook') . '</h3>';
    echo '<p class="description">' . esc_html__('What customers see for the moment the calendar and packages are loading.', 'snapbook') . '</p>';
    echo '<input type="hidden" name="fpb_fe_loader_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    snapbook_setting_row_open(
        __('Show placeholders', 'snapbook'),
        __('While dates and packages load, show grey animated shapes where they will appear. Off leaves the space blank until they load.', 'snapbook')
    );
    echo snapbook_toggle_field('fpb_fe_loader_enable', __('Show a loading skeleton until the data arrives', 'snapbook'), (int) get_option('fpb_fe_loader_enable', 1) === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    snapbook_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    snapbook_render_savebar(__('Save Booking Form', 'snapbook'));
    echo '</form>';

    snapbook_wrap_close();
}
