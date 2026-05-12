<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', 'sb_admin_menu');
function sb_admin_menu()
{
    add_menu_page(
        __('SnapBook', 'snapbook'),
        __('SnapBook', 'snapbook'),
        'manage_options',
        'sb-bookings',
        'sb_page_bookings',
        'dashicons-camera',
        30
    );
    add_submenu_page('sb-bookings', __('All Bookings', 'snapbook'),    __('All Bookings', 'snapbook'),    'manage_options', 'sb-bookings',       'sb_page_bookings');
    add_submenu_page('sb-bookings', __('Session Types', 'snapbook'),   __('Session Types', 'snapbook'),   'manage_options', 'sb-sessions',       'sb_page_sessions');
    add_submenu_page('sb-bookings', __('Packages', 'snapbook'),        __('Packages', 'snapbook'),        'manage_options', 'sb-packages',       'sb_page_packages');
    add_submenu_page('sb-bookings', __('Add-ons', 'snapbook'),         __('Add-ons', 'snapbook'),         'manage_options', 'sb-addons',         'sb_page_addons');
    add_submenu_page('sb-bookings', __('Date Slots', 'snapbook'),      __('Date Slots', 'snapbook'),      'manage_options', 'sb-dates',          'sb_page_dates');
    add_submenu_page('sb-bookings', __('Settings', 'snapbook'),        __('Settings', 'snapbook'),        'manage_options', 'sb-settings',       'sb_page_settings');
}

/* ─── Admin assets ─────────────────────────────────────────── */
add_action('admin_enqueue_scripts', 'sb_admin_assets');
function sb_admin_assets($hook)
{
    if (strpos($hook, 'sb-') === false) return;
    wp_enqueue_style('sb-admin-css', SB_URL . 'assets/css/admin.css', [], SB_VER);
    wp_enqueue_script('sb-admin-js',    SB_URL . 'assets/js/admin.js',   [], SB_VER, true);
    wp_localize_script('sb-admin-js', 'fpbAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('fpb_admin_nonce'),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   HELPER — shared page wrapper
═══════════════════════════════════════════════════════════════ */
function sb_wrap_open($title, $active_tab = '')
{
    $tabs = [
        'sb-bookings' => '📋 Bookings',
        'sb-sessions' => '🎭 Session Types',
        'sb-packages' => '📦 Packages',
        'sb-addons'   => '✨ Add-ons',
        'sb-dates'    => '📅 Date Slots',
        'sb-settings' => '⚙️ Settings',
    ];
    echo '<div class="wrap fpb-admin-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
    echo '<p class="description sb-admin-meta">SnapBook v' . esc_html(SB_VER) . '</p>';
    echo '<h2 class="nav-tab-wrapper wp-clearfix fpb-admin-tabs">';
    foreach ($tabs as $slug => $label) {
        $url    = admin_url('admin.php?page=' . $slug);
        $active = ($slug === $active_tab) ? ' nav-tab-active active' : '';
        echo '<a href="' . esc_url($url) . '" class="nav-tab fpb-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
    echo '<div class="fpb-admin-body">';
}
function sb_wrap_close()
{
    echo '</div></div>';
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ALL BOOKINGS
═══════════════════════════════════════════════════════════════ */
function sb_page_bookings()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $status   = sanitize_text_field(wp_unslash($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $status ? $wpdb->prepare('WHERE status = %s', $status) : '';
    $bookings = $wpdb->get_results("SELECT * FROM {$pfx}bookings {$where} ORDER BY created_at DESC LIMIT 200"); // phpcs:ignore

    sb_wrap_open('All Bookings', 'sb-bookings');
    // Status filter
    $statuses = ['' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'completed' => 'Completed'];
    echo '<ul class="subsubsub sb-filter-bar">';
    $i = 0;
    $total_statuses = count($statuses);
    foreach ($statuses as $k => $v) {
        $url = admin_url('admin.php?page=sb-bookings' . ($k ? '&status=' . $k : ''));
        $cls = ($k === $status) ? ' current active' : '';
        echo '<li><a href="' . esc_url($url) . '" class="fpb-filter-btn' . esc_attr($cls) . '">' . esc_html($v) . '</a>';
        if ($i < $total_statuses - 1) {
            echo ' | ';
        }
        echo '</li>';
        $i++;
    }
    echo '</ul><br class="clear" />';

    if (empty($bookings)) {
        echo '<div class="notice notice-info inline"><p>No bookings found.</p></div>';
    } else {
        echo '<div class="fpb-table-wrap"><table class="wp-list-table widefat fixed striped fpb-table"><thead><tr>';
        echo '<th>#</th><th>Client</th><th>Package</th><th>Date</th><th>Total</th><th>Deposit</th><th>Status</th><th>Order</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($bookings as $b) {
            echo '<tr class="fpb-brow" data-status="' . esc_attr($b->status) . '">';
            echo '<td>' . (int) $b->id . '</td>';
            echo '<td><strong>' . esc_html($b->client_name) . '</strong><br><small>' . esc_html($b->client_email) . '</small></td>';
            echo '<td>' . esc_html($b->session_type) . '<br><small>' . esc_html($b->package_name) . '</small></td>';
            echo '<td>' . esc_html($b->session_date ? date_i18n(get_option('date_format'), strtotime($b->session_date)) : '—') . '</td>';
            echo '<td>€' . esc_html(number_format((float) $b->total, 2)) . '</td>';
            echo '<td>€' . esc_html(number_format((float) $b->deposit, 2)) . '</td>';
            echo '<td><span class="sb-badge sb-badge-' . esc_attr($b->status) . '">' . esc_html(ucfirst($b->status)) . '</span></td>';
            $order_link = $b->order_id ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $b->order_id . '&action=edit')) . '">#' . (int) $b->order_id . '</a>' : '—';
            echo '<td>' . wp_kses_post($order_link) . '</td>';
            echo '<td>';
            echo '<button class="button button-secondary sb-btn-sm sb-btn-view" data-id="' . (int) $b->id . '">View</button> ';
            echo '<select class="sb-status-select" data-id="' . (int) $b->id . '">';
            foreach (['pending', 'confirmed', 'cancelled', 'completed'] as $st) {
                echo '<option value="' . esc_attr($st) . '"' . selected($b->status, $st, false) . '>' . esc_html(ucfirst($st)) . '</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // View modal
    echo '<div id="sb-booking-modal" class="sb-modal" style="display:none"><div class="sb-modal-inner"><div class="sb-modal-head"><span>Booking</span><button class="sb-modal-close" aria-label="Close">✕</button></div><div class="sb-modal-body"></div></div></div>';
    // Inline booking data for JS
    echo '<script>var fpbBookings=' . wp_json_encode(array_values($bookings)) . ';</script>';
    sb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SESSION TYPES
═══════════════════════════════════════════════════════════════ */
function sb_page_sessions()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions ORDER BY sort_order, id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    sb_wrap_open('Session Types', 'sb-sessions');
    echo '<div class="fpb-two-col">';

    // ── Add / Edit form ─
    echo '<div class="postbox fpb-form-card"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Session Type' : 'Add New Session Type') . '</h3>';
    echo '<form id="fpb-session-form">';
    wp_nonce_field('fpb_admin_nonce', 'fpb_nonce');
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-field"><label>Emoji</label><input class="small-text" type="text" name="emoji" maxlength="5" placeholder="📷" value="' . esc_attr($edit_row->emoji ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Name <span class="req">*</span></label><input class="regular-text" type="text" id="fpb-session-name" name="name" required placeholder="Holiday / Couple Photoshoot" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Slug <span class="req">*</span></label><input class="regular-text" type="text" id="fpb-session-slug" name="slug" required placeholder="photo" value="' . esc_attr($edit_row->slug ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn" id="fpb-session-save">' . ($edit_row ? 'Update' : 'Add Session Type') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-sessions')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-session-msg"></div>';
    echo '</form></div></div>';

    // ── List ─
    echo '<div class="postbox fpb-list-card"><div class="inside">';
    if (empty($sessions)) {
        echo '<div class="fpb-empty">No session types yet.</div>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Emoji</th><th>Name</th><th>Slug</th><th>Order</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($sessions as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->emoji) . '</td>';
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
    echo '</div>'; // fpb-two-col
    sb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — PACKAGES
═══════════════════════════════════════════════════════════════ */
function sb_page_packages()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $filter   = isset($_GET['session']) ? (int) $_GET['session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $filter ? $wpdb->prepare('AND p.session_id = %d', $filter) : '';
    $packages = $wpdb->get_results("SELECT p.*, s.name AS sname, s.emoji AS semoji FROM {$pfx}packages p JOIN {$pfx}sessions s ON s.id=p.session_id WHERE 1=1 {$where} ORDER BY p.session_id, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}packages WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    sb_wrap_open('Packages', 'sb-packages');
    echo '<div class="fpb-two-col">';

    // ── Form ─
    echo '<div class="postbox fpb-form-card"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Package' : 'Add New Package') . '</h3>';
    echo '<form id="fpb-package-form">';
    wp_nonce_field('fpb_admin_nonce', 'fpb_nonce');
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-field"><label>Session Type <span class="req">*</span></label><select name="session_id" required>';
    foreach ($sessions as $s) {
        $sel = $edit_row ? selected((int) $edit_row->session_id, (int) $s->id, false) : '';
        echo '<option value="' . (int) $s->id . '"' . $sel . '>' . esc_html($s->emoji . ' ' . $s->name) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';
    echo '<div class="fpb-field"><label>Package Name <span class="req">*</span></label><input class="regular-text" type="text" name="name" required placeholder="Golden Hour" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Price (€) <span class="req">*</span></label><input class="small-text" type="number" name="price" required step="0.01" min="0" placeholder="199" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Duration</label><input class="regular-text" type="text" name="duration" placeholder="1hr · 30 photos" value="' . esc_attr($edit_row->duration ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Description</label><input class="regular-text" type="text" name="description" placeholder="Short tagline shown on card" value="' . esc_attr($edit_row->description ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field fpb-field-half"><label><input type="checkbox" name="featured" value="1"' . (isset($edit_row->featured) ? checked(1, $edit_row->featured, false) : '') . '> Featured ⭐</label></div>';
    echo '<div class="fpb-field"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Package' : 'Add Package') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-packages')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-package-msg"></div>';
    echo '</form></div></div>';

    // ── List with session filter ─
    echo '<div class="postbox fpb-list-card"><div class="inside">';
    echo '<ul class="subsubsub fpb-filter-bar" style="margin-bottom:1rem">';
    echo '<li><a href="' . esc_url(admin_url('admin.php?page=sb-packages')) . '" class="fpb-filter-btn' . (! $filter ? ' current active' : '') . '">All</a> | </li>';
    $session_count = count($sessions);
    $session_idx = 0;
    foreach ($sessions as $s) {
        $session_idx++;
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=sb-packages&session=' . (int) $s->id)) . '" class="fpb-filter-btn' . ($filter === (int) $s->id ? ' current active' : '') . '">' . esc_html($s->emoji . ' ' . $s->name) . '</a>';
        if ($session_idx < $session_count) {
            echo ' | ';
        }
        echo '</li>';
    }
    echo '</ul><br class="clear" />';
    if (empty($packages)) {
        echo '<div class="fpb-empty">No packages yet.</div>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Session</th><th>Name</th><th>Price</th><th>Duration</th><th>Featured</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($packages as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->semoji . ' ' . $row->sname) . '</td>';
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>€' . esc_html(number_format((float) $row->price, 0)) . '</td>';
            echo '<td>' . esc_html($row->duration) . '</td>';
            echo '<td>' . ($row->featured ? '⭐' : '—') . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sb-packages&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-package" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    echo '</div>';
    sb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ADD-ONS
═══════════════════════════════════════════════════════════════ */
function sb_page_addons()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx     = $wpdb->prefix . 'fpb_';
    $addons  = $wpdb->get_results("SELECT a.*, p.name AS pname FROM {$pfx}addons a LEFT JOIN {$pfx}packages p ON p.id=a.package_id ORDER BY a.sort_order, a.id"); // phpcs:ignore
    $all_packages = $wpdb->get_results("SELECT p.id, p.name, s.emoji AS semoji, s.name AS sname FROM {$pfx}packages p JOIN {$pfx}sessions s ON s.id=p.session_id WHERE p.active=1 ORDER BY s.sort_order, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}addons WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    sb_wrap_open('Add-ons', 'sb-addons');
    echo '<div class="fpb-two-col">';

    echo '<div class="postbox fpb-form-card"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Add-on' : 'Add New Add-on') . '</h3>';
    echo '<form id="fpb-addon-form">';
    wp_nonce_field('fpb_admin_nonce', 'fpb_nonce');
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-field fpb-field-half"><label>Emoji</label><input class="small-text" type="text" name="emoji" maxlength="5" placeholder="🚁" value="' . esc_attr($edit_row->emoji ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Price (€) <span class="req">*</span></label><input class="small-text" type="number" name="price" required step="0.01" min="0" placeholder="150" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Name <span class="req">*</span></label><input class="regular-text" type="text" name="name" required placeholder="Drone aerial session" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Description</label><input class="regular-text" type="text" name="description" placeholder="Short description" value="' . esc_attr($edit_row->description ?? '') . '"></div>';

    // Applies-to dropdown
    echo '<div class="fpb-field"><label>Applies To <span class="req">*</span></label><select name="package_id">';
    echo '<option value="0"' . selected(0, (int) ($edit_row->package_id ?? 0), false) . '>— All Packages (global) —</option>';
    foreach ($all_packages as $pkg) {
        $sel = selected((int) ($edit_row->package_id ?? 0), (int) $pkg->id, false);
        echo '<option value="' . (int) $pkg->id . '"' . $sel . '>' . esc_html($pkg->semoji . ' ' . $pkg->sname . ' › ' . $pkg->name) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';

    echo '<div class="fpb-field fpb-field-half"><label>Sort Order</label><input class="small-text" type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field fpb-field-half"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Add-on' : 'Add Add-on') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=sb-addons')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-addon-msg"></div>';
    echo '</form></div></div>';

    echo '<div class="postbox fpb-list-card"><div class="inside">';
    if (empty($addons)) {
        echo '<div class="fpb-empty">No add-ons yet.</div>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped fpb-table"><thead><tr><th>Emoji</th><th>Name</th><th>Price</th><th>Applies To</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($addons as $row) {
            $scope = (int) $row->package_id === 0 ? '<span class="fpb-badge fpb-badge-confirmed">All Packages</span>' : esc_html($row->pname ?? '—');
            echo '<tr>';
            echo '<td>' . esc_html($row->emoji) . '</td>';
            echo '<td><strong>' . esc_html($row->name) . '</strong>' . ($row->description ? '<br><small>' . esc_html($row->description) . '</small>' : '') . '</td>';
            echo '<td>€' . esc_html(number_format((float) $row->price, 0)) . '</td>';
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
    echo '</div>';
    sb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — DATE SLOTS
═══════════════════════════════════════════════════════════════ */
function sb_page_dates()
{
    if (! current_user_can('manage_options')) return;
    sb_wrap_open('Date Slots', 'sb-dates');
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
        <span class="fpb-leg"><span class="fpb-leg-dot available"></span> Available</span>
        <span class="fpb-leg"><span class="fpb-leg-dot booked"></span> Booked</span>
        <span class="fpb-leg"><span class="fpb-leg-dot blocked"></span> Blocked by Admin</span>
        <span class="fpb-leg"><span class="fpb-leg-dot" style="background:var(--fpb-border);opacity:.5"></span> Past</span>
    </div>
    <div id="fpb-dates-msg" class="fpb-dates-msg"></div>
<?php
    sb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SETTINGS
═══════════════════════════════════════════════════════════════ */
function sb_page_settings()
{
    if (! current_user_can('manage_options')) return;

    if (isset($_POST['fpb_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fpb_settings_nonce'])), 'fpb_settings')) {
        // ── Shortcode — step labels ──────────────────────────────
        foreach (['fpb_step1_label', 'fpb_step2_label', 'fpb_step3_label', 'fpb_step4_label'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        // ── Shortcode — step titles & subtitles ──────────────────
        foreach (['fpb_step1_title', 'fpb_step1_sub', 'fpb_step2_title', 'fpb_step2_sub', 'fpb_step3_title', 'fpb_step3_sub', 'fpb_step4_title', 'fpb_step4_sub'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        echo '<div class="notice notice-success is-dismissible inline"><p>Settings saved.</p></div>';
    }

    // ── Read stored values ───────────────────────────────────────
    $step_labels = [
        'fpb_step1_label' => ['Package',   __('Step 1 Label', 'snapbook')],
        'fpb_step2_label' => ['Details',   __('Step 2 Label', 'snapbook')],
        'fpb_step3_label' => ['Contract',  __('Step 3 Label', 'snapbook')],
        'fpb_step4_label' => ['Payment',   __('Step 4 Label', 'snapbook')],
    ];
    $step_content = [
        1 => [
            'title_key' => 'fpb_step1_title',
            'title_def' => 'Choose Your Package',
            'sub_key'   => 'fpb_step1_sub',
            'sub_def'   => 'Select your preferred date, session type, and the package that suits you best.',
            'name'      => '📦 Step 1 — Package',
        ],
        2 => [
            'title_key' => 'fpb_step2_title',
            'title_def' => 'Your Details',
            'sub_key'   => 'fpb_step2_sub',
            'sub_def'   => 'All fields are required.',
            'name'      => '📋 Step 2 — Details',
        ],
        3 => [
            'title_key' => 'fpb_step3_title',
            'title_def' => 'Contract & Signature',
            'sub_key'   => 'fpb_step3_sub',
            'sub_def'   => 'Please read and sign the service agreement.',
            'name'      => '📝 Step 3 — Contract',
        ],
        4 => [
            'title_key' => 'fpb_step4_title',
            'title_def' => 'Confirm & Pay Deposit',
            'sub_key'   => 'fpb_step4_sub',
            'sub_def'   => 'Review your booking then proceed to secure checkout.',
            'name'      => '💳 Step 4 — Payment',
        ],
    ];

    sb_wrap_open('Settings', 'sb-settings');
    echo '<form method="POST">';
    wp_nonce_field('fpb_settings', 'fpb_settings_nonce');

    /* ── SECTION: Shortcode Reference ── */
    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">🔗 Shortcode Reference</h2>';
    echo '<div class="fpb-shortcode-ref">';
    echo '<p>Paste this shortcode into any page or post to display the booking form:</p>';
    echo '<div class="fpb-shortcode-copy-wrap">';
    echo '<code class="fpb-shortcode-code" id="fpb-sc-code">[focus_booking]</code>';
    echo '<button type="button" class="button button-secondary fpb-btn-sm" onclick="fpbCopyShortcode()">' . esc_html__('Copy', 'snapbook') . '</button>';
    echo '</div></div>';
    echo '<p class="fpb-field-note">The form renders a 4-step booking wizard with package selection, client details, contract signature, and payment.</p>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    /* ── SECTION: Shortcode — Step Indicator Labels ── */
    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">🪧 Shortcode — Step Indicator Labels</h2>';
    echo '<p class="fpb-section-desc">Short labels shown in the progress bar above the booking form.</p>';
    echo '<div class="fpb-form-card fpb-form-single">';
    echo '<div class="fpb-row">';
    foreach ($step_labels as $key => [$default, $label]) {
        $val = get_option($key, $default);
        echo '<div class="fpb-field fpb-field-quarter"><label>' . esc_html($label) . '</label>';
        echo '<input class="regular-text" type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" placeholder="' . esc_attr($default) . '"></div>';
    }
    echo '</div></div>';
    echo '</div>';
    echo '</div>';

    /* ── SECTION: Shortcode — Step Titles & Subtitles ── */
    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">✏️ Shortcode — Step Titles &amp; Subtitles</h2>';
    echo '<p class="fpb-section-desc">Heading and subtitle text displayed at the top of each booking step.</p>';
    foreach ($step_content as $step) {
        $title = get_option($step['title_key'], $step['title_def']);
        $sub   = get_option($step['sub_key'],   $step['sub_def']);
        echo '<div class="fpb-form-card fpb-form-single" style="margin-bottom:1rem">';
        echo '<h3 class="fpb-form-title" style="margin-bottom:.8rem">' . esc_html($step['name']) . '</h3>';
        echo '<div class="fpb-field"><label>' . esc_html__('Heading', 'snapbook') . '</label>';
        echo '<input class="regular-text" type="text" name="' . esc_attr($step['title_key']) . '" value="' . esc_attr($title) . '" placeholder="' . esc_attr($step['title_def']) . '">' . "</div>";
        echo '<div class="fpb-field"><label>' . esc_html__('Subtitle', 'snapbook') . '</label>';
        echo '<input class="regular-text" type="text" name="' . esc_attr($step['sub_key']) . '" value="' . esc_attr($sub) . '" placeholder="' . esc_attr($step['sub_def']) . '"></div>';
        echo '</div>';
    }
    echo '</div></div>';

    /* ── SECTION: Form Save Button ── */
    echo '<div class="fpb-form-actions" style="padding:0 0 2rem">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . esc_html__('Save All Settings', 'snapbook') . '</button>';
    echo '</div>';
    echo '</form>';

    // Inline JS for copy shortcode button
    echo '<script>
function fpbCopyShortcode(){
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

    sb_wrap_close();
}
