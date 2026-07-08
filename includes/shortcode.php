<?php
defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Checkout form — field catalog & saved configuration
   The catalog is the fixed list of known fields; the admin
   "Checkout Form" settings store per-field enabled/required/label
   overrides in the fpb_checkout_form_fields option.
───────────────────────────────────────────────────────────── */
function sb_checkout_field_catalog()
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

function sb_get_checkout_form_fields()
{
    $catalog = sb_checkout_field_catalog();
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

function sb_sanitize_checkout_mode($mode)
{
    $mode = sanitize_key((string) $mode);
    return in_array($mode, ['direct', 'redirect'], true) ? $mode : 'direct';
}

function sb_get_checkout_mode()
{
    return sb_sanitize_checkout_mode(get_option('fpb_checkout_mode', 'direct'));
}

/**
 * Build the fpb_checkout_form_fields option value from a settings-form POST.
 * Expects fpb_cf_enabled[key], fpb_cf_required[key], fpb_cf_label[key].
 */
function sb_sanitize_checkout_field_config($post)
{
    $catalog  = sb_checkout_field_catalog();
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
function sb_custom_checkout_field_types()
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

function sb_get_custom_checkout_fields()
{
    $saved = get_option('fpb_checkout_custom_fields', []);
    if (! is_array($saved)) {
        return [];
    }

    $types = sb_custom_checkout_field_types();
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
function sb_sanitize_custom_checkout_fields($post)
{
    $labels   = isset($post['fpb_ccf_label']) && is_array($post['fpb_ccf_label']) ? $post['fpb_ccf_label'] : [];
    $types_in = isset($post['fpb_ccf_type']) && is_array($post['fpb_ccf_type']) ? $post['fpb_ccf_type'] : [];
    $reqs     = isset($post['fpb_ccf_required']) && is_array($post['fpb_ccf_required']) ? $post['fpb_ccf_required'] : [];
    $types    = sb_custom_checkout_field_types();

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
function sb_sanitize_checkout_details($raw)
{
    if (! is_array($raw)) {
        return [];
    }

    $catalog = sb_checkout_field_catalog();
    $fields  = sb_get_checkout_form_fields();
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
    foreach (sb_get_custom_checkout_fields() as $key => $f) {
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
function sb_default_theme_colors()
{
    return ['primary' => '#b8956a', 'accent' => '#3d6b78'];
}

function sb_get_theme_colors()
{
    $defaults = sb_default_theme_colors();
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
function sb_hex_mix($hex, $mix_hex, $ratio)
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
 * scoped to $selector. The derived shades match sb_theme_css() exactly,
 * so per-instance overrides (Elementor widget / Gutenberg block) and the
 * global Appearance setting stay visually consistent. Returns '' when the
 * colors are empty or invalid.
 */
function sb_theme_vars_css($primary, $accent, $selector = '.fpb-wrap')
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
            sb_hex_mix($primary, '#000000', 0.18),
            sb_hex_mix($primary, '#ffffff', 0.72)
        );
    }
    if ($valid($accent)) {
        $vars .= sprintf(
            '--fpb-teal:%s;--fpb-teal-lt:%s;',
            $accent,
            sb_hex_mix($accent, '#ffffff', 0.86)
        );
    }

    return sprintf('%s{%s}', $selector, $vars);
}

/**
 * Build the global CSS-variable override block. Returns '' when the admin
 * hasn't customized anything, so the stylesheet's hand-tuned palette
 * stays byte-identical by default.
 */
function sb_theme_css()
{
    $defaults = sb_default_theme_colors();
    $colors   = sb_get_theme_colors();
    if (strtolower($colors['primary']) === $defaults['primary'] && strtolower($colors['accent']) === $defaults['accent']) {
        return '';
    }

    return sb_theme_vars_css($colors['primary'], $colors['accent'], '.fpb-wrap');
}

/**
 * Active packages for editor "pre-select a package" pickers (Elementor
 * control + Gutenberg block). Each entry carries the stable slug (falling
 * back to the numeric id) plus a human label prefixed with its session
 * type — matching the values the ?package= deep-link accepts.
 */
function sb_get_active_packages_for_picker()
{
    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        "SELECT p.id, p.slug, p.name, s.name AS session_name
           FROM {$pfx}packages p
           JOIN {$pfx}sessions s ON s.id = p.session_id
          WHERE p.active = 1 AND s.active = 1
          ORDER BY s.sort_order, s.id, p.sort_order, p.id"
    );

    $out = [];
    foreach ((array) $rows as $r) {
        $value = ($r->slug !== null && $r->slug !== '') ? $r->slug : (string) (int) $r->id;
        $out[] = [
            'value' => $value,
            'label' => trim(($r->session_name ? $r->session_name . ' — ' : '') . $r->name),
        ];
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Register assets & shortcode
───────────────────────────────────────────────────────────── */
add_action('init', 'sb_register_assets');
add_action('wp_enqueue_scripts', 'sb_register_assets');
function sb_register_assets()
{
    wp_register_style(
        'sb-booking-css',
        SB_URL . 'assets/css/booking.css',
        [],
        SB_VER
    );
    $icon_lib = sb_icon_library_url();
    if ($icon_lib !== '') {
        wp_register_style('sb-icon-library', $icon_lib, [], SB_VER);
    }
    wp_register_script(
        'sb-booking-js',
        SB_URL . 'assets/js/booking.js',
        [],
        SB_VER,
        true
    );
}

add_shortcode('snapbook', 'sb_shortcode');
add_shortcode('focus_booking', 'sb_shortcode');
function sb_shortcode($atts)
{
    $atts = shortcode_atts([
        'package' => '',
        'primary' => '',
        'accent'  => '',
    ], $atts, 'snapbook');

    wp_enqueue_style('sb-booking-css');
    if (wp_style_is('sb-icon-library', 'registered')) {
        wp_enqueue_style('sb-icon-library');
    }
    wp_enqueue_script('sb-booking-js');

    $theme_css = sb_theme_css();
    if ($theme_css !== '') {
        wp_add_inline_style('sb-booking-css', $theme_css);
    }

    // Per-instance color overrides (from the Elementor widget / Gutenberg
    // block passing primary/accent atts) scoped to this instance only.
    $instance_css = sb_theme_vars_css($atts['primary'], $atts['accent'], '.fpb-wrap');
    if ($instance_css !== '') {
        wp_add_inline_style('sb-booking-css', $instance_css);
    }

    $has_wc = class_exists('WooCommerce');
    $local_data = [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('fpb_nonce'),
        'hasWC'      => $has_wc,
        'checkoutMode' => sb_get_checkout_mode(),
        'currency'   => sb_get_currency_symbol(),
        'depositPct' => ((int) get_option('fpb_enable_partial_payment', 1) === 1 ? 50 : 100),
        'partialPaymentEnabled' => ((int) get_option('fpb_enable_partial_payment', 1) === 1),
        'partialBlockDays' => max(0, (int) get_option('fpb_partial_block_days', 0)),
        'partialOptionLabel' => get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook')),
        'whatsapp'   => get_option('fpb_whatsapp', ''),
        'confirmTitle'        => get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook')),
        'confirmMsg'          => get_option('fpb_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'snapbook')),
        'confirmPendingTitle' => get_option('fpb_confirm_pending_title', __('Booking Received!', 'snapbook')),
        'confirmPendingMsg'   => get_option('fpb_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'snapbook')),
    ];
    wp_localize_script('sb-booking-js', 'fpbData', $local_data);

    ob_start();
    sb_render_shortcode(['package' => $atts['package']]);
    return ob_get_clean();
}

/**
 * Step 3 field groups — the Details fields are reordered into labelled
 * sections (Contact / Event / Address / Additional). Each entry lists the
 * catalog keys that belong to it; the last group also carries the admin
 * custom fields. Filterable so integrations can re-map the grouping.
 */
function sb_checkout_field_groups()
{
    return apply_filters('sb_checkout_field_groups', [
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

function sb_render_checkout_form_fields()
{
    $catalog = sb_checkout_field_catalog();
    $fields  = sb_get_checkout_form_fields();
    $custom  = function_exists('sb_get_custom_checkout_fields') ? sb_get_custom_checkout_fields() : [];
    $groups  = sb_checkout_field_groups();

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

        echo '<div class="fdetails-group">';
        echo '<div class="fsec-label">' . esc_html($group['title']) . '</div>';
        echo '<div class="fgrid2">';
        foreach ($items as $i => $item) {
            if ($item[0] === 'builtin') {
                sb_render_builtin_checkout_field($item[1], $catalog[$item[1]], $fields[$item[1]], $span[$i]);
            } else {
                sb_render_custom_checkout_field($item[1], $custom[$item[1]], $span[$i]);
            }
        }
        echo '</div>';
        echo '</div>';
    }
}

/**
 * Render a single built-in checkout field (catalog-driven).
 */
function sb_render_builtin_checkout_field($key, $def, $f, $force_span = false)
{
    $type = $def['type'];
    $wide = $force_span || ! empty($def['wide']) || $type === 'textarea';
    $fid  = 'fpb-cf-' . $key;

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-fpb-cf="' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ' placeholder="' . esc_attr($def['ph'] ?? $f['label']) . '"';

    echo '<div class="ffield' . ($wide ? ' fgridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="req">*</span>' : '') . '</label>';

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
function sb_render_custom_checkout_field($key, $f, $force_span = false)
{
    $fid  = 'fpb-ccf-' . $key;
    $wide = $force_span || $f['type'] === 'textarea';

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-fpb-cf="cf_' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ' placeholder="' . esc_attr($f['label']) . '"';

    echo '<div class="ffield' . ($wide ? ' fgridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="req">*</span>' : '') . '</label>';
    if ($f['type'] === 'textarea') {
        echo '<textarea' . $common . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        echo '<input type="' . esc_attr($f['type']) . '"' . $common . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
}

function sb_render_shortcode($opts = [])
{
    $opts = wp_parse_args($opts, ['package' => '']);
    $preselect = trim((string) $opts['package']);

    $has_wc = class_exists('WooCommerce');
    $mode   = sb_get_checkout_mode();
    if (! $has_wc) {
        $checkout_label = __('Submit Booking Request', 'snapbook');
    } else {
        $checkout_label = ($mode === 'direct') ? __('Place Booking & Pay', 'snapbook') : __('Proceed to Payment', 'snapbook');
    }
?>
    <div class="fpb-wrap"<?php echo $preselect !== '' ? ' data-package="' . esc_attr($preselect) . '"' : ''; ?>>

        <!-- Step indicator -->
        <div class="sbar2" id="fpb-steps">
            <div class="stab active" id="fpb-sp1"><span class="sci">1</span><?php esc_html_e('Date', 'snapbook'); ?></div>
            <div class="stab" id="fpb-sp2"><span class="sci">2</span><?php esc_html_e('Package', 'snapbook'); ?></div>
            <div class="stab" id="fpb-sp3"><span class="sci">3</span><?php esc_html_e('Details', 'snapbook'); ?></div>
            <div class="stab" id="fpb-sp4"><span class="sci">4</span><?php esc_html_e('Payment', 'snapbook'); ?></div>
        </div>

        <!-- STEP 1 — Date -->
        <div class="fstep act" id="fpb-s1">
            <div class="fstep-inner">
                <h2 class="ftitle"><?php echo esc_html(get_option('fpb_step1_title', 'Choose Your Date')); ?></h2>
                <p class="fsub"><?php echo esc_html(get_option('fpb_step1_sub', 'Select your preferred session date to begin your booking.')); ?></p>

                <div class="fdatesec" id="fpb-dateSec">
                    <div class="fpb-cal">
                        <div class="fpb-cal-head">
                            <button class="fpb-cal-nav" id="fpb-calPrev" type="button">&#8249;</button>
                            <span id="fpb-calMonth"></span>
                            <button class="fpb-cal-nav" id="fpb-calNext" type="button">&#8250;</button>
                        </div>
                        <div class="fpb-cal-days">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
                            <span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="fpb-cal-grid" id="fpb-calGrid"></div>
                    </div>
                    <p id="fpb-selDate" class="fpb-seldate"></p>
                </div>

                <div class="fnav">
                    <div></div>
                    <button class="btn bg" id="fpb-s1NextBtn" onclick="fpb.s1Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s1err" class="ferr"></p>
            </div>
        </div>

        <!-- STEP 2 — Package & Add-ons -->
        <div class="fstep" id="fpb-s2">
            <div class="fstep-inner">
                <h2 class="ftitle"><?php esc_html_e('Select Your Package', 'snapbook'); ?></h2>
                <p class="fsub"><?php esc_html_e("Choose a session type, package, and any add-ons you'd like.", 'snapbook'); ?></p>

                <!-- Shown when a ?package= share link points at an unavailable package -->
                <p class="fpb-pkg-notice" id="fpb-pkgNotice" style="display:none"><?php esc_html_e("That package isn't available — please choose from the options below.", 'snapbook'); ?></p>

                <div class="fsec-label"><?php esc_html_e('Session Type', 'snapbook'); ?></div>
                <div class="fpb-stype-wrap" id="fpb-typeTabs">
                    <!-- JS populates one .fpb-stype-btn per session -->
                    <span class="fpb-stype-loading">Loading…</span>
                </div>

                <div class="fsec-label" style="margin-top:1.6rem"><?php esc_html_e('Select Package', 'snapbook'); ?></div>
                <div class="fpcards" id="fpb-pkgGrid"></div>

                <div class="fadd" id="fpb-addonsWrap" style="display:none">
                    <div class="fadd-title"><?php esc_html_e('Add-ons', 'snapbook'); ?> <span class="fadd-opt">(<?php esc_html_e('Optional', 'snapbook'); ?>)</span></div>
                    <div class="fadd-grid" id="fpb-addonsGrid"></div>
                </div>

                <!-- 50% payment toggle — shown right after the add-ons -->
                <div class="fadd" id="fpb-partialWrap" style="display:none">
                    <div class="fadd-title"><?php esc_html_e('Payment Option', 'snapbook'); ?></div>
                    <label class="fadd-card fpb-partial-card" for="fpb-partialToggle">
                        <input class="ac" type="checkbox" id="fpb-partialToggle" checked>
                        <span class="fpb-partial-em" aria-hidden="true">50%</span>
                        <span class="fadd-info">
                            <span class="fadd-name" id="fpb-partialLabel"><?php echo esc_html(get_option('fpb_partial_option_label', __('Book a slot to 50% Pay', 'snapbook'))); ?></span>
                            <span class="fadd-desc" id="fpb-partialNote"></span>
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
                    <div class="fpb-price-cell is-due">
                        <span class="fpb-price-label" id="fpb-s2DueLabel"><?php esc_html_e('Pay now', 'snapbook'); ?></span>
                        <span class="fpb-price-val" id="fpb-s2Due">—</span>
                    </div>
                    <div class="fpb-price-cell" id="fpb-s2LaterCell" style="display:none">
                        <span class="fpb-price-label"><?php esc_html_e('Pay later', 'snapbook'); ?></span>
                        <span class="fpb-price-val" id="fpb-s2Later">—</span>
                    </div>
                </div>

                <div class="fnav">
                    <button class="btn bo" onclick="fpb.bkGo(1)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="btn bg" id="fpb-s2NextBtn" onclick="fpb.s2Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s2err" class="ferr"></p>
            </div>
        </div>

        <!-- STEP 3 — Details (checkout form, fields managed in SnapBook → Settings) -->
        <div class="fstep" id="fpb-s3">
            <div class="fstep-inner">
                <h2 class="ftitle"><?php esc_html_e('Your Details', 'snapbook'); ?></h2>
                <p class="fsub"><?php esc_html_e('Fill in your booking and contact details.', 'snapbook'); ?></p>

                <div class="fdetails-groups" id="fpb-detailsGrid">
                    <?php sb_render_checkout_form_fields(); ?>
                </div>

                <div class="fnav">
                    <button class="btn bo" onclick="fpb.bkGo(2)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="btn bg" id="fpb-s3NextBtn" onclick="fpb.s3Next()"><?php esc_html_e('Continue', 'snapbook'); ?> &#8594;</button>
                </div>
                <p id="fpb-s3err" class="ferr"></p>
            </div>
        </div>

        <!-- STEP 4 — Payment -->
        <div class="fstep" id="fpb-s4">
            <div class="fstep-inner" id="fpb-payWrap">
                <h2 class="ftitle"><?php esc_html_e('Review & Payment', 'snapbook'); ?></h2>
                <p class="fsub"><?php esc_html_e("Review your booking, choose how you'd like to pay, and confirm.", 'snapbook'); ?></p>

                <!-- Live Booking Summary -->
                <div class="bsum" id="fpb-pay-summary">
                    <h5><?php esc_html_e('Booking Summary', 'snapbook'); ?></h5>
                    <div class="sumr"><span><?php esc_html_e('Session', 'snapbook'); ?></span><span class="v" id="fpb-sum-session">—</span></div>
                    <div class="sumr"><span><?php esc_html_e('Package', 'snapbook'); ?></span><span class="v" id="fpb-sum-pkg">—</span></div>
                    <div class="sumr"><span><?php esc_html_e('Date', 'snapbook'); ?></span><span class="v" id="fpb-sum-date">—</span></div>
                    <div class="sumr"><span><?php esc_html_e('Add-ons', 'snapbook'); ?></span><span class="v" id="fpb-sum-addons">—</span></div>
                    <div class="sumr"><span><?php esc_html_e('Total price', 'snapbook'); ?></span><span class="v" id="fpb-sum-price">—</span></div>
                    <div class="sumt">
                        <div>
                            <div class="sumtl"><?php esc_html_e('Due Now', 'snapbook'); ?></div>
                            <div class="sumtd" id="fpb-sum-dep"></div>
                        </div>
                        <div class="sumtn" id="fpb-sum-total">—</div>
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

                <div class="fnav">
                    <button class="btn bo" onclick="fpb.bkGo(3)">&#8592; <?php esc_html_e('Back', 'snapbook'); ?></button>
                    <button class="btn bg fpb-checkout-btn" id="fpb-checkoutBtn" onclick="fpb.proceedToCheckout()"><?php echo esc_html($checkout_label); ?> &#8594;</button>
                </div>
                <p id="fpb-s4err" class="ferr"></p>
                <p id="fpb-checkoutMsg" class="fpb-checkout-msg"></p>
            </div>
            <!-- In-place booking confirmation (direct checkout mode) -->
            <div class="suc" id="fpb-confirmWrap" style="display:none">
                <div class="fstep-inner suc-inner">
                    <div class="suc-icon">✓</div>
                    <h2 class="ftitle" id="fpb-confirmTitle"><?php echo esc_html(get_option('fpb_confirm_title', __('Booking Confirmed!', 'snapbook'))); ?></h2>
                    <p class="fsub" id="fpb-confirmNote"></p>
                    <div class="bsum fpb-confirm-summary">
                        <div class="sumr"><span><?php esc_html_e('Order', 'snapbook'); ?></span><span class="v" id="fpb-confirmOrder">—</span></div>
                        <div class="sumr"><span><?php esc_html_e('Payment method', 'snapbook'); ?></span><span class="v" id="fpb-confirmMethod">—</span></div>
                        <div class="sumr"><span><?php esc_html_e('Amount', 'snapbook'); ?></span><span class="v" id="fpb-confirmAmount">—</span></div>
                        <div class="sumr"><span><?php esc_html_e('Status', 'snapbook'); ?></span><span class="v" id="fpb-confirmStatus">—</span></div>
                    </div>
                    <div class="fnav" style="justify-content:center;margin-top:1.6rem">
                        <a class="btn bg" id="fpb-confirmPayBtn" href="#" style="display:none"><?php esc_html_e('Complete Payment', 'snapbook'); ?> &#8594;</a>
                        <a class="btn bo" id="fpb-confirmViewBtn" href="#" style="display:none"><?php esc_html_e('View Order Details', 'snapbook'); ?></a>
                        <a class="btn bo" id="fpb-confirmWaBtn" href="#" target="_blank" rel="noopener" style="display:none"><?php echo esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp')); ?></a>
                    </div>
                </div>
            </div>
            <!-- Success state (fallback email flow) -->
            <div class="suc" id="fpb-sucWrap" style="display:none">
                <div class="fstep-inner suc-inner">
                    <div class="suc-icon">✓</div>
                    <h2 class="ftitle"><?php echo esc_html(get_option('fpb_success_title', 'Booking Requested!')); ?></h2>
                    <p><?php echo esc_html(get_option('fpb_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to")); ?> <strong id="fpb-sucEmail"></strong>.</p>
                    <div class="fnav" style="justify-content:center;margin-top:2rem">
                        <a class="btn bg" id="fpb-waLink" href="#" target="_blank" rel="noopener"><?php echo esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp')); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
}
