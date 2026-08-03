const { test, expect } = require("@playwright/test");

async function logIn(page) {
  await page.goto("/wp-login.php");
  const loginUrl = new URL("/wp-login.php", page.url()).toString();
  const adminUrl = new URL("/wp-admin/", page.url()).toString();
  const response = await page.context().request.post(loginUrl, {
    form: {
      log: "admin",
      pwd: "admin",
      "wp-submit": "Log In",
      redirect_to: adminUrl,
      testcookie: "1",
    },
  });

  expect(response.ok()).toBe(true);
  await page.goto(adminUrl);
  await expect(page).toHaveURL(/\/wp-admin\//);
}

async function variationRow(page, variationId) {
  return page.locator(".woocommerce_variation").filter({
    has: page.locator(`input.variable_post_id[value="${variationId}"]`),
  });
}

test("variation age-verification controls render and persist in wp-admin", async ({
  page,
}) => {
  await logIn(page);
  await page.goto("/wp-admin/post.php?post=19&action=edit");

  const parentNotice = page.locator(".unq-mb-variation-notice");
  await expect(parentNotice).toContainText("Variation rules active");
  await expect(parentNotice).toContainText(
    "2 variations have direct age-verification rules",
  );

  const variationsTab = page.locator(
    '.product_data_tabs a[href="#variable_product_options"]',
  );
  await variationsTab.scrollIntoViewIfNeeded();
  await variationsTab.click();
  await expect(
    page.locator('input.variable_post_id[value="23"]'),
  ).toBeAttached();

  const restrictedRow = await variationRow(page, 23);
  await restrictedRow.locator(".edit_variation").click();
  const restrictedToggle = restrictedRow.locator(
    ".unq-agev-variation-required",
  );
  await expect(restrictedToggle).toBeVisible();
  await expect(restrictedToggle).toBeChecked();

  const unrestrictedRow = await variationRow(page, 22);
  await unrestrictedRow.locator(".edit_variation").click();
  const unrestrictedToggle = unrestrictedRow.locator(
    ".unq-agev-variation-required",
  );
  await expect(unrestrictedToggle).toBeVisible();
  await expect(unrestrictedToggle).not.toBeChecked();

  await unrestrictedToggle.check();
  const saveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      response.url().includes("/wp-admin/post.php"),
  );
  await page.locator("#publish").click();
  await saveResponse;
  await expect(page.locator("div.notice-success#message")).toContainText(
    /Product (updated|published)/,
  );

  const persistedVariationsTab = page.locator(
    '.product_data_tabs a[href="#variable_product_options"]',
  );
  await persistedVariationsTab.scrollIntoViewIfNeeded();
  await persistedVariationsTab.click();
  await expect(
    page.locator('input.variable_post_id[value="22"]'),
  ).toBeAttached();
  const persistedRow = await variationRow(page, 22);
  await persistedRow.locator(".edit_variation").click();
  await expect(
    persistedRow.locator(".unq-agev-variation-required"),
  ).toBeChecked();
});
