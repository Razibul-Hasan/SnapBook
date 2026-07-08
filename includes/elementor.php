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

            public function get_style_depends()
            {
                return ['sb-booking-css'];
            }

            public function get_script_depends()
            {
                return ['sb-booking-js'];
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
                if (
                    class_exists('\Elementor\Plugin') &&
                    isset(\Elementor\Plugin::$instance) &&
                    isset(\Elementor\Plugin::$instance->editor) &&
                    method_exists(\Elementor\Plugin::$instance->editor, 'is_edit_mode') &&
                    \Elementor\Plugin::$instance->editor->is_edit_mode()
                ) {
                    echo '<style>
.elementor-editor-active .fpb-wrap{max-width:100%;width:100%;}
.elementor-editor-active .fpb-wrap .bklayout{grid-template-columns:1fr;gap:1.5rem;}
.elementor-editor-active .fpb-wrap .pgrid{grid-template-columns:1fr;}
.elementor-editor-active .fpb-wrap .fsteps{flex-wrap:wrap;gap:.5rem;}
</style>';
                }

                echo do_shortcode('[snapbook]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
    }

    $widgets_manager->register(new SB_Elementor_Booking_Widget());
}
