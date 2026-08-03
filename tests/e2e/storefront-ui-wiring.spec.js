const fs = require("fs");
const path = require("path");
const { test, expect } = require("@playwright/test");

const pluginRoot = path.resolve(__dirname, "../..");
const mitIdLogo = `data:image/png;base64,${fs
  .readFileSync(path.join(pluginRoot, "assets/mitid-logo-white.png"))
  .toString("base64")}`;

const i18n = {
  verifyPrompt: "Verify age to continue",
  verified: "Age verified",
  denied: "You do not meet the age requirement for these products.",
  cancelled: "Age verification cancelled.",
  popupBlocked: "Allow popups on this site to verify your age.",
  error: "An error occurred. Please try again.",
  modalTitle: "Age verification required",
  modalBody:
    "This store sells age-restricted products. You must confirm that you are 18 years or older to proceed to checkout. This is done securely via MitID and only takes a moment.",
  popupNotice: "MitID verification opens in a new window.",
  verificationStarting: "Opening age verification…",
  testModeLabel: "Test mode",
  testModeDescription:
    "Test mode is active. No real MitID verification is performed.",
  modalVerifyBtn: "Confirm with MitID",
  modalCancelBtn: "Cancel",
};

async function installSharedUi(page) {
  await page.addStyleTag({
    path: path.join(pluginRoot, "assets/frontend.css"),
  });
  await page.addScriptTag({
    path: path.join(pluginRoot, "assets/verification-ui.js"),
  });
}

async function installRuntimeStubs(page) {
  await page.evaluate(() => {
    window.jQuery = function () {
      return { on() {} };
    };
    window.open = function () {
      return { closed: false };
    };
    window.UnqVerify = {
      callbacks: null,
      isVerified() {
        return false;
      },
      init(callbacks) {
        this.callbacks = callbacks;
      },
      startVerificationWithPopup() {
        this.callbacks.onDenied();
      },
      startVerificationWithRedirect() {
        window.__redirectStarted = true;
      },
    };
  });
}

function cartConfig() {
  return {
    sdkUrl: "https://invalid.test/sdk.js",
    sdkIntegrity: "",
    publicKey: "pk_test_browser",
    ageToVerify: 18,
    redirectUri: "https://shop.test/unqverify/callback/",
    mitIdLogoUrl: mitIdLogo,
    checkoutUrl: "https://shop.test/checkout/",
    mode: "popup",
    testMode: true,
    i18n,
  };
}

test("classic cart opens the shared modal and reports a denied outcome", async ({
  page,
}) => {
  await page.setContent(`
    <!doctype html><html lang="en"><head><title>Classic cart</title></head>
    <body><div class="wc-proceed-to-checkout"><a class="checkout-button" href="/checkout/">Proceed to checkout</a></div></body></html>
  `);
  await installSharedUi(page);
  await installRuntimeStubs(page);
  await page.evaluate((config) => {
    window.UNQCart = config;
  }, cartConfig());
  await page.addScriptTag({ path: path.join(pluginRoot, "assets/cart.js") });

  const checkoutLink = page.getByRole("link", { name: "Proceed to checkout" });
  await expect(checkoutLink).toHaveAttribute("aria-haspopup", "dialog");
  await expect(checkoutLink).toHaveAttribute("aria-controls", "unq-age-modal");
  await checkoutLink.click();
  await expect(
    page.getByRole("dialog", { name: "Age verification required" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Confirm with MitID" }).click();
  await expect(
    page.getByRole("alert").filter({
      hasText: "You do not meet the age requirement",
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Confirm with MitID" }),
  ).toBeFocused();
});

test("block cart binds when WooCommerce renders its checkout link later", async ({
  page,
}) => {
  await page.setContent(
    '<!doctype html><html lang="en"><head><title>Block cart</title></head><body><main id="cart"></main></body></html>',
  );
  await installSharedUi(page);
  await installRuntimeStubs(page);
  await page.evaluate((config) => {
    window.UNQCart = config;
  }, cartConfig());
  await page.addScriptTag({ path: path.join(pluginRoot, "assets/cart.js") });
  await page.evaluate(() => {
    const link = document.createElement("a");
    link.className = "wc-block-cart__submit-button";
    link.href = "/checkout/";
    link.textContent = "Proceed to checkout";
    document.getElementById("cart").appendChild(link);
  });

  await page.getByRole("link", { name: "Proceed to checkout" }).click();
  await expect(
    page.getByRole("dialog", { name: "Age verification required" }),
  ).toBeVisible();
});

test("checkout banner opens the same shared modal", async ({ page }) => {
  await page.setContent(`
    <!doctype html><html lang="en"><head><title>Checkout</title></head>
    <body><form class="checkout"><button type="submit">Place order</button></form></body></html>
  `);
  await installSharedUi(page);
  await installRuntimeStubs(page);
  await page.evaluate(({ strings, logoSource }) => {
    window.UNQCheckout = {
      sdkUrl: "https://invalid.test/sdk.js",
      sdkIntegrity: "",
      publicKey: "pk_test_browser",
      ageToVerify: 18,
      redirectUri: "https://shop.test/unqverify/callback/",
      mitIdLogoUrl: logoSource,
      mode: "popup",
      testMode: true,
      i18n: strings,
    };
  }, { strings: i18n, logoSource: mitIdLogo });
  await page.addScriptTag({ path: path.join(pluginRoot, "assets/checkout.js") });

  const banner = page.locator("#unq-age-checkout-banner");
  await expect(banner).toHaveAttribute("role", "status");
  const verifyPrompt = banner.getByRole("button", {
    name: "Verify age to continue",
  });
  await expect(verifyPrompt).toHaveAttribute("aria-haspopup", "dialog");
  await expect(verifyPrompt).toHaveAttribute("aria-controls", "unq-age-modal");
  await verifyPrompt.click();
  await expect(
    page.getByRole("dialog", { name: "Age verification required" }),
  ).toBeVisible();
});
