<?php

/**
 * SnapBook custom booking confirmation email (plain text).
 *
 * Plain-text counterpart of templates/emails/snapbook-order.php. Nothing is
 * escaped here: this is not an HTML context, and escaping would leave the
 * customer reading "&amp;" instead of "&".
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

echo "= " . esc_html(wp_strip_all_tags($email_heading)) . " =\n\n";

echo snapbook_order_email_body_plain($order) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$snapbook_settings = snapbook_get_order_email_settings();

if (! empty($snapbook_settings['order_table'])) {
    echo "\n----------------------------------------\n\n";
    do_action('woocommerce_email_order_details', $order, $sent_to_admin, true, $email);
    echo "\n----------------------------------------\n\n";
    do_action('woocommerce_email_order_meta', $order, $sent_to_admin, true, $email);
    do_action('woocommerce_email_customer_details', $order, $sent_to_admin, true, $email);
} elseif (function_exists('snapbook_email_append_balance_link')) {
    snapbook_email_append_balance_link($order, $sent_to_admin, true, $email);
}

if (! empty($additional_content)) {
    echo "\n\n" . esc_html(wp_strip_all_tags(wptexturize($additional_content)));
}

echo "\n\n" . esc_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
