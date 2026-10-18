const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { randomBytes } = require('crypto');

const root = path.resolve(__dirname, '../..');
const fixtureKey = `unq_agev_delivery_test_${randomBytes(8).toString('hex')}`;
function fixtures(action) {
  const output = execFileSync(path.join(root, 'node_modules/.bin/wp-env'), [
    'run', 'cli', '--', 'wp', 'eval-file',
    '/var/www/html/wp-content/plugins/unqverify-woocommerce-plugin/scripts/delivery-test-fixtures.php', action, fixtureKey,
  ], { cwd: root, encoding: 'utf8', timeout: 60000 });
  const match = output.match(/DELIVERY_FIXTURE=(.+)/);
  return match ? JSON.parse(match[1]) : null;
}

let fixture;
test.beforeAll(() => { fixture = fixtures('setup'); });
test.afterAll(() => { fixtures('restore'); });

function address(country) {
  return {
    first_name: 'Delivery', last_name: 'Test', company: '',
    address_1: 'Test Street 1', address_2: '', city: country === 'DK' ? 'Copenhagen' : 'Berlin',
    state: '', postcode: country === 'DK' ? '2100' : '10115', country,
    email: fixture.email, phone: '+4533333333',
  };
}

for (const [billing, shipping, allowed] of [['DE', 'DK', false], ['DK', 'DE', true]]) {
  test(`classic checkout: billing ${billing}, shipping ${shipping}`, async ({ page }) => {
    fixtures('classic');
    await page.goto(`/?add-to-cart=${fixture.product}`);
    await page.goto(`/?page_id=${fixture.classic}`);
    // Access to address entry must not require verification; no layout injection.
    await expect(page.locator('form.checkout')).toBeVisible();
    await expect(page.locator('script[src*="assets/checkout.js"]')).toHaveCount(0);
    await expect(page.locator('#unq-age-checkout-banner')).toHaveCount(0);
    const nonce = await page.locator('#woocommerce-process-checkout-nonce').inputValue();
    const form = {
      'woocommerce-process-checkout-nonce': nonce,
      payment_method: 'bacs', ship_to_different_address: '1',
      'shipping_method[0]': fixture.shipping, terms: '1', 'terms-field': '1',
    };
    for (const [key, value] of Object.entries(address(billing))) form[`billing_${key}`] = value;
    for (const [key, value] of Object.entries(address(shipping))) form[`shipping_${key}`] = value;
    const response = await page.request.post('/?wc-ajax=checkout', { form });
    const result = await response.json();
    expect(result.result, JSON.stringify(result)).toBe(allowed ? 'success' : 'failure');
    if (!allowed) {
      expect(result.messages).toContain('Age verification is required');
      expect(result.messages).toContain('Return to cart');
    }
  });
}

test('Store API: foreign billing and a forged bypass cookie cannot authorize Denmark; changing delivery permits Germany', async ({ page }) => {
  fixtures('blocks');
  const request = page.request;
  await page.context().addCookies([
    { name: 'unqverify_outside_denmark', value: 'yes', url: process.env.WP_BASE_URL || 'http://localhost:8890' },
    { name: 'unqverify_token', value: 'forged-token', url: process.env.WP_BASE_URL || 'http://localhost:8890' },
  ]);
  const cart = await request.get('/wp-json/wc/store/v1/cart');
  const headers = { Nonce: cart.headers().nonce };
  expect(headers.Nonce).toBeTruthy();
  const added = await request.post('/wp-json/wc/store/v1/cart/add-item', { headers, data: { id: fixture.product, quantity: 1 } });
  expect(added.ok(), await added.text()).toBeTruthy();
  const data = {
    billing_address: address('DE'), shipping_address: address('DK'),
    payment_method: 'bacs', payment_data: [], customer_note: '',
  };
  const denied = await request.post('/wp-json/wc/store/v1/checkout', { headers, data });
  expect(denied.status(), await denied.text()).toBe(400);
  expect((await denied.json()).code).toBe('unqverify_failed');
  fixtures('audit-denial');
  data.shipping_address = address('DE');
  const accepted = await request.post('/wp-json/wc/store/v1/checkout', { headers, data });
  expect(accepted.ok(), await accepted.text()).toBeTruthy();
  expect((await accepted.json()).payment_result.payment_status).toBe('success');
});

test('Blocks checkout loads without our checkout script or banner', async ({ page }) => {
  fixtures('blocks');
  await page.goto(`/?add-to-cart=${fixture.product}`);
  await page.goto(`/?page_id=${fixture.blocks}`);
  await expect(page.locator('[data-block-name="woocommerce/checkout"]')).toBeVisible();
  await expect(page.locator('.wc-block-components-checkout-place-order-button')).toBeVisible();
  await expect(page.locator('script[src*="assets/checkout.js"]')).toHaveCount(0);
  await expect(page.locator('#unq-age-checkout-banner')).toHaveCount(0);
});
