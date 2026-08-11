import { test, expect } from '@playwright/test';
import { ADMIN_EMAIL, DOCTOR_EMAIL, PASSWORD, cancelLeftoverBookings, login, nextTuesday } from './helpers';

/**
 * The primary E2E: the complete OPD workflow against the REAL backend and
 * REAL database (RLS active, swasthya_app role). No mocks.
 */
test.describe('OPD workflow (real backend)', () => {
  // Repeatable against a persistent dev DB: cancel the previous run's holding
  // appointment for the target date before this run books its own.
  test.beforeAll(async ({ request }) => {
    await cancelLeftoverBookings(request, nextTuesday());
  });

  test('patient → appointment → check-in → queue → encounter → diagnosis → prescription → billing → payment → audit', async ({ browser }) => {
    const admin = await browser.newContext();
    const doctor = await browser.newContext();
    const adminPage = await admin.newPage();
    const doctorPage = await doctor.newPage();
    const date = nextTuesday();
    const stamp = Date.now();
    const patientName = `E2E Patient ${stamp}`;

    // -- 1. admin login
    await login(adminPage, ADMIN_EMAIL, PASSWORD);

    // -- 2. register a patient
    await adminPage.goto('/patients');
    await adminPage.getByRole('button', { name: 'Register patient' }).first().click();
    await adminPage.getByLabel('Full name').fill(patientName);
    await adminPage.getByLabel('Date of birth').fill('1992-06-15');
    await adminPage.getByLabel('Sex').selectOption('female');
    await adminPage.getByLabel('Blood group').fill('O+');
    await adminPage.getByLabel('Phone', { exact: true }).fill('+9779800001234');
    await adminPage.getByRole('button', { name: 'Register patient' }).click();
    // Profile page shows the MRN
    await expect(adminPage.getByRole('heading', { name: patientName })).toBeVisible({ timeout: 20_000 });
    const mrnText = await adminPage.locator('.page__sub .mono').first().textContent();
    const mrn = mrnText?.trim() ?? '';
    expect(mrn).toMatch(/^MRN-/);

    // -- 3. book an appointment on the next Tuesday (fixture schedule)
    await adminPage.goto('/appointments');
    await adminPage.getByLabel('Appointment date').fill(date);
    await adminPage.getByRole('button', { name: 'Book appointment' }).click();
    // Full-name search: a prefix would match E2E patients from previous runs.
    await adminPage.getByPlaceholder('Search name or MRN').fill(patientName);
    await adminPage.locator('.pick-list__item').first().click();
    await adminPage.getByLabel('Provider').selectOption({ index: 1 });
    // A real service: the invoice derives the consultation charge from it.
    await adminPage.getByLabel('Service').selectOption({ label: 'OPD Consultation' });
    await adminPage.getByLabel('Consultation date').fill(date);
    // Availability is loaded from the backend. Wait for the response for THIS
    // date so a stale slot from a previous date can never be clicked.
    await adminPage.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`date=${date}`));
    await expect(adminPage.locator('.slots .slot')).toHaveCount(4, { timeout: 20_000 });
    await adminPage.locator('.slots .slot').first().click();
    await adminPage.getByRole('button', { name: 'Confirm booking' }).click();
    // Queue page is reached; the appointment is booked.
    await expect(adminPage.getByRole('heading', { name: 'Queue', exact: true })).toBeVisible({ timeout: 20_000 });

    // -- 4. check-in from the queue page (admin is also the front desk)
    await adminPage.getByLabel('Queue date').fill(date);
    await expect(adminPage.getByRole('heading', { name: 'Queue', exact: true })).toBeVisible();
    const checkinItem = adminPage.locator('.checkin-list__item').filter({ hasText: patientName });
    await expect(checkinItem).toBeVisible({ timeout: 20_000 });
    await checkinItem.getByRole('button', { name: 'Check in' }).click();
    await expect(adminPage.getByText(/Checked in — token #/)).toBeVisible({ timeout: 20_000 });
    await expect(adminPage.locator('.queue-card').filter({ hasText: patientName })).toContainText('#', { timeout: 20_000 });

    // -- 5. doctor login and queue view
    await login(doctorPage, DOCTOR_EMAIL, PASSWORD);
    await doctorPage.goto('/queue');
    await doctorPage.getByLabel('Queue date').fill(date);
    const queueCard = doctorPage.locator('.queue-card').filter({ hasText: patientName });
    await expect(queueCard).toBeVisible({ timeout: 20_000 });

    // -- 6. start the encounter
    await queueCard.getByRole('button', { name: 'Start consultation' }).click();
    await expect(doctorPage.getByRole('heading', { name: /Encounter/ })).toBeVisible({ timeout: 20_000 });


    // -- 7. clinical documentation
    await doctorPage.getByRole('tab', { name: 'Clinical note' }).click();
    await doctorPage.getByLabel('Chief complaint').fill('Fever and headache for two days');
    await doctorPage.getByLabel('Examination').fill('Temperature 101F, congested throat');
    await doctorPage.getByLabel('Assessment').fill('Likely viral pharyngitis');
    await doctorPage.getByLabel('Plan').fill('Paracetamol 500mg TDS for 3 days');
    await doctorPage.getByRole('button', { name: 'Save draft' }).click();
    await expect(doctorPage.getByText('Note saved.')).toBeVisible({ timeout: 20_000 });
    await doctorPage.getByRole('button', { name: 'Sign note' }).click();
    await expect(doctorPage.getByText('Note saved.')).toBeVisible({ timeout: 20_000 });

    // -- 8. diagnosis
    await doctorPage.getByRole('tab', { name: 'Diagnosis' }).click();
    await doctorPage.getByLabel('ICD-10 code').fill('J11.1');
    await doctorPage.getByLabel('Type').selectOption('final');
    await doctorPage.getByLabel('Description').fill('Influenza with other respiratory manifestations');
    await doctorPage.getByRole('checkbox', { name: 'Primary diagnosis' }).check();
    await doctorPage.getByRole('button', { name: 'Add diagnosis' }).click();
    await expect(doctorPage.getByText('Diagnosis recorded.')).toBeVisible({ timeout: 20_000 });

    // -- 9. prescription
    await doctorPage.getByRole('tab', { name: 'Prescription' }).click();
    await doctorPage.getByLabel('Medication').selectOption({ index: 1 });
    await doctorPage.getByLabel('Dose').fill('500mg');
    await doctorPage.getByLabel('Frequency').fill('TDS');
    await doctorPage.getByLabel('Duration').fill('3 days');
    await doctorPage.getByRole('button', { name: 'Draft prescription' }).click();
    await expect(doctorPage.getByText('Prescription drafted.')).toBeVisible({ timeout: 20_000 });

    // -- 10. sign the encounter
    await doctorPage.getByRole('button', { name: 'Sign encounter' }).click();
    await expect(doctorPage.getByText(/Encounter signed/)).toBeVisible({ timeout: 20_000 });

    // -- 11. admin issues the invoice from the signed encounter
    const encounterUrl = doctorPage.url(); // /encounters/{id}
    await adminPage.goto(encounterUrl);
    await expect(adminPage.getByRole('heading', { name: /Encounter/ })).toBeVisible({ timeout: 20_000 });
    await adminPage.getByRole('button', { name: 'Issue invoice' }).click();
    // Redirects to the invoice; invoice details come from the real API.
    await expect(adminPage.getByRole('heading', { name: /Invoice INV-/ })).toBeVisible({ timeout: 20_000 });
    // The total renders in several places; the first match is enough.
    await expect(adminPage.getByText('NPR 80.00').first()).toBeVisible({ timeout: 20_000 }); // 5000 + 3000 = 8000 minor

    // -- 12. capture payment (idempotency handled by the backend)
    await adminPage.getByLabel('Method').selectOption('cash');
    await adminPage.getByRole('button', { name: 'Capture payment' }).click();
    await expect(adminPage.getByText(/Payment captured/)).toBeVisible({ timeout: 20_000 });
    await expect(adminPage.getByText('Paid', { exact: true }).first()).toBeVisible({ timeout: 20_000 });

    // -- 13. the audit trail records every step (append-only, read-only)
    await adminPage.goto('/audit');
    for (const action of ['patient.created', 'appointment.booked', 'appointment.checked_in', 'encounter.started', 'note.signed', 'diagnosis.added', 'prescription.drafted', 'encounter.signed', 'invoice.issued', 'payment.captured']) {
      // The audit trail is append-only, so an action can appear many times.
      await expect(adminPage.getByText(action).first()).toBeVisible({ timeout: 20_000 });
    }
  });
});
