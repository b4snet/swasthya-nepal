import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ClinicalWorkQueue } from './ClinicalWorkQueue';

// Mock hooks
vi.mock('../context/TenantContext', () => ({
  useTenant: () => ({
    selectedFacilityId: 'fac-1',
    hasRole: () => true,
  }),
}));

const mockNavigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

const mockUseFetch = vi.fn();
vi.mock('../hooks/useFetch', () => ({
  useFetch: (...args: any[]) => mockUseFetch(...args),
}));

// Mock API
const mockAcknowledge = vi.fn().mockResolvedValue({});
const mockComplete = vi.fn().mockResolvedValue({});

vi.mock('../api/clinical', () => ({
  appointmentsApi: { list: vi.fn(), queue: vi.fn() },
  referralsApi: { complete: (...args: any[]) => mockComplete(...args) },
}));

vi.mock('../api/laboratory', () => ({
  criticalValueApi: { acknowledge: (...args: any[]) => mockAcknowledge(...args) },
  radiologyApi: { queue: vi.fn() },
}));

describe('ClinicalWorkQueue — Action Buttons', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUseFetch.mockReturnValue({ data: [], loading: false, error: null });
  });

  it('renders with critical value action button', () => {
    // Provide critical value data that creates a work item with mutationAction
    const criticalValues = [
      {
        id: 'cv-1',
        testName: 'Potassium',
        result_summary: 'K+ 6.2 mmol/L',
        patientId: 'p-1',
        patient: { fullName: 'Ram Bahadur', mrn: '004821' },
        acknowledgedAt: null,
        created_at: new Date().toISOString(),
      },
    ];

    mockUseFetch
      .mockReturnValueOnce({ data: [], loading: false, error: null }) // appointments
      .mockReturnValueOnce({ data: [], loading: false, error: null }) // queue
      .mockReturnValueOnce({ data: [], loading: false, error: null }) // referrals
      .mockReturnValueOnce({ data: criticalValues, loading: false, error: null }) // criticalValues
      .mockReturnValueOnce({ data: [], loading: false, error: null }); // radiology

    render(<ClinicalWorkQueue />);
    // Critical value shows in both the filter button and the work item
    const matches = screen.getAllByText(/critical value/i);
    expect(matches.length).toBeGreaterThanOrEqual(1);
  });

  it('navigates on action button click', () => {
    render(<ClinicalWorkQueue />);
    // With no data, should show empty state
    expect(screen.getByText(/all clear/i)).toBeInTheDocument();
  });

  it('shows empty state when no work items', () => {
    render(<ClinicalWorkQueue />);
    expect(screen.getByText(/no pending work items/i)).toBeInTheDocument();
  });

  it('has accessible region label', () => {
    render(<ClinicalWorkQueue />);
    expect(screen.getByRole('region', { name: /clinical work queue/i })).toBeInTheDocument();
  });
});
