import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { ToastProvider } from '../context/ToastContext';
import { EmergencyPage } from './EmergencyPage';
import { jsonOk, stubFetch } from '../test/helpers';

function sessionResponse(roles: string[] = ['hospital_admin']) {
  return jsonOk({
    accessToken: 'tok_test',
    tokenType: 'Bearer',
    expiresIn: 3600,
    refreshToken: 'ref_test',
    refreshExpiresIn: 86400,
    user: { id: 'u1', email: 'admin@test.com', status: 'active' },
    assignments: [
      { organizationId: 'org-1', organizationCode: 'SMOKE', facilityId: 'fac-1', facilityName: 'Smoke Central', roles },
    ],
  });
}

function queueEntry(overrides: Record<string, unknown> = {}) {
  return {
    encounterId: 'enc-1',
    registrationId: 'reg-1',
    patientId: 'pat-1',
    facilityId: 'fac-1',
    registeredAt: '2026-09-01T10:00:00Z',
    presentingComplaint: 'Chest pain',
    isUnidentified: false,
    triageLevel: null,
    triageColor: null,
    triageAssessedAt: null,
    ...overrides,
  };
}

function dashboardResponse() {
  return jsonOk({
    statusCounts: { open: 2, inProgress: 0, closed: 0, total: 2 },
    triageDistribution: { level_1: 1 },
    avgWaitingMinutes: 12.5,
  });
}

function renderEmergency(roles: string[] = ['hospital_admin'], entry = queueEntry()) {
  // Fetch order after auth resolves: er queue → triage scales → dashboard.
  stubFetch(sessionResponse(roles), jsonOk([entry]), jsonOk([]), dashboardResponse());
  return render(
    <MemoryRouter initialEntries={['/emergency']}>
      <AuthProvider>
        <I18nProvider>
          <TenantProvider>
            <ToastProvider>
              <Routes>
                <Route path="/emergency" element={<EmergencyPage />} />
              </Routes>
            </ToastProvider>
          </TenantProvider>
        </I18nProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('EmergencyPage contract alignment', () => {
  it('renders the server queue with complaint and the server-truth census', async () => {
    renderEmergency();

    expect(await screen.findByText('Emergency Department')).toBeInTheDocument();
    // Queue entry from the server payload (contract: presentingComplaint).
    expect(await screen.findByText('Chest pain')).toBeInTheDocument();
    // Census totals come from the server dashboard, not client math.
    expect(await screen.findByText('2', { selector: 'span.er-census-value' })).toBeInTheDocument();
    expect(screen.getByText('Total in ED')).toBeInTheDocument();
  });

  it('offers only backend-valid dispositions (admitted/referred/home/deceased)', async () => {
    renderEmergency(['hospital_admin'], queueEntry({ triageLevel: 3, triageColor: 'yellow' }));

    const dispositionBtn = await screen.findByRole('button', { name: 'Disposition' });
    fireEvent.click(dispositionBtn);

    const options = await screen.findByRole('combobox', { name: 'Disposition' });
    const values = Array.from(options.querySelectorAll('option')).map((o) => (o as HTMLOptionElement).value);

    // Exact ErDispositionRequest contract — no invented 'transfer'/'ama'.
    expect(values).toEqual(expect.arrayContaining(['admitted', 'referred', 'home', 'deceased']));
    expect(values).not.toContain('transfer');
    expect(values).not.toContain('ama');
    expect(values).not.toContain('admit');
  });

  it('offers only backend-valid event types on the event dialog', async () => {
    renderEmergency();

    const eventBtn = await screen.findByRole('button', { name: 'Event' });
    fireEvent.click(eventBtn);

    const options = await screen.findByRole('combobox', { name: 'Event Type' });
    const values = Array.from(options.querySelectorAll('option')).map((o) => (o as HTMLOptionElement).value);

    // StoreErEventRequest enum — 'assessment'/'vitals'/'consult' are not ER
    // event types in the verified contract.
    expect(values).toEqual(expect.arrayContaining(['seen_by_doctor', 'medication_administered', 'procedure', 'other']));
    expect(values).not.toContain('assessment');
    expect(values).not.toContain('vitals');
    expect(values).not.toContain('consult');
    expect(values).not.toContain('note');
  });

  it('shows the admission bed selector for the admitted disposition', async () => {
    renderEmergency(['hospital_admin'], queueEntry({ triageLevel: 3, triageColor: 'yellow' }));

    const dispositionBtn = await screen.findByRole('button', { name: 'Disposition' });
    fireEvent.click(dispositionBtn);

    const dispositionSelect = await screen.findByRole('combobox', { name: 'Disposition' });
    fireEvent.change(dispositionSelect, { target: { value: 'admitted' } });

    // Backend requires bedId when disposition = admitted (required_if) —
    // the workspace must offer an available-bed picker.
    expect(await screen.findByRole('combobox', { name: 'Bed' })).toBeInTheDocument();
    expect(screen.getByText('Admitting Diagnosis')).toBeInTheDocument();
  });

  it('exposes immediate treatment for er:disposition holders', async () => {
    // hospital_admin has er:disposition in the frontend permission map.
    renderEmergency(['hospital_admin']);
    const immediateBtn = await screen.findByRole('button', { name: 'Immediate Tx' });
    expect(immediateBtn).toBeInTheDocument();
    fireEvent.click(immediateBtn);
    expect(await screen.findByText('Immediate Treatment')).toBeInTheDocument();
  });

  it('surfaces identity reconciliation for unidentified registrations', async () => {
    stubFetch(
      sessionResponse(['hospital_admin']),
      jsonOk([queueEntry({ registrationId: 'reg-9', isUnidentified: true })]),
      jsonOk([]),
      dashboardResponse(),
    );
    render(
      <MemoryRouter initialEntries={['/emergency']}>
        <AuthProvider>
          <I18nProvider>
            <TenantProvider>
              <ToastProvider>
                <Routes>
                  <Route path="/emergency" element={<EmergencyPage />} />
                </Routes>
              </ToastProvider>
            </TenantProvider>
          </I18nProvider>
        </AuthProvider>
      </MemoryRouter>,
    );
    const reconcileBtn = await screen.findByRole('button', { name: 'Reconcile ID' });
    expect(reconcileBtn).toBeInTheDocument();
    fireEvent.click(reconcileBtn);
    expect(await screen.findByText('Reconcile Patient Identity')).toBeInTheDocument();
  });

  it('hides disposition and registration actions from roles without ER permissions', async () => {
    // A doctor has no er:register / er:disposition in the frontend map —
    // the buttons must not render (backend remains authoritative).
    renderEmergency(['doctor']);

    expect(await screen.findByText('Emergency Department')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'New Registration' })).not.toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Disposition' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Immediate Tx' })).not.toBeInTheDocument();
  });
});