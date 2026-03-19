=== Aldersverificering for WooCommerce ===
Contributors: unqtech
Tags: woocommerce, age-verification, mitid, denmark, aldersverificering
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce age verification for Danish webshops using MitID via Aldersverificering.dk.

== Description ==

**Aldersverificering for WooCommerce** integrates MitID-based age verification into your WooCommerce store via the [Aldersverificering.dk](https://www.aldersverificering.dk) service.

When a customer proceeds to checkout with age-restricted products in their cart, they are prompted to verify their age using MitID before the order can be placed. Verification can happen as a popup or a full-page redirect — your choice.

**Key features:**

* MitID age verification via the Aldersverificering.dk SDK
* Works with both classic WooCommerce checkout and the modern Checkout Block
* Compatible with WooCommerce HPOS (High-Performance Order Storage)
* Two targeting modes:
  * **All products** — every checkout requires verification
  * **Selected products & categories** — gate only specific items
* Per-product and per-category age overrides (e.g. gate spirits at 18, tobacco at 18, cinema at 15)
* Three-tier age resolution: store default → category override → product override
* Test and production environments with separate API keys
* Popup and redirect verification flows
* Danish and English storefront strings; admin UI fully translatable

== Installation ==

1. Upload the `aldersverificering-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Navigate to **WooCommerce > Settings > UNQVerify**.
4. Enter your test API key from [Aldersverificering.dk](https://www.aldersverificering.dk) and save.
5. Configure targeting, required age, and verification mode to match your store's needs.

When you are ready to go live, enter your production API key and toggle the environment to **Production**.

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign up at [Aldersverificering.dk](https://www.aldersverificering.dk) to receive test and production API keys.

= Does this work with the WooCommerce Checkout Block? =

Yes. The plugin validates age verification server-side via the Store API checkout hook, so it works with both the classic shortcode checkout and the modern Blocks-based checkout.

= Can I require a different age for different products? =

Yes. In **selected products & categories** mode you can set a per-category age override and a per-product age override. The most specific rule wins: product override > category override > store default.

= What happens if the customer closes the verification popup? =

The checkout button is re-enabled and the customer can try again. No order is placed until a valid, unexpired MitID token is returned.

= Does the plugin store personal data? =

The plugin reads a short-lived JWT cookie set by the MitID flow during checkout validation and immediately discards it. No personal data is persisted to the database.

= What cleanup happens when I delete the plugin? =

All plugin options, category gating meta, product gating meta, and JWKS cache are removed automatically via the included `uninstall.php`.

== Changelog ==

= 0.2.0 =
* Added three-tier age resolution: store default → category override → product override.
* Added per-category and per-product age override fields in the WooCommerce admin.
* Added age gate columns on the Products and Product Categories list screens.
* Added status indicator badge in the per-product meta box.
* Fixed JS enqueue bug: cart/checkout scripts were loaded on all pages regardless of targeting mode.
* Added TEST MODE banner to the verification modal when using a test API key.
* Improved admin column badges to accurately reflect the source of the gating rule.
* Added `uninstall.php` to clean up all plugin data on deletion.
* 60 unit tests covering settings, cart gating, age resolution, and JWT validation.

= 0.1.0 =
* Initial release.
* MitID age verification for WooCommerce checkout (classic + Blocks).
* HPOS and Cart/Checkout Blocks compatibility declarations.
* Popup and redirect verification modes.
* All-products and selected-products/categories targeting modes.
* Test and production environment switching.
