const fs = require("fs");
const path = require("path");
const AxeBuilder = require("@axe-core/playwright").default;
const { test, expect } = require("@playwright/test");

const pluginRoot = path.resolve(__dirname, "../..");
const uiScript = path.join(pluginRoot, "assets/verification-ui.js");
const uiStyles = path.join(pluginRoot, "assets/frontend.css");
const mitIdLogo = `data:image/png;base64,${fs
  .readFileSync(path.join(pluginRoot, "assets/mitid-logo-white.png"))
  .toString("base64")}`;

const strings = {
  en: {
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
  },
  da: {
    error: "Der opstod en fejl. Prøv igen.",
    modalTitle: "Aldersverificering påkrævet",
    modalBody:
      "Denne butik sælger aldersbegrænsede varer. Du skal bekræfte, at du er 18 år eller ældre, for at gå til kassen. Det sker sikkert via MitID og tager kun et øjeblik.",
    popupNotice: "MitID-verificeringen åbner i et nyt vindue.",
    verificationStarting: "Åbner aldersverificering…",
    testModeLabel: "Testtilstand",
    testModeDescription:
      "Testtilstand er aktiv. Der gennemføres ikke en rigtig MitID-verificering.",
    modalVerifyBtn: "Bekræft med MitID",
    modalCancelBtn: "Annuller",
  },
};

async function mountModal(page, options = {}) {
  const locale = options.locale || "en";
  const mode = options.mode || "popup";
  const testMode = options.testMode !== false;

  await page.setContent(`
    <!doctype html>
    <html lang="${locale}">
      <head><title>UNQ age verification UI test</title></head>
      <body>
        <button id="open-modal" type="button">Open verification</button>
        <main id="page-content"><a href="#next">Next control</a></main>
      </body>
    </html>
  `);
  await page.addStyleTag({ path: uiStyles });
  await page.addScriptTag({ path: uiScript });
  await page.evaluate(
    ({ i18n, logoSource, modeValue, isTestMode }) => {
      const opener = document.getElementById("open-modal");
      window.__verificationCalls = 0;
      window.__verificationModal = window.UNQAgeVerificationUI.createModal({
        i18n,
        logoUrl: logoSource,
        mode: modeValue,
        testMode: isTestMode,
        onVerify() {
          window.__verificationCalls += 1;
        },
      });
      opener.addEventListener("click", () => {
        window.__verificationModal.show(opener);
      });
    },
    {
      i18n: strings[locale],
      logoSource: mitIdLogo,
      modeValue: mode,
      isTestMode: testMode,
    },
  );
}

test("popup modal follows the accessible dialog and focus contract", async ({
  page,
}) => {
  await mountModal(page);
  const opener = page.getByRole("button", { name: "Open verification" });
  await opener.click();

  const dialog = page.getByRole("dialog", {
    name: "Age verification required",
  });
  const verifyButton = page.getByRole("button", {
    name: "Confirm with MitID",
  });
  const cancelButton = page.getByRole("button", { name: "Cancel" });

  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute("aria-modal", "true");
  await expect(dialog).toHaveAttribute(
    "aria-describedby",
    "unq-age-modal-body unq-age-modal-popup-notice",
  );
  await expect(
    page.getByText("MitID verification opens in a new window."),
  ).toBeVisible();
  await expect(verifyButton).toBeFocused();
  await expect(page.locator("#page-content")).toHaveAttribute("inert", "");
  const logo = verifyButton.locator("img.unq-agev-mitid-button__logo");
  await expect(logo).toHaveCount(1);
  await expect(logo).toHaveAttribute("alt", "");
  await expect(logo).toHaveAttribute("aria-hidden", "true");
  await expect(logo).toHaveJSProperty("naturalWidth", 732);
  await expect(logo).toHaveJSProperty("naturalHeight", 198);
  const logoBox = await logo.boundingBox();
  expect(logoBox.width).toBeGreaterThanOrEqual(57);
  await expect(verifyButton).toContainText("Confirm with MitID");
  await expect(verifyButton).toHaveCSS("background-color", "rgb(0, 96, 230)");
  await expect(verifyButton).toHaveCSS("border-radius", "4px");
  await expect(verifyButton).toHaveCSS(
    "font-family",
    /IBM Plex Sans/,
  );
  await expect(verifyButton).toHaveCSS("font-weight", "600");

  await page.keyboard.press("Tab");
  await expect(cancelButton).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(verifyButton).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(cancelButton).toBeFocused();

  const verifyBox = await verifyButton.boundingBox();
  const cancelBox = await cancelButton.boundingBox();
  expect(verifyBox.height).toBeGreaterThanOrEqual(44);
  expect(cancelBox.height).toBeGreaterThanOrEqual(44);

  const accessibilityScan = await new AxeBuilder({ page })
    .include("#unq-age-modal")
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(accessibilityScan.violations).toEqual([]);

  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(opener).toBeFocused();
  await expect(page.locator("#page-content")).not.toHaveAttribute("inert", "");
});

test("redirect mode omits the popup notice and supports Danish copy", async ({
  page,
}) => {
  await mountModal(page, { locale: "da", mode: "redirect", testMode: false });
  await page.getByRole("button", { name: "Open verification" }).click();

  const dialog = page.getByRole("dialog", {
    name: "Aldersverificering påkrævet",
  });
  await expect(dialog).toHaveAttribute("aria-describedby", "unq-age-modal-body");
  await expect(
    page.getByText("MitID-verificeringen åbner i et nyt vindue."),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Bekræft med MitID" }),
  ).toBeVisible();
  await expect(page.getByText("Testtilstand")).toHaveCount(0);
});

test("busy and error states are announced and allow retry", async ({ page }) => {
  await mountModal(page);
  await page.getByRole("button", { name: "Open verification" }).click();
  const verifyButton = page.getByRole("button", {
    name: "Confirm with MitID",
  });
  await verifyButton.click();

  await expect(verifyButton).toHaveAttribute("aria-busy", "true");
  await expect(verifyButton).toHaveAttribute("aria-disabled", "true");
  await expect(verifyButton).toContainText("Confirm with MitID");
  await expect(
    verifyButton.locator("img.unq-agev-mitid-button__logo"),
  ).toHaveCount(1);
  await expect(
    page.getByRole("status").filter({ hasText: "Opening age verification…" }),
  ).toBeAttached();
  expect(await page.evaluate(() => window.__verificationCalls)).toBe(1);

  await page.evaluate(() => {
    window.__verificationModal.setStatus(
      "Allow popups on this site to verify your age.",
      "error",
    );
  });

  await expect(
    page.getByRole("alert").filter({
      hasText: "Allow popups on this site to verify your age.",
    }),
  ).toBeVisible();
  await expect(verifyButton).toBeFocused();
  await expect(verifyButton).toHaveAttribute("aria-busy", "false");
  await expect(verifyButton).toHaveAttribute("aria-disabled", "false");
});

test("modal remains usable at a 320 CSS pixel viewport", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await mountModal(page);
  await page.getByRole("button", { name: "Open verification" }).click();

  const dialog = page.getByRole("dialog", {
    name: "Age verification required",
  });
  const box = await dialog.boundingBox();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(320);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await expect(
    page.getByRole("button", { name: "Confirm with MitID" }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Cancel" })).toBeVisible();
});

test("Danish official CTA stays on one line at 320 CSS pixels", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await mountModal(page, { locale: "da" });
  await page.getByRole("button", { name: "Open verification" }).click();

  const button = page.getByRole("button", { name: "Bekræft med MitID" });
  const label = button.locator(".unq-agev-mitid-button__label");
  await expect(button).toBeVisible();
  expect(
    await label.evaluate(
      (element) => element.scrollWidth <= element.clientWidth,
    ),
  ).toBe(true);
  expect((await label.boundingBox()).height).toBeLessThan(30);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});
