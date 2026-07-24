<?php
defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Checkout form — field catalog & saved configuration
   The catalog is the fixed list of known fields; the admin
   "Checkout Form" settings store per-field enabled/required/label
   overrides in the fpb_checkout_form_fields option.
───────────────────────────────────────────────────────────── */
function snapbook_checkout_field_catalog()
{
    return [
        'first_name'   => ['label' => __('First name', 'snapbook'),                              'type' => 'text',     'ph' => __('First name', 'snapbook'),                     'required' => 1, 'locked' => 1],
        'last_name'    => ['label' => __('Last name', 'snapbook'),                               'type' => 'text',     'ph' => __('Last name', 'snapbook'),                      'required' => 1],
        'email'        => ['label' => __('Email address', 'snapbook'),                           'type' => 'email',    'ph' => 'you@example.com',                                'required' => 1, 'locked' => 1],
        'phone'        => ['label' => __('Whatsapp', 'snapbook'),                                'type' => 'tel',      'ph' => '+230 5xxx xxxx',                                 'required' => 1],
        'country'      => ['label' => __('Country / Region', 'snapbook'),                        'type' => 'country',  'ph' => __('Country', 'snapbook'),                        'required' => 1],
        'address_1'    => ['label' => __('Street address', 'snapbook'),                          'type' => 'text',     'ph' => __('House number and street name', 'snapbook'),  'required' => 1, 'wide' => 1],
        'city'         => ['label' => __('Town / City', 'snapbook'),                             'type' => 'text',     'ph' => __('City', 'snapbook'),                           'required' => 1],
        'postcode'     => ['label' => __('Postcode / ZIP', 'snapbook'),                          'type' => 'text',     'ph' => __('Postcode', 'snapbook'),                       'required' => 1],
        'event_time'   => ['label' => __('Start Time', 'snapbook'),                              'type' => 'time',     'ph' => __('Time of the event', 'snapbook'),              'required' => 1],
        'hotel_place'  => ['label' => __('Hotel Name / Bungalow / Place Residence', 'snapbook'), 'type' => 'text',     'ph' => __('Hotel / Bungalow / Place', 'snapbook'),       'required' => 1, 'wide' => 1],
        'participants' => ['label' => __('Participants', 'snapbook'),                            'type' => 'number',   'ph' => __('Number of People', 'snapbook'),               'required' => 1],
        'room_number'  => ['label' => __('Room Number', 'snapbook'),                             'type' => 'number',   'ph' => __('Room number', 'snapbook'),                    'required' => 0],
        'stay_period'  => ['label' => __('Period of stay in Mauritius', 'snapbook'),             'type' => 'text',     'ph' => __('From - To', 'snapbook'),                      'required' => 1, 'wide' => 1],
        'notes'        => ['label' => __('Notes', 'snapbook'),                                   'type' => 'textarea', 'ph' => __('Anything else we should know?', 'snapbook'), 'required' => 0, 'wide' => 1],
    ];
}

function snapbook_get_checkout_form_fields()
{
    $catalog = snapbook_checkout_field_catalog();
    $saved   = get_option('fpb_checkout_form_fields', []);
    if (! is_array($saved)) {
        $saved = [];
    }

    $out = [];
    foreach ($catalog as $key => $def) {
        $row    = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
        $locked = ! empty($def['locked']);

        $enabled  = $locked ? 1 : (array_key_exists('enabled', $row) ? (int) ! empty($row['enabled']) : 1);
        $required = $locked ? 1 : (array_key_exists('required', $row) ? (int) ! empty($row['required']) : (int) ! empty($def['required']));
        $label    = isset($row['label']) && $row['label'] !== '' ? (string) $row['label'] : $def['label'];

        $out[$key] = [
            'enabled'  => $enabled,
            'required' => $enabled ? $required : 0,
            'label'    => $label,
        ];
    }

    return $out;
}

function snapbook_sanitize_checkout_mode($mode)
{
    $mode = sanitize_key((string) $mode);
    return in_array($mode, ['direct', 'redirect'], true) ? $mode : 'direct';
}

function snapbook_get_checkout_mode()
{
    return snapbook_sanitize_checkout_mode(get_option('fpb_checkout_mode', 'direct'));
}

/**
 * Build the fpb_checkout_form_fields option value from a settings-form POST.
 * Expects fpb_cf_enabled[key], fpb_cf_required[key], fpb_cf_label[key].
 */
function snapbook_sanitize_checkout_field_config($post)
{
    $catalog  = snapbook_checkout_field_catalog();
    $enabled  = isset($post['fpb_cf_enabled']) && is_array($post['fpb_cf_enabled']) ? $post['fpb_cf_enabled'] : [];
    $required = isset($post['fpb_cf_required']) && is_array($post['fpb_cf_required']) ? $post['fpb_cf_required'] : [];
    $labels   = isset($post['fpb_cf_label']) && is_array($post['fpb_cf_label']) ? $post['fpb_cf_label'] : [];

    $out = [];
    foreach ($catalog as $key => $def) {
        $locked = ! empty($def['locked']);
        $on     = $locked ? 1 : ((int) ($enabled[$key] ?? 0) === 1 ? 1 : 0);
        $req    = $locked ? 1 : ($on && (int) ($required[$key] ?? 0) === 1 ? 1 : 0);
        $label  = sanitize_text_field(wp_unslash($labels[$key] ?? ''));
        if ($label === '') {
            $label = $def['label'];
        }
        $out[$key] = ['enabled' => $on, 'required' => $req, 'label' => $label];
    }

    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Custom checkout fields — admin-created fields (add/remove)
   stored in the fpb_checkout_custom_fields option as
   [key => ['label','type','required']].
───────────────────────────────────────────────────────────── */
function snapbook_custom_checkout_field_types()
{
    return [
        'text'     => __('Text', 'snapbook'),
        'textarea' => __('Textarea', 'snapbook'),
        'number'   => __('Number', 'snapbook'),
        'email'    => __('Email', 'snapbook'),
        'tel'      => __('Phone', 'snapbook'),
        'date'     => __('Date', 'snapbook'),
        'time'     => __('Time', 'snapbook'),
    ];
}

function snapbook_get_custom_checkout_fields()
{
    $saved = get_option('fpb_checkout_custom_fields', []);
    if (! is_array($saved)) {
        return [];
    }

    $types = snapbook_custom_checkout_field_types();
    $out   = [];
    foreach ($saved as $key => $row) {
        if (! is_array($row)) {
            continue;
        }
        $key   = sanitize_key($key);
        $label = isset($row['label']) ? (string) $row['label'] : '';
        if ($key === '' || $label === '') {
            continue;
        }
        $out[$key] = [
            'label'    => $label,
            'type'     => isset($row['type'], $types[$row['type']]) ? $row['type'] : 'text',
            'required' => ! empty($row['required']) ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * Build the fpb_checkout_custom_fields option value from a settings POST.
 * Rows arrive as fpb_ccf_label[key], fpb_ccf_type[key], fpb_ccf_required[key];
 * rows removed in the UI are simply absent, so they get dropped here.
 */
function snapbook_sanitize_custom_checkout_fields($post)
{
    $labels   = isset($post['fpb_ccf_label']) && is_array($post['fpb_ccf_label']) ? $post['fpb_ccf_label'] : [];
    $types_in = isset($post['fpb_ccf_type']) && is_array($post['fpb_ccf_type']) ? $post['fpb_ccf_type'] : [];
    $reqs     = isset($post['fpb_ccf_required']) && is_array($post['fpb_ccf_required']) ? $post['fpb_ccf_required'] : [];
    $types    = snapbook_custom_checkout_field_types();

    $out = [];
    foreach ($labels as $orig_key => $label) {
        $label = sanitize_text_field(wp_unslash($label));
        if ($label === '') {
            continue;
        }

        $key = sanitize_key($orig_key);
        if ($key === '' || strpos($key, 'new_') === 0) {
            // Newly added row: derive a stable key from the label.
            $key = sanitize_key(str_replace('-', '_', sanitize_title($label)));
            if ($key === '') {
                $key = 'field';
            }
        }
        $base = $key;
        $i    = 2;
        while (isset($out[$key])) {
            $key = $base . '_' . $i++;
        }

        $type = isset($types_in[$orig_key], $types[$types_in[$orig_key]]) ? $types_in[$orig_key] : 'text';
        $out[$key] = [
            'label'    => $label,
            'type'     => $type,
            'required' => (int) ($reqs[$orig_key] ?? 0) === 1 ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * Sanitize the details[] array posted by the booking form,
 * keyed and typed according to the field catalog.
 */
function snapbook_sanitize_checkout_details($raw)
{
    if (! is_array($raw)) {
        return [];
    }

    $catalog = snapbook_checkout_field_catalog();
    $fields  = snapbook_get_checkout_form_fields();
    $out     = [];

    foreach ($fields as $key => $f) {
        if (empty($f['enabled'])) {
            continue;
        }
        $value = isset($raw[$key]) ? wp_unslash($raw[$key]) : '';
        switch ($catalog[$key]['type']) {
            case 'email':
                $value = sanitize_email($value);
                break;
            case 'textarea':
                $value = sanitize_textarea_field($value);
                break;
            case 'number':
                $value = ($value === '' ? '' : (string) absint($value));
                break;
            default:
                $value = sanitize_text_field($value);
        }
        $out[$key] = $value;
    }

    // Admin-created custom fields travel namespaced as cf_{key}.
    foreach (snapbook_get_custom_checkout_fields() as $key => $f) {
        $pkey  = 'cf_' . $key;
        $value = isset($raw[$pkey]) ? wp_unslash($raw[$pkey]) : '';
        switch ($f['type']) {
            case 'email':
                $value = sanitize_email($value);
                break;
            case 'textarea':
                $value = sanitize_textarea_field($value);
                break;
            default:
                $value = sanitize_text_field($value);
        }
        $out[$pkey] = $value;
    }

    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Theme colors — admin-selected brand colors for the booking
   form, injected as CSS-variable overrides on .fpb-wrap.
───────────────────────────────────────────────────────────── */
function snapbook_default_theme_colors()
{
    return ['primary' => '#b8956a', 'accent' => '#3d6b78'];
}

function snapbook_get_theme_colors()
{
    $defaults = snapbook_default_theme_colors();
    $primary  = sanitize_hex_color(get_option('fpb_theme_primary', $defaults['primary']));
    $accent   = sanitize_hex_color(get_option('fpb_theme_accent', $defaults['accent']));
    return [
        'primary' => $primary ?: $defaults['primary'],
        'accent'  => $accent ?: $defaults['accent'],
    ];
}

/**
 * Mix a hex color toward another (e.g. white to lighten, black to darken).
 * $ratio is the weight of $mix_hex (0..1).
 */
function snapbook_hex_mix($hex, $mix_hex, $ratio)
{
    $h = ltrim($hex, '#');
    $m = ltrim($mix_hex, '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (strlen($m) === 3) {
        $m = $m[0] . $m[0] . $m[1] . $m[1] . $m[2] . $m[2];
    }
    if (strlen($h) !== 6 || strlen($m) !== 6) {
        return $hex;
    }
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $c1   = hexdec(substr($h, $i * 2, 2));
        $c2   = hexdec(substr($m, $i * 2, 2));
        $out .= str_pad(dechex((int) round($c1 * (1 - $ratio) + $c2 * $ratio)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Build a CSS-variable override block for a given primary/accent pair,
 * scoped to $selector. The derived shades match snapbook_theme_css() exactly,
 * so per-instance shortcode overrides and the global Appearance setting
 * stay visually consistent. Returns '' when the colors are empty or invalid.
 */
function snapbook_theme_vars_css($primary, $accent, $selector = '.fpb-wrap')
{
    $primary = trim((string) $primary);
    $accent  = trim((string) $accent);
    $valid   = static function ($hex) {
        return (bool) preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $hex);
    };
    if (! $valid($primary) && ! $valid($accent)) {
        return '';
    }

    $vars = '';
    if ($valid($primary)) {
        $vars .= sprintf(
            '--fpb-gold:%s;--fpb-gold-dk:%s;--fpb-gold-lt:%s;',
            $primary,
            snapbook_hex_mix($primary, '#000000', 0.18),
            snapbook_hex_mix($primary, '#ffffff', 0.72)
        );
    }
    if ($valid($accent)) {
        $vars .= sprintf(
            '--fpb-teal:%s;--fpb-teal-lt:%s;',
            $accent,
            snapbook_hex_mix($accent, '#ffffff', 0.86)
        );
    }

    return sprintf('%s{%s}', $selector, $vars);
}

/**
 * Build the global CSS-variable override block. Returns '' when the admin
 * hasn't customized anything, so the stylesheet's hand-tuned palette
 * stays byte-identical by default.
 */
function snapbook_theme_css()
{
    $defaults = snapbook_default_theme_colors();
    $colors   = snapbook_get_theme_colors();
    if (strtolower($colors['primary']) === $defaults['primary'] && strtolower($colors['accent']) === $defaults['accent']) {
        return '';
    }

    return snapbook_theme_vars_css($colors['primary'], $colors['accent'], '.fpb-wrap');
}

/* ─────────────────────────────────────────────────────────────
   Booking catalog
───────────────────────────────────────────────────────────── */
/**
 * Session types, packages and add-ons offered on the booking form.
 *
 * Shared by the shortcode (which inlines this into the page so the form
 * paints without waiting on admin-ajax) and by snapbook_ajax_get_data()
 * (the fallback for renders that never got the inline copy). Availability
 * dates are deliberately NOT here: they must stay a live request, or a
 * cached page would offer dates that have since been booked.
 */
function snapbook_get_catalog_data()
{
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';

    return [
        'sessions' => $wpdb->get_results("SELECT id, name, emoji, slug FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
        'packages' => $wpdb->get_results("SELECT id, session_id, name, slug, price, duration, description, featured FROM {$pfx}packages WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
        'addons'   => $wpdb->get_results("SELECT id, name, price, emoji, description, package_id, package_ids FROM {$pfx}addons WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
    ];
}

/* ─────────────────────────────────────────────────────────────
   Register assets & shortcode
───────────────────────────────────────────────────────────── */
add_action('init', 'snapbook_register_assets');
function snapbook_register_assets()
{
    wp_register_style(
        'snapbook-booking',
        SNAPBOOK_URL . 'assets/css/booking.css',
        [],
        SNAPBOOK_VER
    );
    $icon_lib = snapbook_icon_library_url();
    if ($icon_lib !== '') {
        wp_register_style('snapbook-icons', $icon_lib, [], SNAPBOOK_VER);
    }
    // Deferred so the script never blocks first paint. On WP < 6.3 the
    // array argument gracefully degrades to a plain footer script.
    wp_register_script(
        'snapbook-booking',
        SNAPBOOK_URL . 'assets/js/booking.js',
        [],
        SNAPBOOK_VER,
        ['in_footer' => true, 'strategy' => 'defer']
    );
}

function snapbook_enqueue_booking_assets()
{
    wp_enqueue_style('snapbook-booking');
    // Dashicons render the optional "dashicons dashicons-*" icon values.
    wp_enqueue_style('dashicons');
    if (wp_style_is('snapbook-icons', 'registered')) {
        wp_enqueue_style('snapbook-icons');
    }
    wp_enqueue_script('snapbook-booking');

    // Attach the admin's Appearance color override here, as the stylesheet is
    // enqueued, so it prints together with the stylesheet in <head>. Adding it
    // later (during the shortcode render, in the page body) is too late — by
    // then snapbook_maybe_enqueue_assets() has already printed the stylesheet
    // in <head>, so the inline override would be silently dropped. The static
    // guard keeps a second enqueue from stacking a duplicate block.
    static $theme_added = false;
    if (! $theme_added) {
        $theme_css = snapbook_theme_css();
        if ($theme_css !== '') {
            wp_add_inline_style('snapbook-booking', $theme_css);
        }
        $theme_added = true;
    }
}

/**
 * Lazy, conditional loading: assets are enqueued only on pages that
 * actually contain the booking form. Enqueueing here (instead of only
 * inside the shortcode callback) lets the stylesheet print in <head>
 * on the first page load, avoiding a flash of unstyled form. Pages that
 * render the shortcode another way (e.g. a page-builder widget) are
 * still covered by the enqueue inside the shortcode callback itself.
 */
add_action('wp_enqueue_scripts', 'snapbook_maybe_enqueue_assets');
function snapbook_maybe_enqueue_assets()
{
    if (! is_singular()) {
        return;
    }
    $post = get_post();
    if (! $post) {
        return;
    }
    $content = (string) $post->post_content;
    if (has_shortcode($content, 'snapbook') || has_shortcode($content, 'focus_booking')) {
        snapbook_enqueue_booking_assets();
    }
}

add_shortcode('snapbook', 'snapbook_shortcode');
add_shortcode('focus_booking', 'snapbook_shortcode');
function snapbook_shortcode($atts)
{
    $atts = shortcode_atts([
        'package' => '',
        'primary' => '',
        'accent'  => '',
    ], $atts, 'snapbook');

    snapbook_enqueue_booking_assets();

    // Per-instance color overrides from the shortcode's primary/accent
    // attributes. Emitted inline in the output below (not via
    // wp_add_inline_style) because the stylesheet is usually already printed
    // in <head> by the time the shortcode runs, so an enqueued inline style
    // would be dropped. Shortcodes run after wpautop, so the <style> tag is
    // left untouched.
    $instance_css = snapbook_theme_vars_css($atts['primary'], $atts['accent'], '.fpb-wrap');

    $has_wc = class_exists('WooCommerce');
    $local_data = [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('snapbook_nonce'),
        'hasWC'      => $has_wc,
        'checkoutMode' => snapbook_get_checkout_mode(),
        'currency'   => snapbook_get_currency_symbol(),
        'depositPct' => ((int) get_option('fpb_enable_partial_payment', 1) === 1 ? 50 : 100),
        'partialPaymentEnabled' => ((int) get_option('fpb_enable_partial_payment', 1) === 1),
        'partialBlockDays' => max(0, (int) get_option('fpb_partial_block_days', 0)),
        'partialOptionLabel' => get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook')),
        'paymentFeePct'   => snapbook_get_payment_fee_pct(),
        'paymentFeeLabel' => __('PayPal fee', 'snapbook'),
        'subtotalLabel'   => __('Subtotal', 'snapbook'),
        'whatsapp'   => get_option('fpb_whatsapp', ''),
        'confirmTitle'        => get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook')),
        'confirmMsg'          => get_option('fpb_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'snapbook')),
        'confirmPendingTitle' => get_option('fpb_confirm_pending_title', __('Booking Received!', 'snapbook')),
        'confirmPendingMsg'   => get_option('fpb_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'snapbook')),
        // Packages/sessions/add-ons travel with the page, so the form renders
        // on first paint instead of after an admin-ajax round trip. Dates are
        // still fetched live (see snapbook_get_catalog_data()).
        'catalog'    => snapbook_get_catalog_data(),
        'showLoader' => ((int) get_option('fpb_fe_loader_enable', 1) === 1),
        // Optional Contract step between Details and Payment — shifts the
        // payment step from 3 to 4 when on (see bkGo/PAY_STEP in booking.js).
        'contractEnabled' => snapbook_contract_step_enabled(),
        'contractRequiredMsg' => __('Please accept the Terms & Conditions to continue.', 'snapbook'),
    ];
    wp_localize_script('snapbook-booking', 'snapbookData', $local_data);

    ob_start();
    if ($instance_css !== '') {
        // CSS built from validated hex colors in snapbook_theme_vars_css().
        echo '<style id="snapbook-instance-theme">' . $instance_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    snapbook_render_shortcode(['package' => $atts['package']]);
    return ob_get_clean();
}

/**
 * Step 3 field groups — the Details fields are reordered into labelled
 * sections (Contact / Event / Address / Additional). Each entry lists the
 * catalog keys that belong to it; the last group also carries the admin
 * custom fields. Filterable so integrations can re-map the grouping.
 */
function snapbook_checkout_field_groups()
{
    return apply_filters('snapbook_checkout_field_groups', [
        [
            'title' => __('Contact', 'snapbook'),
            'keys'  => ['first_name', 'last_name', 'email', 'phone'],
        ],
        [
            'title' => __('Event details', 'snapbook'),
            'keys'  => ['event_time', 'participants', 'hotel_place', 'room_number', 'stay_period'],
        ],
        [
            'title' => __('Address', 'snapbook'),
            'keys'  => ['country', 'address_1', 'city', 'postcode'],
        ],
        [
            'title'  => __('Additional details', 'snapbook'),
            'keys'   => ['notes'],
            'custom' => true, // admin-created custom fields append here
        ],
    ]);
}

function snapbook_render_checkout_form_fields()
{
    $catalog = snapbook_checkout_field_catalog();
    $fields  = snapbook_get_checkout_form_fields();
    $custom  = function_exists('snapbook_get_custom_checkout_fields') ? snapbook_get_custom_checkout_fields() : [];
    $groups  = snapbook_checkout_field_groups();

    // Safety net: any catalog field not named in a group still renders,
    // appended to the last group, so a future field is never dropped.
    $placed = [];
    foreach ($groups as $group) {
        $placed = array_merge($placed, $group['keys']);
    }
    $leftover = array_diff(array_keys($catalog), $placed);
    if (! empty($leftover)) {
        $last = count($groups) - 1;
        $groups[$last]['keys'] = array_merge($groups[$last]['keys'], array_values($leftover));
    }

    foreach ($groups as $group) {
        // Collect the visible fields for this section before printing anything,
        // so an all-disabled section renders no empty heading. Each item also
        // records whether it is a "wide" field that spans both columns.
        $items = [];
        foreach ($group['keys'] as $key) {
            if (isset($catalog[$key], $fields[$key]) && ! empty($fields[$key]['enabled'])) {
                $def  = $catalog[$key];
                $wide = ! empty($def['wide']) || $def['type'] === 'textarea';
                $items[] = ['builtin', $key, $wide];
            }
        }
        if (! empty($group['custom'])) {
            foreach ($custom as $ckey => $cf) {
                $items[] = ['custom', $ckey, $cf['type'] === 'textarea'];
            }
        }
        if (empty($items)) {
            continue;
        }

        // Smart mix (1 & 2 column): narrow fields pair up two-per-row, while a
        // narrow field left without a partner — interrupted by a wide field or
        // sitting at the section's end — is promoted to full-width so the grid
        // never renders an empty half-cell next to a full-width field.
        $span    = array_fill(0, count($items), false); // true = force full-width
        $pending = null;                                 // index of an unpaired narrow field
        foreach ($items as $i => $item) {
            if ($item[2]) { // wide
                if ($pending !== null) {
                    $span[$pending] = true; // lone narrow field before a wide one
                    $pending = null;
                }
                $span[$i] = true;
            } elseif ($pending === null) {
                $pending = $i; // first of a potential pair
            } else {
                $pending = null; // paired with the previous narrow field
            }
        }
        if ($pending !== null) {
            $span[$pending] = true; // trailing lone narrow field
        }

        echo '<div class="fpb-details-group">';
        echo '<div class="fpb-sec-label">' . esc_html($group['title']) . '</div>';
        echo '<div class="fpb-grid2">';
        foreach ($items as $i => $item) {
            if ($item[0] === 'builtin') {
                snapbook_render_builtin_checkout_field($item[1], $catalog[$item[1]], $fields[$item[1]], $span[$i]);
            } else {
                snapbook_render_custom_checkout_field($item[1], $custom[$item[1]], $span[$i]);
            }
        }
        echo '</div>';
        echo '</div>';
    }
}

/**
 * Render a single built-in checkout field (catalog-driven).
 */
function snapbook_render_builtin_checkout_field($key, $def, $f, $force_span = false)
{
    $type = $def['type'];
    $wide = $force_span || ! empty($def['wide']) || $type === 'textarea';
    $fid  = 'fpb-cf-' . $key;

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-fpb-cf="' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ' placeholder="' . esc_attr($def['ph'] ?? $f['label']) . '"';

    echo '<div class="fpb-field' . ($wide ? ' fpb-gridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="fpb-req">*</span>' : '') . '</label>';

    if ($type === 'textarea') {
        echo '<textarea' . $common . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } elseif ($type === 'country' && class_exists('WooCommerce') && function_exists('WC') && WC()->countries) {
        $countries = WC()->countries->get_allowed_countries();
        echo '<select' . $common . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<option value="">' . esc_html__('Select a country / region…', 'snapbook') . '</option>';
        foreach ($countries as $code => $name) {
            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
        }
        echo '</select>';
    } else {
        $input_type = $type === 'country' ? 'text' : $type;
        $extra = '';
        if ($key === 'participants') {
            $extra = ' min="1" step="1"';
        } elseif ($key === 'room_number') {
            $extra = ' min="0" step="1"';
        }
        echo '<input type="' . esc_attr($input_type) . '"' . $common . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    echo '</div>';
}

/**
 * Render a single admin-created custom checkout field (namespaced cf_{key}).
 */
function snapbook_render_custom_checkout_field($key, $f, $force_span = false)
{
    $fid  = 'fpb-ccf-' . $key;
    $wide = $force_span || $f['type'] === 'textarea';

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-fpb-cf="cf_' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ' placeholder="' . esc_attr($f['label']) . '"';

    echo '<div class="fpb-field' . ($wide ? ' fpb-gridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="fpb-req">*</span>' : '') . '</label>';
    if ($f['type'] === 'textarea') {
        echo '<textarea' . $common . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        echo '<input type="' . esc_attr($f['type']) . '"' . $common . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
}

/* ─────────────────────────────────────────────────────────────
   Frontend sidebar — informational cards shown beside the booking
   form. Content is admin-editable in SnapBook → Frontend and stored
   in fpb_fe_* options.
───────────────────────────────────────────────────────────── */
/**
 * Defaults for the order confirmation email extras (SnapBook → Settings →
 * Order Email): an editable message block and a file attachment such as a
 * Terms of Service PDF. Lives here because woocommerce.php is only loaded
 * when WooCommerce is active, while the settings screen always needs these.
 * The message ships disabled so updating the plugin never silently changes
 * the email customers receive.
 */
function snapbook_order_email_defaults()
{
    return [
        'enable'        => 0,
        'subject'       => __('Your booking is confirmed — {site_name}', 'snapbook'),
        'heading'       => __('Your booking', 'snapbook'),
        'message'       => __("<p>Hi {first_name},</p>\n<p>Thank you for booking <strong>{package_name}</strong> with us on <strong>{session_date}</strong>.</p>\n<p>Our terms are attached to this email. If you have any questions, just reply and we'll be happy to help.</p>\n<p>{site_name}</p>", 'snapbook'),
        'order_table'   => 1,
        'attachment_id' => 0,
    ];
}

function snapbook_get_order_email_settings()
{
    $d = snapbook_order_email_defaults();
    return [
        'enable'        => (int) get_option('fpb_order_email_enable', $d['enable']),
        'subject'       => (string) get_option('fpb_order_email_subject', $d['subject']),
        'heading'       => (string) get_option('fpb_order_email_heading', $d['heading']),
        'message'       => (string) get_option('fpb_order_email_message', $d['message']),
        'order_table'   => (int) get_option('fpb_order_email_order_table', $d['order_table']),
        'attachment_id' => (int) get_option('fpb_order_email_attachment_id', $d['attachment_id']),
    ];
}

/**
 * Human label for the chosen attachment — the real filename when the file
 * still exists, so a deleted media item is obvious in the settings screen.
 */
function snapbook_order_email_attachment_label($attachment_id)
{
    $attachment_id = (int) $attachment_id;
    if ($attachment_id < 1) {
        return __('No file selected.', 'snapbook');
    }
    $path = get_attached_file($attachment_id);
    if (! $path || ! file_exists($path)) {
        return __('Selected file is missing — please choose another.', 'snapbook');
    }
    return basename($path);
}

/**
 * Defaults for the admin "New booking" notification (SnapBook → Settings →
 * Admin Order Email). Like the customer order email, this replaces
 * WooCommerce's plain New Order email with the branded SnapBook shell — but
 * with the extra detail an admin needs to action a booking (payment
 * breakdown, customer contact, and a manage-order link). Ships disabled so
 * updating the plugin never changes the email a live studio receives.
 */
function snapbook_admin_email_defaults()
{
    return [
        'enable'      => 0,
        'recipient'   => '',
        'subject'     => __('New booking {order_id} — {package_name}', 'snapbook'),
        'heading'     => __('New booking received', 'snapbook'),
        'intro'       => __('<p>A new booking has just come in. The full details are below.</p>', 'snapbook'),
    ];
}

function snapbook_get_admin_email_settings()
{
    $d = snapbook_admin_email_defaults();
    return [
        'enable'    => (int) get_option('fpb_admin_email_enable', $d['enable']),
        'recipient' => (string) get_option('fpb_admin_email_recipient', $d['recipient']),
        'subject'   => (string) get_option('fpb_admin_email_subject', $d['subject']),
        'heading'   => (string) get_option('fpb_admin_email_heading', $d['heading']),
        'intro'     => (string) get_option('fpb_admin_email_intro', $d['intro']),
    ];
}

/**
 * Sanitize a comma-separated list of recipient emails down to the valid ones,
 * rejoined with ", ". Empty when nothing valid was supplied, so the caller can
 * fall back to WooCommerce's own recipient.
 */
function snapbook_sanitize_email_list($raw)
{
    $out = [];
    foreach (explode(',', (string) $raw) as $email) {
        $email = sanitize_email(trim($email));
        if ($email !== '' && ! in_array($email, $out, true)) {
            $out[] = $email;
        }
    }
    return implode(', ', $out);
}

function snapbook_frontend_sidebar_defaults()
{
    return [
        'hiw_enable'     => 1,
        'hiw_title'      => __('How it works', 'snapbook'),
        'hiw_steps'      => __("Choose your package & session type\nFill in your details\nReserve & digitally sign the contract\nPay 50% deposit securely online\nWe confirm within 24 hours", 'snapbook'),
        'date_title'     => __('Choose your date', 'snapbook'),
        'date_sub'       => __('Select your preferred session date to begin.', 'snapbook'),
        'deposit_enable' => 1,
        'deposit_title'  => __('50% deposit to confirm', 'snapbook'),
        'deposit_text'   => __('Your date is fully reserved once the deposit is paid. We send a full confirmation with session details and location suggestions. The balance is due before your session.', 'snapbook'),
    ];
}

function snapbook_get_frontend_sidebar()
{
    $d = snapbook_frontend_sidebar_defaults();
    return [
        'hiw_enable'     => (int) get_option('fpb_fe_hiw_enable', $d['hiw_enable']),
        'hiw_title'      => get_option('fpb_fe_hiw_title', $d['hiw_title']),
        'hiw_steps'      => get_option('fpb_fe_hiw_steps', $d['hiw_steps']),
        'date_title'     => get_option('fpb_fe_date_title', $d['date_title']),
        'date_sub'       => get_option('fpb_fe_date_sub', $d['date_sub']),
        'deposit_enable' => (int) get_option('fpb_fe_deposit_enable', $d['deposit_enable']),
        'deposit_title'  => get_option('fpb_fe_deposit_title', $d['deposit_title']),
        'deposit_text'   => get_option('fpb_fe_deposit_text', $d['deposit_text']),
    ];
}

/**
 * Contract step defaults — the optional "Contract" step between Details and
 * Payment, where the customer reads the studio's terms and accepts them.
 * Ships disabled so updating never adds a step to a live booking form.
 */
function snapbook_contract_defaults()
{
    return [
        'enable'       => 0,
        'step_label'   => __('Contract', 'snapbook'),
        'title'        => __('Review & Sign Contract', 'snapbook'),
        'sub'          => __('Please read and digitally sign our Terms & Conditions to proceed.', 'snapbook'),
        'text'         => '<h3>' . __('Service Agreement', 'snapbook') . '</h3>'
            . '<p>' . __('By accepting below, you agree to the following terms in full.', 'snapbook') . '</p>'
            . '<h4>' . __('1. Booking fee &amp; payments', 'snapbook') . '</h4>'
            . '<p>' . __('A non-refundable deposit is required to secure your date. The remaining balance is due before the session takes place.', 'snapbook') . '</p>'
            . '<h4>' . __('2. Delivery time', 'snapbook') . '</h4>'
            . '<p>' . __('Edited images are delivered within the timeframe stated for your package. Rush delivery may be available on request.', 'snapbook') . '</p>'
            . '<h4>' . __('3. Cancellation &amp; rescheduling', 'snapbook') . '</h4>'
            . '<p>' . __('Sessions may be rescheduled once, subject to availability. Deposits are not refundable on cancellation.', 'snapbook') . '</p>',
        'accept_label' => __('I have read and agree to the full Terms & Conditions.', 'snapbook'),
    ];
}

/**
 * Contract step settings, with blank labels falling back to the defaults so
 * the step can never render without a name or an acceptance line.
 */
function snapbook_get_contract_settings()
{
    $d = snapbook_contract_defaults();
    $s = [
        'enable'       => (int) get_option('fpb_fe_contract_enable', $d['enable']),
        'step_label'   => (string) get_option('fpb_fe_contract_step_label', $d['step_label']),
        'title'        => (string) get_option('fpb_fe_contract_title', $d['title']),
        'sub'          => (string) get_option('fpb_fe_contract_sub', $d['sub']),
        'text'         => (string) get_option('fpb_fe_contract_text', $d['text']),
        'accept_label' => (string) get_option('fpb_fe_contract_accept_label', $d['accept_label']),
    ];
    foreach (['step_label', 'title', 'accept_label'] as $key) {
        if (trim($s[$key]) === '') {
            $s[$key] = $d[$key];
        }
    }
    return $s;
}

/**
 * Whether the wizard runs Package → Details → Contract → Payment (true) or
 * the stock Package → Details → Payment (false).
 */
function snapbook_contract_step_enabled()
{
    $s = snapbook_get_contract_settings();
    return ! empty($s['enable']);
}

/**
 * The booking calendar card — the customer picks their session date here
 * (the wizard itself is now Package → Details → Payment). Rendered by both
 * the sidebar and the standalone fallback, so its markup lives in one place.
 * The element IDs match what booking.js binds (fpb-calGrid etc.).
 */
function snapbook_render_calendar_card($s)
{
    ob_start();
    echo '<div class="fpb-side-card fpb-side-cal">';
    echo '<div class="fpb-side-head"><span class="fpb-side-ic dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="fpb-side-title">' . esc_html($s['date_title']) . '</span></div>';
    if ($s['date_sub'] !== '') {
        echo '<p class="fpb-side-text fpb-side-cal-sub">' . esc_html($s['date_sub']) . '</p>';
    }
    echo '<div class="fpb-cal">';
    echo '<div class="fpb-cal-head">';
    echo '<button class="fpb-cal-nav" id="fpb-calPrev" type="button">&#8249;</button>';
    echo '<span id="fpb-calMonth"></span>';
    echo '<button class="fpb-cal-nav" id="fpb-calNext" type="button">&#8250;</button>';
    echo '</div>';
    echo '<div class="fpb-cal-days"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>';
    echo '<div class="fpb-cal-grid" id="fpb-calGrid"></div>';
    echo '</div>';
    echo '<p id="fpb-selDate" class="fpb-seldate"></p>';
    echo '</div>';
    return ob_get_clean();
}

/**
 * Build the frontend sidebar markup. The calendar card always renders
 * (date selection lives here now); the informational cards each honour
 * their own enable toggle. Output is fully escaped.
 */
function snapbook_render_frontend_sidebar()
{
    $s = snapbook_get_frontend_sidebar();

    ob_start();

    if (! empty($s['hiw_enable'])) {
        $steps = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $s['hiw_steps'])), 'strlen');
        if ($s['hiw_title'] !== '' || ! empty($steps)) {
            echo '<div class="fpb-side-card fpb-side-hiw">';
            echo '<div class="fpb-side-head"><span class="fpb-side-ic dashicons dashicons-list-view" aria-hidden="true"></span><span class="fpb-side-title">' . esc_html($s['hiw_title']) . '</span></div>';
            if (! empty($steps)) {
                echo '<ol class="fpb-side-steps">';
                foreach ($steps as $step) {
                    echo '<li>' . esc_html($step) . '</li>';
                }
                echo '</ol>';
            }
            echo '</div>';
        }
    }

    // Calendar card — always present, positioned right after "How it works".
    echo snapbook_render_calendar_card($s); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts

    if (! empty($s['deposit_enable']) && ($s['deposit_title'] !== '' || $s['deposit_text'] !== '')) {
        echo '<div class="fpb-side-card fpb-side-card-dark">';
        echo '<div class="fpb-side-head"><span class="fpb-side-ic dashicons dashicons-shield-alt" aria-hidden="true"></span><span class="fpb-side-title">' . esc_html($s['deposit_title']) . '</span></div>';
        if ($s['deposit_text'] !== '') {
            echo '<p class="fpb-side-text">' . nl2br(esc_html($s['deposit_text'])) . '</p>';
        }
        echo '</div>';
    }

    $inner = trim(ob_get_clean());
    if ($inner === '') {
        return '';
    }

    return '<aside class="fpb-sidebar">' . $inner . '</aside>';
}

function snapbook_render_shortcode($opts = [])
{
    $opts = wp_parse_args($opts, ['package' => '']);
    $preselect = trim((string) $opts['package']);

    $has_wc = class_exists('WooCommerce');
    $mode   = snapbook_get_checkout_mode();
    if (! $has_wc) {
        $checkout_label = __('Submit Booking Request', 'snapbook');
    } else {
        $checkout_label = ($mode === 'direct') ? __('Place Booking & Pay', 'snapbook') : __('Proceed to Payment', 'snapbook');
    }

    $sidebar_html = snapbook_render_frontend_sidebar();
    $wrap_class   = 'fpb-wrap' . ($sidebar_html === '' ? ' fpb-no-sidebar' : '');

    // The Contract step is optional, so the wizard is either 3 or 4 steps.
    // Everything downstream (indicator, panel ids, Back buttons) is derived
    // from $step_labels / $pay_step rather than hard-coded numbers.
    $contract     = snapbook_get_contract_settings();
    $has_contract = ! empty($contract['enable']);
    $step_labels  = [__('Package', 'snapbook'), __('Details', 'snapbook')];
    if ($has_contract) {
        $step_labels[] = $contract['step_label'];
    }
    $step_labels[] = __('Payment', 'snapbook');
    $pay_step      = count($step_labels);
?>
    <div class="<?php echo esc_attr($wrap_class); ?>"<?php echo $preselect !== '' ? ' data-package="' . esc_attr($preselect) . '"' : ''; ?>>
      <div class="fpb-layout">
        <div class="fpb-main">
        <div class="fpb-card">

        <!-- Step indicator -->
        <div class="fpb-steps-bar" id="fpb-steps">
            <?php foreach ($step_labels as $i => $step_label) : $n = $i + 1; ?>
            <div class="fpb-stab<?php echo $n === 1 ? ' fpb-active' : ''; ?>" id="fpb-sp<?php echo (int) $n; ?>"><span class="fpb-sci"><?php echo (int) $n; ?></span><?php echo esc_html($step_label); ?></div>
            <?php endforeach; ?>
        </div>

        <!-- STEP 1 — Package & Add-ons (date is chosen in the sidebar calendar) -->
        <div class="fpb-step fpb-act" id="fpb-s1">
            <div class="fpb-step-inner">
                <h2 class="fpb-title"><?php esc_html_e('Select Your Package', 'snapbook'); ?></h2>
                <p class="fpb-sub"><?php esc_html_e("Choose a session type, package, and any add-ons you'd like.", 'snapbook'); ?></p>

                <!-- Shown when a ?package= share link points at an unavailable package -->
                <p class="fpb-pkg-notice" id="fpb-pkgNotice" style="display:none"><?php esc_html_e("That package isn't available — please choose from the options below.", 'snapbook'); ?></p>

                <div class="fpb-sec-label"><?php esc_html_e('Session Type', 'snapbook'); ?></div>
                <div class="fpb-stype-wrap" id="fpb-typeTabs">
                    <!-- JS populates one .fpb-stype-btn per session -->
                    <span class="fpb-stype-loading">Loading…</span>
                </div>

                <div class="fpb-sec-label" style="margin-top:1.6rem"><?php esc_html_e('Select Package', 'snapbook'); ?></div>
                <div class="fpb-pkg-cards" id="fpb-pkgGrid"></div>

                <div class="fpb-addons-box" id="fpb-addonsWrap" style="display:none">
                    <div class="fpb-addon-title"><?php esc_html_e('Add-ons', 'snapbook'); ?> <span class="fpb-addon-opt">(<?php esc_html_e('Optional', 'snapbook'); ?>)</span></div>
                    <div class="fpb-addon-grid" id="fpb-addonsGrid"></div>
                </div>

                <!-- 50% payment toggle — shown right after the add-ons -->
                <div class="fpb-addons-box" id="fpb-partialWrap" style="display:none">
                    <div class="fpb-addon-title"><?php esc_html_e('Payment Option', 'snapbook'); ?></div>
                    <label class="fpb-addon-card fpb-partial-card" for="fpb-partialToggle">
                        <input class="fpb-ac" type="checkbox" id="fpb-partialToggle" checked>
                        <span class="fpb-partial-em" aria-hidden="true">50%</span>
                        <span class="fpb-addon-info">
                            <span class="fpb-addon-name" id="fpb-partialLabel"><?php echo esc_html(get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook'))); ?></span>
                            <span class="fpb-addon-desc" id="fpb-partialNote"></span>
                        </span>
                        <span class="fpb-partial-switch" aria-hidden="true"><span class="fpb-partial-knob"></span></span>
                    </label>
                </div>

                <!-- Live price strip — updates as package / add-ons / toggle change -->
                <div class="fpb-price-strip" id="fpb-s2Price" style="display:none" aria-live="polite">
                    <div class="fpb-price-cell">
                        <span class="fpb-price-label"><?php esc_html_e('Total', 'snapbook'); ?></span>
                        <span class="fpb-price-val" id="fpb-s2Total">—</span>
                    </div>
                    <div class="fpb-price-cell fpb-is-due">
                        <span class="fpb-price-label" id="fpb-s2DueLabel"><?php esc_html_e('Pay now', 'snapbook'); ?></span>
                        <span class="fpb-price-val" id="fpb-s2Due">—</span>
                    </div>
                    <div class="fpb-price-cell" id="fpb-s2LaterCell" style="display:none">
                        <span class="fpb-price-label"><?php esc_html_e('Pay later', 'snapbook'); ?></span>
                        <span class="fpb-price-val" id="fpb-s2Later">—</span>
                    </div>
                </div>

                <div class="fpb-nav">
                    <div></div>
                    <button class="fpb-btn fpb-btn-gold" id="fpb-s1NextBtn" onclick="snapbook.s1Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s1err" class="fpb-error"></p>
            </div>
        </div>

        <!-- STEP 2 — Details (checkout form, fields managed in SnapBook → Settings) -->
        <div class="fpb-step" id="fpb-s2">
            <div class="fpb-step-inner">
                <h2 class="fpb-title"><?php esc_html_e('Your Details', 'snapbook'); ?></h2>
                <p class="fpb-sub"><?php esc_html_e('Fill in your booking and contact details.', 'snapbook'); ?></p>

                <div class="fpb-details-groups" id="fpb-detailsGrid">
                    <?php snapbook_render_checkout_form_fields(); ?>
                </div>

                <div class="fpb-nav">
                    <button class="fpb-btn fpb-btn-outline" onclick="snapbook.bkGo(1)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="fpb-btn fpb-btn-gold" id="fpb-s2NextBtn" onclick="snapbook.s2Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s2err" class="fpb-error"></p>
            </div>
        </div>

        <?php if ($has_contract) : ?>
        <!-- STEP 3 — Contract (optional, SnapBook → Frontend → Contract step) -->
        <div class="fpb-step" id="fpb-s3">
            <div class="fpb-step-inner">
                <h2 class="fpb-title"><?php echo esc_html($contract['title']); ?></h2>
                <?php if (trim($contract['sub']) !== '') : ?>
                <p class="fpb-sub"><?php echo esc_html($contract['sub']); ?></p>
                <?php endif; ?>

                <div class="fpb-contract-doc" id="fpb-contractDoc" tabindex="0" role="region" aria-label="<?php echo esc_attr($contract['title']); ?>">
                    <?php echo wp_kses_post(wpautop($contract['text'])); ?>
                </div>

                <label class="fpb-contract-accept" for="fpb-contractAccept">
                    <input type="checkbox" id="fpb-contractAccept">
                    <span class="fpb-contract-accept-text"><?php echo esc_html($contract['accept_label']); ?></span>
                </label>

                <div class="fpb-nav">
                    <button class="fpb-btn fpb-btn-outline" onclick="snapbook.bkGo(2)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="fpb-btn fpb-btn-gold" id="fpb-s3NextBtn" onclick="snapbook.s3Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s3err" class="fpb-error"></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- FINAL STEP — Payment (step 3, or 4 when the contract step is on) -->
        <div class="fpb-step" id="fpb-s<?php echo (int) $pay_step; ?>">
            <div class="fpb-step-inner" id="fpb-payWrap">
                <h2 class="fpb-title"><?php esc_html_e('Review & Payment', 'snapbook'); ?></h2>
                <p class="fpb-sub"><?php esc_html_e("Review your booking, choose how you'd like to pay, and confirm.", 'snapbook'); ?></p>

                <!-- Live Booking Summary -->
                <div class="fpb-bsum" id="fpb-pay-summary">
                    <h5><?php esc_html_e('Booking Summary', 'snapbook'); ?></h5>
                    <div class="fpb-sumr"><span><?php esc_html_e('Session', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-session">—</span></div>
                    <div class="fpb-sumr"><span><?php esc_html_e('Package', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-pkg">—</span></div>
                    <div class="fpb-sumr"><span><?php esc_html_e('Date', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-date">—</span></div>
                    <div class="fpb-sumr"><span><?php esc_html_e('Add-ons', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-addons">—</span></div>
                    <div class="fpb-sumr"><span id="fpb-sum-price-label"><?php esc_html_e('Total price', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-price">—</span></div>
                    <div class="fpb-sumr fpb-sum-fee-row" id="fpb-sum-fee-row" style="display:none"><span id="fpb-sum-fee-label"><?php esc_html_e('PayPal fee', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-fee">—</span></div>
                    <div class="fpb-sumr fpb-sum-payable-row" id="fpb-sum-payable-row" style="display:none"><span><?php esc_html_e('Total payable', 'snapbook'); ?></span><span class="fpb-v" id="fpb-sum-payable">—</span></div>
                    <div class="fpb-sumt">
                        <div>
                            <div class="fpb-sumtl"><?php esc_html_e('Due Now', 'snapbook'); ?></div>
                            <div class="fpb-sumtd" id="fpb-sum-dep"></div>
                        </div>
                        <div class="fpb-sumtn" id="fpb-sum-total">—</div>
                    </div>
                    <p class="fpb-sum-note" id="fpb-sum-balance-row" style="display:none">
                        <?php esc_html_e('Remaining balance', 'snapbook'); ?> <strong id="fpb-sum-balance">—</strong> <?php esc_html_e('is due later — we will send you a payment link.', 'snapbook'); ?>
                    </p>
                </div>

                <!-- WooCommerce payment methods -->
                <div class="fpb-gateway-box" id="fpb-gatewayBox" style="display:none">
                    <div class="fpb-gateway-title"><?php esc_html_e('Payment Method', 'snapbook'); ?></div>
                    <div class="fpb-gateway-list" id="fpb-gatewayList"></div>
                </div>

                <div class="fpb-nav">
                    <button class="fpb-btn fpb-btn-outline" onclick="snapbook.bkGo(<?php echo (int) ($pay_step - 1); ?>)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="fpb-btn fpb-btn-gold fpb-checkout-btn" id="fpb-checkoutBtn" onclick="snapbook.proceedToCheckout()"><?php echo esc_html($checkout_label); ?> &#8594;</button>
                </div>
                <p id="fpb-payErr" class="fpb-error"></p>
                <p id="fpb-checkoutMsg" class="fpb-checkout-msg"></p>
            </div>
            <!-- In-place booking confirmation (direct checkout mode) -->
            <div class="fpb-suc" id="fpb-confirmWrap" style="display:none">
                <div class="fpb-step-inner fpb-suc-inner">
                    <div class="fpb-suc-icon">✓</div>
                    <h2 class="fpb-title" id="fpb-confirmTitle"><?php echo esc_html(get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook'))); ?></h2>
                    <p class="fpb-sub" id="fpb-confirmNote"></p>
                    <div class="fpb-bsum fpb-confirm-summary">
                        <div class="fpb-sumr"><span><?php esc_html_e('Order', 'snapbook'); ?></span><span class="fpb-v" id="fpb-confirmOrder">—</span></div>
                        <div class="fpb-sumr"><span><?php esc_html_e('Payment method', 'snapbook'); ?></span><span class="fpb-v" id="fpb-confirmMethod">—</span></div>
                        <div class="fpb-sumr"><span><?php esc_html_e('Amount', 'snapbook'); ?></span><span class="fpb-v" id="fpb-confirmAmount">—</span></div>
                        <div class="fpb-sumr"><span><?php esc_html_e('Status', 'snapbook'); ?></span><span class="fpb-v" id="fpb-confirmStatus">—</span></div>
                    </div>
                    <div class="fpb-nav" style="justify-content:center;margin-top:1.6rem">
                        <a class="fpb-btn fpb-btn-gold" id="fpb-confirmPayBtn" href="#" style="display:none"><?php esc_html_e('Complete Payment', 'snapbook'); ?> &#8594;</a>
                        <a class="fpb-btn fpb-btn-outline" id="fpb-confirmViewBtn" href="#" style="display:none"><?php esc_html_e('View Order Details', 'snapbook'); ?></a>
                        <a class="fpb-btn fpb-btn-outline" id="fpb-confirmWaBtn" href="#" target="_blank" rel="noopener" style="display:none"><?php echo esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp')); ?></a>
                    </div>
                </div>
            </div>
            <!-- Success state (fallback email flow) -->
            <div class="fpb-suc" id="fpb-sucWrap" style="display:none">
                <div class="fpb-step-inner fpb-suc-inner">
                    <div class="fpb-suc-icon">✓</div>
                    <h2 class="fpb-title"><?php echo esc_html(get_option('fpb_success_title', 'Booking Requested!')); ?></h2>
                    <p><?php echo esc_html(get_option('fpb_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to")); ?> <strong id="fpb-sucEmail"></strong>.</p>
                    <div class="fpb-nav" style="justify-content:center;margin-top:2rem">
                        <a class="fpb-btn fpb-btn-gold" id="fpb-waLink" href="#" target="_blank" rel="noopener"><?php echo esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp')); ?></a>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- .fpb-card -->
        </div><!-- .fpb-main -->
        <?php echo $sidebar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in snapbook_render_frontend_sidebar() ?>
      </div><!-- .fpb-layout -->
    </div>
<?php
}
