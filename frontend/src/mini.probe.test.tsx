import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './auth/AuthProvider';
import { TenantProvider } from './context/TenantContext';
import { I18nProvider } from './i18n/I18nProvider';
import { ToastProvider } from './context/ToastContext';
import { EmergencyPage } from './pages/EmergencyPage';
import { jsonOk, stubFetch } from './test/helpers';

function sessionResponse() {
  return jsonOk({
    accessToken: 't', tokenType: 'Bearer', expiresIn: 3600, refreshToken: 'r', refreshExpiresIn: 86400,
    user: { id: 'u1', email: 'a@b.c', status: 'active' },
    assignments: [{ organizationId: 'org-1', organizationCode: 'X', facilityId: 'fac-1', facilityName: 'F', roles: ['hospital_admin'] }],
  });
}

describe('mini', () => {
  it('renders page', async () => {
    stubFetch(
      sessionResponse(),
      jsonOk([{ encounterId: 'enc-1', registrationId: 'reg-1', patientId: 'pat-1', facilityId: 'fac-1', registeredAt: '2026-09-01T10:00:00Z', presentingComplaint: 'Chest pain', isUnidentified: false, triageLevel: null, triageColor: null, triageAssessedAt: null }]),
      jsonOk([]),
      jsonOk({ statusCounts: { open: 2, inProgress: 0, closed: 0, total: 2 }, triageDistribution: {}, avgWaitingMinutes: null }),
    );
    render(
      <MemoryRouter initialEntries={['/emergency']}>
        <AuthProvider><I18nProvider><TenantProvider><ToastProvider>
          <Routes><Route path="/emergency" element={<EmergencyPage />} /></Routes>
        </ToastProvider></TenantProvider></I18nProvider></AuthProvider>
      </MemoryRouter>,
    );
    expect(await screen.findByText('Emergency Department')).toBeInTheDocument();
  });
});
