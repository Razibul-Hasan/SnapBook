<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', 'snapbook_admin_menu');
function snapbook_admin_menu()
{
    // Day-to-day booking screens need manage_snapbook (Administrators and
    // Shop Managers). An administrator whose role somehow lacks it still
    // gets the menu through manage_options. Settings stays manage_options,
    // and WordPress hides that submenu from everyone else.
    $cap = snapbook_admin_menu_cap();

    add_menu_page(
        __('SnapBook', 'snapbook'),
        __('SnapBook', 'snapbook'),
        $cap,
        'sb-bookings',
        'snapbook_page_bookings',
        'dashicons-camera',
        30
    );
    add_submenu_page('sb-bookings', __('All Bookings', 'snapbook'),    __('All Bookings', 'snapbook'),    $cap,             'sb-bookings',       'snapbook_page_bookings');
    add_submenu_page('sb-bookings', __('Session Types', 'snapbook'),   __('Session Types', 'snapbook'),   $cap,             'sb-sessions',       'snapbook_page_sessions');
    add_submenu_page('sb-bookings', __('Packages', 'snapbook'),        __('Packages', 'snapbook'),        $cap,             'sb-packages',       'snapbook_page_packages');
    add_submenu_page('sb-bookings', __('Add-ons', 'snapbook'),         __('Add-ons', 'snapbook'),         $cap,             'sb-addons',         'snapbook_page_addons');
    add_submenu_page('sb-bookings', __('Date Slots', 'snapbook'),      __('Date Slots', 'snapbook'),      $cap,             'sb-dates',          'snapbook_page_dates');
    add_submenu_page('sb-bookings', __('Booking Form', 'snapbook'),    __('Booking Form', 'snapbook'),    $cap,             'sb-frontend',       'snapbook_page_frontend');
    add_submenu_page('sb-bookings', __('Settings', 'snapbook'),        __('Settings', 'snapbook'),        'manage_options', 'sb-settings',       'snapbook_page_settings');
}

/**
 * Capability the SnapBook booking screens are registered with for the
 * current user: manage_snapbook when they have it, else manage_options.
 */
function snapbook_admin_menu_cap()
{
    $cap = function_exists('snapbook_manage_cap') ? snapbook_manage_cap() : 'manage_options';

    return current_user_can($cap) ? $cap : 'manage_options';
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
    // Media library — only the Settings screen opens wp.media (the Order
    // Email attachment picker). It pulls in Backbone, Underscore and the
    // media templates, so it must not load on every SnapBook page.
    if (strpos($hook, 'sb-settings') !== false) {
        wp_enqueue_media();
    }
    wp_enqueue_script('snapbook-admin',    SNAPBOOK_URL . 'assets/js/admin.js',   [], SNAPBOOK_VER, true);
    wp_localize_script('snapbook-admin', 'snapbookAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('snapbook_admin_nonce'),
        'bookingsUrl' => admin_url('admin.php?page=sb-bookings'),
        'i18n'    => [
            // Same helper the PHP side renders with, so the "no file" wording
            // cannot drift between the initial render and the Remove button.
            'noFile'           => function_exists('snapbook_order_email_attachment_label')
                ? snapbook_order_email_attachment_label(0)
                : __('No file selected.', 'snapbook'),
            'mediaUnavailable' => __('Media library unavailable.', 'snapbook'),
            'pickTitle'        => __('Select or upload the order email attachment', 'snapbook'),
            'pickButton'       => __('Use this file', 'snapbook'),
        ],
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
 * A $tip adds a help button beside the switch (outside the <label>, so
 * clicking it never flips the switch).
 */
function snapbook_toggle_field($name, $label, $checked, $hint = '', $tip = '')
{
    $out  = '<label class="fpb-toggle">';
    $out .= '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
    $out .= '<span class="fpb-toggle-track" aria-hidden="true"></span>';
    $out .= '<span class="fpb-toggle-text">' . esc_html($label);
    if ($hint !== '') {
        $out .= '<small>' . esc_html($hint) . '</small>';
    }
    $out .= '</span></label>';

    if ($tip !== '') {
        $out = '<span class="fpb-toggle-row">' . $out . snapbook_help_tip($tip) . '</span>';
    }

    return $out;
}

/**
 * "?" help button whose explanation shows on hover, keyboard focus or tap
 * (admin.js positions one shared bubble). The text also lives in a hidden
 * element the button points to with aria-describedby, so screen readers
 * announce it without the bubble. Never place it inside a <label>.
 */
function snapbook_help_tip($text)
{
    static $n = 0;
    $n++;
    $id = 'fpb-tip-' . $n;

    return '<span class="fpb-tip-wrap">'
        . '<button type="button" class="fpb-tip" aria-label="' . esc_attr__('More information', 'snapbook') . '" aria-describedby="' . esc_attr($id) . '">'
        . '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button>'
        . '<span class="fpb-tip-text" id="' . esc_attr($id) . '" hidden>' . esc_html($text) . '</span>'
        . '</span>';
}

/**
 * Label row for the add/edit form grids: the label, a required marker and
 * an optional help tip beside it.
 */
function snapbook_field_label($text, $tip = '', $for = '', $required = false)
{
    $out  = '<div class="fpb-label-row">';
    $out .= '<label' . ($for !== '' ? ' for="' . esc_attr($for) . '"' : '') . '>' . esc_html($text);
    if ($required) {
        $out .= ' <span class="fpb-req">*</span>';
    }
    $out .= '</label>';
    if ($tip !== '') {
        $out .= snapbook_help_tip($tip);
    }
    $out .= '</div>';

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
    // Grouped by job: day-to-day bookings, what you sell, when you are
    // open, then how the form looks and works. Settings stays last — it is
    // the configuration screen, not a day-to-day one.
    $groups = [
        'manage'  => [
            'label' => __('Manage', 'snapbook'),
            'tabs'  => [
                'sb-bookings' => ['label' => __('Bookings', 'snapbook'), 'icon' => 'dashicons-clipboard'],
            ],
        ],
        'catalog' => [
            'label' => __('What you sell', 'snapbook'),
            'tabs'  => [
                'sb-sessions' => ['label' => __('Session Types', 'snapbook'), 'icon' => 'dashicons-category'],
                'sb-packages' => ['label' => __('Packages', 'snapbook'),      'icon' => 'dashicons-archive'],
                'sb-addons'   => ['label' => __('Add-ons', 'snapbook'),       'icon' => 'dashicons-star-filled'],
            ],
        ],
        'dates'   => [
            'label' => __('When you are open', 'snapbook'),
            'tabs'  => [
                'sb-dates' => ['label' => __('Date Slots', 'snapbook'), 'icon' => 'dashicons-calendar-alt'],
            ],
        ],
        'setup'   => [
            'label' => __('Set up', 'snapbook'),
            'tabs'  => [
                'sb-frontend' => ['label' => __('Booking Form', 'snapbook'), 'icon' => 'dashicons-feedback'],
                'sb-settings' => ['label' => __('Settings', 'snapbook'),     'icon' => 'dashicons-admin-generic'],
            ],
        ],
    ];
    // Shop Managers run bookings but not the plugin-wide settings.
    if (! current_user_can('manage_options')) {
        unset($groups['setup']['tabs']['sb-settings']);
    }
    echo '<div class="wrap fpb-admin-wrap">';
    echo '<div class="sb-topbar">';
    echo '<div class="sb-topbar-brand">';
    echo '<span class="sb-topbar-logo"><span class="dashicons dashicons-camera" aria-hidden="true"></span></span>';
    echo '<span class="sb-topbar-title">SnapBook</span>';
    echo '<span class="sb-topbar-ver">v' . esc_html(SNAPBOOK_VER) . '</span>';
    echo '</div>';
    echo '<nav class="sb-tabs" aria-label="' . esc_attr__('SnapBook sections', 'snapbook') . '">';
    $first = true;
    foreach ($groups as $group_key => $group) {
        if (! $first) {
            echo '<span class="sb-tab-sep" aria-hidden="true"></span>';
        }
        $first = false;
        echo '<span class="sb-tab-group sb-tab-group-' . esc_attr($group_key) . '" role="group" aria-label="' . esc_attr($group['label']) . '">';
        foreach ($group['tabs'] as $slug => $tab) {
            $url     = admin_url('admin.php?page=' . $slug);
            $current = $slug === $active_tab;
            echo '<a href="' . esc_url($url) . '" class="sb-tab' . ($current ? ' fpb-active' : '') . '"' . ($current ? ' aria-current="page"' : '') . '>';
            echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" aria-hidden="true"></span>';
            echo '<span class="sb-tab-label">' . esc_html($tab['label']) . '</span>';
            echo '</a>';
        }
        echo '</span>';
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

/* All Bookings (snapbook_page_bookings) lives in includes/admin-bookings.php. */

/* ═══════════════════════════════════════════════════════════════
   PAGE — SESSION TYPES
═══════════════════════════════════════════════════════════════ */
function snapbook_page_sessions()
{
    if (! snapbook_can_manage()) return;
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
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_field_label() escapes its parts.
    echo '<div class="fpb-field">' . snapbook_field_label(__('Name', 'snapbook'), __('What customers see as a tab at the top of the booking form, e.g. “Wedding”, “Portrait” or “Family”.', 'snapbook'), 'fpb-session-name', true) . '<input class="regular-text" type="text" id="fpb-session-name" name="name" required placeholder="Holiday / Couple Photoshoot" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown as a tab at the top of the booking form.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field">' . snapbook_field_label(__('Slug', 'snapbook'), __('A short, web-friendly ID made from the name (lowercase letters, numbers and dashes). It must be unique. You rarely need to change it.', 'snapbook'), 'fpb-session-slug', true) . '<input class="regular-text" type="text" id="fpb-session-slug" name="slug" required placeholder="photo" value="' . esc_attr($edit_row->slug ?? '') . '">';
    echo '<p class="description">' . esc_html__('Filled in automatically from the name.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field">' . snapbook_field_label(__('Emoji / Icon', 'snapbook'), __('Shown beside the name on the tab. Paste an emoji such as 📷, or a Dashicons class such as “dashicons dashicons-camera” (see developer.wordpress.org/resource/dashicons). Font Awesome classes also work if your theme loads Font Awesome.', 'snapbook'), 'fpb-session-emoji') . '<input class="regular-text" type="text" id="fpb-session-emoji" name="emoji" maxlength="100" placeholder="📷 or dashicons dashicons-camera" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji or a Dashicons class.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field">' . snapbook_field_label(__('Sort Order', 'snapbook'), __('Controls the order of the tabs on the booking form: lower numbers come first. Use 10, 20, 30… to leave room for new ones.', 'snapbook'), 'fpb-session-sort') . '<input class="small-text" type="number" id="fpb-session-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    // phpcs:enable
    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Visible on the booking form', 'snapbook'), __('Untick to hide this session type from the booking form without deleting it. Existing bookings are not affected.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
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
    if (! snapbook_can_manage()) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $cur      = snapbook_get_currency_symbol();
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $filter   = isset($_GET['session']) ? (int) $_GET['session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $filter ? $wpdb->prepare('AND p.session_id = %d', $filter) : '';
    // LEFT JOIN so a package whose session type no longer exists still shows
    // up here (and can be fixed or deleted) instead of silently disappearing.
    $packages = $wpdb->get_results("SELECT p.*, s.name AS sname, s.emoji AS semoji FROM {$pfx}packages p LEFT JOIN {$pfx}sessions s ON s.id=p.session_id WHERE 1=1 {$where} ORDER BY p.session_id, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}packages WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    // Session choices for the form: the active ones, plus the edited
    // package's current session even when inactive -- otherwise nothing is
    // selected and saving silently moves the package to the first session.
    $form_sessions = $edit_row
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE active=1 OR id=%d ORDER BY sort_order, id", (int) $edit_row->session_id)) // phpcs:ignore
        : $sessions;
    $edit_session_found = false;
    if ($edit_row) {
        foreach ($form_sessions as $s) {
            if ((int) $s->id === (int) $edit_row->session_id) {
                $edit_session_found = true;
                break;
            }
        }
    }

    snapbook_wrap_open('Packages', 'sb-packages', __('Create the packages offered under each session type.', 'snapbook'));
    snapbook_render_smart_layout_bar(admin_url('admin.php?page=sb-packages'));

    // ── Form ─
    echo '<div class="postbox fpb-form-card" id="fpb-packages-add"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Package' : 'Add New Package') . '</h3>';
    echo '<form id="fpb-package-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-form-grid fpb-cols-2">';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_field_label() escapes its parts.
    echo '<div class="fpb-field">' . snapbook_field_label(__('Session Type', 'snapbook'), __('Which tab of the booking form this package appears under.', 'snapbook'), 'fpb-package-session', true) . '<select id="fpb-package-session" name="session_id" required>';
    // phpcs:enable
    if ($edit_row && ! $edit_session_found) {
        // The package's session type was deleted: make the admin pick one
        // rather than quietly defaulting to the first in the list.
        echo '<option value="" selected="selected">' . esc_html__('— Select a session type —', 'snapbook') . '</option>';
    }
    foreach ($form_sessions as $s) {
        $sel = $edit_row ? selected((int) $edit_row->session_id, (int) $s->id, false) : '';
        $s_label = trim(snapbook_icon_text($s->emoji) . ' ' . $s->name);
        if (! (int) $s->active) {
            $s_label .= ' ' . __('(inactive)', 'snapbook');
        }
        echo '<option value="' . (int) $s->id . '"' . $sel . '>' . esc_html($s_label) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_field_label() escapes its parts.
    echo '<div class="fpb-field">' . snapbook_field_label(__('Package Name', 'snapbook'), __('The name on the package card, e.g. “Golden Hour” or “Full Day”.', 'snapbook'), 'fpb-package-name', true) . '<input class="regular-text" type="text" id="fpb-package-name" name="name" required placeholder="Golden Hour" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    /* translators: %s: currency symbol */
    echo '<div class="fpb-field fpb-field-half">' . snapbook_field_label(sprintf(__('Price (%s)', 'snapbook'), html_entity_decode((string) $cur, ENT_QUOTES, 'UTF-8')), __('The full price of the package, before add-ons, any payment fee or promo code discount. The deposit is worked out from this.', 'snapbook'), 'fpb-package-price', true) . '<input class="small-text" type="number" id="fpb-package-price" name="price" required step="0.01" min="0" placeholder="199" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half">' . snapbook_field_label(__('Duration', 'snapbook'), __('A short summary shown on the card, e.g. “1 hr · 30 photos”. It is just text — it does not block time in your calendar.', 'snapbook'), 'fpb-package-duration') . '<input class="regular-text" type="text" id="fpb-package-duration" name="duration" placeholder="1hr · 30 photos" value="' . esc_attr($edit_row->duration ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-editor">' . snapbook_field_label(__('Description', 'snapbook'), __('What is included, shown on the package card. Bullet lists work well here.', 'snapbook'));
    // phpcs:enable
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
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_field_label() escapes its parts.
    echo '<div class="fpb-field fpb-field-half">' . snapbook_field_label(__('Sort Order', 'snapbook'), __('Order of the packages within their session type: lower numbers come first.', 'snapbook'), 'fpb-package-sort') . '<input class="small-text" type="number" id="fpb-package-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    $global_deposit = function_exists('snapbook_opt') ? (int) snapbook_opt('fpb_deposit_pct') : 50;
    $pkg_deposit    = isset($edit_row->deposit_pct) ? (int) $edit_row->deposit_pct : 0;
    /* translators: %d: the global deposit percentage */
    echo '<div class="fpb-field fpb-field-half">' . snapbook_field_label(__('Deposit %', 'snapbook'), sprintf(__('How much of this package is paid up front when the customer picks the deposit option. Leave blank to use the usual deposit (%d%%, set in Settings → Payments), or enter 1–99 to give this package its own.', 'snapbook'), $global_deposit), 'fpb-package-deposit');
    // phpcs:enable
    echo '<input id="fpb-package-deposit" class="small-text" type="number" name="deposit_pct" min="0" max="99" step="1" value="' . esc_attr($pkg_deposit > 0 ? $pkg_deposit : '') . '" placeholder="' . esc_attr($global_deposit) . '">';
    /* translators: %d: the global deposit percentage */
    echo '<p class="description">' . esc_html(sprintf(__('Blank = the usual %d%% deposit (Settings → Payments).', 'snapbook'), $global_deposit)) . '</p></div>';
    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('featured', __('Featured', 'snapbook'), isset($edit_row->featured) && (int) $edit_row->featured === 1, __('Highlighted with a ★ Popular tag', 'snapbook'), __('Adds a “★ Popular” tag to the card so this package stands out. Use it on the one you most want to sell.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'snapbook'), __('Untick to hide this package from the booking form without deleting it. Existing bookings are not affected.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
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
        echo esc_html__('No booking page found, so "Copy Link" URLs point to your homepage. Add the [snapbook] shortcode to a page, or pick your booking page under Settings → General → Booking page.', 'snapbook');
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
            if ($row->sname === null) {
                echo '<td><em>' . esc_html__('— (no session)', 'snapbook') . '</em></td>';
            } else {
                echo '<td>' . snapbook_icon_html($row->semoji) . ' ' . esc_html($row->sname) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_icon_html.
            }
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 2));
            if (isset($row->deposit_pct) && (int) $row->deposit_pct > 0) {
                /* translators: %d: package deposit percentage */
                echo '<br><small class="fpb-pkg-deposit">' . esc_html(sprintf(__('Deposit %d%%', 'snapbook'), (int) $row->deposit_pct)) . '</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html($row->duration) . '</td>';
            echo '<td>' . ($row->featured ? '⭐' : '—') . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            $share_url = snapbook_package_share_link($row, $booking_url);
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sb-packages&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button type="button" class="button button-small fpb-btn-sm fpb-copy-link" data-link="' . esc_attr($share_url) . '" title="' . esc_attr__('Copy a link that opens the booking form with this package already picked — handy for emails, Instagram or ads', 'snapbook') . '">' . esc_html__('Copy Link', 'snapbook') . '</button> ';
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
    if (! snapbook_can_manage()) return;
    global $wpdb;
    $pfx     = $wpdb->prefix . 'fpb_';
    $cur     = snapbook_get_currency_symbol();
    $addons  = $wpdb->get_results("SELECT a.*, p.name AS pname FROM {$pfx}addons a LEFT JOIN {$pfx}packages p ON p.id=a.package_id ORDER BY a.sort_order, a.id"); // phpcs:ignore
    // Every package, inactive ones included, so the "Applies To" checklist can
    // show (and a save keeps) an add-on's scope to an inactive package.
    $all_packages = $wpdb->get_results("SELECT p.id, p.name, p.active, s.emoji AS semoji, s.name AS sname FROM {$pfx}packages p LEFT JOIN {$pfx}sessions s ON s.id=p.session_id ORDER BY s.id IS NULL, s.sort_order, p.sort_order, p.id"); // phpcs:ignore
    foreach ($all_packages as $pkg) {
        $pkg->label = ($pkg->sname === null ? __('— (no session)', 'snapbook') : $pkg->sname) . ' › ' . $pkg->name;
        if (! (int) $pkg->active) {
            $pkg->label .= ' ' . __('(inactive)', 'snapbook');
        }
    }
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}addons WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    snapbook_wrap_open('Add-ons', 'sb-addons', __('Optional extras customers can add to any package.', 'snapbook'));
    snapbook_render_smart_layout_bar(admin_url('admin.php?page=sb-addons'));

    echo '<div class="postbox fpb-form-card" id="fpb-addons-add"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Add-on' : 'Add New Add-on') . '</h3>';
    echo '<form id="fpb-addon-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-form-grid fpb-cols-2">';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_field_label() escapes its parts.
    echo '<div class="fpb-field">' . snapbook_field_label(__('Name', 'snapbook'), __('The extra\'s name on its card, e.g. “Drone footage” or “Extra hour”.', 'snapbook'), 'fpb-addon-name', true) . '<input class="regular-text" type="text" id="fpb-addon-name" name="name" required placeholder="Drone aerial session" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown on the add-on card, under the packages.', 'snapbook') . '</p></div>';
    /* translators: %s: currency symbol */
    echo '<div class="fpb-field">' . snapbook_field_label(sprintf(__('Price (%s)', 'snapbook'), html_entity_decode((string) $cur, ENT_QUOTES, 'UTF-8')), __('Added to the package price when the customer ticks this extra. Use 0 for a free extra.', 'snapbook'), 'fpb-addon-price', true) . '<input class="small-text" type="number" id="fpb-addon-price" name="price" required step="0.01" min="0" placeholder="150" value="' . esc_attr($edit_row->price ?? '') . '">';
    echo '<p class="description">' . esc_html__('Added on top of the package price.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field">' . snapbook_field_label(__('Emoji / Icon', 'snapbook'), __('Shown on the add-on card. Paste an emoji such as 🚁, or a Dashicons class such as “dashicons dashicons-star-filled”.', 'snapbook'), 'fpb-addon-emoji') . '<input class="regular-text" type="text" id="fpb-addon-emoji" name="emoji" maxlength="100" placeholder="🚁 or dashicons dashicons-star-filled" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji or a Dashicons class.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field">' . snapbook_field_label(__('Sort Order', 'snapbook'), __('Order of the add-ons on the booking form: lower numbers come first.', 'snapbook'), 'fpb-addon-sort') . '<input class="small-text" type="number" id="fpb-addon-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'snapbook') . '</p></div>';
    echo '<div class="fpb-field fpb-field-editor">' . snapbook_field_label(__('Description', 'snapbook'), __('A short explanation shown on the add-on card, e.g. what the customer gets and for how long.', 'snapbook'));
    // phpcs:enable
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
    echo '<div class="fpb-field fpb-field-wide">' . snapbook_field_label(__('Applies To', 'snapbook'), __('Which packages offer this extra. Tick “All Packages” to offer it with every package, or tick only the packages it suits.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_field_label.
    echo '<div class="fpb-pkg-checklist" id="fpb-addon-pkg-list">';
    echo '<label class="fpb-pkg-check fpb-pkg-check-all"><input type="checkbox" name="package_ids[]" value="0"' . checked($is_global_addon, true, false) . '> <strong>' . esc_html__('All Packages (global)', 'snapbook') . '</strong></label>';
    foreach ($all_packages as $pkg) {
        $chk = checked(in_array((int) $pkg->id, $addon_pkg_ids, true), true, false);
        echo '<label class="fpb-pkg-check"><input type="checkbox" name="package_ids[]" value="' . (int) $pkg->id . '"' . $chk . '> ' . esc_html(trim(snapbook_icon_text($pkg->semoji) . ' ' . $pkg->label)) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
    echo '<p class="description">' . esc_html__('Tick the packages this add-on is offered with — one, several, or "All Packages" for every package.', 'snapbook') . '</p></div>';

    echo '</div>';
    echo '<div class="fpb-form-switches">';
    echo snapbook_toggle_field('active', __('Active', 'snapbook'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'snapbook'), __('Untick to hide this extra from the booking form without deleting it. Existing bookings are not affected.', 'snapbook')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside snapbook_toggle_field.
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
            $pkg_name_lookup[(int) $pkg->id] = $pkg->label;
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
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 2)) . '</td>';
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
    if (! snapbook_can_manage()) return;
    snapbook_wrap_open(__('Date Slots', 'snapbook'), 'sb-dates', __('Open or close single dates for new bookings — holidays, days off, or days you are already busy.', 'snapbook'));

    echo '<div class="fpb-howto">';
    echo '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div>';
    echo '<p><strong>' . esc_html__('Click a future date to change it:', 'snapbook') . '</strong> ';
    echo esc_html__('Available → Booked → Blocked → back to Available.', 'snapbook') . '</p>';
    echo '<p>' . esc_html__('Dates with real customer bookings are marked for you. Rules that repeat every week — closed weekdays, bookings per day, start times — are in', 'snapbook') . ' ';
    if (current_user_can('manage_options')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=sb-settings#availability')) . '">' . esc_html__('Settings → Availability', 'snapbook') . '</a>.';
    } else {
        echo esc_html__('Settings → Availability', 'snapbook') . '.';
    }
    echo '</p></div></div>';
?>
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
    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- snapbook_help_tip() escapes its text. ?>
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-available"></span> <?php esc_html_e('Available', 'snapbook'); ?><?php echo snapbook_help_tip(__('Customers can book this date (unless a weekly rule in Settings → Availability closes it).', 'snapbook')); ?></span>
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-booked"></span> <?php esc_html_e('Booked', 'snapbook'); ?><?php echo snapbook_help_tip(__('The date is full. SnapBook marks it when bookings reach your daily limit; you can also mark it by hand, e.g. for a shoot booked outside the website.', 'snapbook')); ?></span>
    <span class="fpb-leg"><span class="fpb-leg-dot fpb-blocked"></span> <?php esc_html_e('Blocked by Admin', 'snapbook'); ?><?php echo snapbook_help_tip(__('You closed this date — a holiday or day off. Customers can\'t pick it.', 'snapbook')); ?></span>
    <span class="fpb-leg"><span class="fpb-leg-dot" style="background:var(--fpb-border);opacity:.5"></span> <?php esc_html_e('Past', 'snapbook'); ?></span>
    <?php // phpcs:enable ?>
</div>
<div id="fpb-dates-msg" class="fpb-dates-msg"></div>
<?php
    snapbook_wrap_close();
}

/* Settings and Booking Form screens live in includes/admin-settings.php. */
require_once SNAPBOOK_DIR . 'includes/admin-settings.php';

// All Bookings: list, calendar, CSV export, add / edit / record payment.
require_once SNAPBOOK_DIR . 'includes/admin-bookings.php';
