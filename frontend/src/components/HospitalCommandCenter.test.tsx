import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { HospitalCommandCenter } from './HospitalCommandCenter';

// Mock hooks
vi.mock('../context/TenantContext', () => ({
  useTenant: () => ({ selectedFacilityId: 'fac-1', hasRole: () => true }),
}));

const mockNavigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

const mockUseFetch = vi.fn();
vi.mock('../hooks/useFetch', () => ({
  useFetch: (...args: any[]) => mockUseFetch(...args),
}));

vi.mock('../api/endpoints', () => ({
  appointmentsApi: { list: vi.fn() },
}));

vi.mock('../api/dashboard', () => ({
  dashboardApi: { metrics: vi.fn() },
}));

describe('HospitalCommandCenter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows loading state', () => {
    mockUseFetch
      .mockReturnValueOnce({ data: null, loading: true, error: null }) // appointments
      .mockReturnValueOnce({ data: null, loading: true, error: null }); // metrics
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/loading hospital state/i)).toBeInTheDocument();
  });

  it('renders summary stats', () => {
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null }) // appointments
      .mockReturnValueOnce({ data: null, loading: false, error: null }); // metrics
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/patients/i)).toBeInTheDocument();
    expect(screen.getByText(/waiting/i)).toBeInTheDocument();
  });

  it('renders capacity section', () => {
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null })
      .mockReturnValueOnce({ data: null, loading: false, error: null });
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/capacity/i)).toBeInTheDocument();
  });

  it('renders patient flow section', () => {
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null })
      .mockReturnValueOnce({ data: null, loading: false, error: null });
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/patient flow/i)).toBeInTheDocument();
  });

  it('renders department status when metrics available', () => {
    const metricsData = {
      pendingLabOrders: 5,
      criticalValues: 1,
      pendingReports: 3,
      prescriptionsToday: 12,
      lowStockItems: 2,
      erRegistrationsToday: 8,
      erWaiting: 3,
      revenueToday: 45000,
      appointmentsToday: 20,
      inQueue: 5,
      inConsultation: 8,
      occupiedBeds: 32,
      totalBeds: 50,
      availableBeds: 18,
      cleaningBeds: 0,
      dischargesToday: 3,
      totalPatients: 150,
      encountersToday: 15,
    };
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null }) // appointments
      .mockReturnValueOnce({ data: metricsData, loading: false, error: null }); // metrics
    render(<HospitalCommandCenter />);
    // Department status rows — verify at least one instance of each dept name
    expect(screen.getAllByText(/laboratory/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/radiology/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/pharmacy/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/emergency/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/finance/i).length).toBeGreaterThanOrEqual(1);
  });

  it('shows critical value alert from metrics', () => {
    const metricsData = {
      criticalValues: 2,
      pendingLabOrders: 10,
      pendingReports: 5,
      prescriptionsToday: 8,
      lowStockItems: 0,
      erRegistrationsToday: 5,
      erWaiting: 0,
      revenueToday: 0,
      appointmentsToday: 15,
      inQueue: 3,
      inConsultation: 5,
      totalBeds: 50,
      occupiedBeds: 30,
      availableBeds: 20,
      cleaningBeds: 0,
      dischargesToday: 2,
      totalPatients: 100,
      encountersToday: 10,
    };
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null })
      .mockReturnValueOnce({ data: metricsData, loading: false, error: null });
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/2 unacknowledged critical value/i)).toBeInTheDocument();
  });

  it('shows bed capacity when metrics available', () => {
    const metricsData = {
      totalBeds: 50,
      occupiedBeds: 42,
      availableBeds: 8,
      cleaningBeds: 0,
      pendingLabOrders: 0,
      criticalValues: 0,
      pendingReports: 0,
      prescriptionsToday: 0,
      lowStockItems: 0,
      erRegistrationsToday: 0,
      erWaiting: 0,
      revenueToday: 0,
      appointmentsToday: 0,
      inQueue: 0,
      inConsultation: 0,
      dischargesToday: 0,
      totalPatients: 0,
      encountersToday: 0,
    };
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null })
      .mockReturnValueOnce({ data: metricsData, loading: false, error: null });
    render(<HospitalCommandCenter />);
    expect(screen.getByText(/hospital beds/i)).toBeInTheDocument();
    // Bed capacity shows 42 / 50
    const capacityValues = screen.getAllByText(/42/);
    expect(capacityValues.length).toBeGreaterThanOrEqual(1);
  });

  it('has accessible region label', () => {
    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null })
      .mockReturnValueOnce({ data: null, loading: false, error: null });
    render(<HospitalCommandCenter />);
    expect(screen.getByRole('region', { name: /hospital command center/i })).toBeInTheDocument();
  });
});
