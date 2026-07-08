# SnapBook

> Multi-step photography booking plugin for WordPress with WooCommerce deposit checkout.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a?logo=woocommerce)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL--2.0%2B-green)
![Version](https://img.shields.io/badge/Version-2.4.7-orange)

---

## Features

- **Multi-step booking form** — a 4-step flow (Date → Package → Details → Payment) rendered via the `[snapbook]` shortcode, with a live price breakdown.
- **Session types, packages & add-ons** — fully manageable from the admin dashboard.
- **Date slot calendar** — toggle any future date between Available / Booked / Blocked.
- **WooCommerce checkout** — direct or redirect checkout with a configurable deposit, plus an optional 50% partial-payment (deposit now, balance later) mode.
- **Balance reminders** — automatic and manual email reminders with a payment link for the remaining balance.
- **Shareable package links** — every package gets a stable slug and a "Copy Link" button; opening the booking page with `?package=` pre-selects it.
- **Appearance settings** — pick primary and accent brand colors for the booking form.
- **Checkout field builder** — toggle built-in checkout fields and add or remove custom ones.
- **Gutenberg & Elementor** — a block pattern and an Elementor widget for inserting the form.
- **WhatsApp fallback** — sends a booking enquiry when WooCommerce is not active.
- **Email notifications** — automatic emails to both admin and client on new bookings.
- **Security hardened** — nonce verification, output escaping, and input sanitisation throughout.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce *(optional)* | 7.0 |

> The store currency is taken automatically from WooCommerce when it is active.

---

## Installation

1. Clone or download this repository into your plugins directory:
   ```bash
   git clone https://github.com/your-username/snapbook.git \
      wp-content/plugins/snapbook
   ```
2. Activate the plugin from the **WordPress Admin → Plugins** screen.
3. Go to **SnapBook → Settings** and configure:
   - Notification email and WhatsApp number
   - Booking page (auto-detected, or select it)
   - Appearance (primary / accent colors)
   - Checkout mode, fields, and partial-payment options
4. Add `[snapbook]` to any page or post.

---

## Usage

### Shortcode

```
[snapbook]
```

Place this shortcode on any page to display the multi-step booking form. A Gutenberg
block pattern and an Elementor widget are also available for inserting it visually.

### Admin Menus

| Menu | Description |
|---|---|
| SnapBook → All Bookings | View and manage all bookings with status updates |
| SnapBook → Session Types | Create and edit photography session types |
| SnapBook → Packages | Manage pricing packages per session type |
| SnapBook → Add-ons | Configure optional add-on services |
| SnapBook → Date Slots | Manage calendar availability |
| SnapBook → Settings | Appearance, checkout, payments, notifications & form text |

---

## Project Structure

```
snapbook/
├── snapbook.php                    # Plugin bootstrap, hooks & compat wrappers
├── readme.txt                      # WordPress.org readme
├── README.md                       # This file
├── assets/
│   ├── css/
│   │   ├── admin.css               # Admin dashboard styles
│   │   ├── booking.css             # Front-end booking form styles
│   │   └── checkout.css            # WooCommerce checkout styles
│   └── js/
│       ├── admin.js                # Admin scripts
│       └── booking.js              # Front-end booking form scripts
├── includes/
│   ├── install.php                 # Activation / deactivation hooks & DB tables
│   ├── admin.php                   # Admin menus, pages, and CRUD
│   ├── ajax.php                    # AJAX handlers
│   ├── shortcode.php               # [snapbook] shortcode & asset registration
│   ├── gutenberg.php               # Gutenberg block pattern
│   ├── elementor.php               # Elementor widget
│   └── woocommerce.php             # WooCommerce checkout & deposit integration
└── templates/
    └── embed-pay.php               # Embedded order-pay page (iframe)
```

---

## Changelog

### 2.4.7
- Booking form: the Details step (Step 3) is now organised into labelled sections — Contact, Event details, Address, and Additional details.

### 2.4.6
- Partial payments: the remaining-balance payment link is now included in the customer's first booking confirmation email, not only in later reminders.
- Admin bookings: a balance status pill, a "Copy Payment Link" quick action, and a payment panel in the booking view for easier handling of deposit balances.

### 2.4.5
- Shareable package deep-links: every package gets a stable slug and a "Copy Link" button on the Packages page.
- Visiting the booking page with `?package=` pre-selects that package after the date step.
- New "Booking page" setting with auto-detection of the page containing the booking form.

### 2.3.1
- Refined step 2 design: featured-package ribbon, selection checkmarks, redesigned 50% payment toggle.

### 2.3.0
- New Appearance settings: pick primary and accent colors for the booking form.
- Rewrote and optimized the frontend stylesheet; removed legacy unused styles.

### 2.2.0
- New 4-step booking flow (Date, Package, Details, Payment) with live price breakdown.
- Direct checkout: order is created from the booking form; offline payments confirm in place.
- Checkout form builder: toggle built-in fields, add or remove custom fields.
- Order confirmation screen with editable text, live status filters, and a redesigned admin UI.

### 2.1.0
- Added WooCommerce deposit checkout integration.
- Added emoji support for session types and add-ons.
- Improved admin UI with tabbed navigation.
- Security hardening: nonce verification, output escaping, input sanitisation.

### 2.0.0
- Complete rewrite with multi-step booking form.
- Added add-ons support.
- Added date availability calendar.

### 1.0.0
- Initial release.

---

## License

Licensed under the [GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html).
