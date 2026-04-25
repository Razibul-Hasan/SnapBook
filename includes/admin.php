<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', 'fpb_admin_menu');
function fpb_admin_menu()
{
    add_menu_page(
        __('FP Booking', 'focus-photography-booking'),
        __('FP Booking', 'focus-photography-booking'),
        'manage_options',
        'fpb-bookings',
        'fpb_page_bookings',
        'dashicons-camera',
        30
    );
    add_submenu_page('fpb-bookings', __('All Bookings', 'focus-photography-booking'),    __('All Bookings', 'focus-photography-booking'),    'manage_options', 'fpb-bookings',       'fpb_page_bookings');
    add_submenu_page('fpb-bookings', __('Session Types', 'focus-photography-booking'),   __('Session Types', 'focus-photography-booking'),   'manage_options', 'fpb-sessions',       'fpb_page_sessions');
    add_submenu_page('fpb-bookings', __('Packages', 'focus-photography-booking'),        __('Packages', 'focus-photography-booking'),        'manage_options', 'fpb-packages',       'fpb_page_packages');
    add_submenu_page('fpb-bookings', __('Add-ons', 'focus-photography-booking'),         __('Add-ons', 'focus-photography-booking'),         'manage_options', 'fpb-addons',         'fpb_page_addons');
    add_submenu_page('fpb-bookings', __('Date Slots', 'focus-photography-booking'),      __('Date Slots', 'focus-photography-booking'),      'manage_options', 'fpb-dates',          'fpb_page_dates');
    add_submenu_page('fpb-bookings', __('Settings', 'focus-photography-booking'),        __('Settings', 'focus-photography-booking'),        'manage_options', 'fpb-settings',       'fpb_page_settings');
}

/* ─── Admin assets ─────────────────────────────────────────── */
add_action('admin_enqueue_scripts', 'fpb_admin_assets');
function fpb_admin_assets($hook)
{
    if (strpos($hook, 'fpb-') === false) return;
    wp_enqueue_style('fpb-admin-css', FPB_URL . 'assets/css/admin.css', [], FPB_VER);
    wp_enqueue_script('fpb-admin-js',    FPB_URL . 'assets/js/admin.js',   [], FPB_VER, true);
    wp_localize_script('fpb-admin-js', 'fpbAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('fpb_admin_nonce'),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   HELPER — shared page wrapper
═══════════════════════════════════════════════════════════════ */
function fpb_wrap_open($title, $active_tab = '')
{
    $tabs = [
        'fpb-bookings' => '📋 Bookings',
        'fpb-sessions' => '🎭 Session Types',
        'fpb-packages' => '📦 Packages',
        'fpb-addons'   => '✨ Add-ons',
        'fpb-dates'    => '📅 Date Slots',
        'fpb-settings' => '⚙️ Settings',
    ];
    echo '<div class="wrap fpb-admin-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
    echo '<p class="description" style="margin:6px 0 14px;">FP Booking v' . esc_html(FPB_VER) . '</p>';
    echo '<h2 class="nav-tab-wrapper wp-clearfix fpb-admin-tabs">';
    foreach ($tabs as $slug => $label) {
        $url    = admin_url('admin.php?page=' . $slug);
        $active = ($slug === $active_tab) ? ' nav-tab-active active' : '';
        echo '<a href="' . esc_url($url) . '" class="nav-tab fpb-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
    echo '<div class="fpb-admin-body">';
}
function fpb_wrap_close()
{
    echo '</div></div>';
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ALL BOOKINGS
═══════════════════════════════════════════════════════════════ */
function fpb_page_bookings()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $status   = sanitize_text_field(wp_unslash($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $status ? $wpdb->prepare('WHERE status = %s', $status) : '';
    $bookings = $wpdb->get_results("SELECT * FROM {$pfx}bookings {$where} ORDER BY created_at DESC LIMIT 200"); // phpcs:ignore

    fpb_wrap_open('All Bookings', 'fpb-bookings');
    // Status filter
    $statuses = ['' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'];
    echo '<ul class="subsubsub fpb-filter-bar">';
    $i = 0;
    $total_statuses = count($statuses);
    foreach ($statuses as $k => $v) {
        $url = admin_url('admin.php?page=fpb-bookings' . ($k ? '&status=' . $k : ''));
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
            echo '<td><span class="fpb-badge fpb-badge-' . esc_attr($b->status) . '">' . esc_html(ucfirst($b->status)) . '</span></td>';
            $order_link = $b->order_id ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $b->order_id . '&action=edit')) . '">#' . (int) $b->order_id . '</a>' : '—';
            echo '<td>' . wp_kses_post($order_link) . '</td>';
            echo '<td>';
            echo '<button class="button button-secondary fpb-btn-sm fpb-btn-view" data-id="' . (int) $b->id . '">View</button> ';
            echo '<select class="fpb-status-select" data-id="' . (int) $b->id . '">';
            foreach (['pending', 'confirmed', 'cancelled'] as $st) {
                echo '<option value="' . esc_attr($st) . '"' . selected($b->status, $st, false) . '>' . esc_html(ucfirst($st)) . '</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // View modal
    echo '<div id="fpb-booking-modal" class="fpb-modal" style="display:none"><div class="fpb-modal-inner"><div class="fpb-modal-head"><span>Booking</span><button class="fpb-modal-close" aria-label="Close">✕</button></div><div class="fpb-modal-body"></div></div></div>';
    // Inline booking data for JS
    echo '<script>var fpbBookings=' . wp_json_encode(array_values($bookings)) . ';</script>';
    fpb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SESSION TYPES
═══════════════════════════════════════════════════════════════ */
function fpb_page_sessions()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions ORDER BY sort_order, id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    fpb_wrap_open('Session Types', 'fpb-sessions');
    echo '<div class="fpb-two-col">';

    // ── Add / Edit form ─
    echo '<div class="postbox fpb-form-card"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Session Type' : 'Add New Session Type') . '</h3>';
    echo '<form id="fpb-session-form">';
    wp_nonce_field('fpb_admin_nonce', 'fpb_nonce');
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-field"><label>Emoji</label><input type="text" name="emoji" maxlength="5" placeholder="📷" value="' . esc_attr($edit_row->emoji ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Name <span class="req">*</span></label><input type="text" id="fpb-session-name" name="name" required placeholder="Holiday / Couple Photoshoot" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Slug <span class="req">*</span></label><input type="text" id="fpb-session-slug" name="slug" required placeholder="photo" value="' . esc_attr($edit_row->slug ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Sort Order</label><input type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn" id="fpb-session-save">' . ($edit_row ? 'Update' : 'Add Session Type') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-sessions')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
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
            echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-sessions&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-session" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    echo '</div>'; // fpb-two-col
    fpb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — PACKAGES
═══════════════════════════════════════════════════════════════ */
function fpb_page_packages()
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

    fpb_wrap_open('Packages', 'fpb-packages');
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
    echo '<div class="fpb-field"><label>Package Name <span class="req">*</span></label><input type="text" name="name" required placeholder="Golden Hour" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Price (€) <span class="req">*</span></label><input type="number" name="price" required step="0.01" min="0" placeholder="199" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Duration</label><input type="text" name="duration" placeholder="1hr · 30 photos" value="' . esc_attr($edit_row->duration ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Description</label><input type="text" name="description" placeholder="Short tagline shown on card" value="' . esc_attr($edit_row->description ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Sort Order</label><input type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field fpb-field-half"><label><input type="checkbox" name="featured" value="1"' . (isset($edit_row->featured) ? checked(1, $edit_row->featured, false) : '') . '> Featured ⭐</label></div>';
    echo '<div class="fpb-field"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Package' : 'Add Package') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-packages')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
    echo '</div><div class="fpb-form-msg" id="fpb-package-msg"></div>';
    echo '</form></div></div>';

    // ── List with session filter ─
    echo '<div class="postbox fpb-list-card"><div class="inside">';
    echo '<ul class="subsubsub fpb-filter-bar" style="margin-bottom:1rem">';
    echo '<li><a href="' . esc_url(admin_url('admin.php?page=fpb-packages')) . '" class="fpb-filter-btn' . (! $filter ? ' current active' : '') . '">All</a> | </li>';
    $session_count = count($sessions);
    $session_idx = 0;
    foreach ($sessions as $s) {
        $session_idx++;
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=fpb-packages&session=' . (int) $s->id)) . '" class="fpb-filter-btn' . ($filter === (int) $s->id ? ' current active' : '') . '">' . esc_html($s->emoji . ' ' . $s->name) . '</a>';
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
            echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-packages&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-package" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    echo '</div>';
    fpb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ADD-ONS
═══════════════════════════════════════════════════════════════ */
function fpb_page_addons()
{
    if (! current_user_can('manage_options')) return;
    global $wpdb;
    $pfx     = $wpdb->prefix . 'fpb_';
    $addons  = $wpdb->get_results("SELECT a.*, p.name AS pname FROM {$pfx}addons a LEFT JOIN {$pfx}packages p ON p.id=a.package_id ORDER BY a.sort_order, a.id"); // phpcs:ignore
    $all_packages = $wpdb->get_results("SELECT p.id, p.name, s.emoji AS semoji, s.name AS sname FROM {$pfx}packages p JOIN {$pfx}sessions s ON s.id=p.session_id WHERE p.active=1 ORDER BY s.sort_order, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}addons WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    fpb_wrap_open('Add-ons', 'fpb-addons');
    echo '<div class="fpb-two-col">';

    echo '<div class="postbox fpb-form-card"><div class="inside">';
    echo '<h3 class="fpb-form-title">' . ($edit_row ? 'Edit Add-on' : 'Add New Add-on') . '</h3>';
    echo '<form id="fpb-addon-form">';
    wp_nonce_field('fpb_admin_nonce', 'fpb_nonce');
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="fpb-field fpb-field-half"><label>Emoji</label><input type="text" name="emoji" maxlength="5" placeholder="🚁" value="' . esc_attr($edit_row->emoji ?? '') . '"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>Price (€) <span class="req">*</span></label><input type="number" name="price" required step="0.01" min="0" placeholder="150" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Name <span class="req">*</span></label><input type="text" name="name" required placeholder="Drone aerial session" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    echo '<div class="fpb-field"><label>Description</label><input type="text" name="description" placeholder="Short description" value="' . esc_attr($edit_row->description ?? '') . '"></div>';

    // Applies-to dropdown
    echo '<div class="fpb-field"><label>Applies To <span class="req">*</span></label><select name="package_id">';
    echo '<option value="0"' . selected(0, (int) ($edit_row->package_id ?? 0), false) . '>— All Packages (global) —</option>';
    foreach ($all_packages as $pkg) {
        $sel = selected((int) ($edit_row->package_id ?? 0), (int) $pkg->id, false);
        echo '<option value="' . (int) $pkg->id . '"' . $sel . '>' . esc_html($pkg->semoji . ' ' . $pkg->sname . ' › ' . $pkg->name) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';

    echo '<div class="fpb-field fpb-field-half"><label>Sort Order</label><input type="number" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0"></div>';
    echo '<div class="fpb-field fpb-field-half"><label><input type="checkbox" name="active" value="1"' . (isset($edit_row->active) ? checked(1, $edit_row->active, false) : ' checked') . '> Active</label></div>';
    echo '<div class="fpb-form-actions">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . ($edit_row ? 'Update Add-on' : 'Add Add-on') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-addons')) . '" class="button fpb-btn fpb-btn-ghost">Cancel</a>';
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
            echo '<a href="' . esc_url(admin_url('admin.php?page=fpb-addons&edit=' . (int) $row->id)) . '" class="button button-small fpb-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete fpb-btn-sm fpb-btn-danger fpb-del-addon" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    echo '</div>';
    fpb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — DATE SLOTS
═══════════════════════════════════════════════════════════════ */
function fpb_page_dates()
{
    if (! current_user_can('manage_options')) return;
    fpb_wrap_open('Date Slots', 'fpb-dates');
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
    fpb_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SETTINGS
═══════════════════════════════════════════════════════════════ */
function fpb_page_settings()
{
    if (! current_user_can('manage_options')) return;

    if (isset($_POST['fpb_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fpb_settings_nonce'])), 'fpb_settings')) {
        // ── General ─────────────────────────────────────────────
        update_option('fpb_whatsapp',     sanitize_text_field(wp_unslash($_POST['fpb_whatsapp']     ?? '')));
        update_option('fpb_deposit_pct',  absint($_POST['fpb_deposit_pct']  ?? 50));
        update_option('fpb_currency_sym', sanitize_text_field(wp_unslash($_POST['fpb_currency_sym'] ?? '€')));
        update_option('fpb_admin_email',  sanitize_email(wp_unslash($_POST['fpb_admin_email']  ?? '')));
        // ── Shortcode — step labels ──────────────────────────────
        foreach (['fpb_step1_label', 'fpb_step2_label', 'fpb_step3_label', 'fpb_step4_label'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        // ── Shortcode — step titles & subtitles ──────────────────
        foreach (['fpb_step1_title', 'fpb_step1_sub', 'fpb_step2_title', 'fpb_step2_sub', 'fpb_step3_title', 'fpb_step3_sub', 'fpb_step4_title', 'fpb_step4_sub'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        // ── Shortcode — success & WhatsApp CTA ───────────────────
        update_option('fpb_success_title',   sanitize_text_field(wp_unslash($_POST['fpb_success_title']   ?? '')));
        update_option('fpb_success_msg',     sanitize_textarea_field(wp_unslash($_POST['fpb_success_msg'] ?? '')));
        update_option('fpb_whatsapp_btn',    sanitize_text_field(wp_unslash($_POST['fpb_whatsapp_btn']    ?? '')));
        echo '<div class="notice notice-success is-dismissible inline"><p>Settings saved.</p></div>';
    }

    // ── Read stored values ───────────────────────────────────────
    $wa      = get_option('fpb_whatsapp',     '23059355040');
    $dep     = get_option('fpb_deposit_pct',  50);
    $cur     = get_option('fpb_currency_sym', '€');
    $adm_em  = get_option('fpb_admin_email',  get_option('admin_email'));
    $wc_pid  = (int) get_option('fpb_wc_product_id', 0);

    $step_labels = [
        'fpb_step1_label' => ['Package',   __('Step 1 Label', 'focus-photography-booking')],
        'fpb_step2_label' => ['Details',   __('Step 2 Label', 'focus-photography-booking')],
        'fpb_step3_label' => ['Contract',  __('Step 3 Label', 'focus-photography-booking')],
        'fpb_step4_label' => ['Payment',   __('Step 4 Label', 'focus-photography-booking')],
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

    fpb_wrap_open('Settings', 'fpb-settings');
    echo '<form method="POST">';
    wp_nonce_field('fpb_settings', 'fpb_settings_nonce');

    /* ── SECTION: Shortcode Reference ── */
    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">🔗 Shortcode Reference</h2>';
    echo '<div class="fpb-shortcode-ref">';
    echo '<p>Paste this shortcode into any page or post to display the booking form:</p>';
    echo '<div class="fpb-shortcode-copy-wrap">';
    echo '<code class="fpb-shortcode-code" id="fpb-sc-code">[focus_booking]</code>';
    echo '<button type="button" class="button button-secondary fpb-btn-sm" onclick="fpbCopyShortcode()">' . esc_html__('Copy', 'focus-photography-booking') . '</button>';
    echo '</div></div>';
    echo '<p class="fpb-field-note">The form renders a 4-step booking wizard with package selection, client details, contract signature, and payment.</p>';
    echo '</div>';
    echo '</div>';

    /* ── SECTION: General ── */
    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">⚙️ General Settings</h2>';
    echo '<div class="fpb-form-card fpb-form-single">';
    echo '<div class="fpb-field"><label>' . esc_html__('WhatsApp Number', 'focus-photography-booking') . '</label>';
    echo '<input type="text" name="fpb_whatsapp" value="' . esc_attr($wa) . '" placeholder="23059355040">';
    echo '<p class="fpb-field-note">International format without +. Used for the WhatsApp CTA on the success screen.</p></div>';
    echo '<div class="fpb-row">';
    echo '<div class="fpb-field fpb-field-half"><label>' . esc_html__('Deposit %', 'focus-photography-booking') . '</label><input type="number" name="fpb_deposit_pct" value="' . esc_attr($dep) . '" min="1" max="100"></div>';
    echo '<div class="fpb-field fpb-field-half"><label>' . esc_html__('Currency Symbol', 'focus-photography-booking') . '</label><input type="text" name="fpb_currency_sym" value="' . esc_attr($cur) . '" maxlength="5" placeholder="€"></div>';
    echo '</div>';
    echo '<div class="fpb-field"><label>' . esc_html__('Admin Notification Email', 'focus-photography-booking') . '</label>';
    echo '<input type="email" name="fpb_admin_email" value="' . esc_attr($adm_em) . '"></div>';
    if ($wc_pid) {
        echo '<div class="fpb-field"><label>' . esc_html__('WooCommerce Product', 'focus-photography-booking') . '</label>';
        echo '<p class="fpb-field-note">Deposit product ID: <a href="' . esc_url(admin_url('post.php?post=' . $wc_pid . '&action=edit')) . '" target="_blank">#' . absint($wc_pid) . '</a> — do not delete this product.</p></div>';
    } elseif (class_exists('WooCommerce')) {
        echo '<div class="fpb-field"><label>' . esc_html__('WooCommerce Product', 'focus-photography-booking') . '</label>';
        echo '<p class="fpb-field-note">No product set. <button type="button" onclick="fpbAdminCreateProduct()" class="button button-secondary fpb-btn-sm">Create Now</button></p></div>';
    }
    echo '</div></div>';
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
        echo '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" placeholder="' . esc_attr($default) . '"></div>';
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
        echo '<div class="fpb-field"><label>' . esc_html__('Heading', 'focus-photography-booking') . '</label>';
        echo '<input type="text" name="' . esc_attr($step['title_key']) . '" value="' . esc_attr($title) . '" placeholder="' . esc_attr($step['title_def']) . '"></div>';
        echo '<div class="fpb-field"><label>' . esc_html__('Subtitle', 'focus-photography-booking') . '</label>';
        echo '<input type="text" name="' . esc_attr($step['sub_key']) . '" value="' . esc_attr($sub) . '" placeholder="' . esc_attr($step['sub_def']) . '"></div>';
        echo '</div>';
    }
    echo '</div></div>';

    /* ── SECTION: Shortcode — Success Screen ── */
    $success_title = get_option('fpb_success_title', 'Booking Requested!');
    $success_msg   = get_option('fpb_success_msg',   "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to your email.");
    $wa_btn        = get_option('fpb_whatsapp_btn',  'Message us on WhatsApp');

    echo '<div class="postbox fpb-settings-section"><div class="inside">';
    echo '<h2 class="fpb-section-title">🎉 Shortcode — Success Screen</h2>';
    echo '<p class="fpb-section-desc">Text shown after a booking is successfully submitted.</p>';
    echo '<div class="fpb-form-card fpb-form-single">';
    echo '<div class="fpb-field"><label>' . esc_html__('Success Heading', 'focus-photography-booking') . '</label>';
    echo '<input type="text" name="fpb_success_title" value="' . esc_attr($success_title) . '" placeholder="Booking Requested!"></div>';
    echo '<div class="fpb-field"><label>' . esc_html__('Success Message', 'focus-photography-booking') . '</label>';
    echo '<textarea name="fpb_success_msg" rows="3" style="width:100%;resize:vertical">' . esc_textarea($success_msg) . '</textarea>';
    echo '<p class="fpb-field-note">The client\'s email address is automatically appended at the end.</p></div>';
    echo '<div class="fpb-field"><label>' . esc_html__('WhatsApp Button Text', 'focus-photography-booking') . '</label>';
    echo '<input type="text" name="fpb_whatsapp_btn" value="' . esc_attr($wa_btn) . '" placeholder="Message us on WhatsApp"></div>';
    echo '</div></div>';
    echo '</div>';

    echo '<div class="fpb-form-actions" style="padding:0 0 2rem">';
    echo '<button type="submit" class="button button-primary fpb-btn">' . esc_html__('Save All Settings', 'focus-photography-booking') . '</button>';
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

    fpb_wrap_close();
}
