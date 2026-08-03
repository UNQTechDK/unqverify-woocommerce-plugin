# Manual Test Suite — Aldersverificering for WooCommerce

**Plugin version:** 1.1.0
**Last updated:** 2026-03-18

This document is a complete step-by-step guide for manually testing every feature of the age verification plugin. It is written for someone with no prior WordPress or WooCommerce experience. Follow the sections in order — each section builds on the setup and state from the one before it.

---

## Table of Contents

1. [Environment Setup](#1-environment-setup)
2. [Plugin Installation](#2-plugin-installation)
3. [Initial Settings — Admin UI](#3-initial-settings--admin-ui)
4. [Settings Validation — Key Prefix Enforcement](#4-settings-validation--key-prefix-enforcement)
5. [Scope: All Products Mode](#5-scope-all-products-mode)
   - [5A. Cart Notice on Direct Checkout](#5a-cart-notice-on-direct-checkout)
   - [5B. Age Verification Popup (Test Mode)](#5b-age-verification-popup-test-mode)
   - [5C. Verified — Checkout Allowed](#5c-verified--checkout-allowed)
   - [5D. Thank-You / Order-Received Page](#5d-thank-you--order-received-page)
6. [Scope: Selected Products & Categories Mode](#6-scope-selected-products--categories-mode)
   - [6A. Category Picker in Settings](#6a-category-picker-in-settings)
   - [6B. Category Edit Screen](#6b-category-edit-screen)
   - [6C. Cart With No Gated Products (Gate Must Stay Open)](#6c-cart-with-no-gated-products-gate-must-stay-open)
   - [6D. Cart With a Gated Product (Gate Must Activate)](#6d-cart-with-a-gated-product-gate-must-activate)
7. [Per-Product Meta Box](#7-per-product-meta-box)
   - [7A. Mark a Product via Meta Box](#7a-mark-a-product-via-meta-box)
   - [7B. Per-Product Age Override](#7b-per-product-age-override)
   - [7C. Max-Age Logic Across Multiple Items](#7c-max-age-logic-across-multiple-items)
8. [Per-Variation Rules](#8-per-variation-rules)
   - [8A. Direct Variation Rule](#8a-direct-variation-rule)
   - [8B. Inherited Parent and Category Rules](#8b-inherited-parent-and-category-rules)
   - [8C. Variation Age Override](#8c-variation-age-override)
9. [Products List Age Gate Column](#9-products-list-age-gate-column)
10. [Redirect Mode](#10-redirect-mode)
11. [Plugin Disabled State](#11-plugin-disabled-state)
12. [Admin Notice — Missing Key](#12-admin-notice--missing-key)
13. [Production Mode](#13-production-mode)
14. [Locale / Language](#14-locale--language)
15. [Edge Cases](#15-edge-cases)
   - [15A. Expired JWT Cookie](#15a-expired-jwt-cookie)
   - [15B. Order-Pay Page (Return to Pay)](#15b-order-pay-page-return-to-pay)
   - [15C. Thank-You Page](#15c-thank-you-page)
   - [15D. Empty Cart Direct Checkout URL](#15d-empty-cart-direct-checkout-url)
16. [Quick Reference — Pass/Fail Checklist](#16-quick-reference--passfail-checklist)

---

## 1. Environment Setup

You need a local WordPress + WooCommerce installation. The easiest way is to use **LocalWP** (free, Mac/Windows/Linux).

### 1.1 Install LocalWP

1. Download and install **LocalWP** from [localwp.com](https://localwp.com/).
2. Open LocalWP and click **+ Create a new site**.
3. Site name: `unqverify-test` (or anything you like).
4. Choose **Preferred** environment → click **Continue**.
5. Set an admin username and password you will remember, e.g. `admin` / `admin123`. Click **Add site**.
6. Wait for the site to provision (about 1 minute).
7. Click **Open site** — your browser opens at a blank WordPress site.
8. Click **WP Admin** in LocalWP (or go to `http://unqverify-test.local/wp-admin`) and log in.

### 1.2 Install WooCommerce

1. In the WordPress admin sidebar go to **Plugins → Add New Plugin**.
2. Search for `WooCommerce`.
3. Click **Install Now** next to the official WooCommerce plugin, then **Activate**.
4. The WooCommerce Setup Wizard opens. Click **Skip setup wizard** at the bottom (or complete it — it does not matter for testing).
5. Go to **WooCommerce → Settings → General** and verify **Store country / region** is set. Save if needed.

### 1.3 Create Test Products

You need at least **three simple products** — they do not need to be real.

| # | Name | Price | Notes |
|---|------|-------|-------|
| P1 | Ordinary Widget | 10 | Never gated |
| P2 | Age-Restricted Item | 50 | Will be individually gated |
| P3 | Premium Item | 80 | Will be in a gated category |

**Steps for each product:**

1. Go to **Products → Add New**.
2. Enter the **Product name** from the table above.
3. In the **Product data** box below the description, set **Regular price** to the price in the table.
4. Click **Publish**.
5. Repeat for all three products.

### 1.4 Create Test Categories

1. Go to **Products → Categories**.
2. Create category **"Beverages"** (Name: `Beverages`, Slug: `beverages`). Click **Add new category**.
3. Create category **"General Goods"** (Name: `General Goods`, Slug: `general-goods`). Click **Add new category**.

### 1.5 Assign Products to Categories

1. Go to **Products → All Products**.
2. Click **P3 — Premium Item** to edit.
3. On the right sidebar under **Product categories**, tick **Beverages**.
4. Click **Update**.
5. Click **P1 — Ordinary Widget** to edit.
6. Tick **General Goods**. Click **Update**.
7. Click **P2 — Age-Restricted Item** to edit. Leave it with no category or any category you like; it will be gated by its meta box, not by category. Click **Update**.

### 1.6 Enable Permalinks

The plugin registers a custom URL `/unqverify/callback/`. This requires WordPress pretty permalinks to be enabled.

1. Go to **Settings → Permalinks**.
2. Select **Post name** (e.g. `/sample-post/`).
3. Click **Save Changes**.

> **Expected result:** You see "Permalink structure updated." WooCommerce regenerates its own rules at the same time.

### 1.7 Register at UNQVerify and Get API Keys

1. Go to [www.aldersverificering.dk](https://www.aldersverificering.dk/) and create an account.
2. After logging in navigate to **Account → API Keys**.
3. Copy your **Test Public Key** — it starts with `pk_test_`.
4. Add your local domain (`unqverify-test.local`) to **Account → Domains**.

> **Note:** Without the domain whitelist step, every verification attempt will fail with an error regardless of any other setting.

---

## 2. Plugin Installation

### 2.1 Install the Plugin

1. In the WordPress admin go to **Plugins → Add New Plugin → Upload Plugin**.
2. Click **Choose file** and select the plugin ZIP file (`aldersverificering-woocommerce.zip`).
3. Click **Install Now**, then **Activate Plugin**.

### 2.2 Verify the Plugin Appears in WooCommerce Settings

1. Go to **WooCommerce → Settings**.
2. Look at the tab row at the top of the page.
3. **Expected result:** A tab labelled **UNQVerify** is present.
4. Click the **UNQVerify** tab.
5. **Expected result:** The settings page loads with four cards:
   - **Age Verification** (enable toggle + status strip + scope section)
   - **Environment & API Keys** (Test / Production radio cards + two key fields)
   - **General Settings** (required age, mode, locale)
   - **Domain Setup**
   - A collapsible **Quick Start Guide** at the bottom.

---

## 3. Initial Settings — Admin UI

### 3.1 Verify Default State

With a brand-new install and no settings saved yet:

1. Go to **WooCommerce → Settings → UNQVerify**.
2. Verify every field shows its default:

| Field | Expected default |
|-------|-----------------|
| Enable toggle | OFF (unchecked) |
| Status strip | "Inactive — age verification is disabled" |
| Scope | **All products** selected (blue border) |
| Environment | **Test mode** selected (amber border) |
| Test Public Key | Empty |
| Production Public Key | Empty |
| Required Age | 18 |
| Verification Mode | Popup (recommended) |
| Popup Language | Auto-detect |

### 3.2 Configure for Test Mode

Follow these steps in order. **Do this now** — later tests depend on this saved state.

1. Toggle **Enable age verification on checkout** — the switch turns green.
2. Confirm the status strip stays "Inactive — no API key configured" (key is still empty).
3. Under **Environment**, confirm **Test mode** card is already selected.
4. Paste your `pk_test_...` key into the **Test Public Key** field.
5. Leave **Scope** set to **All products**.
6. Click **Save changes** (the blue WooCommerce button at the bottom of the page).
7. **Expected result:** The page reloads with no red error banner. The **status strip** now reads something like:  
   *"Active — Test mode · all products"*  
   with a yellow/amber dot and amber background.
8. Verify the **Quick Start Guide** card is present. Click **Hide guide** — it collapses. Reload the page — it should still be collapsed (state is stored in localStorage).

---

## 4. Settings Validation — Key Prefix Enforcement

These tests confirm that the plugin rejects malformed API keys. We are deliberately entering bad data.

### 4.1 Wrong Prefix on Test Key (Client-side)

1. Go to **WooCommerce → Settings → UNQVerify**.
2. Clear the Test Public Key field and type: `BADKEY12345678901234567890123`
3. Click somewhere else on the page (blur the field).
4. **Expected result:** An inline red error appears below the field: *"Test key must start with 'pk_test_'"* (or equivalent). The field border turns red.
5. Fix the value back to your real `pk_test_...` key. The red error disappears.

### 4.2 Wrong Prefix Saved (Server-side)

1. Open your browser's dev tools → **Application** → **Cookies** and find the `wordpress_test_cookie` or use a different browser profile to avoid any autofill.
2. In the Test Public Key field, temporarily type `live_isnotvalid123456789012345` (no `pk_test_` prefix).
3. Click **Save changes**.
4. **Expected result:** The page reloads and shows a red **error block** at the top of the UNQVerify tab: *"Test key must start with pk_test_"*. The key is **not** saved.

### 4.3 Wrong Prefix on Production Key

1. In the **Production Public Key** field type `pk_test_thisiswrong123456789012`.
2. Click **Save changes**.
3. **Expected result:** Error block: *"Production key must start with pk_live_"*.

### 4.4 Production Mode Without Production Key

1. Click the **Production mode** radio card so it is selected.
2. Leave the Production Public Key field empty.
3. Click **Save changes**.
4. **Expected result:** Error block: *"You cannot enable Production mode without a Production Public Key."*
5. Click **Test mode** again and **Save changes** to restore test mode with the valid test key.

---

## 5. Scope: All Products Mode

At this point your settings should be:  
✅ Enabled · ✅ Test mode · ✅ Valid `pk_test_` key · ✅ Scope = All products

### 5A. Cart Notice on Direct Checkout

This test confirms that a customer who navigates directly to `/checkout/` without a valid JWT is redirected to the cart with a notice.

1. Make sure you are **logged out** of the WooCommerce store front-end (not the admin). Open a private/incognito browser window.
2. Add **P1 — Ordinary Widget** to the cart by navigating to the product page and clicking **Add to cart**.
3. Open your browser's developer tools → **Application** tab → **Cookies**. Confirm there is NO cookie named `unqverify_token`.
4. Navigate directly to `http://unqverify-test.local/checkout/`.
5. **Expected result:** You are **redirected to the cart page** (URL changes to `/cart/?unqverify_required=1`). A notice banner appears at the top of the cart page: *"You must complete age verification before proceeding to checkout."* (or Danish equivalent if locale is set to `da`).

> **Pass criterion:** Checkout is unreachable without a verified token.

### 5B. Age Verification Popup (Test Mode)

1. On the cart page (from step 5A), you should see the age verification widget/button.
2. **Expected result:** A button or banner is visible asking you to verify your age — something like *"Verify age to continue"*.
3. Click the verify button.
4. **Expected result:** A modal/popup appears explaining that age verification is required, with a **"Verify age with MitID"** button and a **Cancel** button.
5. Click **Verify age with MitID**.
6. **Expected result:** A new popup window opens (or the page redirects, depending on mode). In test mode you will see a simulated MitID flow — follow through the test verification. No real personal data is needed.
7. Complete the test verification in the MitID popup.
8. **Expected result:** The popup closes. The cart page now shows a green **"Age verified"** confirmation. The verify button is gone or disabled.
9. Open developer tools → Cookies → Confirm a cookie named `unqverify_token` is now present with a non-empty value.

> **Pass criterion:** Successful test verification sets the `unqverify_token` cookie.

### 5C. Verified — Checkout Allowed

1. With the `unqverify_token` cookie set (from step 5B), click **Proceed to checkout** on the cart page.
2. **Expected result:** The checkout page loads normally. You are NOT redirected to the cart.
3. Fill in the checkout form with test data:
   - First name: `Test`
   - Last name: `User`
   - Address: `Test Street 1`
   - City: `Copenhagen`
   - Postcode: `1000`
   - Country: Denmark (or whatever is set as default)
   - Email: `test@example.com`
4. Choose the default payment method (e.g. Direct Bank Transfer).
5. Click **Place order**.
6. **Expected result:** Order is created. You land on the **Thank you / Order received** page at `/checkout/order-received/`.

> **Pass criterion:** Order is placed successfully without being blocked.

### 5D. Thank-You / Order-Received Page

1. You should be on `/checkout/order-received/XXXX/?key=wc_order_...`.
2. **Expected result:** The page displays the order confirmation. You are NOT redirected to the cart. The age gate does not interfere with this page.

---

## 6. Scope: Selected Products & Categories Mode

### 6A. Category Picker in Settings

1. Go to **WooCommerce → Settings → UNQVerify**.
2. Under the **Scope** section in Card 1, click the **"Selected products & categories"** radio card.
3. **Expected result:** The card gets a purple border and is marked selected. A **category picker section** slides open below the scope cards, showing a list of checkboxes for your product categories: **Beverages** and **General Goods** (counts in parentheses).
4. Tick the checkbox next to **Beverages**.
5. Click **Save changes**.
6. **Expected result:** Page reloads. The status strip reads *"Active — Test mode · selected products only"*. The **Beverages** checkbox is still ticked. **General Goods** is unticked.

> **Pass criterion:** Category gating is saved and re-displayed correctly after save.

### 6B. Category Edit Screen

This is an alternative way to mark a category — direct from the category admin page.

1. Go to **Products → Categories**.
2. Click **Beverages** to edit it.
3. **Expected result:** A row labelled **Age Verification** is present with a checkbox: *"Require age verification for all products in this category"*. The checkbox is **already ticked** (because we saved it via the settings page in 6A).
4. Tick the **General Goods** edit page instead:  
   - Go **back** to the category list.  
   - Click **General Goods** to edit.  
   - Tick the **Age Verification** checkbox.  
   - Click **Update**.
5. Go back to **WooCommerce → Settings → UNQVerify**.
6. **Expected result:** Both **Beverages** and **General Goods** checkboxes are now ticked in the category picker — the settings page reflects what was saved via the category edit screen.
7. **Untick General Goods** from the settings page and save. Verify it saves correctly.

### 6C. Cart With No Gated Products (Gate Must Stay Open)

The scope is **"Selected products & categories"** with only **Beverages** category gated. **P1 — Ordinary Widget** is in **General Goods** (which is not gated).

1. Open a **private/incognito** window (fresh — no cookies).
2. Add **P1 — Ordinary Widget** to the cart.
3. Navigate directly to `/checkout/`.
4. **Expected result:** The checkout page **loads normally**. You are NOT redirected to the cart. No age gate is triggered.
5. Place the test order (use test data from section 5C).
6. **Expected result:** Order created successfully on the order-received page.

> **Pass criterion:** Carts with only non-gated products skip the age gate entirely.

### 6D. Cart With a Gated Product (Gate Must Activate)

Now add a product from the **Beverages** category (**P3 — Premium Item**) to the cart.

1. Still in the private window — clear cookies, or open a new private window.
2. Add **P3 — Premium Item** to the cart (it is in **Beverages** which is gated).
3. Navigate to `/checkout/`.
4. **Expected result:** You are redirected to the cart with the *"You must complete age verification..."* notice. Same behaviour as section 5A.
5. Complete the verification (same flow as 5B–5C).
6. **Expected result:** After verification, the checkout page loads and the order can be placed.

**Bonus sub-test — mixed cart:**

1. Clear cookies. Add both **P1 — Ordinary Widget** AND **P3 — Premium Item** to the cart.
2. Navigate to `/checkout/`.
3. **Expected result:** Redirected to cart with notice. One gated item in the cart is enough to trigger the gate.
4. Complete verification and place the order normally.

> **Pass criterion:** A single gated item in a mixed cart triggers the age gate for the whole checkout.

---

## 7. Per-Product Meta Box

### 7A. Mark a Product via Meta Box

We will gate **P2 — Age-Restricted Item** directly on its product edit screen, without using a category.

1. Go to **Products → All Products** and click **P2 — Age-Restricted Item** to edit.
2. On the right side panel (sidebar), find the box labelled **Age Verification**.
3. **Expected result:** The box contains:
   - A checkbox: *"Require age verification to purchase this product"*
   - Checkbox is unchecked.
   - No age override field visible yet.
4. **Tick** the checkbox.
5. **Expected result:** A number field labelled **"Minimum age override"** slides into view below the checkbox, with a placeholder showing the store default (e.g. `18`).
6. Leave the override field empty for now.
7. Click **Update** to save the product.
8. Open a private/incognito window. Add **P2 — Age-Restricted Item** to the cart. Navigate to `/checkout/`.
9. **Expected result:** Age gate triggers (redirect to cart with notice).

> **Pass criterion:** Product-level flag correctly enables the gate for that product.

### 7B. Per-Product Age Override

1. Go back to edit **P2 — Age-Restricted Item**.
2. Tick the **Require age verification** checkbox (should already be ticked).
3. In the **Minimum age override** field enter `21`.
4. Click **Update**.
5. Go to **WooCommerce → Settings → UNQVerify → General Settings**.
6. **Expected result:** The **Required Age** field still shows `18` (the store default is unchanged).
7. Add **P2** to cart, go to checkout. You will be blocked by the gate.
8. After completing the test verification, the cart/checkout would validate against age **21** server-side. (In test mode the simulated verification always succeeds, so this verifies the age propagates correctly — confirm via browser dev tools → Network → that `ageToVerify: 21` appears in the `UNQCheckout` or `UNQCart` JS object. See tip below.)

> **Tip:** In developer tools, open the **Console** tab and type: `UNQCart.ageToVerify` on the cart page, or `UNQCheckout.ageToVerify` on the checkout page. It should return `21`.

### 7C. Max-Age Logic Across Multiple Items

Now test that the gate uses the **highest** required age when multiple gated products are in the cart.

Setup:
- **P2** is gated via meta box with override age **21**.
- Make sure **P3** (Premium Item, Beverages category) now also has an age override: go to **P3 → Edit → Age Verification meta box → tick checkbox → set override to `16`** → Update.

Test steps:
1. Clear cookies. Add both **P2** and **P3** to the cart.
2. Go to the cart page.
3. In the browser console type: `UNQCart.ageToVerify`
4. **Expected result:** Returns `21` (the maximum of 21 and 16).
5. Remove **P2** from the cart (keep only **P3**).
6. Refresh the cart page.
7. In the console type: `UNQCart.ageToVerify`
8. **Expected result:** Returns `16`.

> **Pass criterion:** `ageToVerify` always reflects the highest required age among gated cart items.

---

## 8. Per-Variation Rules

Create a variable product named **Configurable Item** with Size options **S** and **L**, and Eligibility options **Unrestricted** and **Age-restricted**. This creates four exact variations. Do not mark the parent product or either category as gated before starting this section.

### 8A. Direct Variation Rule

1. Edit **Configurable Item** and select **Variable product** in Product data.
2. Open the **Variations** tab and expand **L / Age-restricted**.
3. At the bottom of the expanded variation, tick **Require age verification for this variation**. Confirm the control is visible without enabling **Manage stock?**, leave the age override empty, and update the product.
4. Add **L / Unrestricted** to a private browser window and go to checkout.
5. **Expected result:** Checkout is not age-gated.
6. Clear the cart, add **L / Age-restricted**, and go to checkout.
7. **Expected result:** The age gate activates.
8. Add both variations to the cart.
9. **Expected result:** The age gate remains active because one cart line is gated.

### 8B. Inherited Parent and Category Rules

1. Edit **Configurable Item** and mark the parent product as age-gated, or assign it to a gated category.
2. Expand **L / Unrestricted** in the Variations tab.
3. **Expected result:** The variation displays inherited-rule copy. Do not tick its direct checkbox.
4. Add **L / Unrestricted** to a private browser window and go to checkout.
5. **Expected result:** The age gate activates. A direct variation setting never exempts a parent/category rule.

### 8C. Variation Age Override

1. Remove the parent/category gate used in 8B.
2. On **L / Age-restricted**, tick the direct variation checkbox and set **Minimum age override** to `21`.
3. Update the product, add **L / Age-restricted** to cart, and inspect `UNQCart.ageToVerify` in the browser console.
4. **Expected result:** It returns `21`, even if the parent/category/store settings are lower.
5. Untick the variation checkbox, update, reload the editor, and add **L / Age-restricted** again.
6. **Expected result:** The direct requirement and override are both removed; no stale override remains.

---

## 9. Products List Age Gate Column

1. Go to **Products → All Products**.
2. **Expected result:** The product list table has a column labelled **"Age gate"** after the product title.
3. Verify the column content for each product:

| Product | Scope = all products | Scope = selected_only |
|---------|---------------------|----------------------|
| P1 — Ordinary Widget | Blue "Store-wide" pill | Gray dash `—` |
| P2 — Age-Restricted Item | Blue "Store-wide" pill | Green "Required (21+)" pill |
| P3 — Premium Item | Blue "Store-wide" pill | Green "Required (16+)" pill |

4. Switch scope back to **All products** in settings and save. Reload the products list — all three show the blue **"Store-wide"** pill.
5. Switch scope back to **Selected products & categories** and save. Reload products list — verify the table matches the "selected_only" column above.

> **Pass criterion:** The column reflects both scope mode and per-product/category flags accurately.

---

## 10. Redirect Mode

The plugin supports two modes: **Popup** (default) and **Full-page redirect**. In redirect mode, instead of opening a popup window, the browser navigates the customer to MitID and back to `/unqverify/callback/`.

1. Go to **WooCommerce → Settings → UNQVerify → General Settings**.
2. Change **Verification Mode** to **Full-page redirect**.
3. Click **Save changes**.
4. Open a private window. Add a gated product to the cart.
5. On the cart page, click the verify button.
6. **Expected result:** Instead of a new popup, the **current tab navigates** away to the MitID test flow (or the UNQVerify redirect page). Complete the test verification.
7. **Expected result:** After verification, the browser lands back on the cart or checkout page with the `unqverify_token` cookie set. Proceed to checkout normally.
8. After this test, go back to settings and switch **Verification Mode** back to **Popup (recommended)**.

> **Pass criterion:** Redirect mode completes a full verification round-trip without a popup.

---

## 11. Plugin Disabled State

1. Go to **WooCommerce → Settings → UNQVerify**.
2. **Turn off** the enable toggle (switch turns grey).
3. Click **Save changes**.
4. **Expected result:** Status strip reads *"Inactive — age verification is disabled"* with a grey dot.
5. Open a private/incognito window. Add any product to the cart.
6. Navigate directly to `/checkout/`.
7. **Expected result:** The checkout page **loads normally**. No redirect, no age gate, no verification banner.
8. Place a test order. **Expected result:** Order placed without any age verification.
9. Re-enable the plugin: go back to settings, turn the toggle on, save.

> **Pass criterion:** Disabling the plugin fully removes all age gate behaviour.

---

## 12. Admin Notice — Missing Key

1. Go to **WooCommerce → Settings → UNQVerify**.
2. **Clear** the Test Public Key field (delete all content).
3. Click **Save changes**.
4. Navigate to any other admin page, e.g. **Dashboard** or **WooCommerce → Orders**.
5. **Expected result:** A yellow **admin notice** appears at the top of the page:  
   *"UNQVerify Age Verification: No public key is configured. Add your key in WooCommerce → Settings → UNQVerify."*  
   The text contains a clickable link.
6. Click the link — **Expected result:** Goes directly to the UNQVerify settings tab.
7. Re-enter your `pk_test_...` key and save.
8. Navigate to Dashboard again.
9. **Expected result:** The admin notice is **gone**.

> **Pass criterion:** Admin notice appears only when the plugin is enabled but no key is configured, and dismisses when a key is saved.

---

## 13. Production Mode

> **Prerequisites:** You must have a `pk_live_...` production key from your account at aldersverificering.dk. If you do not have one, **skip to section 13** and come back when you have an active subscription.
>
> **Warning:** Switching to production mode triggers **real** MitID verification. Only do this on purpose.

1. Go to **WooCommerce → Settings → UNQVerify → Environment & API Keys**.
2. Paste your `pk_live_...` key in the **Production Public Key** field.
3. Click the **Production mode** radio card.
4. Click **Save changes**.
5. **Expected result:** Status strip turns green and reads *"Active — Production mode · ..."* with a green dot.
6. Open a private window, add a product to the cart, go to checkout.
7. **Expected result:** Age verification popup appears. The MitID flow is **real** — use an actual MitID test account (not your real personal MitID) if available.
8. After testing, switch back to **Test mode** and save.

---

## 14. Locale / Language

### 13.1 Force Danish

1. Go to **WooCommerce → Settings → UNQVerify → General Settings**.
2. Set **Popup Language** to **Dansk**.
3. Click **Save changes**.
4. Open a private window, add a gated product to the cart.
5. **Expected result:** All customer-facing text is in Danish:
   - Cart notice: *"Du skal gennemføre aldersverificering, inden du kan gå til kassen."*
   - Verify button: *"Bekræft alder for at fortsætte"*
   - Modal title: *"Aldersverificering påkrævet"*

### 13.2 Force English

1. Set **Popup Language** to **English** and save.
2. Repeat the cart/checkout flow.
3. **Expected result:** All text is in English:
   - Cart notice: *"You must complete age verification before proceeding to checkout."*
   - Verify button: *"Verify age to continue"*
   - Modal title: *"Age verification required"*

### 13.3 Auto-Detect

1. Set **Popup Language** to **Auto-detect**. Save.
2. Go to **Settings → General** in WordPress admin. If the site language is set to **Danish**, the plugin should show Danish strings. If English (or other), it should show English strings.
3. Verify the customer-facing text matches the site language.

---

## 15. Edge Cases

### 15A. Expired JWT Cookie

This test verifies that an expired or tampered JWT cookie is correctly rejected and does not allow checkout.

1. Open a private window. Add a gated product to the cart.
2. Complete age verification (follow section 5B). You now have a valid `unqverify_token` cookie.
3. Open devtools → Application → Cookies. Find `unqverify_token`.
4. **Manually edit** the cookie value — change any one character in the middle.
5. Navigate to `/checkout/`.
6. **Expected result:** You are redirected back to the cart with the notice *"You must complete age verification..."*. The tampered cookie is rejected server-side.

> **Note:** You can also wait for the JWT to expire naturally (the token has a built-in expiry). Test this by checking the jwt.io payload of the real token and waiting until after the `exp` timestamp.

### 15B. Order-Pay Page (Return to Pay)

The `/checkout/order-pay/` endpoint is used when a customer returns to complete a payment for an existing order (e.g. failed card, offline payment). The age gate must NOT block this page.

1. In the WP admin, go to **WooCommerce → Orders**.
2. Find any order that was placed in "Awaiting payment" status (from section 5C).
3. Click the order, then under **Order actions** set it back to **Pending payment** and save.
4. Open the order's payment link: in the order detail look for the "Customer payment page" link and copy it.
5. Open a **private window** with **no** `unqverify_token` cookie.
6. Paste the payment URL (which will be like `/checkout/order-pay/123/?pay_for_order=true&key=wc_order_...`).
7. **Expected result:** The order-pay page **loads normally**. You are NOT redirected to the cart. The age gate does not block returning customers.

### 15C. Thank-You Page

1. After successfully placing an order (from section 5C or 6D), note the order-received URL: `/checkout/order-received/XXXX/?key=...`.
2. Clear the `unqverify_token` cookie from devtools.
3. Navigate back to the order-received URL.
4. **Expected result:** The page loads normally. No redirect to cart.

### 15D. Empty Cart Direct Checkout URL

1. Clear cookies. Make sure the cart is **empty** (in the store front-end, go to `/cart/` and remove all items if any).
2. Navigate to `/checkout/`.
3. **Expected result:** WooCommerce itself redirects you to the cart because it is empty — this is WooCommerce's native behaviour, not the plugin's. The plugin's redirect should not interfere with the empty-cart case. *(If both happen, you will see the cart with a "Your cart is empty" message, which is acceptable.)*

---

## 16. Quick Reference — Pass/Fail Checklist

Use this table as a final sign-off checklist. Mark each test ✅ Pass or ❌ Fail.

| # | Test | Result | Notes |
|---|------|--------|-------|
| 2.2 | UNQVerify tab appears in WooCommerce settings | | |
| 3.1 | Default settings match expected values | | |
| 3.2 | Settings save correctly (status strip updates) | | |
| 4.1 | Client-side error — wrong test key prefix | | |
| 4.2 | Server-side error — wrong test key prefix blocked on save | | |
| 4.3 | Server-side error — wrong production key prefix blocked | | |
| 4.4 | Server-side error — production mode without production key blocked | | |
| 5A | Direct checkout without JWT → redirect to cart with notice | | |
| 5B | Age verification popup opens and completes (test mode) | | |
| 5B | `unqverify_token` cookie set after successful verification | | |
| 5C | Checkout accessible after verification | | |
| 5C | Order placed successfully | | |
| 5D | Order-received page loads without being blocked | | |
| 6A | Category picker appears when "Selected only" scope is chosen | | |
| 6A | Category picker saves ticked categories | | |
| 6B | Category edit screen shows pre-ticked checkbox | | |
| 6B | Ticking category via edit screen appears in settings page | | |
| 6C | Cart with only non-gated products passes checkout freely | | |
| 6D | Cart with a gated category product triggers age gate | | |
| 6D | Mixed cart (gated + non-gated) triggers gate | | |
| 7A | Product meta box visible on product edit screen | | |
| 7A | Marking product via meta box triggers age gate | | |
| 7B | Per-product age override saves and is used as `ageToVerify` | | |
| 7C | `ageToVerify` = max age across multiple gated items | | |
| 8A | Age-restricted variation gates without gating its sibling | | |
| 8B | Parent/category inheritance gates every variation | | |
| 8C | Variation override takes precedence and clears correctly | | |
| 8 | "Age gate" column appears in Products list | | |
| 8 | Column shows "Store-wide" pill when scope = all products | | |
| 8 | Column shows correct pill per product when scope = selected | | |
| 9 | Redirect mode completes verification without popup | | |
| 10 | Disabling plugin removes all age gate behaviour | | |
| 11 | Admin notice appears when enabled but no key set | | |
| 11 | Admin notice disappears when key is saved | | |
| 13.1 | Danish strings shown when locale forced to Danish | | |
| 13.2 | English strings shown when locale forced to English | | |
| 14A | Tampered JWT cookie rejected — gate re-triggered | | |
| 14B | Order-pay page loads without age gate interference | | |
| 14C | Order-received page loads without age gate interference | | |

---

## Appendix: Useful URLs

| Page | URL (replace `unqverify-test.local` with your site) |
|------|------------------------------------------------------|
| WP Admin | `http://unqverify-test.local/wp-admin` |
| WC Settings → UNQVerify | `http://unqverify-test.local/wp-admin/admin.php?page=wc-settings&tab=unq_agev` |
| Cart page | `http://unqverify-test.local/cart/` |
| Checkout | `http://unqverify-test.local/checkout/` |
| Order-received | `http://unqverify-test.local/checkout/order-received/` |
| JWT Callback URL | `http://unqverify-test.local/unqverify/callback/` |
| Products list | `http://unqverify-test.local/wp-admin/edit.php?post_type=product` |
| Product categories | `http://unqverify-test.local/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product` |
| UNQVerify account | `https://www.aldersverificering.dk/account` |
| UNQVerify API keys | `https://www.aldersverificering.dk/account/api-keys` |
| UNQVerify domains | `https://www.aldersverificering.dk/account/domains` |

## Appendix: Cookie Reference

| Cookie name | Set by | Purpose |
|-------------|--------|---------|
| `unqverify_token` | UNQVerify SDK (after successful MitID verification) | JWT proof of age; read server-side by every gating hook |

To clear the token during testing, go to devtools → Application → Cookies → right-click `unqverify_token` → Delete. Alternatively, open a new private/incognito window for each test that needs a fresh state.
