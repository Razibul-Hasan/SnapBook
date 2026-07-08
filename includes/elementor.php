<?php
defined('ABSPATH') || exit;

/*
 * Elementor integration for the SnapBook booking form.
 *
 * Registers a dedicated "SnapBook" widget category and a booking-form
 * widget with content, layout and color controls. The widget renders the
 * [snapbook] shortcode, passing through the editor's package pre-selection
 * and per-instance colors so the drag-and-drop experience matches the
 * front-end exactly.
 */

// Dedicated widget category so the widget is easy to find in the panel.
add_action('elementor/elements/categories_registered', 'sb_register_elementor_category');
function sb_register_elementor_category($elements_manager)
{
    $elements_manager->add_category('snapbook', [
        'title' => __('SnapBook', 'snapbook'),
        'icon'  => 'eicon-calendar',
    ]);
}

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
                return ['snapbook', 'general'];
            }

            public function get_keywords()
            {
                return ['snapbook', 'booking', 'calendar', 'appointment', 'photography', 'form', 'reservation', 'schedule'];
            }

            public function get_style_depends()
            {
                return ['sb-booking-css'];
            }

            public function get_script_depends()
            {
                return ['sb-booking-js'];
            }

            /* ---------------------------------------------------------------
               Controls
            --------------------------------------------------------------- */
            protected function register_controls()
            {
                /* Content › Booking */
                $this->start_controls_section('sb_section_content', [
                    'label' => __('Booking Form', 'snapbook'),
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ]);

                $packages = ['' => __('— None (let the visitor choose) —', 'snapbook')];
                foreach (sb_get_active_packages_for_picker() as $p) {
                    $packages[$p['value']] = $p['label'];
                }

                $this->add_control('sb_package', [
                    'label'       => __('Pre-select a package', 'snapbook'),
                    'type'        => \Elementor\Controls_Manager::SELECT2,
                    'options'     => $packages,
                    'default'     => '',
                    'label_block' => true,
                    'description' => __('Skips straight to this package after the date step. Manage packages under SnapBook → Packages.', 'snapbook'),
                ]);

                $this->add_control('sb_content_note', [
                    'type'            => \Elementor\Controls_Manager::RAW_HTML,
                    'raw'             => __('The form’s step titles, checkout fields, currency and payment options are managed in <strong>SnapBook → Settings</strong>.', 'snapbook'),
                    'content_classes' => 'elementor-descriptor',
                ]);

                $this->end_controls_section();

                /* Content › Layout */
                $this->start_controls_section('sb_section_layout', [
                    'label' => __('Layout', 'snapbook'),
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ]);

                $this->add_responsive_control('sb_max_width', [
                    'label'      => __('Max width', 'snapbook'),
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => ['px', '%', 'vw'],
                    'range'      => [
                        'px' => ['min' => 320, 'max' => 1200, 'step' => 10],
                        '%'  => ['min' => 20,  'max' => 100],
                        'vw' => ['min' => 20,  'max' => 100],
                    ],
                    'selectors'  => [
                        '{{WRAPPER}} .fpb-wrap' => 'max-width:{{SIZE}}{{UNIT}};',
                    ],
                ]);

                $this->add_responsive_control('sb_align', [
                    'label'     => __('Alignment', 'snapbook'),
                    'type'      => \Elementor\Controls_Manager::CHOOSE,
                    'options'   => [
                        'flex-start' => ['title' => __('Left', 'snapbook'),   'icon' => 'eicon-h-align-left'],
                        'center'     => ['title' => __('Center', 'snapbook'), 'icon' => 'eicon-h-align-center'],
                        'flex-end'   => ['title' => __('Right', 'snapbook'),  'icon' => 'eicon-h-align-right'],
                    ],
                    'default'   => 'center',
                    'selectors' => [
                        '{{WRAPPER}} .sb-el-wrap' => 'display:flex;flex-direction:column;align-items:{{VALUE}};',
                    ],
                ]);

                $this->end_controls_section();

                /* Style › Colors */
                $this->start_controls_section('sb_section_colors', [
                    'label' => __('Colors', 'snapbook'),
                    'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                ]);

                $this->add_control('sb_colors_note', [
                    'type'            => \Elementor\Controls_Manager::RAW_HTML,
                    'raw'             => __('Override the accent colors for this form only. Leave empty to use the global palette from SnapBook → Settings → Appearance.', 'snapbook'),
                    'content_classes' => 'elementor-descriptor',
                ]);

                $this->add_control('sb_primary_color', [
                    'label' => __('Primary color', 'snapbook'),
                    'type'  => \Elementor\Controls_Manager::COLOR,
                ]);

                $this->add_control('sb_accent_color', [
                    'label' => __('Accent color', 'snapbook'),
                    'type'  => \Elementor\Controls_Manager::COLOR,
                ]);

                $this->end_controls_section();
            }

            /* ---------------------------------------------------------------
               Render
            --------------------------------------------------------------- */
            protected function render()
            {
                $settings = $this->get_settings_for_display();
                $package  = isset($settings['sb_package']) ? trim((string) $settings['sb_package']) : '';
                $primary  = isset($settings['sb_primary_color']) ? $settings['sb_primary_color'] : '';
                $accent   = isset($settings['sb_accent_color']) ? $settings['sb_accent_color'] : '';

                // Scope per-instance colors + editor tidy-up to this widget only.
                $uid      = 'sb-el-' . $this->get_id();
                $color_css = sb_theme_vars_css($primary, $accent, '.' . $uid . ' .fpb-wrap');

                $is_editor = (
                    class_exists('\Elementor\Plugin') &&
                    isset(\Elementor\Plugin::$instance) &&
                    isset(\Elementor\Plugin::$instance->editor) &&
                    method_exists(\Elementor\Plugin::$instance->editor, 'is_edit_mode') &&
                    \Elementor\Plugin::$instance->editor->is_edit_mode()
                );

                $editor_css = $is_editor ? (
                    '.elementor-editor-active .' . $uid . ' .fpb-wrap{max-width:100%;width:100%;}' .
                    '.elementor-editor-active .' . $uid . ' .fpcards{grid-template-columns:1fr;}' .
                    '.elementor-editor-active .' . $uid . ' .fadd-grid{grid-template-columns:1fr;}' .
                    '.elementor-editor-active .' . $uid . ' .sbar2{flex-wrap:wrap;gap:.25rem;}'
                ) : '';

                $inline = $color_css . $editor_css;
                if ($inline !== '') {
                    echo '<style>' . $inline . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }

                echo '<div class="sb-el-wrap ' . esc_attr($uid) . '">';

                $sc = '[snapbook';
                if ($package !== '') {
                    $sc .= ' package="' . esc_attr($package) . '"';
                }
                $sc .= ']';
                echo do_shortcode($sc); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                echo '</div>';
            }
        }
    }

    $widgets_manager->register(new SB_Elementor_Booking_Widget());
}
