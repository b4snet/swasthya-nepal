import type { APIRequestContext, Page } from '@playwright/test';
import { expect } from '@playwright/test';

export const ADMIN_EMAIL = 'smoke.hadmin@two.test';
export const DOCTOR_EMAIL = 'smoke.doctor@two.test';
export const PASSWORD = 'SmokePass-2026!';

export const API_BASE = 'http://localhost:5173/api/v1';

/**
 * E2E setup hygiene against the REAL backend: a previous (possibly failed)
 * run leaves its appointment in `booked` status, and the partial unique index
 * allows only one holding appointment per (tenant, provider, starts_at) — so
 * the same slot can never be booked again until it is cancelled. This helper
 * logs in as the fixture admin and cancels leftover booked appointments for
 * the target date, making the workflow spec repeatable. It exercises the real
 * cancel endpoint; it does not touch data outside the fixture facility.
 */
export async function cancelLeftoverBookings(request: APIRequestContext, date: string): Promise<number> {
  const login = await request.post(`${API_BASE}/auth/login`, { data: { email: ADMIN_EMAIL, password: PASSWORD } });
  const session = (await login.json()).data as {
    accessToken: string;
    assignments?: Array<{ facilityId?: string }>;
  };
  const token = session.accessToken;
  const facilityId = session.assignments?.[0]?.facilityId;
  const headers = { Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': facilityId ?? '' };

  const list = await request.get(`${API_BASE}/appointments?date=${date}`, { headers });
  const appointments = ((await list.json()).data ?? []) as Array<{ id: string; status: string }>;
  // Holding states only — a failed run can leave booked/checked_in/
  // in_consultation rows behind, all of which occupy the unique slot.
  const HOLDING = new Set(['booked', 'checked_in', 'in_consultation']);
  let cancelled = 0;
  for (const appt of appointments) {
    if (!HOLDING.has(appt.status)) continue;
    const res = await request.post(`${API_BASE}/appointments/${appt.id}/cancel`, { headers, data: { reason: 'E2E setup: clear leftover appointment from a previous run' } });
    if (res.ok()) cancelled += 1;
  }
  return cancelled;
}

/** Next Tuesday (UTC-safe): the fixture schedule template is Tue 09:00–11:00. */
export function nextTuesday(): string {
  const now = new Date();
  const day = now.getUTCDay();
  const diff = (2 - day + 7) % 7 || 7; // next Tuesday (not today even if today is Tue)
  const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + diff));
  return d.toISOString().slice(0, 10);
}

export async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  // The dashboard header appears once authenticated. Exact match: the page
  // also has a card titled "Today's appointments".
  await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible({ timeout: 20_000 });
}

export async function expectDashboardLoaded(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible({ timeout: 20_000 });
}
