import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import WorkActivityFeed from './WorkActivityFeed';

// Mock hooks
vi.mock('../context/TenantContext', () => ({
  useTenant: () => ({ selectedFacilityId: 'fac-1', hasRole: () => true }),
}));

const mockUseFetch = vi.fn();
vi.mock('../hooks/useFetch', () => ({
  useFetch: (...args: any[]) => mockUseFetch(...args),
}));

vi.mock('../api/endpoints', () => ({
  patientsApi: { timeline: vi.fn() },
}));

describe('WorkActivityFeed', () => {
  beforeEach(() => {
    mockUseFetch.mockReset();
  });

  it('shows loading state', () => {
    mockUseFetch.mockReturnValue({ data: null, loading: true, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    expect(screen.getByText(/loading activity/i)).toBeInTheDocument();
  });

  it('shows empty state when no events', () => {
    mockUseFetch.mockReturnValue({ data: [], loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    expect(screen.getByText(/no recent activity/i)).toBeInTheDocument();
  });

  it('renders timeline events', () => {
    const events = [
      { id: '1', type: 'encounter_open', title: 'Consultation opened', timestamp: new Date().toISOString() },
      { id: '2', type: 'prescription_issued', title: 'Prescription issued', timestamp: new Date().toISOString() },
    ];
    mockUseFetch.mockReturnValue({ data: events, loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    expect(screen.getByText('Consultation opened')).toBeInTheDocument();
    expect(screen.getByText('Prescription issued')).toBeInTheDocument();
  });

  it('groups events by day', () => {
    const today = new Date().toISOString();
    const yesterday = new Date(Date.now() - 86400000).toISOString();
    const events = [
      { id: '1', type: 'encounter_open', title: 'Encounter today', timestamp: today },
      { id: '2', type: 'note_signed', title: 'Note yesterday', timestamp: yesterday },
    ];
    mockUseFetch.mockReturnValue({ data: events, loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    expect(screen.getByText('Today')).toBeInTheDocument();
    expect(screen.getByText('Yesterday')).toBeInTheDocument();
  });

  it('respects maxEvents prop', () => {
    const events = Array.from({ length: 20 }, (_, i) => ({
      id: `${i}`,
      type: 'encounter_open',
      title: `Event ${i}`,
      timestamp: new Date().toISOString(),
    }));
    mockUseFetch.mockReturnValue({ data: events, loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" maxEvents={5} />);
    // maxEvents=5 should limit to 5 events
    const countText = screen.getByText(/5 events/);
    expect(countText).toBeInTheDocument();
  });

  it('displays event timestamps', () => {
    const timestamp = new Date().toISOString();
    const events = [
      { id: '1', type: 'encounter_open', title: 'Event', timestamp },
    ];
    mockUseFetch.mockReturnValue({ data: events, loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    // Should show "Just now" or similar relative time
    expect(screen.getByText(/just now|ago/i)).toBeInTheDocument();
  });

  it('has accessible region label when events present', () => {
    const events = [
      { id: '1', type: 'encounter_open', title: 'Encounter opened', timestamp: new Date().toISOString() },
    ];
    mockUseFetch.mockReturnValue({ data: events, loading: false, error: null });
    render(<WorkActivityFeed patientId="p1" />);
    expect(screen.getByRole('region', { name: /recent clinical activity/i })).toBeInTheDocument();
  });
});
