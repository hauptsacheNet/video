import { defineConfig } from '@playwright/test';
import config from './tests/playwright/config';

export default defineConfig({
  testDir: './tests/playwright',
  // Video conversion via ffmpeg.wasm is slow in headless Chromium — give it room.
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
  outputDir: './test-results',

  use: {
    baseURL: config.baseUrl,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // ffmpeg.wasm needs SharedArrayBuffer, which requires a "secure" context.
    // Trust the configured base origin so http://web:8080 / http://127.0.0.1:8080
    // count as secure for this test run.
    launchOptions: {
      args: [
        `--unsafely-treat-insecure-origin-as-secure=${config.baseUrl}`,
        '--enable-features=SharedArrayBuffer',
        '--js-flags=--experimental-wasm-threads',
      ],
    },
  },

  projects: [
    {
      name: 'login setup',
      testMatch: /helper\/login\.setup\.ts/,
    },
    {
      name: 'e2e',
      testMatch: /e2e\/.*\.spec\.ts/,
      dependencies: ['login setup'],
      use: {
        storageState: './tests/playwright/.auth/login.json',
      },
    },
  ],
});
