<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
═══════════════════════════════════════════════════════════════ */
function fpb_activate()
{
    fpb_create_tables();
    fpb_seed_defaults();
    fpb_create_wc_product();
    flush_rewrite_rules();
}

function fpb_deactivate()
{
    flush_rewrite_rules();
}

/* ═══════════════════════════════════════════════════════════════
   CREATE DATABASE TABLES
═══════════════════════════════════════════════════════════════ */
function fpb_create_tables()
{
    global $wpdb;
    $c   = $wpdb->get_charset_collate();
    $pfx = $wpdb->prefix . 'fpb_';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Session types (e.g. "Holiday Photoshoot", "Wedding Photography")
    dbDelta("CREATE TABLE {$pfx}sessions (
		id       mediumint(9) NOT NULL AUTO_INCREMENT,
		name     varchar(100) NOT NULL,
		emoji    varchar(20)  DEFAULT '',
		slug     varchar(50)  NOT NULL,
		active   tinyint(1)   DEFAULT 1,
		sort_order int        DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY slug (slug)
	) $c;");

    // Packages — belong to a session type
    dbDelta("CREATE TABLE {$pfx}packages (
		id          mediumint(9) NOT NULL AUTO_INCREMENT,
		session_id  mediumint(9) NOT NULL,
		name        varchar(100) NOT NULL,
		price       decimal(10,2) NOT NULL DEFAULT 0.00,
		duration    varchar(80)  DEFAULT '',
		description varchar(200) DEFAULT '',
		featured    tinyint(1)   DEFAULT 0,
		sort_order  int          DEFAULT 0,
		active      tinyint(1)   DEFAULT 1,
		PRIMARY KEY (id),
		KEY session_id (session_id)
	) $c;");

    // Add-ons — global (package_id=0) or tied to a specific package
    dbDelta("CREATE TABLE {$pfx}addons (
		id          mediumint(9) NOT NULL AUTO_INCREMENT,
		name        varchar(100) NOT NULL,
		price       decimal(10,2) NOT NULL DEFAULT 0.00,
		emoji       varchar(20)  DEFAULT '',
		description varchar(200) DEFAULT '',
		package_id  mediumint(9) NOT NULL DEFAULT 0,
		active      tinyint(1)   DEFAULT 1,
		sort_order  int          DEFAULT 0,
		PRIMARY KEY (id),
		KEY package_id (package_id)
	) $c;");

    // Date slots — dates marked booked/blocked by admin; all other future dates = available
    dbDelta("CREATE TABLE {$pfx}dates (
		id       mediumint(9) NOT NULL AUTO_INCREMENT,
		date_str date         NOT NULL,
		status   varchar(20)  DEFAULT 'booked',
		notes    varchar(200) DEFAULT '',
		PRIMARY KEY (id),
		UNIQUE KEY date_str (date_str)
	) $c;");

    // Bookings — created on WooCommerce payment complete (or fallback AJAX submit)
    dbDelta("CREATE TABLE {$pfx}bookings (
		id            mediumint(9)  NOT NULL AUTO_INCREMENT,
		order_id      bigint        DEFAULT NULL,
		session_type  varchar(100)  NOT NULL DEFAULT '',
		package_name  varchar(100)  NOT NULL DEFAULT '',
		package_price decimal(10,2) NOT NULL DEFAULT 0.00,
		addons_json   text          DEFAULT '',
		addons_total  decimal(10,2) DEFAULT 0.00,
		total         decimal(10,2) NOT NULL DEFAULT 0.00,
		deposit       decimal(10,2) NOT NULL DEFAULT 0.00,
		client_name   varchar(200)  NOT NULL DEFAULT '',
		client_email  varchar(200)  NOT NULL DEFAULT '',
		client_phone  varchar(100)  DEFAULT '',
		client_country varchar(100) DEFAULT '',
		session_date  date          DEFAULT NULL,
		session_time  varchar(100)  DEFAULT '',
		location_pref varchar(200)  DEFAULT '',
		notes         text          DEFAULT '',
		signer_name   varchar(200)  DEFAULT '',
		status        varchar(50)   DEFAULT 'pending',
		created_at    datetime      DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY order_id (order_id)
	) $c;");

    update_option('fpb_db_version', FPB_VER);
}

/* ═══════════════════════════════════════════════════════════════
   MIGRATE EMOJI — fill blank emoji columns on existing DB rows
   Runs once; gated by option flag so it never re-runs.
═══════════════════════════════════════════════════════════════ */
function fpb_migrate_emoji()
{
    if (get_option('fpb_emoji_migrated')) {
        return;
    }
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';

    // Sessions: match by slug (slug never changes)
    $session_emoji = [
        'photo'   => wp_encode_emoji('📷'),
        'wedding' => wp_encode_emoji('💍'),
        'video'   => wp_encode_emoji('🎬'),
        'booth'   => wp_encode_emoji('📸'),
    ];
    foreach ($session_emoji as $slug => $emoji) {
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "UPDATE {$pfx}sessions SET emoji = %s WHERE slug = %s AND (emoji = '' OR emoji IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $emoji,
                $slug
            )
        );
    }

    // Add-ons: match by name
    $addon_emoji = [
        'Drone aerial session'   => wp_encode_emoji('🚁'),
        'Printed album 20×30cm' => wp_encode_emoji('🖨️'),
        'Rush delivery'          => wp_encode_emoji('⚡'),
    ];
    foreach ($addon_emoji as $name => $emoji) {
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "UPDATE {$pfx}addons SET emoji = %s WHERE name = %s AND (emoji = '' OR emoji IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $emoji,
                $name
            )
        );
    }

    update_option('fpb_emoji_migrated', '1');
}

/* ═══════════════════════════════════════════════════════════════
   SEED DEFAULT DATA (only on first activation)
═══════════════════════════════════════════════════════════════ */
function fpb_seed_defaults()
{
    global $wpdb;
    $pfx = $wpdb->prefix . 'fpb_';

    // Already seeded?
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}sessions") > 0) { // phpcs:ignore
        return;
    }

    $sessions = [
        ['name' => 'Holiday / Couple Photoshoot', 'emoji' => wp_encode_emoji('📷'), 'slug' => 'photo',   'sort_order' => 1],
        ['name' => 'Wedding Photography',          'emoji' => wp_encode_emoji('💍'), 'slug' => 'wedding', 'sort_order' => 2],
        ['name' => 'Videography',                  'emoji' => wp_encode_emoji('🎬'), 'slug' => 'video',   'sort_order' => 3],
        ['name' => 'PhotoBooth Hire',              'emoji' => wp_encode_emoji('📸'), 'slug' => 'booth',   'sort_order' => 4],
    ];
    foreach ($sessions as $s) {
        $wpdb->insert("{$pfx}sessions", $s);  // phpcs:ignore
    }

    $packages = [
        // photo
        ['session_id' => 1, 'name' => 'Golden Hour',       'price' => 199,  'duration' => '1hr',      'description' => '30 edited photos',         'featured' => 0, 'sort_order' => 1],
        ['session_id' => 1, 'name' => 'Mauritius Story',   'price' => 399,  'duration' => '3hrs',     'description' => '80+ edited photos',        'featured' => 1, 'sort_order' => 2],
        ['session_id' => 1, 'name' => 'Island Explorer',   'price' => 699,  'duration' => 'Full day', 'description' => 'Unlimited + drone',        'featured' => 0, 'sort_order' => 3],
        // wedding
        ['session_id' => 2, 'name' => 'Elopement',         'price' => 750,  'duration' => '4hrs',     'description' => '100 edited photos',        'featured' => 0, 'sort_order' => 1],
        ['session_id' => 2, 'name' => 'Full Day',          'price' => 1800, 'duration' => '8hrs',     'description' => 'Photo + video highlights', 'featured' => 1, 'sort_order' => 2],
        ['session_id' => 2, 'name' => 'Grand Celebration', 'price' => 3500, 'duration' => '2 days',   'description' => 'Full team coverage',       'featured' => 0, 'sort_order' => 3],
        // video
        ['session_id' => 3, 'name' => 'Short Film',        'price' => 600,  'duration' => 'Up to 3min', 'description' => 'Highlights reel',         'featured' => 0, 'sort_order' => 1],
        ['session_id' => 3, 'name' => 'Wedding Film',      'price' => 1200, 'duration' => 'Full day', 'description' => 'Trailer + full film',      'featured' => 1, 'sort_order' => 2],
        ['session_id' => 3, 'name' => 'Cinematic Suite',   'price' => 2400, 'duration' => 'Full day', 'description' => 'Photo + video combo',      'featured' => 0, 'sort_order' => 3],
        // booth
        ['session_id' => 4, 'name' => 'Essential',         'price' => 350,  'duration' => '2hrs',     'description' => 'Instant prints',           'featured' => 0, 'sort_order' => 1],
        ['session_id' => 4, 'name' => 'Premium',           'price' => 550,  'duration' => '4hrs',     'description' => 'Full booth experience',    'featured' => 1, 'sort_order' => 2],
        ['session_id' => 4, 'name' => 'Corporate',         'price' => 800,  'duration' => 'Up to 6hrs', 'description' => 'Branded + custom props',  'featured' => 0, 'sort_order' => 3],
    ];
    foreach ($packages as $p) {
        $wpdb->insert("{$pfx}packages", $p);  // phpcs:ignore
    }

    $addons = [
        ['name' => 'Drone aerial session',   'price' => 150, 'emoji' => wp_encode_emoji('🚁'), 'description' => 'FAA-compliant aerial footage', 'sort_order' => 1],
        ['name' => 'Printed album 20×30cm', 'price' => 80,  'emoji' => wp_encode_emoji('🖨️'), 'description' => 'Premium lay-flat album',       'sort_order' => 2],
        ['name' => 'Rush delivery',          'price' => 50,  'emoji' => wp_encode_emoji('⚡'), 'description' => 'Delivery within 5 days',       'sort_order' => 3],
    ];
    foreach ($addons as $a) {
        $wpdb->insert("{$pfx}addons", $a);  // phpcs:ignore
    }
}

/* ═══════════════════════════════════════════════════════════════
   CREATE HIDDEN WOOCOMMERCE PRODUCT (deposit placeholder)
═══════════════════════════════════════════════════════════════ */
function fpb_create_wc_product()
{
    if (! class_exists('WooCommerce')) {
        return;
    }
    $existing_id = (int) get_option('fpb_wc_product_id', 0);
    if ($existing_id && 'publish' === get_post_status($existing_id)) {
        return; // product already exists
    }

    $product = new WC_Product_Simple();
    $product->set_name(__('Photography Session Booking', 'snapbook'));
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_sold_individually(true);
    $product->set_price(1);
    $product->set_regular_price(1);
    $id = $product->save();

    update_option('fpb_wc_product_id', $id);
}
