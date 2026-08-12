import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN_EMAIL, DOCTOR_EMAIL, PASSWORD, login } from './helpers';

/**
 * Accessibility scan (DESIGN_SYSTEM.md §30, STAGING_DEPLOYMENT_REPORT §21).
 *
 * Runs axe against the primary OPD screens as the fixture admin (and the
 * doctor workspace as the doctor). Asserts zero serious or critical
 * violations — minor/moderate findings are surfaced but do not fail, so the
 * gate targets genuinely blocking accessibility defects only.
 *
 * The spec is env-driven (SWASTHYA_E2E_BASE_URL) so it runs against both the
 * dev preview (default) and the staging stack through the staging config.
 */
const SCREENS: Array<{ name: string; path: string }> = [
  { name: 'dashboard', path: '/' },
  { name: 'patients', path: '/patients' },
  { name: 'appointments', path: '/appointments' },
  { name: 'queue', path: '/queue' },
  { name: 'billing', path: '/billing' },
];

test.describe('accessibility (axe)', () => {
  test('admin screens have no serious or critical violations', async ({ page }) => {
    await login(page, ADMIN_EMAIL, PASSWORD);
    for (const screen of SCREENS) {
      await page.goto(screen.path);
      // Wait for the route's content (h1) before scanning.
      await page.waitForSelector('main h1', { timeout: 20_000 });
      const results = await new AxeBuilder({ page }).analyze();
      const blocking = results.violations.filter((v) =>
        v.impact === 'serious' || v.impact === 'critical',
      );
      const summary = blocking.map((v) => `${v.id}(${v.nodes.length})`).join(', ');
      expect(
        blocking,
        `${screen.name}: serious/critical violations [${summary}]`,
      ).toHaveLength(0);
    }
  });

  test('doctor workspace has no serious or critical violations', async ({ page }) => {
    await login(page, DOCTOR_EMAIL, PASSWORD);
    await page.goto('/queue');
    await page.waitForSelector('main h1', { timeout: 20_000 });
    const results = await new AxeBuilder({ page }).analyze();
    const blocking = results.violations.filter((v) =>
      v.impact === 'serious' || v.impact === 'critical',
    );
    const summary = blocking.map((v) => `${v.id}(${v.nodes.length})`).join(', ');
    expect(
      blocking,
      `doctor queue: serious/critical violations [${summary}]`,
    ).toHaveLength(0);
  });
});
