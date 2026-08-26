import { describe, expect, it } from 'vitest';

// ── Appointment status flow (extracted from PatientFlowOrchestrator) ──
const STATUS_FLOW: Record<string, { next: string | null; label: string; color: string }> = {
  booked: { next: 'checked_in', label: 'Booked', color: '#3b82f6' },
  checked_in: { next: 'in_consultation', label: 'Checked In', color: '#f59e0b' },
  in_consultation: { next: 'completed', label: 'In Consultation', color: '#8b5cf6' },
  completed: { next: null, label: 'Completed', color: '#10b981' },
  cancelled: { next: null, label: 'Cancelled', color: '#ef4444' },
  no_show: { next: null, label: 'No Show', color: '#6b7280' },
};

// ── Flow step logic (extracted from component) ──
interface FlowStep {
  id: string;
  label: string;
  status: 'completed' | 'current' | 'pending' | 'blocked';
}

function getFlowSteps(appointment: { status: string; tokenNo?: number | null; startsAt: string; provider?: { fullName?: string } | null }): FlowStep[] {
  const steps: FlowStep[] = [
    { id: 'scheduled', label: 'Scheduled', status: 'completed' },
    { id: 'checkin', label: 'Check-in', status: appointment.status === 'booked' ? 'current' : ['checked_in', 'in_consultation', 'completed'].includes(appointment.status) ? 'completed' : 'pending' },
    { id: 'queue', label: 'Queue', status: appointment.status === 'checked_in' ? 'current' : ['in_consultation', 'completed'].includes(appointment.status) ? 'completed' : 'pending' },
    { id: 'consultation', label: 'Consultation', status: appointment.status === 'in_consultation' ? 'current' : appointment.status === 'completed' ? 'completed' : 'pending' },
    { id: 'next', label: 'Next Step', status: appointment.status === 'completed' ? 'current' : 'pending' },
  ];

  if (appointment.status === 'cancelled' || appointment.status === 'no_show') {
    return steps.map(s => ({
      ...s,
      status: s.id === 'scheduled' ? 'completed' as const : 'blocked' as const,
    }));
  }

  return steps;
}

// ── Queue filtering logic ──
function filterQueueEntries(entries: Array<{ status: string; patient?: { fullName?: string }; tokenNo?: number; appointmentId: string }>) {
  return {
    waiting: entries.filter(e => e.status === 'checked_in'),
    inConsultation: entries.filter(e => e.status === 'in_consultation'),
    next: entries.find(e => e.status === 'checked_in'),
  };
}

// ── Status count logic ──
function countByStatus(appointments: Array<{ status: string }>) {
  const counts: Record<string, number> = {};
  for (const a of appointments) {
    counts[a.status] = (counts[a.status] || 0) + 1;
  }
  return counts;
}

describe('PatientFlowOrchestrator status flow', () => {
  it('defines all appointment statuses', () => {
    expect(Object.keys(STATUS_FLOW)).toHaveLength(6);
    expect(STATUS_FLOW.booked).toBeDefined();
    expect(STATUS_FLOW.checked_in).toBeDefined();
    expect(STATUS_FLOW.in_consultation).toBeDefined();
    expect(STATUS_FLOW.completed).toBeDefined();
    expect(STATUS_FLOW.cancelled).toBeDefined();
    expect(STATUS_FLOW.no_show).toBeDefined();
  });

  it('defines correct next states', () => {
    expect(STATUS_FLOW.booked.next).toBe('checked_in');
    expect(STATUS_FLOW.checked_in.next).toBe('in_consultation');
    expect(STATUS_FLOW.in_consultation.next).toBe('completed');
    expect(STATUS_FLOW.completed.next).toBeNull();
    expect(STATUS_FLOW.cancelled.next).toBeNull();
    expect(STATUS_FLOW.no_show.next).toBeNull();
  });

  it('defines labels for all statuses', () => {
    Object.values(STATUS_FLOW).forEach(s => {
      expect(s.label).toBeTruthy();
    });
  });

  it('defines colors for all statuses', () => {
    Object.values(STATUS_FLOW).forEach(s => {
      expect(s.color).toMatch(/^#/);
    });
  });
});

describe('PatientFlowOrchestrator flow steps', () => {
  const baseAppointment = {
    status: 'booked',
    startsAt: '2024-01-15T10:00:00Z',
    tokenNo: 1,
    provider: { fullName: 'Dr. Rajan' },
  };

  it('shows all 5 steps for booked appointment', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'booked' });
    expect(steps).toHaveLength(5);
    expect(steps.map(s => s.id)).toEqual(['scheduled', 'checkin', 'queue', 'consultation', 'next']);
  });

  it('shows check-in as current for booked appointment', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'booked' });
    const checkin = steps.find(s => s.id === 'checkin')!;
    expect(checkin.status).toBe('current');
  });

  it('marks check-in as completed when checked_in', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'checked_in' });
    expect(steps.find(s => s.id === 'checkin')!.status).toBe('completed');
    expect(steps.find(s => s.id === 'queue')!.status).toBe('current');
  });

  it('marks queue as current when checked_in', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'checked_in' });
    expect(steps.find(s => s.id === 'queue')!.status).toBe('current');
  });

  it('marks consultation as current when in_consultation', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'in_consultation' });
    expect(steps.find(s => s.id === 'consultation')!.status).toBe('current');
  });

  it('marks next step as current when completed', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'completed' });
    expect(steps.find(s => s.id === 'next')!.status).toBe('current');
  });

  it('marks all non-scheduled as blocked for cancelled', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'cancelled' });
    expect(steps.find(s => s.id === 'scheduled')!.status).toBe('completed');
    steps.filter(s => s.id !== 'scheduled').forEach(s => {
      expect(s.status).toBe('blocked');
    });
  });

  it('marks all non-scheduled as blocked for no_show', () => {
    const steps = getFlowSteps({ ...baseAppointment, status: 'no_show' });
    expect(steps.find(s => s.id === 'scheduled')!.status).toBe('completed');
    steps.filter(s => s.id !== 'scheduled').forEach(s => {
      expect(s.status).toBe('blocked');
    });
  });
});

describe('PatientFlowOrchestrator queue filtering', () => {
  const entries = [
    { appointmentId: '1', status: 'checked_in', tokenNo: 1, patient: { fullName: 'Ram' } },
    { appointmentId: '2', status: 'in_consultation', tokenNo: 2, patient: { fullName: 'Shyam' } },
    { appointmentId: '3', status: 'checked_in', tokenNo: 3, patient: { fullName: 'Hari' } },
  ];

  it('filters waiting entries', () => {
    const { waiting } = filterQueueEntries(entries);
    expect(waiting).toHaveLength(2);
    expect(waiting[0].patient?.fullName).toBe('Ram');
  });

  it('filters in-consultation entries', () => {
    const { inConsultation } = filterQueueEntries(entries);
    expect(inConsultation).toHaveLength(1);
    expect(inConsultation[0].patient?.fullName).toBe('Shyam');
  });

  it('gets next waiting patient', () => {
    const { next } = filterQueueEntries(entries);
    expect(next).toBeDefined();
    expect(next?.tokenNo).toBe(1);
  });

  it('returns undefined for next when no waiting', () => {
    const { next } = filterQueueEntries([]);
    expect(next).toBeUndefined();
  });
});

describe('PatientFlowOrchestrator status counting', () => {
  it('counts appointments by status', () => {
    const appointments = [
      { status: 'booked' },
      { status: 'booked' },
      { status: 'checked_in' },
      { status: 'completed' },
      { status: 'completed' },
      { status: 'completed' },
    ];
    const counts = countByStatus(appointments);
    expect(counts['booked']).toBe(2);
    expect(counts['checked_in']).toBe(1);
    expect(counts['completed']).toBe(3);
  });

  it('returns empty object for no appointments', () => {
    expect(countByStatus([])).toEqual({});
  });
});
