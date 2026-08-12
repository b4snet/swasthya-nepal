import { defineConfig, devices } from '@playwright/test';

/**
 * Staging E2E — the SAME desktop + mobile specs run against the STAGING
 * backend (port 58998, swasthya_staging DB, RLS active) instead of the dev
 * backend, proving the release candidate end-to-end (STAGING.md §11, §13).
 *
 * The SPA talks same-origin; Vite proxies /api to SWASTHYA_API_TARGET. The
 * staging run uses its own Vite instance on port 5174 so it never collides
 * with a running dev preview on 5173. Prerequisites:
 *  1. Staging backend running: backend/ with APP_ENV=staging
 *     (php artisan serve --host=127.0.0.1 --port=58998) — .env.staging
 *     connects as the least-privilege staging role with RLS active.
 *  2. Staging DB provisioned: migrate + StagingFixtureSeeder (tenants
 *     smoke-group + apex-care).
 *  3. Run: npx playwright test --config=playwright.staging.config.ts
 */
const STAGING_API = process.env.SWASTHYA_STAGING_API_TARGET ?? 'http://127.0.0.1:58998';
// Bind Vite explicitly to IPv4: `localhost` resolves to ::1 on Windows while
// the API helper and curl target 127.0.0.1 — a silent binding mismatch that
// surfaces as ECONNREFUSED / empty responses in the E2E helper.
const HOST = '127.0.0.1';
const PORT = 5174;
const ORIGIN = `http://${HOST}:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  fullyParallel: false,
  use: {
    baseURL: ORIGIN,
    trace: 'retain-on-failure',
  },
  webServer: {
    command: `npm run dev -- --host ${HOST} --port ${PORT} --strictPort`,
    url: ORIGIN,
    env: { SWASTHYA_API_TARGET: STAGING_API, SWASTHYA_E2E_BASE_URL: ORIGIN },
    reuseExistingServer: false,
    timeout: 60_000,
  },
  projects: [
    {
      name: 'desktop-staging',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
      testMatch: /opd-workflow\.spec\.ts/,
    },
    {
      name: 'mobile-staging',
      use: { ...devices['iPhone 13'] },
      testMatch: /opd-workflow-mobile\.spec\.ts/,
    },
    {
      name: 'a11y-staging',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
      testMatch: /accessibility\.spec\.ts/,
    },
  ],
});
