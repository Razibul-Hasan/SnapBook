=== SnapBook ===
Contributors: snapbook
Tags: booking, photography, woocommerce, appointment, calendar
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your photography site into a booking engine: packages, add-ons, availability calendar, e-signed contracts and 50% deposits through WooCommerce.

== Description ==

SnapBook gives photography studios a complete booking funnel on one page. Clients pick a date from the calendar, choose a package and add-ons, fill in their details, accept your terms, and pay a deposit — without ever leaving the booking form.

Everything is managed from a single admin menu: session types, packages, add-ons, availability, form text, emails, and payment rules.

**The booking flow**

Package and add-ons &rarr; client details &rarr; contract (optional) &rarr; payment. The availability calendar sits in a sidebar next to the form, so the date is always visible and never costs an extra step.

**Highlights**

* **One-page booking wizard** — drop `[snapbook]` on any page. No page reloads between steps.
* **Availability calendar** — mark any future date Available, Booked, or Blocked with a click.
* **Packages and add-ons** — priced per session type, with featured badges and optional images.
* **50% deposits** — clients pay half now and half later. SnapBook creates the balance order automatically and emails a payment link.
* **Terms &amp; Conditions step** — show your service agreement in the form and require the client to accept it before paying. Fully optional and edited with the visual editor.
* **Embedded checkout** — the WooCommerce payment section loads inside the form, so PayPal buttons and card fields appear without a redirect.
* **Branded emails** — every message the plugin sends uses one themed template that follows your brand colors, with an optional file attachment such as a contract PDF.
* **Balance reminders** — automatic and manual reminder emails with a pay-now link.
* **Shareable package links** — every package gets a link that opens the booking form with that package pre-selected.
* **Checkout field builder** — enable, rename, or require any built-in field, and add custom fields of your own.
* **Works without WooCommerce** — the form falls back to an enquiry email plus a WhatsApp button.
* **No external requests** — no CDN fonts or icon libraries. Assets load only on pages that contain the form.

== Installation ==

1. Upload the `snapbook` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins &rarr; Add New**.
2. Activate the plugin through the **Plugins** menu.
3. Go to **SnapBook &rarr; Settings** to set your notification email, WhatsApp number, booking page, brand colors, and checkout and payment options. Currency comes from WooCommerce automatically.
4. Add your session types, packages, and add-ons, then open **SnapBook &rarr; Date Slots** to manage availability.
5. Put the shortcode `[snapbook]` on any page.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. WooCommerce is optional. When it is active, clients pay a deposit or the full amount at checkout. Without it, the form sends a booking enquiry email and offers a WhatsApp button instead.

= How do I display the booking form? =

Use the shortcode `[snapbook]` on any page or post. It also accepts optional attributes: `[snapbook package="slug-or-id" primary="#b8956a" accent="#3d6b78"]` pre-selects a package and overrides the two brand colors for that instance.

= How do I add my Terms and Conditions to the booking form? =

Go to **SnapBook &rarr; Frontend** and turn on **Contract step**. You can set the step name, heading, the agreement text (using the visual editor), and the wording of the acceptance checkbox. The client cannot reach the payment step until the box is ticked. The step is off by default.

= Can I block specific dates? =

Yes. Go to **SnapBook &rarr; Date Slots** and click any future date to cycle it between Available, Booked, and Blocked.

= How does the 50% deposit work? =

The client pays half at checkout. SnapBook creates a second WooCommerce order for the remaining balance, shows the full breakdown on the order and in emails, and sends a payment link for the balance — immediately in the confirmation email and again through scheduled reminders.

= Can I change the wording on the booking form? =

Yes. **SnapBook &rarr; Frontend** controls the sidebar cards and the contract step, and **SnapBook &rarr; Settings** controls the confirmation screens, the order email, and the balance reminder.

= Which page does the booking form live on? =

Any page with the shortcode. SnapBook auto-detects it for the package share links, and you can also choose it explicitly under **SnapBook &rarr; Settings &rarr; General**.

== Screenshots ==

1. The booking form: package selection with the availability calendar in the sidebar.
2. The contract step, where clients read and accept your Terms and Conditions.
3. The payment step with the booking summary and the embedded WooCommerce checkout.
4. Admin bookings list with status management and balance tracking.
5. Packages management with pricing, add-ons, and share links.
6. The Date Slots availability calendar.
7. The Frontend screen, where the sidebar cards and the contract step are edited.

== Changelog ==

= 1.1.1 =
* The step indicator and the booking form now share a single background card for a cleaner, more unified look.

= 1.1.0 =
* New optional **Contract step** between Details and Payment: show your Terms and Conditions in a scrollable panel and require the client to accept them before paying.
* The contract step is fully editable under **SnapBook &rarr; Frontend** — toggle it on or off, name the step, write the agreement in the visual editor, and set the acceptance wording.
* The booking form now adapts to three or four steps automatically, with the step indicator, navigation, and Back buttons all following along.

= 1.0.0 =
* First public release of SnapBook.
* Multi-step booking form via the `[snapbook]` shortcode, with the availability calendar in a sidebar beside the form.
* Session types, packages, and add-ons management, with featured packages and shareable package links.
* Availability calendar with Available, Booked, and Blocked date states.
* WooCommerce checkout in direct (embedded) or redirect mode, with configurable deposits, a 50% partial-payment mode, automatic balance orders, and an optional payment fee.
* Checkout form builder for built-in and custom fields.
* Branded email system covering booking confirmations, balance reminders, and enquiries, with a customisable order email and file attachment.
* Frontend screen for the sidebar cards, form text, and loading placeholders; Appearance settings for brand colors.
* Enquiry-email and WhatsApp fallback when WooCommerce is not active.
* Security hardening throughout: nonce verification, output escaping, and input sanitisation.

== Upgrade Notice ==

= 1.1.1 =
Visual polish only: the step indicator and booking form now sit in one card. No settings or behaviour change.

= 1.1.0 =
Adds an optional Terms and Conditions step to the booking form. Nothing changes on existing sites until you turn it on under SnapBook &rarr; Frontend.
