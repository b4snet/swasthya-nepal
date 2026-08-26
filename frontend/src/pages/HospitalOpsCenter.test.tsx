import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';

const mockRefresh = vi.fn();

vi.mock('../auth/AuthProvider', () => ({
  useAuth: () => ({
    user: { id: 'u1', email: 'admin@hospital.org', staffName: 'Admin' },
    status: 'authenticated',
    assignments: [{ facilityId: 'f1', roles: ['hospital_admin'] }],
  }),
}));

vi.mock('../auth/useAccess', () => ({
  useAccess: () => ({
    hasAnyRole: (roles: string[]) => roles.includes('hospital_admin'),
    hasRole: (role: string) => role === 'hospital_admin',
  }),
}));

vi.mock('../context/TenantContext', () => ({
  useTenant: () => ({ selectedFacilityId: 'f1', organizationId: 'o1' }),
}));

vi.mock('../hooks/useFetch', () => ({
  useFetch: () => ({ data: null, loading: false, error: null, refresh: mockRefresh }),
}));

vi.mock('../api/endpoints', () => ({
  bedWardApi: { occupancy: vi.fn() },
  erApi: { queue: vi.fn() },
}));

vi.mock('../api/client', () => ({
  api: { request: vi.fn() },
}));

import { HospitalOpsCenter } from './HospitalOpsCenter';

const renderOps = () =>
  render(
    <BrowserRouter>
      <HospitalOpsCenter />
    </BrowserRouter>,
  );

describe('HospitalOpsCenter', () => {
  it('renders the Hospital Operations title', () => {
    renderOps();
    expect(screen.getByText('Hospital Operations')).toBeDefined();
  });

  it('renders subtitle', () => {
    renderOps();
    expect(screen.getByText(/Capacity, flow, exceptions/)).toBeDefined();
  });

  it('renders section headers', () => {
    renderOps();
    expect(screen.getByText('Hospital Capacity')).toBeDefined();
    expect(screen.getByText('Ward Capacity')).toBeDefined();
  });

  it('shows empty states when no data', () => {
    renderOps();
    expect(screen.getByText('No wards configured')).toBeDefined();
  });

  it('renders without crashing with empty data', () => {
    const { container } = renderOps();
    expect(container.firstChild).toBeTruthy();
  });
});
