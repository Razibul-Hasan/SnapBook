<?php

/**
 * SnapBook custom booking confirmation email (plain text).
 *
 * Plain-text counterpart of templates/emails/snapbook-order.php, laid out to
 * mirror it section for section. Nothing is escaped here: this is not an HTML
 * context, and escaping would leave the customer reading "&amp;" instead of "&".
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

$snapbook_settings = snapbook_get_order_email_settings();

echo "= " . wp_strip_all_tags($email_heading) . " =\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

echo snapbook_order_email_body_plain($order) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$snapbook_meta = snapbook_get_order_booking_meta($order);
$snapbook_facts = snapbook_email_plain_facts([
    ['label' => __('Session', 'snapbook'), 'value' => $snapbook_meta['session_type']],
    ['label' => __('Package', 'snapbook'), 'value' => $snapbook_meta['package_name']],
    ['label' => __('Date', 'snapbook'), 'value' => $snapbook_meta['session_date']],
    ['label' => __('Time', 'snapbook'), 'value' => (string) $order->get_meta('_fpb_billing_event_time', true)],
    ['label' => __('Booking reference', 'snapbook'), 'value' => '#' . $order->get_order_number()],
]);

if ($snapbook_facts !== '') {
    echo snapbook_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags(__('Your session', 'snapbook'))) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $snapbook_facts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

ob_start();

if (! empty($snapbook_settings['order_table'])) {
    echo snapbook_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    do_action('woocommerce_email_order_details', $order, $sent_to_admin, true, $email);
    echo "\n";
    do_action('woocommerce_email_order_meta', $order, $sent_to_admin, true, $email);
    do_action('woocommerce_email_customer_details', $order, $sent_to_admin, true, $email);
} else {
    // Without the order table the after-table hook never fires from
    // WooCommerce, so run it here — the deposit CTA must not vanish.
    do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, true, $email);
}

// WooCommerce's plain templates leave prices as HTML entities (&#2547;);
// decode them so the customer reads the currency symbol itself.
echo html_entity_decode((string) ob_get_clean(), ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if (! empty($additional_content)) {
    echo "\n\n" . snapbook_email_plain_from_html(wptexturize($additional_content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "\n\n" . snapbook_email_plain_from_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
