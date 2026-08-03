const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost:8890',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});