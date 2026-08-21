<?php

/**
 * Google Calendar integration — one-click connect for a single site.
 *
 * There is no broker/server to run. You create one Google OAuth app once and
 * save its Client ID + Secret in SnapBook → Settings → Google Calendar (no file
 * editing). From then on the admin just clicks "Connect with Google" and
 * approves. "Connect" goes straight to accounts.google.com.
 *
 * Register the site's redirect URI (shown on the settings screen) under the
 * Google app's "Authorized redirect URIs".
 *
 * Flow:
 *   1. Admin clicks "Connect with Google" (snapbook_gcal_connect) → redirect
 *      to Google's consent screen with the site's own credentials.
 *   2. Google redirects back to our callback (snapbook_gcal_callback) with an
 *      authorization code, which we swap for tokens directly at Google.
 *   3. On every paid booking we schedule a background job that pushes an event
 *      to the connected calendar (package + order number, client, notes).
 */

defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   GOOGLE APP CREDENTIALS
   ───────────────────────────────────────────────────────────────
   Saved from the plugin backend (SnapBook → Settings → Google
   Calendar) into the fpb_gcal_client_id / fpb_gcal_client_secret
   options — nothing to edit in any file. Advanced installs may
   instead define SNAPBOOK_GCAL_CLIENT_ID / _SECRET in wp-config.php,
   which then take precedence over the saved fields.
═══════════════════════════════════════════════════════════════ */

function snapbook_gcal_client_id()
{
    if (defined('SNAPBOOK_GCAL_CLIENT_ID') && SNAPBOOK_GCAL_CLIENT_ID) {
        return (string) SNAPBOOK_GCAL_CLIENT_ID;
    }
    return trim((string) get_option('fpb_gcal_client_id', ''));
}

function snapbook_gcal_client_secret()
{
    if (defined('SNAPBOOK_GCAL_CLIENT_SECRET') && SNAPBOOK_GCAL_CLIENT_SECRET) {
        return (string) SNAPBOOK_GCAL_CLIENT_SECRET;
    }
    return trim((string) get_option('fpb_gcal_client_secret', ''));
}

function snapbook_gcal_has_credentials()
{
    return snapbook_gcal_client_id() !== '' && snapbook_gcal_client_secret() !== '';
}

/**
 * True only when the credentials come from wp-config constants — then the
 * settings screen shows a short note in place of the editable fields.
 */
function snapbook_gcal_creds_from_constant()
{
    return (defined('SNAPBOOK_GCAL_CLIENT_ID') && SNAPBOOK_GCAL_CLIENT_ID)
        && (defined('SNAPBOOK_GCAL_CLIENT_SECRET') && SNAPBOOK_GCAL_CLIENT_SECRET);
}

/**
 * The exact redirect URI to register on the Google OAuth client for this site.
 * Google matches it byte-for-byte, so it is always built the same way.
 */
function snapbook_gcal_redirect_uri()
{
    return admin_url('admin-post.php?action=snapbook_gcal_callback');
}

/**
 * Requested scopes: create/manage calendar events, plus openid+email so we can
 * show which account is connected. Least privilege — no read of other calendars.
 */
function snapbook_gcal_scopes()
{
    return (string) apply_filters(
        'snapbook_gcal_scopes',
        'openid email https://www.googleapis.com/auth/calendar.events'
    );
}

/* ═══════════════════════════════════════════════════════════════
   CONNECTION STATE
═══════════════════════════════════════════════════════════════ */

/**
 * Stored connection: access_token, refresh_token, expires_at (unix),
 * email, scope, calendar_id, connected_at. Empty array when not connected.
 */
function snapbook_gcal_get_connection()
{
    $conn = get_option('fpb_gcal_connection', []);
    return is_array($conn) ? $conn : [];
}

function snapbook_gcal_is_connected()
{
    $conn = snapbook_gcal_get_connection();
    return ! empty($conn['refresh_token']);
}

/**
 * Whether new bookings should be pushed to Google Calendar right now:
 * connected AND the sync toggle is on.
 */
function snapbook_gcal_sync_enabled()
{
    return snapbook_gcal_is_connected() && (int) get_option('fpb_gcal_enabled', 1) === 1;
}

/**
 * Calendar the events are written to. 'primary' is the connected account's
 * own calendar; a filter lets integrators target a shared calendar.
 */
function snapbook_gcal_calendar_id()
{
    $conn = snapbook_gcal_get_connection();
    $id   = ! empty($conn['calendar_id']) ? $conn['calendar_id'] : 'primary';
    return (string) apply_filters('snapbook_gcal_calendar_id', $id);
}

function snapbook_gcal_store_connection(array $conn)
{
    // autoload=no: tokens are only needed on admin + booking events, not on
    // every front-end page load.
    update_option('fpb_gcal_connection', $conn, false);
}

function snapbook_gcal_forget_connection()
{
    delete_option('fpb_gcal_connection');
    delete_option('fpb_gcal_last_error');
}

function snapbook_gcal_set_error($message)
{
    update_option('fpb_gcal_last_error', (string) $message, false);
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — CONNECT (straight to Google)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_snapbook_gcal_connect', 'snapbook_gcal_handle_connect');
function snapbook_gcal_handle_connect()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'snapbook'));
    }
    check_admin_referer('snapbook_gcal_connect');

    if (! snapbook_gcal_has_credentials()) {
        snapbook_gcal_redirect_settings(['sb_gcal' => 'error', 'reason' => 'nocreds']);
    }

    // CSRF: a random state we check when Google sends the browser back.
    $state = wp_generate_password(32, false, false);
    set_transient('fpb_gcal_oauth', [
        'state' => $state,
        'user'  => get_current_user_id(),
    ], 15 * MINUTE_IN_SECONDS);

    // add_query_arg() does not encode values it adds, so encode them here.
    $auth = add_query_arg(
        [
            'client_id'              => rawurlencode(snapbook_gcal_client_id()),
            'redirect_uri'           => rawurlencode(snapbook_gcal_redirect_uri()),
            'response_type'          => 'code',
            'scope'                  => rawurlencode(snapbook_gcal_scopes()),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ],
        'https://accounts.google.com/o/oauth2/v2/auth'
    );

    // External host → wp_redirect (wp_safe_redirect would block Google).
    wp_redirect($auth); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberate off-site redirect to Google's consent screen.
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — CALLBACK (Google → us, swap code for tokens)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_snapbook_gcal_callback', 'snapbook_gcal_handle_callback');
function snapbook_gcal_handle_callback()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'snapbook'));
    }

    $stored = get_transient('fpb_gcal_oauth');
    delete_transient('fpb_gcal_oauth');

    // This is Google's OAuth redirect, not a WP form: the CSRF proof is the
    // `state` value checked against the stored transient below, so the standard
    // WP nonce sniff does not apply to these reads.
    $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($error !== '') {
        // Keep Google's own wording — it names the real cause (unverified app,
        // tester-only access, disabled API) far better than we can guess.
        $desc = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        snapbook_gcal_set_error('Google returned "' . $error . '"' . ($desc !== '' ? ': ' . $desc : '.'));

        // access_denied is either "user pressed Cancel" or "the app is still in
        // Testing and this account is not a test user" — the latter needs a
        // different fix, so it gets its own notice.
        snapbook_gcal_redirect_settings([
            'sb_gcal' => 'error',
            'reason'  => ($error === 'access_denied') ? 'access_denied' : 'denied',
        ]);
    }

    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';    // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if (! is_array($stored) || empty($stored['state']) || ! hash_equals($stored['state'], $state) || $code === '') {
        snapbook_gcal_redirect_settings(['sb_gcal' => 'error', 'reason' => 'state']);
    }

    if (! snapbook_gcal_has_credentials()) {
        snapbook_gcal_redirect_settings(['sb_gcal' => 'error', 'reason' => 'nocreds']);
    }

    // Exchange the authorization code for tokens, directly with Google.
    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 25,
        'body'    => [
            'code'          => $code,
            'client_id'     => snapbook_gcal_client_id(),
            'client_secret' => snapbook_gcal_client_secret(),
            'redirect_uri'  => snapbook_gcal_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ],
    ]);

    if (is_wp_error($res)) {
        snapbook_gcal_set_error($res->get_error_message());
        snapbook_gcal_redirect_settings(['sb_gcal' => 'error', 'reason' => 'network']);
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);

    if (empty($data['access_token']) || empty($data['refresh_token'])) {
        // Google returns { error, error_description } on failure — surface it so
        // a redirect-URI mismatch or a wrong secret is diagnosable.
        $detail = isset($data['error_description']) ? $data['error_description']
            : (isset($data['error']) ? $data['error'] : ('HTTP ' . (int) wp_remote_retrieve_response_code($res)));
        snapbook_gcal_set_error('Token exchange failed: ' . $detail);
        snapbook_gcal_redirect_settings(['sb_gcal' => 'error', 'reason' => 'exchange']);
    }

    snapbook_gcal_store_connection([
        'access_token'  => sanitize_text_field($data['access_token']),
        'refresh_token' => sanitize_text_field($data['refresh_token']),
        'expires_at'    => time() + (int) ($data['expires_in'] ?? 3500),
        'email'         => snapbook_gcal_email_from_id_token($data['id_token'] ?? ''),
        'scope'         => isset($data['scope']) ? sanitize_text_field($data['scope']) : '',
        'calendar_id'   => 'primary',
        'connected_at'  => time(),
    ]);
    delete_option('fpb_gcal_last_error');
    update_option('fpb_gcal_enabled', 1);

    snapbook_gcal_redirect_settings(['sb_gcal' => 'connected']);
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — DISCONNECT (revoke at Google)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_snapbook_gcal_disconnect', 'snapbook_gcal_handle_disconnect');
function snapbook_gcal_handle_disconnect()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'snapbook'));
    }
    check_admin_referer('snapbook_gcal_disconnect');

    $conn  = snapbook_gcal_get_connection();
    $token = ! empty($conn['refresh_token']) ? $conn['refresh_token'] : ($conn['access_token'] ?? '');
    if ($token !== '') {
        // Best-effort revoke so the grant is dropped on Google's side too.
        wp_remote_post('https://oauth2.googleapis.com/revoke', [
            'timeout'  => 15,
            'blocking' => false,
            'body'     => ['token' => $token],
        ]);
    }

    snapbook_gcal_forget_connection();
    delete_option('fpb_gcal_enabled');

    snapbook_gcal_redirect_settings(['sb_gcal' => 'disconnected']);
}

function snapbook_gcal_redirect_settings(array $args)
{
    wp_safe_redirect(add_query_arg(array_merge(['page' => 'sb-settings'], $args), admin_url('admin.php')) . '#fpb-gcal');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACCESS TOKEN — cached, auto-refreshed directly with Google
═══════════════════════════════════════════════════════════════ */

/**
 * A valid access token, refreshing with Google when the cached one is near
 * expiry. Returns '' when not connected or the refresh failed.
 */
function snapbook_gcal_access_token()
{
    $conn = snapbook_gcal_get_connection();
    if (empty($conn['refresh_token']) || ! snapbook_gcal_has_credentials()) {
        return '';
    }

    $now = time();
    if (! empty($conn['access_token']) && ! empty($conn['expires_at']) && $conn['expires_at'] > ($now + 60)) {
        return $conn['access_token'];
    }

    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 25,
        'body'    => [
            'client_id'     => snapbook_gcal_client_id(),
            'client_secret' => snapbook_gcal_client_secret(),
            'refresh_token' => $conn['refresh_token'],
            'grant_type'    => 'refresh_token',
        ],
    ]);

    if (is_wp_error($res)) {
        snapbook_gcal_set_error($res->get_error_message());
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);

    // A revoked / expired grant comes back as invalid_grant. When that happens
    // the connection is dead — drop it so the UI prompts a reconnect. Note that
    // a Google app left in "Testing" expires its refresh tokens after 7 days,
    // which lands here too — hence the hint.
    if ($code === 400 && isset($data['error']) && $data['error'] === 'invalid_grant') {
        snapbook_gcal_forget_connection();
        snapbook_gcal_set_error('Google access expired or was revoked. Reconnect — and if your Google app is still in "Testing", publish it so the connection stops expiring every 7 days.');
        return '';
    }

    if (empty($data['access_token'])) {
        snapbook_gcal_set_error('Token refresh failed (HTTP ' . $code . ').');
        return '';
    }

    $conn['access_token'] = sanitize_text_field($data['access_token']);
    $conn['expires_at']   = $now + (int) ($data['expires_in'] ?? 3500);
    snapbook_gcal_store_connection($conn);

    return $conn['access_token'];
}

/**
 * Pull the account email out of Google's id_token (a JWT). The token comes
 * straight from Google over the TLS token exchange, so the payload is trusted
 * without re-verifying the signature. Returns '' if absent.
 */
function snapbook_gcal_email_from_id_token($id_token)
{
    $parts = explode('.', (string) $id_token);
    if (count($parts) < 2) {
        return '';
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a JWT payload from Google, not obfuscation.
    return is_array($payload) && ! empty($payload['email']) ? sanitize_email($payload['email']) : '';
}

/* ═══════════════════════════════════════════════════════════════
   BOOKING → CALENDAR EVENT
═══════════════════════════════════════════════════════════════ */

/**
 * POST an event body to the connected calendar. Shared by the booking sync and
 * the admin test button so both send guests the same way.
 *
 * sendUpdates=all is what actually emails the invitation; without it Google
 * records the attendee silently and the client never hears about it.
 */
function snapbook_gcal_insert_event(array $event, $token)
{
    $calendar = rawurlencode(snapbook_gcal_calendar_id());
    $url      = add_query_arg(
        ['sendUpdates' => empty($event['attendees']) ? 'none' : 'all'],
        "https://www.googleapis.com/calendar/v3/calendars/{$calendar}/events"
    );

    return wp_remote_post($url, [
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($event),
    ]);
}

/**
 * Fired by the booking recorder (woocommerce.php) once a paid booking row is
 * saved. We push the calendar event on a background job so a slow Google call
 * never delays the customer's checkout.
 */
add_action('snapbook_booking_created', 'snapbook_gcal_on_booking_created', 10, 1);
function snapbook_gcal_on_booking_created($booking_id)
{
    $booking_id = (int) $booking_id;
    if ($booking_id < 1 || ! snapbook_gcal_sync_enabled()) {
        return;
    }

    if (! wp_next_scheduled('snapbook_gcal_create_event', [$booking_id])) {
        wp_schedule_single_event(time() + 5, 'snapbook_gcal_create_event', [$booking_id]);
    }
}

add_action('snapbook_gcal_create_event', 'snapbook_gcal_create_event_handler', 10, 1);
function snapbook_gcal_create_event_handler($booking_id)
{
    $booking_id = (int) $booking_id;
    if ($booking_id < 1 || ! snapbook_gcal_sync_enabled()) {
        return;
    }

    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared query on the custom bookings table; only the trusted table prefix is interpolated.
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return;
    }
    // Already synced (or column missing on an un-migrated install).
    if (! empty($booking->gcal_event_id)) {
        return;
    }

    $token = snapbook_gcal_access_token();
    if ($token === '') {
        return;
    }

    $event = snapbook_gcal_build_event($booking);
    if (empty($event)) {
        return;
    }

    $res = snapbook_gcal_insert_event($event, $token);

    if (is_wp_error($res)) {
        snapbook_gcal_set_error($res->get_error_message());
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);

    if ($code >= 200 && $code < 300 && ! empty($data['id'])) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- writing the event id back to the custom bookings table; only the trusted table prefix is interpolated.
        $wpdb->update(
            "{$pfx}bookings",
            ['gcal_event_id' => sanitize_text_field($data['id'])],
            ['id' => $booking_id],
            ['%s'],
            ['%d']
        );
        delete_option('fpb_gcal_last_error');
        return;
    }

    $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
    snapbook_gcal_set_error('Calendar event was not created: ' . $api_msg);
}

/**
 * Build the Google Calendar event body for a booking row. Pure (no network),
 * so it can be unit-tested. Returns [] when the booking has no usable date.
 *
 * Layout the studio asked for: package + order number as the title, the client's
 * chosen place as the event location, the client invited as a guest, a 2-hour
 * alert, and the session / contact details in the notes.
 */
function snapbook_gcal_build_event($booking)
{
    $package = trim((string) ($booking->package_name ?? ''));
    $session = trim((string) ($booking->session_type ?? ''));
    $addons  = trim((string) ($booking->addons_json ?? ''));
    $time    = trim((string) ($booking->session_time ?? ''));
    $date    = trim((string) ($booking->session_date ?? ''));
    $client  = trim((string) ($booking->client_name ?? ''));
    $email   = trim((string) ($booking->client_email ?? ''));
    $phone   = trim((string) ($booking->client_phone ?? ''));
    $place   = trim((string) ($booking->location_pref ?? ''));
    $id      = (int) $booking->id;
    $order   = (int) ($booking->order_id ?? 0);

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }

    // Title: package + order number — "Beach shooting #34182". Falls back to the
    // session type when a booking has no package, and to the booking id when it
    // was taken without a WooCommerce order (the no-Woo enquiry path).
    $shoot   = $package !== '' ? $package : ($session !== '' ? $session : __('Booking', 'snapbook'));
    $summary = $shoot . ' #' . ($order > 0 ? $order : $id);

    // Notes: the session, then everything needed to deal with the client
    // without opening wp-admin.
    $lines = [];
    if ($package !== '') {
        $lines[] = __('Package:', 'snapbook') . ' ' . $package;
    }
    $lines[] = __('Add-ons:', 'snapbook') . ' ' . ($addons !== '' ? $addons : __('None', 'snapbook'));
    if ($time !== '') {
        $lines[] = __('Time:', 'snapbook') . ' ' . $time;
    }
    $lines[] = '';
    if ($client !== '') {
        $lines[] = __('Client:', 'snapbook') . ' ' . $client;
    }
    if ($phone !== '') {
        $lines[] = __('Phone:', 'snapbook') . ' ' . $phone;
    }
    if ($email !== '') {
        $lines[] = __('Email:', 'snapbook') . ' ' . $email;
    }
    $lines[] = __('Location:', 'snapbook') . ' ' . ($place !== '' ? $place : __('Not given', 'snapbook'));

    // Google caps reminder overrides at 4 weeks (40320 minutes).
    $remind = (int) apply_filters('snapbook_gcal_reminder_minutes', 120);
    $remind = max(0, min(40320, $remind));

    $event = [
        'summary'     => $summary,
        'description' => implode("\n", $lines),
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'popup', 'minutes' => $remind],
            ],
        ],
        // Lets you find/filter SnapBook events in the calendar API later.
        'extendedProperties' => [
            'private' => ['snapbook_booking_id' => (string) $id],
        ],
    ];

    if ($place !== '') {
        $event['location'] = $place;
    }

    // Invite the client as a guest. snapbook_gcal_insert_event() switches
    // Google's sendUpdates on whenever this key is present, so they get the
    // invitation email and the shoot lands in their own calendar.
    if (is_email($email)) {
        $event['attendees'] = [
            ['email' => $email, 'displayName' => $client],
        ];
    }

    $times = snapbook_gcal_event_times($date, $time);
    if ($times) {
        $event['start'] = ['dateTime' => $times['start']];
        $event['end']   = ['dateTime' => $times['end']];
    } else {
        // All-day event: end date is exclusive in the Calendar API.
        $event['start'] = ['date' => $date];
        try {
            $end = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
        } catch (Exception $e) {
            $end = $date;
        }
        $event['end'] = ['date' => $end];
    }

    return apply_filters('snapbook_gcal_event', $event, $booking);
}

/**
 * Turn a booking's free-text time into ISO-8601 start/end (with the site's
 * UTC offset baked in, so no IANA zone name is needed). Returns null when no
 * clock time can be read out — the event then falls back to all-day.
 */
function snapbook_gcal_event_times($date, $time)
{
    if (! preg_match('/(\d{1,2})[:.](\d{2})\s*(am|pm)?|\b(\d{1,2})\s*(am|pm)\b/i', $time, $m)) {
        return null;
    }

    if (! empty($m[1])) {
        $hour = (int) $m[1];
        $min  = (int) $m[2];
        $mer  = strtolower($m[3] ?? '');
    } else {
        $hour = (int) $m[4];
        $min  = 0;
        $mer  = strtolower($m[5] ?? '');
    }

    if ($mer === 'pm' && $hour < 12) {
        $hour += 12;
    } elseif ($mer === 'am' && $hour === 12) {
        $hour = 0;
    }

    if ($hour > 23 || $min > 59) {
        return null;
    }

    $duration = (int) apply_filters('snapbook_gcal_event_duration', 60);
    $duration = max(15, $duration);

    try {
        $tz    = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $start = new DateTime($date, $tz);
        $start->setTime($hour, $min, 0);
        $end = (clone $start)->modify('+' . $duration . ' minutes');

        return [
            'start' => $start->format('c'),
            'end'   => $end->format('c'),
        ];
    } catch (Exception $e) {
        return null;
    }
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — "Send test event" button
═══════════════════════════════════════════════════════════════ */

add_action('wp_ajax_snapbook_gcal_test_event', 'snapbook_gcal_ajax_test_event');
function snapbook_gcal_ajax_test_event()
{
    check_ajax_referer('snapbook_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'snapbook')]);
    }
    if (! snapbook_gcal_is_connected()) {
        wp_send_json_error(['message' => __('Connect your Google account first.', 'snapbook')]);
    }

    $token = snapbook_gcal_access_token();
    if ($token === '') {
        wp_send_json_error(['message' => get_option('fpb_gcal_last_error', __('Could not obtain an access token.', 'snapbook'))]);
    }

    // The guest is the connected account itself — the test exercises the invite
    // path without mailing a real client.
    $conn  = snapbook_gcal_get_connection();
    $today = current_time('Y-m-d');
    $event = snapbook_gcal_build_event((object) [
        'id'            => 0,
        'package_name'  => __('Sample package', 'snapbook'),
        'session_type'  => __('SnapBook test', 'snapbook'),
        'addons_json'   => __('Sample add-on', 'snapbook'),
        'session_time'  => '10:00',
        'session_date'  => $today,
        'client_name'   => __('Test booking', 'snapbook'),
        'client_email'  => isset($conn['email']) ? $conn['email'] : '',
        'client_phone'  => '+00 000 000 000',
        'location_pref' => __('Test location', 'snapbook'),
    ]);
    $event['summary'] = __('SnapBook · test event', 'snapbook');

    $res = snapbook_gcal_insert_event($event, $token);

    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()]);
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);

    if ($code >= 200 && $code < 300 && ! empty($data['id'])) {
        wp_send_json_success([
            'message' => __('A test event was added to your Google Calendar for today.', 'snapbook'),
            'link'    => isset($data['htmlLink']) ? esc_url_raw($data['htmlLink']) : '',
        ]);
    }

    $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
    wp_send_json_error(['message' => $api_msg]);
}
