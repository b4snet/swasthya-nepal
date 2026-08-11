import { defineConfig, devices } from '@playwright/test';

/**
 * E2E against the REAL backend and REAL database (no mocks).
 *
 * Prerequisites (documented in FRONTEND_FOUNDATION_REPORT.md §20):
 *  1. Backend running as swasthya_app: in backend/ run
 *       DB_USERNAME=swasthya_app DB_PASSWORD=<app role pw> \
 *       ../.toolchain/php/php.exe artisan serve --host=127.0.0.1 --port=58999
 *  2. Test identities in the dev DB (smoke fixture org): smoke.hadmin@two.test /
 *     smoke.doctor@two.test with the shared test password.
 *  3. The fixture schedule template exists for the doctor (Tuesday 09:00–11:00).
 *
 * The frontend dev server (vite, port 5173) is started by Playwright.
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  retries: 0,
  // Both projects book the same provider/date (next Tuesday) and the backend
  // double-booking guard is a partial unique index — running the projects in
  // parallel would make one of them lose the race. Serialize deliberately.
  workers: 1,
  fullyParallel: false,
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev -- --port 5173 --strictPort',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
    timeout: 60_000,
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
      testMatch: /opd-workflow\.spec\.ts/,
    },
    {
      name: 'mobile',
      use: { ...devices['iPhone 13'] },
      testMatch: /opd-workflow-mobile\.spec\.ts/,
    },
  ],
});
