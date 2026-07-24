<?php

/**
 * SnapBook admin "New booking" notification (plain text).
 *
 * Plain-text counterpart of templates/emails/snapbook-admin-order.php, laid
 * out section for section. Nothing is escaped here: this is not an HTML
 * context, and escaping would leave the admin reading "&amp;" instead of "&".
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

echo "= " . wp_strip_all_tags($email_heading) . " =\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$snapbook_intro = snapbook_email_plain_from_html(snapbook_admin_email_intro_html($order));
if ($snapbook_intro !== '') {
    echo $snapbook_intro . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$snapbook_sections = [
    __('Booking', 'snapbook')  => snapbook_email_plain_facts(snapbook_email_booking_facts_rows($order)),
    __('Customer', 'snapbook') => snapbook_email_plain_facts(snapbook_email_admin_contact_rows($order)),
    __('Payment', 'snapbook')  => snapbook_email_plain_facts(snapbook_email_admin_payment_facts_rows($order)),
];

foreach ($snapbook_sections as $snapbook_label => $snapbook_facts) {
    if (trim($snapbook_facts) === '') {
        continue;
    }
    echo snapbook_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags($snapbook_label)) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $snapbook_facts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$snapbook_note = snapbook_admin_email_customer_note($order);
if ($snapbook_note !== '') {
    echo snapbook_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags(__('Customer note', 'snapbook'))) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $snapbook_note . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "\n\n" . snapbook_email_plain_from_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
