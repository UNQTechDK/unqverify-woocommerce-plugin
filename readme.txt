=== Aldersverificering for WooCommerce ===
Contributors: unqtech
Tags: woocommerce, age-verification, mitid, denmark, aldersverificering
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
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
* Per-product, per-category, and per-variation age overrides
* Target individual age-restricted variations without gating their sibling variations
* Four-tier age resolution: store default → gated category override → parent product override → variation override
* Test and production environments with separate API keys
* Popup and redirect verification flows
* Danish and English storefront strings; admin UI fully translatable
* WPML and Polylang compatible
* Safe for WordPress Multisite (single-site activation only)

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

= Does the plugin work in a WordPress Multisite installation? =

The plugin is configured for single-site activation only (`Network: false`). A network
admin cannot activate it across all sites at once. Each sub-site admin must activate
and configure the plugin independently, which is the recommended approach since each
store needs its own API key and settings.

= Does it work with WPML or Polylang? =

Yes. When WPML or Polylang is active the plugin automatically resolves translated
products to their source-language variant before reading per-product age gate settings.
This ensures age gate flags and age overrides set on a product apply to all translations
of that product.

= Does the plugin store personal data? =

The plugin reads a short-lived JWT cookie set by the MitID flow during checkout validation and immediately discards it. No personal data is persisted to the database.

= What cleanup happens when I delete the plugin? =

All plugin options, category gating meta, product gating meta, and JWKS cache are removed automatically via the included `uninstall.php`.

== Changelog ==

= 1.1.0 =
* Added per-variation age-gate rules for variable WooCommerce products.
* Added variation-level age overrides and parent/category inheritance guidance.
* Fixed release ZIP packaging and added CI verification gates.

= 1.0.0 =
* Extracted all helper functions into `includes/functions.php` for cleaner code organisation.
* Registered all plugin options via `register_setting()` with sanitize callbacks (WordPress.org submission requirement).
* Added `current_user_can()` capability checks in all settings and meta save handlers.
* Fixed JWKS stale-cache option to not autoload (`update_option(..., false)`).
* Added term-meta cache pre-warming on the Products list screen to eliminate N+1 queries.

= 0.2.0 =
* Added WPML and Polylang support: translated products resolve to source-language post for per-product meta reads.
* Added per-request cart cache invalidation on `woocommerce_cart_updated`, `woocommerce_add_to_cart`, etc. — prevents stale gating results when other plugins modify the cart mid-request.
* Added jQuery availability guard to cart.js and checkout.js — prevents ReferenceError on stores where performance plugins defer jQuery loading.
* Added `Network: false` in plugin header — prevents accidental network-wide activation in Multisite.
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
