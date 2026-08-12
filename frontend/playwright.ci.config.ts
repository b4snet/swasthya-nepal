import { defineConfig, devices } from '@playwright/test';

/**
 * Swasthya CI E2E — desktop + mobile OPD workflow + accessibility scan
 * against a REAL backend and DISPOSABLE PostgreSQL inside GitHub Actions
 * (STAGING_DEPLOYMENT_REPORT §CI/CD, TESTING_STRATEGY.md §12).
 *
 * Unlike the local/staging configs, this config starts the Laravel backend
 * itself (artisan serve on the CI port) as part of webServer, so a single
 * `npx playwright test --config=playwright.ci.config.ts` proves the full
 * release chain: migrations → fixture seed → backend → SPA → workflow.
 *
 * Prerequisites handled by the CI job BEFORE Playwright runs:
 *  1. Disposable postgres:16 container (POSTGRES_DB=swasthya_ci)
 *  2. roles.sql (swasthya_app NOBYPASSRLS) + migrate:fresh + seed
 *     StagingFixtureSeeder, all as the migration owner role.
 *
 * Env (set by the job):
 *  SWASTHYA_CI_API_PORT — backend port (default 58999)
 *  SWASTHYA_E2E_BASE_URL — SPA origin, must match the Vite port below
 */
const API_PORT = Number(process.env.SWASTHYA_CI_API_PORT ?? 58999);
const API_ORIGIN = `http://127.0.0.1:${API_PORT}`;
const HOST = '127.0.0.1';
const PORT = Number(process.env.SWASTHYA_CI_SPA_PORT ?? 5175);
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
  webServer: [
    // 1. Laravel backend as the least-privilege role against the CI database.
    {
      command: `php artisan serve --host=127.0.0.1 --port=${API_PORT}`,
      url: `${API_ORIGIN}/api/v1/health/ready`,
      cwd: '../backend',
      // Run the APP as the least-privilege role (RLS enforced), never the
      // schema owner — the same posture as staging (TENANCY.md V2 §6).
      env: {
        PATH: process.env.PATH ?? '',
        APP_ENV: 'ci',
        // The disposable Postgres service container is exposed on 54329; the
        // pgsql config defaults to 5432, so host/port must be explicit (the
        // CI job has no .env for Laravel to read).
        DB_HOST: '127.0.0.1',
        DB_PORT: '54329',
        DB_DATABASE: 'swasthya_ci',
        DB_USERNAME: 'swasthya_app',
        DB_PASSWORD: process.env.CI_APP_ROLE_PASSWORD ?? 'ci-app-role-password-2026',
        RLS_DB_DATABASE: 'swasthya_ci',
        RLS_DB_USERNAME: 'swasthya_app',
        RLS_DB_PASSWORD: process.env.CI_APP_ROLE_PASSWORD ?? 'ci-app-role-password-2026',
        SWASTHYA_RATE_LIMIT_AUTH: '200',
      },
      reuseExistingServer: false,
      timeout: 60_000,
    },
    // 2. Vite SPA proxying /api to the backend.
    {
      command: `npm run dev -- --host ${HOST} --port ${PORT} --strictPort`,
      url: ORIGIN,
      env: {
        SWASTHYA_API_TARGET: API_ORIGIN,
        SWASTHYA_E2E_BASE_URL: ORIGIN,
      },
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
  projects: [
    { name: 'ci-desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } }, testMatch: /opd-workflow\.spec\.ts/ },
    { name: 'ci-mobile', use: { ...devices['iPhone 13'] }, testMatch: /opd-workflow-mobile\.spec\.ts/ },
    { name: 'ci-a11y', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } }, testMatch: /accessibility\.spec\.ts/ },
  ],
});
