<?php

/**
 * SnapBook custom booking confirmation email (HTML).
 *
 * Replaces WooCommerce's customer order email body when the custom message
 * is enabled under SnapBook → Settings → Order Email, so the customer sees
 * only the admin's wording instead of it plus WooCommerce's default copy.
 *
 * The whole document — shell, masthead, footer — comes from SnapBook's email
 * design system (includes/emails.php) rather than WooCommerce's header and
 * footer templates, so the layout is designed end to end. WooCommerce still
 * runs its CSS inliner over the result; every element here carries its own
 * inline styles, which win over anything the inliner adds.
 *
 * Available from wc_get_template(): $order, $email_heading, $sent_to_admin,
 * $plain_text, $email, $additional_content.
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

$snapbook_settings = snapbook_get_order_email_settings();
$snapbook_content  = '';

$snapbook_meta = snapbook_get_order_booking_meta($order);

// Eye-catching hero — the heading, centered.
$snapbook_content .= '<div style="text-align:center;">';
$snapbook_content .= snapbook_email_title(wp_strip_all_tags($email_heading));
$snapbook_content .= '</div>';

// The admin's message. Sanitised with wp_kses_post on save and again when
// built, so it is safe rich text; the wrapper only adds email-safe styling.
$snapbook_content .= snapbook_email_rich_text(snapbook_order_email_body_html($order));

// Feature the session date — the one fact the customer looks for first.
$snapbook_pretty_date = snapbook_email_pretty_date($snapbook_meta['session_date']);
if ($snapbook_pretty_date !== '') {
    $snapbook_date_sub = trim(implode('  ·  ', array_filter([$snapbook_meta['session_type'], $snapbook_meta['package_name']])));
    $snapbook_content .= snapbook_email_highlight(__('Your session date', 'snapbook'), $snapbook_pretty_date, $snapbook_date_sub);
}

// What was booked — the finer detail beneath the headline date.
$snapbook_booking_facts = snapbook_email_booking_facts_html($order);
if ($snapbook_booking_facts !== '') {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_section_label(__('Your session', 'snapbook'));
    $snapbook_content .= $snapbook_booking_facts;
}

if (! empty($snapbook_settings['order_table'])) {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_section_label(__('Order summary', 'snapbook'));
    // Branded totals panel (booking total / deposit / balance) in the same
    // label/value design as the rest of the email, matching the admin notice.
    $snapbook_content .= snapbook_email_money_facts_html($order);
}

// The remaining-balance CTA and anything third parties append to the order
// table. Fired even without the order summary above, so a deposit booking
// always tells the customer how to settle the rest.
ob_start();
do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email);
$snapbook_after_table = trim((string) ob_get_clean());
if ($snapbook_after_table !== '') {
    $snapbook_content .= $snapbook_after_table;
}

if (! empty($additional_content)) {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_rich_text(wp_kses_post(wpautop(wptexturize($additional_content))));
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own data.
echo snapbook_email_wrap($snapbook_content, [
    'preheader' => trim(sprintf(
        /* translators: 1: package name, 2: session date */
        __('%1$s — %2$s', 'snapbook'),
        $snapbook_meta['package_name'] !== '' ? $snapbook_meta['package_name'] : get_bloginfo('name'),
        $snapbook_meta['session_date']
    ), " —\t\n"),
    'eyebrow'   => __('Booking confirmation', 'snapbook'),
]);
