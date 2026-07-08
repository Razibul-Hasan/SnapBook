<?php
defined('ABSPATH') || exit;

/*
 * Gutenberg (block editor) integration for SnapBook — build-step free.
 *
 * Registers a native dynamic block "snapbook/booking-form" whose editor UI
 * is a clean branded placeholder with an inspector panel (pre-select a
 * package, override accent colors). The block renders the [snapbook]
 * shortcode on the front end, passing the chosen options through. The
 * legacy Shortcode-block pattern is kept for back-compat.
 */

add_action('init', 'sb_register_gutenberg_block');
function sb_register_gutenberg_block()
{
    // Block category so the block groups nicely in the inserter.
    add_filter('block_categories_all', 'sb_register_block_category');

    if (! function_exists('register_block_type')) {
        return;
    }

    // Editor script (no build step — plain wp.* globals + createElement).
    wp_register_script(
        'sb-block-editor',
        SB_URL . 'assets/js/block-editor.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
        SB_VER,
        true
    );

    wp_localize_script('sb-block-editor', 'sbBlockData', [
        'packages'    => sb_get_active_packages_for_picker(),
        'settingsUrl' => admin_url('admin.php?page=snapbook-settings'),
        'i18n'        => [
            'title'          => __('SnapBook Booking Form', 'snapbook'),
            'description'    => __('A multi-step photography booking form: date, package & add-ons, details and payment.', 'snapbook'),
            'previewNote'    => __('The interactive form appears on the published page.', 'snapbook'),
            'bookingPanel'   => __('Booking Form', 'snapbook'),
            'packageLabel'   => __('Pre-select a package', 'snapbook'),
            'packageNone'    => __('— None (let the visitor choose) —', 'snapbook'),
            'packageHelp'    => __('Skips straight to this package after the date step.', 'snapbook'),
            'colorsPanel'    => __('Colors', 'snapbook'),
            'primaryColor'   => __('Primary color', 'snapbook'),
            'accentColor'    => __('Accent color', 'snapbook'),
            'colorsHelp'     => __('Override the accent colors for this form only. Leave empty to use the global palette.', 'snapbook'),
            'selectedPrefix' => __('Pre-selected:', 'snapbook'),
        ],
    ]);

    // The stylesheet is registered on `init` by sb_register_assets(); make
    // sure the block editor iframe pulls it in for accurate placeholder styling.
    $editor_style = wp_style_is('sb-booking-css', 'registered') ? 'sb-booking-css' : '';

    register_block_type('snapbook/booking-form', [
        'api_version'     => 2,
        'title'           => __('SnapBook Booking Form', 'snapbook'),
        'description'     => __('Insert the SnapBook multi-step booking form.', 'snapbook'),
        'category'        => 'snapbook',
        'icon'            => 'calendar-alt',
        'keywords'        => ['snapbook', 'booking', 'appointment', 'calendar', 'photography'],
        'editor_script'   => 'sb-block-editor',
        'editor_style'    => $editor_style,
        'render_callback' => 'sb_render_gutenberg_block',
        'supports'        => [
            'align' => ['wide', 'full'],
            'html'  => false,
        ],
        'attributes'      => [
            'package'      => ['type' => 'string', 'default' => ''],
            'primaryColor' => ['type' => 'string', 'default' => ''],
            'accentColor'  => ['type' => 'string', 'default' => ''],
            'align'        => ['type' => 'string', 'default' => ''],
        ],
    ]);
}

/**
 * Server render for the SnapBook block: delegate to the [snapbook]
 * shortcode, threading through the pre-selected package and per-instance
 * colors chosen in the inspector.
 */
function sb_render_gutenberg_block($attributes = [])
{
    $package = isset($attributes['package']) ? trim((string) $attributes['package']) : '';
    $primary = isset($attributes['primaryColor']) ? (string) $attributes['primaryColor'] : '';
    $accent  = isset($attributes['accentColor']) ? (string) $attributes['accentColor'] : '';
    $align   = isset($attributes['align']) ? (string) $attributes['align'] : '';

    $sc = '[snapbook';
    if ($package !== '') {
        $sc .= ' package="' . esc_attr($package) . '"';
    }
    if ($primary !== '') {
        $sc .= ' primary="' . esc_attr($primary) . '"';
    }
    if ($accent !== '') {
        $sc .= ' accent="' . esc_attr($accent) . '"';
    }
    $sc .= ']';

    $classes = 'sb-gb-wrap';
    if ($align !== '') {
        $classes .= ' align' . preg_replace('/[^a-z]/', '', $align);
    }

    return '<div class="' . esc_attr($classes) . '">' . do_shortcode($sc) . '</div>';
}

/**
 * Add the SnapBook category to the block inserter.
 */
function sb_register_block_category($categories)
{
    foreach ($categories as $cat) {
        if (isset($cat['slug']) && $cat['slug'] === 'snapbook') {
            return $categories;
        }
    }
    array_unshift($categories, [
        'slug'  => 'snapbook',
        'title' => __('SnapBook', 'snapbook'),
        'icon'  => null,
    ]);
    return $categories;
}

/*
 * Back-compat: keep the classic Shortcode-block pattern so existing content
 * and the native Shortcode block continue to work.
 */
add_action('init', 'sb_register_gutenberg_pattern');
function sb_register_gutenberg_pattern()
{
    if (function_exists('register_block_pattern_category')) {
        register_block_pattern_category('snapbook', [
            'label' => __('SnapBook', 'snapbook'),
        ]);
    }

    if (! function_exists('register_block_pattern')) {
        return;
    }

    register_block_pattern('snapbook/booking-form-shortcode', [
        'title'       => __('SnapBook Booking Form (Shortcode)', 'snapbook'),
        'description' => __('Insert the SnapBook booking form using the native Shortcode block.', 'snapbook'),
        'categories'  => ['snapbook'],
        'blockTypes'  => ['core/shortcode'],
        'content'     => "<!-- wp:shortcode -->\n[snapbook]\n<!-- /wp:shortcode -->",
    ]);
}
