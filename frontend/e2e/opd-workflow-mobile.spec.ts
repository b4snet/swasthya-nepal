import { test, expect } from '@playwright/test';
import { ADMIN_EMAIL, PASSWORD, cancelLeftoverBookings, login, nextTuesday } from './helpers';

/**
 * Mobile (iPhone 13 viewport) E2E of the receptionist flow:
 * login → book → check-in → queue, entirely via the bottom navigation,
 * with no horizontal overflow. Real backend, real database.
 */
test.describe('receptionist flow (mobile)', () => {
  test.beforeAll(async ({ request }) => {
    await cancelLeftoverBookings(request, nextTuesday());
  });

  test('works at mobile viewport with bottom navigation', async ({ page }) => {
  const date = nextTuesday();
  const stamp = Date.now();
  const patientName = `E2E Mobile ${stamp}`;

  // Login (mobile layout: single column, bottom nav)
  await login(page, ADMIN_EMAIL, PASSWORD);
  await expect(page.locator('.bottom-nav')).toBeVisible();

  // No horizontal overflow at any point
  const checkOverflow = async () => {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  };
  await checkOverflow();

  // Register a patient via the bottom-nav Patients destination
  await page.locator('.bottom-nav__item', { hasText: 'Patients' }).click();
  await page.getByRole('button', { name: 'Register patient' }).click();
  await page.getByLabel('Full name').fill(patientName);
  await page.getByLabel('Date of birth').fill('1993-03-20');
  await page.getByLabel('Sex').selectOption('male');
  await page.getByRole('button', { name: 'Register patient' }).click();
  await expect(page.getByRole('heading', { name: patientName })).toBeVisible({ timeout: 20_000 });
  await checkOverflow();

  // Book an appointment
  await page.locator('.bottom-nav__item', { hasText: 'Appointments' }).click();
  await page.getByLabel('Appointment date').fill(date);
  await page.getByRole('button', { name: 'Book appointment' }).click();
  // Full-name search: a prefix would match E2E patients from previous runs.
  await page.getByPlaceholder('Search name or MRN').fill(patientName);
  await page.locator('.pick-list__item').first().click();
  await page.getByLabel('Provider').selectOption({ index: 1 });
  // A real service: the invoice derives the consultation charge from it.
  await page.getByLabel('Service').selectOption({ label: 'OPD Consultation' });
  await page.getByLabel('Consultation date').fill(date);
  // Wait for availability for THIS date so a stale slot can never be clicked.
  await page.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`date=${date}`));
  await expect(page.locator('.slots .slot').nth(1)).toBeVisible({ timeout: 20_000 });
  // Book the SECOND slot: the desktop spec books slot 1, and the backend's
  // double-booking guard allows one holding appointment per start time.
  await page.locator('.slots .slot').nth(1).click();
  await page.getByRole('button', { name: 'Confirm booking' }).click();
  await expect(page.getByRole('heading', { name: 'Queue', exact: true })).toBeVisible({ timeout: 20_000 });
  await checkOverflow();

  // Check in from the phone
  await page.getByLabel('Queue date').fill(date);
  const item = page.locator('.checkin-list__item').filter({ hasText: patientName });
  await expect(item).toBeVisible({ timeout: 20_000 });
  await item.getByRole('button', { name: 'Check in' }).click();
  await expect(page.getByText(/Checked in — token #/)).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('.queue-card').filter({ hasText: patientName })).toContainText('#');
  await checkOverflow();
  });
});
