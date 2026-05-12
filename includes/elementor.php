<?php
defined('ABSPATH') || exit;

/*
 * Elementor widget integration for SnapBook booking form.
 */
add_action('elementor/widgets/register', 'sb_register_elementor_widget');
function sb_register_elementor_widget($widgets_manager)
{
    if (! class_exists('\\Elementor\\Widget_Base')) {
        return;
    }

    if (! class_exists('SB_Elementor_Booking_Widget')) {
        class SB_Elementor_Booking_Widget extends \Elementor\Widget_Base
        {
            public function get_name()
            {
                return 'snapbook_booking_form';
            }

            public function get_title()
            {
                return __('SnapBook Booking Form', 'snapbook');
            }

            public function get_icon()
            {
                return 'eicon-calendar';
            }

            public function get_categories()
            {
                return ['general'];
            }

            protected function render()
            {
                echo do_shortcode('[snapbook]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
    }

    $widgets_manager->register(new SB_Elementor_Booking_Widget());
}
