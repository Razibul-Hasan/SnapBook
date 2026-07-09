=== SnapBook ===
Contributors: snapbook
Tags: booking, photography, woocommerce, appointment, calendar
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-step photography booking with backend management and WooCommerce checkout.

== Description ==

SnapBook is a complete booking solution for photography studios. It provides a multi-step booking form, session type and package management, add-on services, a date availability calendar, and optional WooCommerce deposit checkout.

**Features:**

* Multi-step booking form via shortcode `[snapbook]`
* Session types, packages, and add-ons management
* Date slot calendar (available / booked / blocked)
* WooCommerce integration for deposit payments
* Admin dashboard with booking status management
* WhatsApp fallback for non-WooCommerce bookings
* Email notifications to admin and client

== Installation ==

1. Upload the `snapbook` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **SnapBook → Settings** to configure the notification email, WhatsApp number, booking page, appearance colors, and checkout/payment options. (Currency is taken automatically from WooCommerce.)
4. Add the shortcode `[snapbook]` to any page.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. WooCommerce is optional. When active, clients pay a deposit at checkout. Without WooCommerce, a booking enquiry email is sent instead.

= How do I display the booking form? =

Use the shortcode `[snapbook]` on any page or post.

= Can I block specific dates? =

Yes. Go to **SnapBook → Date Slots** and click on any future date to toggle it between Available, Booked, and Blocked.

== Screenshots ==

1. Multi-step booking form on the front end.
2. Admin bookings list with status management.
3. Session types management screen.
4. Packages management screen.
5. Date slots calendar.
6. Settings page.

== Changelog ==

= 2.5.0 =
* Cleanup release prepared for WordPress.org: removed the Elementor widget and the Gutenberg block — use the `[snapbook]` shortcode (it accepts optional `package`, `primary`, and `accent` attributes).
* No more external requests: the Font Awesome CDN stylesheet is no longer loaded. Icons now use emoji or bundled Dashicons classes out of the box.
* Performance: the booking script loads deferred, and all assets load only on pages that contain the booking form.
* All PHP functions, AJAX endpoints, and CSS classes are now consistently prefixed; removed legacy duplicate code paths.

= 2.4.7 =
* Booking form: the Details step (Step 3) is now organised into labelled sections — Contact, Event details, Address, and Additional details.

= 2.4.6 =
* Partial payments: the remaining-balance payment link is now included in the customer's first booking confirmation email, not only in later reminders.
* Admin bookings: a balance status pill, a "Copy Payment Link" quick action, and a payment panel in the booking view for easier handling of deposit balances.

= 2.4.5 =
* Shareable package deep-links: every package gets a stable slug and a "Copy Link" button on the Packages page.
* Visiting the booking page with ?package= pre-selects that package after the date step (add-ons stay unchecked).
* New "Booking page" setting with auto-detection of the page containing the booking form.

= 2.3.1 =
* Refined step 2 design: featured-package ribbon, selection checkmarks, redesigned 50% payment toggle.

= 2.3.0 =
* New Appearance settings: pick primary and accent colors for the booking form.
* Rewrote and optimized the frontend stylesheet; removed legacy unused styles.

= 2.2.0 =
* New 4-step booking flow (Date, Package, Details, Payment) with live price breakdown.
* Direct checkout: order is created from the booking form; offline payments confirm in place.
* Checkout form builder: toggle built-in fields, add or remove custom fields.
* Order confirmation screen with editable text, live status filters, and a redesigned admin UI.

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
