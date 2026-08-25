import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, beforeEach } from 'vitest';
import { AuthProvider } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { I18nProvider } from '../i18n/I18nProvider';
import { ToastProvider } from '../context/ToastContext';
import { InteropPage } from './InteropPage';
import { stubFetch, jsonOk } from '../test/helpers';

/**
 * InteropPage test suite.
 *
 * Stub order: no session refresh is made because beforeEach clears storage,
 * so the three useFetch hooks consume stubs in order:
 *   1. integrations
 *   2. partners
 *   3. egressAllowlist
 */
function intResponse(items: unknown[] = []) {
  return jsonOk({ integrations: items });
}

function partnerResponse(items: unknown[] = []) {
  return jsonOk({ partners: items });
}

function egressResponse(items: unknown[] = []) {
  return jsonOk({ destinations: items });
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/integrations']}>
      <AuthProvider>
        <I18nProvider>
          <TenantProvider>
            <ToastProvider>
              <Routes>
                <Route path="/admin/integrations" element={<InteropPage />} />
              </Routes>
            </ToastProvider>
          </TenantProvider>
        </I18nProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('InteropPage', () => {
  beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
  });

  it('renders the page heading and subtitle', async () => {
    stubFetch(intResponse(), partnerResponse(), egressResponse());

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Interoperability')).toBeDefined();
      expect(screen.getByText(/FHIR, HL7, DICOM/)).toBeDefined();
    });
  });

  it('shows empty state when no integrations exist', async () => {
    stubFetch(intResponse(), partnerResponse(), egressResponse());

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('No integrations')).toBeDefined();
    });
  });

  it('renders integration rows when data is returned', async () => {
    const intData = [
      {
        id: 'int-1',
        code: 'lab-lis-01',
        name: 'External LIS',
        integrationType: 'fhir',
        standards: ['FHIR', 'HL7'],
        status: 'active',
        lastCheckedAt: null,
        killSwitchEnabled: false,
      },
    ];
    // Provide duplicates in case hooks re-fire
    stubFetch(intResponse(intData), partnerResponse(), egressResponse(), intResponse(intData), partnerResponse(), egressResponse());

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('lab-lis-01')).toBeDefined();
      expect(screen.getByText('External LIS')).toBeDefined();
    });
  });

  it('has tab buttons for all sections', async () => {
    stubFetch(intResponse(), partnerResponse(), egressResponse());

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Integrations' })).toBeDefined();
      expect(screen.getByRole('button', { name: 'FHIR Endpoints' })).toBeDefined();
      expect(screen.getByRole('button', { name: 'Partners' })).toBeDefined();
      expect(screen.getByRole('button', { name: 'Egress Allowlist' })).toBeDefined();
      expect(screen.getByRole('button', { name: 'Events' })).toBeDefined();
    });
  });

  it('shows census labels for partners and egress', async () => {
    stubFetch(intResponse(), partnerResponse(), egressResponse());

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Partners', { selector: '.io-census-label' })).toBeDefined();
      expect(screen.getByText('Egress Destinations', { selector: '.io-census-label' })).toBeDefined();
    });
  });
});
