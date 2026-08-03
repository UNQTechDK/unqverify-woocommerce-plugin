const { test, expect } = require("@playwright/test");

test("gated WooCommerce cart enqueues and opens the accessible modal", async ({
  page,
}) => {
  await page.addInitScript(() => {
    window.UnqVerify = {
      isVerified() {
        return false;
      },
      init() {},
      startVerificationWithPopup() {},
      startVerificationWithRedirect() {},
    };
  });

  // Product #16 is the deterministic ordinary-product fixture created by
  // scripts/seed-products.php. All-products targeting makes the cart gated.
  await page.goto("/?add-to-cart=16");
  await page.goto("/cart/");

  await expect(
    page.locator('link[href*="assets/frontend.css"]'),
  ).toBeAttached();
  await expect(
    page.locator('script[src*="assets/verification-ui.js"]'),
  ).toBeAttached();

  const checkoutLink = page
    .locator(
      "a.checkout-button, a.wc-block-cart__submit-button, .wc-proceed-to-checkout a, .wc-block-cart__submit-container a",
    )
    .first();
  await expect(checkoutLink).toBeVisible();
  await checkoutLink.click();

  const dialog = page.getByRole("dialog", {
    name: "Age verification required",
  });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.getByText("MitID verification opens in a new window."),
  ).toBeVisible();
  const mitIdButton = dialog.getByRole("button", {
    name: "Confirm with MitID",
  });
  await expect(mitIdButton).toBeFocused();
  await expect(
    mitIdButton.locator('img[src*="assets/mitid-logo-white.png"]'),
  ).toHaveCount(1);
  await expect(mitIdButton).toHaveCSS("background-color", "rgb(0, 96, 230)");
  await expect
    .poll(() =>
      page.evaluate(() =>
        document.fonts.check('600 16px "IBM Plex Sans"'),
      ),
    )
    .toBe(true);
});
