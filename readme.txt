=== SnapBook ===
Contributors: snapbook
Tags: booking, photography, woocommerce, appointment, calendar
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-step photography booking with backend management and WooCommerce checkout.

== Description ==

SnapBook is a complete booking solution for photography studios. It provides a multi-step booking form, session type and package management, add-on services, a date availability calendar, and optional WooCommerce deposit checkout.

**Features:**

* Multi-step booking form via shortcode `[snapbook]`
* Gutenberg-friendly block pattern that inserts the native Shortcode block
* Session types, packages, and add-ons management
* Date slot calendar (available / booked / blocked)
* WooCommerce integration for deposit payments
* Admin dashboard with booking status management
* WhatsApp fallback for non-WooCommerce bookings
* Email notifications to admin and client

== Installation ==

1. Upload the `snapbook` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **FP Booking → Settings** to configure WhatsApp number, deposit percentage, and currency.
4. Add the shortcode `[snapbook]` to any page.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. WooCommerce is optional. When active, clients pay a deposit at checkout. Without WooCommerce, a booking enquiry email is sent instead.

= How do I display the booking form? =

Use the shortcode `[snapbook]` on any page or post.

= Can I block specific dates? =

Yes. Go to **FP Booking → Date Slots** and click on any future date to toggle it between Available, Booked, and Blocked.

== Screenshots ==

1. Multi-step booking form on the front end.
2. Admin bookings list with status management.
3. Session types management screen.
4. Packages management screen.
5. Date slots calendar.
6. Settings page.

== Changelog ==

= 2.1.0 =
* Added WooCommerce deposit checkout integration.
* Added emoji support for session types and add-ons.
* Improved admin UI with tabbed navigation.
* Security hardening: nonce verification, output escaping, input sanitisation.

= 2.0.0 =
* Complete rewrite with multi-step booking form.
* Added add-ons support.
* Added date availability calendar.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.1.0 =
Major update with WooCommerce integration. Please back up your database before upgrading.
