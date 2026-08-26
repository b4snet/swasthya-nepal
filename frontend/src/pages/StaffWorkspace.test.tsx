import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';

const mockRefresh = vi.fn();
const mockUser = { id: 'u1', email: 'dr.rajan@birat.org', staffName: 'Dr. Rajan' };
const mockRoles = ['doctor'];

vi.mock('../auth/AuthProvider', () => ({
  useAuth: () => ({ user: mockUser, status: 'authenticated', assignments: [{ facilityId: 'f1', roles: mockRoles }] }),
}));

vi.mock('../auth/useAccess', () => ({
  useAccess: () => ({
    hasAnyRole: (roles: string[]) => roles.includes('doctor'),
    hasRole: (role: string) => role === 'doctor',
  }),
}));

vi.mock('../context/TenantContext', () => ({
  useTenant: () => ({ selectedFacilityId: 'f1', organizationId: 'o1' }),
}));

vi.mock('../hooks/useFetch', () => ({
  useFetch: () => ({ data: [], loading: false, error: null, refresh: mockRefresh }),
}));

vi.mock('../api/endpoints', () => ({
  appointmentsApi: { list: vi.fn() },
  encountersApi: { forPatient: vi.fn() },
  referralsApi: { list: vi.fn() },
  hrApi: { rosters: vi.fn(), attendance: vi.fn() },
}));

import { StaffWorkspace } from './StaffWorkspace';

const renderWorkspace = () =>
  render(
    <BrowserRouter>
      <StaffWorkspace />
    </BrowserRouter>,
  );

describe('StaffWorkspace', () => {
  it('renders the My Work title', () => {
    renderWorkspace();
    expect(screen.getByText('My Work')).toBeDefined();
  });

  it('displays the staff name', () => {
    renderWorkspace();
    expect(screen.getByText(/Dr\. Rajan/)).toBeDefined();
  });

  it('renders section tabs including Today and Appointments', () => {
    renderWorkspace();
    // "Today's Overview" appears in both the tab and the section header
    expect(screen.getAllByText("Today's Overview").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByRole('tab', { name: /appointments/i })).toBeDefined();
  });

  it('renders with empty data without crashing', () => {
    const { container } = renderWorkspace();
    expect(container.firstChild).toBeTruthy();
  });

  it('renders staff avatar initials', () => {
    renderWorkspace();
    expect(screen.getByText('DR')).toBeDefined();
  });
});
