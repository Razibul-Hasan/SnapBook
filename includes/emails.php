<?php

/**
 * SnapBook email design system.
 *
 * One branded, table-based HTML shell shared by every message the plugin
 * sends — the WooCommerce booking confirmation (templates/emails/), the
 * balance reminder, and the WooCommerce-less enquiry emails. Everything is
 * inline-styled: email clients strip <style> blocks, and WooCommerce runs
 * its own CSS inliner over our markup (inline attributes win there, so the
 * design survives untouched).
 *
 * Colors follow SnapBook → Settings → Appearance, so the emails match the
 * booking form without a second setting to maintain.
 *
 * @package SnapBook
 */

defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Tokens
───────────────────────────────────────────────────────────── */

/**
 * Readable text color for a filled brand-colored surface.
 */
function snapbook_email_contrast_color($hex)
{
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '#ffffff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Perceived luminance (ITU-R BT.601).
    $luma = ($r * 299 + $g * 587 + $b * 114) / 1000;

    return $luma > 165 ? '#1c1916' : '#ffffff';
}

/**
 * The palette every email component draws from. Filterable so a site can
 * re-skin the emails without touching templates.
 */
function snapbook_email_palette()
{
    $colors = function_exists('snapbook_get_theme_colors')
        ? snapbook_get_theme_colors()
        : ['primary' => '#b8956a', 'accent' => '#3d6b78'];

    $mix = static function ($hex, $towards, $ratio) {
        return function_exists('snapbook_hex_mix') ? snapbook_hex_mix($hex, $towards, $ratio) : $hex;
    };

    $primary = $colors['primary'];
    $accent  = $colors['accent'];

    return (array) apply_filters('snapbook_email_palette', [
        'primary'     => $primary,
        'primary_dk'  => $mix($primary, '#000000', 0.20),
        'primary_lt'  => $mix($primary, '#ffffff', 0.88),
        'accent'      => $accent,
        'accent_dk'   => $mix($accent, '#000000', 0.18),
        'accent_lt'   => $mix($accent, '#ffffff', 0.90),
        'on_primary'  => snapbook_email_contrast_color($primary),
        'on_accent'   => snapbook_email_contrast_color($accent),
        'bg'          => '#f4f1ec',
        'card'        => '#ffffff',
        'panel'       => '#faf9f7',
        'border'      => '#e8e3dc',
        'rule'        => '#efeae3',
        'text'        => '#1c1916',
        'sub'         => '#6b6259',
        'muted'       => '#a09690',
        'serif'       => "Georgia, 'Times New Roman', Times, serif",
        'sans'        => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif",
    ]);
}

/* ─────────────────────────────────────────────────────────────
   Shell
───────────────────────────────────────────────────────────── */

/**
 * Masthead: the WooCommerce email header image if the merchant set one,
 * then the site logo, then a typographic wordmark.
 */
function snapbook_email_masthead_html($palette)
{
    $image = '';
    if (function_exists('get_option')) {
        $image = (string) get_option('woocommerce_email_header_image', '');
    }
    if ($image === '' && function_exists('get_theme_mod')) {
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id > 0) {
            $image = (string) wp_get_attachment_image_url($logo_id, 'medium');
        }
    }

    $name = get_bloginfo('name', 'display');

    if ($image !== '') {
        return '<img src="' . esc_url($image) . '" alt="' . esc_attr($name) . '" width="180" style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;width:auto;max-width:180px;height:auto;max-height:56px;">';
    }

    return '<span style="font-family:' . esc_attr($palette['serif']) . ';font-size:24px;line-height:1.2;letter-spacing:0.06em;color:' . esc_attr($palette['text']) . ';">'
        . esc_html($name) . '</span>';
}

/**
 * Wrap body markup in the branded document shell.
 *
 * @param string $content Inner HTML (already escaped by the component helpers).
 * @param array  $args    preheader, eyebrow, footer.
 */
function snapbook_email_wrap($content, $args = [])
{
    $palette = snapbook_email_palette();
    $args    = wp_parse_args($args, [
        'preheader' => '',
        'eyebrow'   => '',
        'footer'    => '',
    ]);

    $site_name = get_bloginfo('name', 'display');
    $site_url  = home_url('/');

    $footer = trim((string) $args['footer']);
    if ($footer === '') {
        $wc_footer = (string) get_option('woocommerce_email_footer_text', '');
        if (trim(wp_strip_all_tags($wc_footer)) !== '') {
            // WooCommerce owns this string's placeholders ({site_title},
            // {store_address}, …) — let it resolve them.
            if (function_exists('WC') && WC()->mailer()) {
                $wc_footer = WC()->mailer()->replace_placeholders($wc_footer);
            }
            $footer = wp_kses_post($wc_footer);
        } else {
            $footer = '<a href="' . esc_url($site_url) . '" style="color:' . esc_attr($palette['sub']) . ';text-decoration:none;">' . esc_html($site_name) . '</a>';
        }
    }

    $preheader = '';
    if ($args['preheader'] !== '') {
        // Inbox preview text. Deliberately avoids display:none — WooCommerce's
        // CSS inliner prunes display:none nodes out of the document.
        $preheader = '<div style="font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:transparent;mso-hide:all;">'
            . esc_html($args['preheader'])
            . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>';
    }

    $eyebrow = '';
    if ($args['eyebrow'] !== '') {
        $eyebrow = '<div style="margin:10px 0 0;font-family:' . esc_attr($palette['sans']) . ';font-size:10px;line-height:1.4;letter-spacing:0.22em;text-transform:uppercase;color:' . esc_attr($palette['muted']) . ';">'
            . esc_html($args['eyebrow']) . '</div>';
    }

    $html  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="' . esc_attr(get_bloginfo('language')) . '">' . "\n";
    $html .= '<head>' . "\n";
    $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\n";
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
    $html .= '<meta name="x-apple-disable-message-reformatting" />' . "\n";
    $html .= '<title>' . esc_html($site_name) . '</title>' . "\n";
    $html .= '<style type="text/css">
        @media only screen and (max-width: 620px) {
            .sb-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .sb-h1 { font-size: 23px !important; }
            .sb-stack { display: block !important; width: 100% !important; text-align: left !important; padding-left: 0 !important; }
            .sb-stack-v { padding-top: 2px !important; padding-bottom: 10px !important; }
        }
    </style>' . "\n";
    $html .= '</head>' . "\n";
    $html .= '<body style="margin:0;padding:0;width:100%;background-color:' . esc_attr($palette['bg']) . ';-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;">' . "\n";
    $html .= $preheader;
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background-color:' . esc_attr($palette['bg']) . ';">';
    $html .= '<tr><td align="center" style="padding:32px 14px;">';

    // Fluid card with an Outlook-only fixed-width wrapper: max-width alone
    // keeps it 600px everywhere except Word-rendered Outlook, which ignores it.
    $html .= '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td><![endif]-->';
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background-color:' . esc_attr($palette['card']) . ';border:1px solid ' . esc_attr($palette['border']) . ';border-radius:14px;overflow:hidden;">';

    // Brand edge.
    $html .= '<tr><td style="height:5px;line-height:5px;font-size:0;background-color:' . esc_attr($palette['primary']) . ';">&nbsp;</td></tr>';

    // Masthead.
    $html .= '<tr><td class="sb-pad" align="center" style="padding:30px 40px 24px;border-bottom:1px solid ' . esc_attr($palette['rule']) . ';">';
    $html .= snapbook_email_masthead_html($palette) . $eyebrow;
    $html .= '</td></tr>';

    // Body.
    $html .= '<tr><td class="sb-pad" style="padding:32px 40px 36px;">' . $content . '</td></tr>';

    // Footer.
    $html .= '<tr><td class="sb-pad" align="center" style="padding:22px 40px 26px;background-color:' . esc_attr($palette['panel']) . ';border-top:1px solid ' . esc_attr($palette['rule']) . ';">';
    $html .= '<div style="font-family:' . esc_attr($palette['sans']) . ';font-size:12px;line-height:1.7;color:' . esc_attr($palette['sub']) . ';">' . $footer . '</div>';
    $html .= '</td></tr>';

    $html .= '</table>';
    $html .= '<!--[if mso]></td></tr></table><![endif]-->';

    $html .= '<div style="max-width:600px;margin:16px auto 0;font-family:' . esc_attr($palette['sans']) . ';font-size:11px;line-height:1.6;color:' . esc_attr($palette['muted']) . ';text-align:center;">';
    /* translators: %s: site name */
    $html .= esc_html(sprintf(__('This message was sent by %s regarding your booking.', 'snapbook'), $site_name));
    $html .= '</div>';

    $html .= '</td></tr></table>' . "\n";
    $html .= '</body></html>';

    return $html;
}

/* ─────────────────────────────────────────────────────────────
   Components
───────────────────────────────────────────────────────────── */

/**
 * Small uppercase status pill, e.g. "Booking confirmed".
 */
function snapbook_email_pill($label, $tone = 'accent')
{
    $p    = snapbook_email_palette();
    $bg   = $tone === 'primary' ? $p['primary_lt'] : $p['accent_lt'];
    $text = $tone === 'primary' ? $p['primary_dk'] : $p['accent_dk'];

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0 0 14px;"><tr>'
        . '<td style="padding:6px 13px;background-color:' . esc_attr($bg) . ';border-radius:100px;font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1;letter-spacing:0.12em;text-transform:uppercase;color:' . esc_attr($text) . ';">'
        . esc_html($label) . '</td></tr></table>';
}

/**
 * Page title.
 */
function snapbook_email_title($text)
{
    $p = snapbook_email_palette();

    return '<h1 class="sb-h1" style="margin:0 0 14px;font-family:' . esc_attr($p['serif']) . ';font-size:27px;line-height:1.25;font-weight:400;color:' . esc_attr($p['text']) . ';">'
        . esc_html($text) . '</h1>';
}

/**
 * Body paragraph. Pass pre-sanitised rich text as $is_html.
 */
function snapbook_email_text($text, $is_html = false)
{
    $p    = snapbook_email_palette();
    $body = $is_html ? $text : esc_html($text);

    return '<div style="margin:0 0 18px;font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.7;color:' . esc_attr($p['sub']) . ';">'
        . $body . '</div>';
}

/**
 * Normalise admin-authored rich text for email: paragraphs and links carry
 * no styling of their own in the editor, so give them ours.
 */
function snapbook_email_rich_text($html)
{
    $p = snapbook_email_palette();

    $html = str_replace(
        ['<p>', '<a ', '<h2>', '<h3>', '<ul>', '<ol>', '<li>', '<strong>', '<blockquote>'],
        [
            '<p style="margin:0 0 14px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<a style="color:' . $p['accent'] . ';text-decoration:underline;" ',
            '<h2 style="margin:24px 0 10px;font-family:' . $p['serif'] . ';font-size:19px;line-height:1.3;font-weight:400;color:' . $p['text'] . ';">',
            '<h3 style="margin:20px 0 8px;font-family:' . $p['sans'] . ';font-size:14px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:' . $p['text'] . ';">',
            '<ul style="margin:0 0 16px;padding-left:20px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<ol style="margin:0 0 16px;padding-left:20px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<li style="margin:0 0 6px;">',
            '<strong style="color:' . $p['text'] . ';font-weight:700;">',
            '<blockquote style="margin:0 0 16px;padding:2px 0 2px 16px;border-left:3px solid ' . $p['primary_lt'] . ';font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
        ],
        $html
    );

    return '<div style="font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.7;color:' . esc_attr($p['sub']) . ';">' . $html . '</div>';
}

/**
 * Bulletproof call-to-action button.
 */
function snapbook_email_button($url, $label, $variant = 'primary')
{
    $p   = snapbook_email_palette();
    $bg  = $variant === 'accent' ? $p['accent'] : $p['primary'];
    $fg  = $variant === 'accent' ? $p['on_accent'] : $p['on_primary'];

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:4px 0 6px;"><tr>'
        . '<td align="center" bgcolor="' . esc_attr($bg) . '" style="border-radius:8px;">'
        . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="display:inline-block;padding:14px 30px;font-family:' . esc_attr($p['sans']) . ';font-size:15px;font-weight:700;line-height:1;letter-spacing:0.01em;color:' . esc_attr($fg) . ';text-decoration:none;border-radius:8px;">'
        . esc_html($label) . '</a></td></tr></table>';
}

/**
 * Hairline separator.
 */
function snapbook_email_divider($space = 26)
{
    $p     = snapbook_email_palette();
    $space = (int) $space;

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;"><tr>'
        . '<td style="padding:' . $space . 'px 0 0;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-size:0;line-height:0;">&nbsp;</td>'
        . '</tr></table><div style="height:' . $space . 'px;line-height:' . $space . 'px;font-size:0;">&nbsp;</div>';
}

/**
 * Vertical space between blocks that carry no margin of their own (tables).
 */
function snapbook_email_spacer($height = 20)
{
    $height = (int) $height;

    return '<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:0;">&nbsp;</div>';
}

/**
 * Section label above a block.
 */
function snapbook_email_section_label($label)
{
    $p = snapbook_email_palette();

    return '<div style="margin:0 0 12px;font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1;letter-spacing:0.16em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">'
        . esc_html($label) . '</div>';
}

/**
 * Label/value panel — the workhorse for booking details.
 *
 * @param array $rows  [ ['label' => …, 'value' => …, 'strong' => bool], … ]
 * @param array $args  tone: 'panel'|'brand'
 */
function snapbook_email_facts($rows, $args = [])
{
    $rows = array_values(array_filter((array) $rows, static function ($row) {
        return isset($row['value']) && trim((string) $row['value']) !== '';
    }));
    if (empty($rows)) {
        return '';
    }

    $p    = snapbook_email_palette();
    $args = wp_parse_args($args, ['tone' => 'panel']);
    $bg     = $args['tone'] === 'brand' ? $p['accent_lt'] : $p['panel'];
    $border = $args['tone'] === 'brand' ? $p['accent_lt'] : $p['border'];

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;background-color:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';border-radius:10px;">';
    $html .= '<tr><td style="padding:6px 20px 8px;">';
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">';

    $last = count($rows) - 1;
    foreach ($rows as $i => $row) {
        $strong = ! empty($row['strong']);
        $rule   = $i === $last ? 'none' : '1px solid ' . $p['rule'];

        $html .= '<tr>';
        $html .= '<td class="sb-stack" width="42%" valign="top" style="padding:12px 12px 12px 0;border-bottom:' . esc_attr($rule) . ';font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1.5;letter-spacing:0.11em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">'
            . esc_html($row['label']) . '</td>';
        $html .= '<td class="sb-stack sb-stack-v" align="right" valign="top" style="padding:12px 0;border-bottom:' . esc_attr($rule) . ';font-family:' . esc_attr($p['sans']) . ';font-size:' . ($strong ? '17px' : '15px') . ';font-weight:' . ($strong ? '700' : '500') . ';line-height:1.5;color:' . esc_attr($p['text']) . ';">'
            . esc_html($row['value']) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table></td></tr></table>';

    return $html;
}

/**
 * Highlighted callout box (used for the remaining-balance CTA).
 *
 * @param array $args title, text, amount, button_url, button_label, note.
 */
function snapbook_email_callout($args = [])
{
    $p    = snapbook_email_palette();
    $args = wp_parse_args($args, [
        'title'        => '',
        'text'         => '',
        'amount'       => '',
        'button_url'   => '',
        'button_label' => '',
        'note'         => '',
    ]);

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;background-color:' . esc_attr($p['accent_lt']) . ';border:1px solid ' . esc_attr($p['accent']) . ';border-radius:12px;">';
    $html .= '<tr><td style="padding:24px;">';

    if ($args['title'] !== '') {
        $html .= '<div style="margin:0 0 8px;font-family:' . esc_attr($p['serif']) . ';font-size:20px;line-height:1.3;color:' . esc_attr($p['accent_dk']) . ';">' . esc_html($args['title']) . '</div>';
    }
    if ($args['text'] !== '') {
        $html .= '<div style="margin:0 0 14px;font-family:' . esc_attr($p['sans']) . ';font-size:14px;line-height:1.65;color:' . esc_attr($p['sub']) . ';">' . esc_html($args['text']) . '</div>';
    }
    if ($args['amount'] !== '') {
        $html .= '<div style="margin:0 0 18px;font-family:' . esc_attr($p['sans']) . ';font-size:30px;font-weight:700;line-height:1.1;letter-spacing:-0.01em;color:' . esc_attr($p['accent_dk']) . ';">' . esc_html($args['amount']) . '</div>';
    }
    if ($args['button_url'] !== '' && $args['button_label'] !== '') {
        $html .= snapbook_email_button($args['button_url'], $args['button_label'], 'accent');
    }
    if ($args['note'] !== '') {
        $html .= '<div style="margin:14px 0 0;font-family:' . esc_attr($p['sans']) . ';font-size:11px;line-height:1.6;color:' . esc_attr($p['sub']) . ';word-break:break-all;">' . $args['note'] . '</div>';
    }

    $html .= '</td></tr></table>';

    return $html;
}

/* ─────────────────────────────────────────────────────────────
   Order rendering (WooCommerce)
───────────────────────────────────────────────────────────── */

/**
 * Booking facts for an order — what the customer actually booked.
 */
function snapbook_email_booking_facts_html($order)
{
    if (! $order || ! function_exists('snapbook_get_order_booking_meta')) {
        return '';
    }

    $meta = snapbook_get_order_booking_meta($order);
    $time = (string) $order->get_meta('_fpb_billing_event_time', true);
    $rows = [
        ['label' => __('Session', 'snapbook'), 'value' => $meta['session_type']],
        ['label' => __('Package', 'snapbook'), 'value' => $meta['package_name']],
        // Empty values are dropped by snapbook_email_facts(), so a booking
        // without extras simply shows no Add-ons row.
        ['label' => __('Add-ons', 'snapbook'), 'value' => $meta['addons']],
        ['label' => __('Date', 'snapbook'), 'value' => $meta['session_date'], 'strong' => true],
        ['label' => __('Time', 'snapbook'), 'value' => $time],
        ['label' => __('Booking reference', 'snapbook'), 'value' => '#' . $order->get_order_number()],
    ];

    return snapbook_email_facts($rows);
}

/**
 * Designed replacement for WooCommerce's order-details table: booking line
 * items with their add-on meta, then the order total rows.
 */
function snapbook_email_order_table_html($order)
{
    if (! $order) {
        return '';
    }
    $p = snapbook_email_palette();

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border:1px solid ' . esc_attr($p['border']) . ';border-radius:10px;">';

    // Head.
    $html .= '<tr>';
    $html .= '<th align="left" style="padding:13px 20px;background-color:' . esc_attr($p['panel']) . ';border-bottom:1px solid ' . esc_attr($p['border']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:10px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">' . esc_html__('Item', 'snapbook') . '</th>';
    $html .= '<th align="right" style="padding:13px 20px;background-color:' . esc_attr($p['panel']) . ';border-bottom:1px solid ' . esc_attr($p['border']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:10px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">' . esc_html__('Amount', 'snapbook') . '</th>';
    $html .= '</tr>';

    foreach ($order->get_items() as $item_id => $item) {
        $meta_html = wc_display_item_meta($item, [
            'before'       => '<div style="margin:6px 0 0;font-family:' . $p['sans'] . ';font-size:13px;line-height:1.6;color:' . $p['sub'] . ';">',
            'after'        => '</div>',
            'separator'    => '<br>',
            'label_before' => '<span style="font-weight:700;color:' . $p['text'] . ';">',
            'label_after'  => ':</span> ',
            'echo'         => false,
            'autop'        => false,
        ]);

        $html .= '<tr>';
        $html .= '<td valign="top" style="padding:16px 20px;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.5;color:' . esc_attr($p['text']) . ';">';
        $html .= '<span style="font-weight:600;">' . wp_kses_post($item->get_name()) . '</span>';
        $html .= $meta_html; // Escaped by wc_display_item_meta / our own filter.
        $html .= '</td>';
        $html .= '<td valign="top" align="right" style="padding:16px 20px;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.5;white-space:nowrap;color:' . esc_attr($p['text']) . ';">';
        $html .= wp_kses_post($order->get_formatted_line_subtotal($item));
        $html .= '</td></tr>';
    }

    $totals = (array) $order->get_order_item_totals();
    // Emphasise the order total, not whatever row happens to come last
    // (WooCommerce puts "Payment method" after it).
    $emphasis = isset($totals['order_total']) ? 'order_total' : (string) array_key_last($totals);
    foreach ($totals as $key => $total) {
        $big = ($key === $emphasis);
        $html .= '<tr>';
        $html .= '<td align="right" style="padding:' . ($big ? '14px' : '10px') . ' 20px;font-family:' . esc_attr($p['sans']) . ';font-size:' . ($big ? '15px' : '13px') . ';font-weight:' . ($big ? '700' : '500') . ';line-height:1.5;color:' . esc_attr($big ? $p['text'] : $p['sub']) . ';">'
            . wp_kses_post($total['label']) . '</td>';
        $html .= '<td align="right" style="padding:' . ($big ? '14px' : '10px') . ' 20px;font-family:' . esc_attr($p['sans']) . ';font-size:' . ($big ? '18px' : '13px') . ';font-weight:' . ($big ? '700' : '500') . ';line-height:1.5;white-space:nowrap;color:' . esc_attr($big ? $p['text'] : $p['sub']) . ';">'
            . wp_kses_post($total['value']) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    return $html;
}

/**
 * The customer's own details, as submitted on the booking form.
 */
function snapbook_email_customer_facts_html($order)
{
    if (! $order) {
        return '';
    }

    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $rows = [
        ['label' => __('Name', 'snapbook'), 'value' => $name],
        ['label' => __('Email', 'snapbook'), 'value' => $order->get_billing_email()],
        ['label' => __('Phone', 'snapbook'), 'value' => $order->get_billing_phone()],
        ['label' => __('Guests', 'snapbook'), 'value' => (string) $order->get_meta('_fpb_billing_participants', true)],
        ['label' => __('Place', 'snapbook'), 'value' => (string) $order->get_meta('_fpb_billing_hotel_place', true)],
        ['label' => __('Room', 'snapbook'), 'value' => (string) $order->get_meta('_fpb_billing_room_number', true)],
        ['label' => __('Stay period', 'snapbook'), 'value' => (string) $order->get_meta('_fpb_billing_stay_period', true)],
    ];

    // WooCommerce joins address lines with <br/>; turn those into commas
    // first, or stripping the tags would run the lines together.
    $address = (string) $order->get_formatted_billing_address();
    $address = preg_replace('#<br\s*/?>#i', ', ', $address);
    $address = trim(preg_replace('/\s*,\s*/', ', ', wp_strip_all_tags($address, true)), " ,\t\n");
    if ($address !== '') {
        $rows[] = ['label' => __('Address', 'snapbook'), 'value' => $address];
    }

    // Admin-defined custom checkout fields.
    if (function_exists('snapbook_get_custom_checkout_fields')) {
        foreach (snapbook_get_custom_checkout_fields() as $key => $field) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => (string) $order->get_meta('_fpb_cf_' . $key, true),
            ];
        }
    }

    return snapbook_email_facts($rows);
}

/* ─────────────────────────────────────────────────────────────
   Plain-text counterparts
───────────────────────────────────────────────────────────── */

function snapbook_email_plain_rule()
{
    return "\n" . str_repeat('-', 48) . "\n\n";
}

/**
 * HTML → readable plain text: line breaks survive as newlines and entities
 * become real characters, so a customer never reads "&#2547;" or finds two
 * address lines welded together.
 */
function snapbook_email_plain_from_html($html)
{
    $text = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
    $text = preg_replace('#</(p|div|tr|h[1-6])>#i', "\n", $text);
    $text = wp_strip_all_tags($text);

    return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
}

/**
 * Plain-text label/value list matching snapbook_email_facts().
 */
function snapbook_email_plain_facts($rows)
{
    $out = '';
    foreach ((array) $rows as $row) {
        if (trim((string) ($row['value'] ?? '')) === '') {
            continue;
        }
        $out .= $row['label'] . ': ' . $row['value'] . "\n";
    }

    return $out;
}

/**
 * Default wording of the balance reminder (SnapBook → Settings). The email
 * renders its own "Pay Remaining Balance" button, so the default copy no
 * longer spells the link out — {pay_link} still works for anyone who wants it.
 */
function snapbook_balance_reminder_default_template()
{
    return __("Hi {customer_name},\n\nThis is a friendly reminder that {balance_amount} is still due for your booking on {session_date}.\n\nThank you.", 'snapbook');
}

/**
 * Send a SnapBook system email (anything outside WooCommerce's own mailer)
 * through the branded shell.
 *
 * @param string $to
 * @param string $subject
 * @param string $content Inner HTML built with the component helpers.
 * @param array  $args    Passed to snapbook_email_wrap(), plus 'headers'.
 */
function snapbook_email_send($to, $subject, $content, $args = [])
{
    $headers = (array) ($args['headers'] ?? []);
    unset($args['headers']);
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $html = snapbook_email_wrap($content, $args);

    return wp_mail($to, wp_specialchars_decode(sanitize_text_field($subject)), $html, $headers);
}
