# Focus Photography Booking

> Multi-step photography booking plugin for WordPress with WooCommerce deposit checkout.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a?logo=woocommerce)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL--2.0%2B-green)
![Version](https://img.shields.io/badge/Version-2.1.0-orange)

---

## Features

- **Multi-step booking form** — rendered via shortcode `[focus_booking]`
- **Session types, packages & add-ons** — fully manageable from the admin dashboard
- **Date slot calendar** — toggle dates between Available / Booked / Blocked
- **WooCommerce integration** — collect a configurable deposit at checkout
- **WhatsApp fallback** — sends a booking enquiry when WooCommerce is not active
- **Email notifications** — automatic emails to both admin and client on new booking
- **Security hardened** — nonce verification, output escaping, and input sanitisation throughout

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce *(optional)* | 7.0 |

---

## Installation

1. Clone or download this repository into your plugins directory:
   ```bash
   git clone https://github.com/your-username/focus-photography-booking.git \
     wp-content/plugins/focus-photography-booking
   ```
2. Activate the plugin from the **WordPress Admin → Plugins** screen.
3. Go to **FP Booking → Settings** and configure:
   - WhatsApp number
   - Deposit percentage
   - Currency
4. Add `[focus_booking]` to any page or post.

---

## Usage

### Shortcode

```
[focus_booking]
```

Place this shortcode on any page to display the multi-step booking form.

### Admin Menus

| Menu | Description |
|---|---|
| FP Booking → Bookings | View and manage all bookings with status updates |
| FP Booking → Session Types | Create and edit photography session types |
| FP Booking → Packages | Manage pricing packages per session type |
| FP Booking → Add-ons | Configure optional add-on services |
| FP Booking → Date Slots | Manage calendar availability |
| FP Booking → Settings | Configure WhatsApp, deposit %, and currency |

---

## Project Structure

```
focus-photography-booking/
├── focus-photography-booking.php   # Plugin bootstrap & hooks
├── readme.txt                      # WordPress.org readme
├── assets/
│   ├── css/
│   │   ├── admin.css               # Admin styles
│   │   └── booking.css             # Front-end booking form styles
│   └── js/
│       ├── admin.js                # Admin scripts
│       └── booking.js              # Front-end booking form scripts
└── includes/
    ├── install.php                 # Activation / deactivation hooks & DB setup
    ├── admin.php                   # Admin menus, pages, and CRUD
    ├── ajax.php                    # AJAX handlers
    ├── shortcode.php               # [focus_booking] shortcode & asset registration
    └── woocommerce.php             # WooCommerce deposit integration
```

---

## Changelog

### 2.1.0
- Added WooCommerce deposit checkout integration
- Added emoji support for session types and add-ons
- Improved admin UI with tabbed navigation
- Security hardening: nonce verification, output escaping, input sanitisation

### 2.0.0
- Complete rewrite with multi-step booking form
- Added add-ons support
- Added date availability calendar

### 1.0.0
- Initial release

---

## License

Licensed under the [GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html).
