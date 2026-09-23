<?php

/**
 * SnapBook availability: which dates (and start times) can still be booked.
 *
 * A date is unavailable when the studio blocked it (Date Slots), when it
 * breaks a rule (past, inside the minimum notice, beyond the booking window,
 * a closed weekday), or when it is full: its bookings plus short-lived holds
 * reach the daily capacity — or, with start times set up, every start time is
 * taken.
 *
 * A "hold" is an unpaid booking order the form just created: while the
 * customer pays, nobody else can take the same date/time (SnapBook →
 * Settings → "Hold a date while the customer pays"). Each browser sends a
 * random hold token so the customer's own order never blocks them.
 *
 * Loaded unconditionally; holds need WooCommerce and are skipped without it.
 *
 * @package SnapBook
 */

defined('ABSPATH') || exit;

/**
 * Start times the studio offers ("HH:MM"). Empty = no start-time choice.
 */
function snapbook_time_slots()
{
    return (array) snapbook_opt('fpb_time_slots');
}

function snapbook_slots_enabled()
{
    return ! empty(snapbook_time_slots());
}

/**
 * How many bookings one date takes: one per start time when start times are
 * set up, else the "Bookings per day" setting (default 1).
 */
function snapbook_day_capacity()
{
    return snapbook_slots_enabled() ? count(snapbook_time_slots()) : max(1, (int) snapbook_opt('fpb_daily_capacity'));
}

/**
 * A browser's hold token, or '' when it isn't one.
 */
function snapbook_clean_hold_token($token)
{
    $token = (string) $token;

    return preg_match('/^[A-Za-z0-9]{8,64}$/', $token) ? $token : '';
}

/**
 * Unpaid booking orders created in the last "hold" minutes.
 *
 * @return array[] Each: order_id, date, time.
 */
function snapbook_pending_holds($from, $to = '', $exclude_order_id = 0, $exclude_token = '')
{
    $minutes = (int) snapbook_opt('fpb_hold_minutes');
    if ($minutes < 1 || ! function_exists('wc_get_orders')) {
        return [];
    }

    $orders = wc_get_orders([
        'limit'        => 200,
        'status'       => ['pending'],
        'created_via'  => 'snapbook',
        'date_created' => '>' . (time() - $minutes * MINUTE_IN_SECONDS),
        'return'       => 'objects',
    ]);

    $holds = [];
    foreach ((array) $orders as $order) {
        if (! $order || (int) $order->get_id() === (int) $exclude_order_id) {
            continue;
        }
        if ((int) $order->get_meta('_fpb_is_balance_order', true) === 1) {
            continue;
        }
        if ($exclude_token !== '' && hash_equals((string) $order->get_meta('_fpb_hold_token', true), $exclude_token)) {
            continue;
        }
        $date = (string) $order->get_meta('_fpb_billing_event_date', true);
        if ($date === '' || $date < $from || ($to !== '' && $date > $to)) {
            continue;
        }
        $holds[] = [
            'order_id' => (int) $order->get_id(),
            'date'     => $date,
            'time'     => snapbook_normalize_time((string) $order->get_meta('_fpb_billing_event_time', true)),
        ];
    }

    return $holds;
}

/**
 * Bookings (and optionally holds) per date.
 *
 * @return array date => ['count' => int, 'times' => ['HH:MM' => int]]
 */
function snapbook_occupancy($from, $to = '', $exclude_order_id = 0, $exclude_token = '', $include_holds = true)
{
    global $wpdb;
    $pfx      = $wpdb->prefix . 'fpb_';
    $statuses = "'" . implode("','", array_map('esc_sql', snapbook_active_booking_statuses())) . "'";

    $sql  = "SELECT order_id, session_date, session_time FROM {$pfx}bookings WHERE session_date >= %s AND status IN ($statuses) AND (order_id IS NULL OR order_id <> %d)";
    $args = [$from, (int) $exclude_order_id];
    if ($to !== '') {
        $sql   .= ' AND session_date <= %s';
        $args[] = $to;
    }
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built from the table prefix and esc_sql()'d status constants; all values are placeholders.

    $out       = [];
    $order_ids = [];
    $add       = static function ($date, $time) use (&$out) {
        if (! isset($out[$date])) {
            $out[$date] = ['count' => 0, 'times' => []];
        }
        $out[$date]['count']++;
        if ($time !== '') {
            $out[$date]['times'][$time] = ($out[$date]['times'][$time] ?? 0) + 1;
        }
    };

    foreach ((array) $rows as $row) {
        $order_ids[(int) $row->order_id] = true;
        $add((string) $row->session_date, snapbook_normalize_time((string) $row->session_time));
    }

    if ($include_holds) {
        foreach (snapbook_pending_holds($from, $to, $exclude_order_id, $exclude_token) as $hold) {
            if (isset($order_ids[$hold['order_id']])) {
                continue; // already counted through its booking row
            }
            $add($hold['date'], $hold['time']);
        }
    }

    return $out;
}

/**
 * The booking-window rules for a date: a real date, not past, not inside the
 * minimum notice, not beyond the furthest bookable day, not a closed weekday.
 *
 * @return WP_Error|null
 */
function snapbook_date_rule_error($date)
{
    $date = trim((string) $date);
    if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return new WP_Error('snapbook_date', __('Please choose a valid session date from the calendar.', 'snapbook'));
    }

    $rules = snapbook_booking_window();
    if ($date < $rules['today']) {
        return new WP_Error('snapbook_date_past', __('That date has already passed. Please choose another date.', 'snapbook'));
    }
    if ($date < $rules['minDate']) {
        return new WP_Error('snapbook_date_notice', sprintf(
            /* translators: %s: first bookable date */
            __('We need a little more notice. The earliest date you can book is %s.', 'snapbook'),
            date_i18n(get_option('date_format'), strtotime($rules['minDate']))
        ));
    }
    if ($rules['maxDate'] !== '' && $date > $rules['maxDate']) {
        return new WP_Error('snapbook_date_window', sprintf(
            /* translators: %s: last bookable date */
            __('Bookings are open until %s. Please choose an earlier date.', 'snapbook'),
            date_i18n(get_option('date_format'), strtotime($rules['maxDate']))
        ));
    }
    $weekday = (int) gmdate('w', gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
    if (in_array($weekday, $rules['closedWeekdays'], true)) {
        return new WP_Error('snapbook_date_closed', __('We don\'t take bookings on that day of the week. Please choose another date.', 'snapbook'));
    }

    return null;
}

/**
 * Today and the bookable range, in the site's timezone.
 */
function snapbook_booking_window()
{
    $tz    = wp_timezone();
    $today = new DateTimeImmutable('today', $tz);
    $min   = (int) snapbook_opt('fpb_min_notice_days');
    $max   = (int) snapbook_opt('fpb_max_advance_days');

    return [
        'today'          => $today->format('Y-m-d'),
        'minDate'        => $today->modify('+' . $min . ' days')->format('Y-m-d'),
        'maxDate'        => $max > 0 ? $today->modify('+' . $max . ' days')->format('Y-m-d') : '',
        'closedWeekdays' => array_map('intval', (array) snapbook_opt('fpb_closed_weekdays')),
        'weekStart'      => (int) get_option('start_of_week', 0),
    ];
}

/**
 * Whether a session date (and start time) can still be booked. The calendar
 * in the browser can be stale — another customer may have just booked the
 * date — so the server checks again before creating an order.
 *
 * @param string $date             Y-m-d.
 * @param int    $exclude_order_id Booking order to ignore (itself).
 * @param string $time             Start time; required when start times are set up.
 * @param string $hold_token       The customer's own hold token (their unpaid order doesn't count).
 * @param bool   $for_admin        Bookings added by the studio: skip the booking-window
 *                                 rules (past dates, notice, closed weekdays), keep capacity.
 * @return true|WP_Error
 */
function snapbook_validate_booking_date($date, $exclude_order_id = 0, $time = '', $hold_token = '', $for_admin = false)
{
    $date  = trim((string) $date);
    $error = snapbook_date_rule_error($date);
    if ($error && (! $for_admin || $error->get_error_code() === 'snapbook_date')) {
        return $error;
    }

    $taken = new WP_Error('snapbook_date_taken', __('Sorry, that date is no longer available — it may have just been booked. Please choose another date.', 'snapbook'));

    global $wpdb;
    // "booked" = full (SnapBook keeps it in step with the bookings, and the
    // studio can set it by hand); "blocked" = closed by the studio.
    $slot = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fpb_dates WHERE date_str = %s", $date)); // phpcs:ignore
    if (in_array($slot, ['booked', 'blocked'], true) && ! $exclude_order_id) {
        return $taken;
    }

    $occupancy = snapbook_occupancy($date, $date, (int) $exclude_order_id, snapbook_clean_hold_token($hold_token));
    $day       = $occupancy[$date] ?? ['count' => 0, 'times' => []];
    if ($slot === 'blocked') {
        return $taken;
    }

    if (snapbook_slots_enabled()) {
        $t = snapbook_normalize_time($time);
        if ($t === '') {
            return new WP_Error('snapbook_slot_required', __('Please choose a start time.', 'snapbook'));
        }
        if (! in_array($t, snapbook_time_slots(), true)) {
            return new WP_Error('snapbook_slot_invalid', __('That start time isn\'t available. Please choose one of the listed times.', 'snapbook'));
        }
        if (($day['times'][$t] ?? 0) > 0) {
            return new WP_Error('snapbook_slot_taken', __('Sorry, that start time was just booked. Please choose another time.', 'snapbook'));
        }
    }

    if ($day['count'] >= snapbook_day_capacity()) {
        return $taken;
    }

    return true;
}

/**
 * Keep the Date Slots "booked" mark in step with the bookings: set when a
 * date is full, cleared when it no longer is. A day the studio blocked by
 * hand is never touched. Holds don't count here.
 *
 * @return string The date's slot status afterwards: 'booked', 'blocked' or ''.
 */
function snapbook_refresh_date_slot($date)
{
    $date = (string) $date;
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return '';
    }

    global $wpdb;
    $pfx  = $wpdb->prefix . 'fpb_';
    $slot = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$pfx}dates WHERE date_str = %s", $date)); // phpcs:ignore
    if ($slot === 'blocked') {
        return 'blocked';
    }

    $occupancy = snapbook_occupancy($date, $date, 0, '', false);
    $full      = ($occupancy[$date]['count'] ?? 0) >= snapbook_day_capacity();

    if ($full && $slot !== 'booked') {
        if ($slot === '') {
            $wpdb->insert("{$pfx}dates", ['date_str' => $date, 'status' => 'booked']); // phpcs:ignore
        } else {
            $wpdb->update("{$pfx}dates", ['status' => 'booked'], ['date_str' => $date]); // phpcs:ignore
        }
        return 'booked';
    }
    if (! $full && $slot === 'booked') {
        $wpdb->delete("{$pfx}dates", ['date_str' => $date, 'status' => 'booked']); // phpcs:ignore
        return '';
    }

    return $full ? 'booked' : ($slot === 'available' ? '' : $slot);
}

/**
 * Re-check every future date that has bookings — after the capacity or the
 * start times change, "full" means something different.
 */
function snapbook_refresh_future_date_slots()
{
    global $wpdb;
    $today = wp_date('Y-m-d');
    // Only dates with bookings: a "booked" mark on an empty day was set by the
    // studio by hand and stays.
    $dates = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT session_date FROM {$wpdb->prefix}fpb_bookings WHERE session_date >= %s", $today)); // phpcs:ignore
    foreach ((array) $dates as $date) {
        snapbook_refresh_date_slot((string) $date);
    }
}
// add_option_* too: the first save of a setting adds it rather than updating.
add_action('update_option_fpb_daily_capacity', 'snapbook_refresh_future_date_slots', 10, 0);
add_action('update_option_fpb_time_slots', 'snapbook_refresh_future_date_slots', 10, 0);
add_action('add_option_fpb_daily_capacity', 'snapbook_refresh_future_date_slots', 10, 0);
add_action('add_option_fpb_time_slots', 'snapbook_refresh_future_date_slots', 10, 0);

/**
 * Reopen a session date after a booking on it was cancelled (or moved).
 * Call after the booking row no longer counts as active.
 *
 * @return bool Whether the date is bookable again.
 */
function snapbook_release_booking_date($date, $exclude_order_id = 0)
{
    return snapbook_refresh_date_slot($date) === '';
}

/**
 * Everything the booking calendar needs, for the next 18 months (or up to
 * the furthest bookable day).
 *
 * @param string $hold_token The visitor's own token — their unpaid order doesn't block them.
 */
function snapbook_get_availability_data($hold_token = '')
{
    global $wpdb;
    $rules = snapbook_booking_window();
    $to    = $rules['maxDate'] !== '' ? $rules['maxDate'] : (new DateTimeImmutable('today', wp_timezone()))->modify('+18 months')->format('Y-m-d');

    $unavailable = [];
    $marks       = $wpdb->get_results($wpdb->prepare("SELECT date_str, status FROM {$wpdb->prefix}fpb_dates WHERE status IN ('booked','blocked') AND date_str >= %s ORDER BY date_str", $rules['today'])); // phpcs:ignore
    foreach ((array) $marks as $mark) {
        $unavailable[(string) $mark->date_str] = (string) $mark->status;
    }

    $capacity = snapbook_day_capacity();
    $slots_on = snapbook_slots_enabled();
    $taken    = [];
    foreach (snapbook_occupancy($rules['today'], $to, 0, snapbook_clean_hold_token($hold_token)) as $date => $day) {
        if ($day['count'] >= $capacity && ! isset($unavailable[$date])) {
            $unavailable[$date] = 'booked';
        }
        if ($slots_on) {
            $times = array_keys(array_filter($day['times']));
            if ($times) {
                $taken[$date] = array_values($times);
            }
        }
    }

    return [
        'unavailable' => $unavailable,
        'rules'       => $rules,
        'capacity'    => $capacity,
        'slots'       => [
            'enabled' => $slots_on,
            'times'   => array_values(snapbook_time_slots()),
            'taken'   => $taken,
        ],
    ];
}

/*
 * Public, read-only availability for the booking calendar. No nonce: with
 * one, a page served from a page cache after the nonce expired got a 403 and
 * the calendar showed every date as free.
 */
add_action('wp_ajax_snapbook_get_availability', 'snapbook_ajax_get_availability');
add_action('wp_ajax_nopriv_snapbook_get_availability', 'snapbook_ajax_get_availability');
function snapbook_ajax_get_availability()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- public read-only data, see above.
    $token = isset($_REQUEST['hold_token']) ? sanitize_text_field(wp_unslash($_REQUEST['hold_token'])) : '';
    wp_send_json_success(snapbook_get_availability_data($token));
}
