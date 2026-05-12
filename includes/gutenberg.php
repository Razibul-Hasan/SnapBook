<?php
defined('ABSPATH') || exit;

/*
 * Gutenberg compatibility for SnapBook without React:
 * register a block pattern that inserts the native Shortcode block.
 */
add_action('init', 'sb_register_gutenberg_support');
function sb_register_gutenberg_support()
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
        'title'       => __('SnapBook Booking Form', 'snapbook'),
        'description' => __('Insert the SnapBook booking form using the native Shortcode block.', 'snapbook'),
        'categories'  => ['snapbook'],
        'blockTypes'  => ['core/shortcode'],
        'content'     => "<!-- wp:shortcode -->\n[snapbook]\n<!-- /wp:shortcode -->",
    ]);
}
