<?php

/**
 * SnapBook custom booking confirmation email (HTML).
 *
 * Replaces WooCommerce's customer order email body when the custom message
 * is enabled under SnapBook → Settings → Order Email, so the customer sees
 * only the admin's wording instead of it plus WooCommerce's default copy.
 *
 * Available from wc_get_template(): $order, $email_heading, $sent_to_admin,
 * $plain_text, $email, $additional_content.
 *
 * @package SnapBook
 */

if (! defined('ABSPATH')) {
    exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);

// The admin's message. Already sanitised with wp_kses_post on save and
// again when built, so it is safe rich text.
echo snapbook_order_email_body_html($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$snapbook_settings = snapbook_get_order_email_settings();

if (! empty($snapbook_settings['order_table'])) {
    /*
     * @hooked WC_Emails::order_details() Shows the order details table.
     * Also fires woocommerce_email_after_order_table, which renders the
     * remaining-balance link.
     */
    do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

    /*
     * @hooked WC_Emails::order_meta() Shows order meta data.
     */
    do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

    /*
     * @hooked WC_Emails::customer_details() Shows customer details
     */
    do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
} elseif (function_exists('snapbook_email_append_balance_link')) {
    // Without the order table the after-table hook never fires, so call the
    // balance CTA directly — a deposit booking must still show how to pay.
    snapbook_email_append_balance_link($order, $sent_to_admin, $plain_text, $email);
}

if (! empty($additional_content)) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);
