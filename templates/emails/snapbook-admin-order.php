<?php

/**
 * SnapBook admin "New booking" notification (HTML).
 *
 * Replaces WooCommerce's admin New Order email body when the branded admin
 * email is enabled under SnapBook → Settings → Admin Order Email. It gives the
 * studio the full picture of a booking in one place: what was booked, who
 * booked it, the money breakdown (deposit taken vs. balance still due), any
 * note the customer left, and a one-click link to manage the order.
 *
 * The document shell, masthead and footer come from SnapBook's email design
 * system (includes/emails.php); every element is inline-styled so WooCommerce's
 * CSS inliner leaves the design untouched.
 *
 * Available from wc_get_template(): $order, $email_heading, $sent_to_admin,
 * $plain_text, $email, $additional_content.
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

$snapbook_content = '';

$snapbook_status_label = $order->is_paid()
    ? __('New booking — paid', 'snapbook')
    : __('New booking — awaiting payment', 'snapbook');

$snapbook_content .= snapbook_email_pill($snapbook_status_label, 'primary');
$snapbook_content .= snapbook_email_title(wp_strip_all_tags($email_heading));

// The admin's own intro note (optional).
$snapbook_intro = snapbook_admin_email_intro_html($order);
if ($snapbook_intro !== '') {
    $snapbook_content .= snapbook_email_rich_text($snapbook_intro);
}

// What was booked.
$snapbook_booking = snapbook_email_booking_facts_html($order);
if ($snapbook_booking !== '') {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_section_label(__('Booking', 'snapbook'));
    $snapbook_content .= $snapbook_booking;
}

// Who booked it — actionable contact (clickable email + WhatsApp), place of
// stay, address, country and any custom fields.
$snapbook_customer = snapbook_email_admin_contact_html($order);
if ($snapbook_customer !== '') {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_section_label(__('Customer', 'snapbook'));
    $snapbook_content .= $snapbook_customer;
}

// The money — deposit taken vs. balance still to collect.
$snapbook_payment = snapbook_email_admin_payment_facts_html($order);
if ($snapbook_payment !== '') {
    $snapbook_content .= snapbook_email_divider(24);
    $snapbook_content .= snapbook_email_section_label(__('Payment', 'snapbook'));
    $snapbook_content .= $snapbook_payment;
}

// Anything the customer typed in the notes field, highlighted so it is seen.
$snapbook_note = snapbook_admin_email_customer_note($order);
if ($snapbook_note !== '') {
    $snapbook_content .= snapbook_email_spacer(22);
    $snapbook_content .= snapbook_email_callout([
        'title' => __('Customer note', 'snapbook'),
        'text'  => $snapbook_note,
    ]);
}

$snapbook_meta = snapbook_get_order_booking_meta($order);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own data.
echo snapbook_email_wrap($snapbook_content, [
    'preheader' => trim(sprintf(
        /* translators: 1: package name or order number, 2: session date */
        __('%1$s — %2$s', 'snapbook'),
        $snapbook_meta['package_name'] !== '' ? $snapbook_meta['package_name'] : ('#' . $order->get_order_number()),
        $snapbook_meta['session_date']
    ), " —\t\n"),
    'eyebrow'   => __('New booking', 'snapbook'),
]);
