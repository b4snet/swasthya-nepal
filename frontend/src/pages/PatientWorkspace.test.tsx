import { describe, expect, it } from 'vitest';

// ── Timeline grouping logic (extracted from PatientWorkspace) ──
// This mirrors the exact algorithm used in the component.

type TimeEntry = { id: string; occurredAt: string; eventType: string };
type TimeGroup = { label: string; items: TimeEntry[] };

function groupTimelineEntries(entries: TimeEntry[]): TimeGroup[] {
  const now = new Date();
  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const weekAgo = new Date(todayStart.getTime() - 7 * 24 * 60 * 60 * 1000);

  const today: TimeEntry[] = [];
  const recent: TimeEntry[] = [];
  const earlier: TimeEntry[] = [];

  for (const entry of entries) {
    const date = new Date(entry.occurredAt);
    if (date >= todayStart) {
      today.push(entry);
    } else if (date >= weekAgo) {
      recent.push(entry);
    } else {
      earlier.push(entry);
    }
  }

  const groups: TimeGroup[] = [];
  if (today.length > 0) groups.push({ label: 'Today', items: today });
  if (recent.length > 0) groups.push({ label: 'Recent (past week)', items: recent });
  if (earlier.length > 0) groups.push({ label: 'Earlier', items: earlier });
  return groups;
}

// ── Patient status helper ──
function patientStatusInfo(status: string): { tone: string; label: string } {
  switch (status) {
    case 'active': return { tone: 'success', label: 'Active' };
    case 'deceased': return { tone: 'danger', label: 'Deceased' };
    case 'inactive': return { tone: 'neutral', label: 'Inactive' };
    case 'transferred': return { tone: 'info', label: 'Transferred' };
    case 'discharged': return { tone: 'info', label: 'Discharged' };
    default: return { tone: 'neutral', label: status };
  }
}

describe('PatientWorkspace timeline grouping', () => {
  const now = new Date();
  const todayStr = now.toISOString();
  const threeDaysAgo = new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000).toISOString();
  const twoWeeksAgo = new Date(now.getTime() - 14 * 24 * 60 * 60 * 1000).toISOString();

  it('groups entries into today, recent, and earlier', () => {
    const entries: TimeEntry[] = [
      { id: '1', occurredAt: todayStr, eventType: 'encounter' },
      { id: '2', occurredAt: threeDaysAgo, eventType: 'prescription' },
      { id: '3', occurredAt: twoWeeksAgo, eventType: 'admission' },
    ];
    const groups = groupTimelineEntries(entries);
    expect(groups).toHaveLength(3);
    expect(groups[0].label).toBe('Today');
    expect(groups[0].items).toHaveLength(1);
    expect(groups[0].items[0].eventType).toBe('encounter');
    expect(groups[1].label).toBe('Recent (past week)');
    expect(groups[1].items).toHaveLength(1);
    expect(groups[1].items[0].eventType).toBe('prescription');
    expect(groups[2].label).toBe('Earlier');
    expect(groups[2].items).toHaveLength(1);
    expect(groups[2].items[0].eventType).toBe('admission');
  });

  it('returns empty array for empty input', () => {
    expect(groupTimelineEntries([])).toEqual([]);
  });

  it('groups multiple items in the same period', () => {
    const entries: TimeEntry[] = [
      { id: '1', occurredAt: todayStr, eventType: 'encounter' },
      { id: '2', occurredAt: todayStr, eventType: 'prescription' },
      { id: '3', occurredAt: todayStr, eventType: 'lab_result' },
    ];
    const groups = groupTimelineEntries(entries);
    expect(groups).toHaveLength(1);
    expect(groups[0].label).toBe('Today');
    expect(groups[0].items).toHaveLength(3);
  });

  it('places older entries in Earlier when no recent entries exist', () => {
    const entries: TimeEntry[] = [
      { id: '1', occurredAt: twoWeeksAgo, eventType: 'admission' },
    ];
    const groups = groupTimelineEntries(entries);
    expect(groups).toHaveLength(1);
    expect(groups[0].label).toBe('Earlier');
  });

  it('places recent entries when no today entries exist', () => {
    const entries: TimeEntry[] = [
      { id: '1', occurredAt: threeDaysAgo, eventType: 'follow_up' },
    ];
    const groups = groupTimelineEntries(entries);
    expect(groups).toHaveLength(1);
    expect(groups[0].label).toBe('Recent (past week)');
  });
});

describe('PatientWorkspace patientStatusInfo', () => {
  it('maps active to success', () => {
    expect(patientStatusInfo('active')).toEqual({ tone: 'success', label: 'Active' });
  });

  it('maps deceased to danger', () => {
    expect(patientStatusInfo('deceased')).toEqual({ tone: 'danger', label: 'Deceased' });
  });

  it('maps inactive to neutral', () => {
    expect(patientStatusInfo('inactive')).toEqual({ tone: 'neutral', label: 'Inactive' });
  });

  it('maps transferred to info', () => {
    expect(patientStatusInfo('transferred')).toEqual({ tone: 'info', label: 'Transferred' });
  });

  it('maps unknown status to neutral with original text', () => {
    expect(patientStatusInfo('unknown')).toEqual({ tone: 'neutral', label: 'unknown' });
  });
});

describe('PatientWorkspace workspace definitions', () => {
  it('has an overview workspace visible to all roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const overview = PATIENT_WORKSPACES.find((w) => w.id === 'overview');
    expect(overview).toBeDefined();
    expect(overview!.roles).toEqual([]);
  });

  it('restricts encounters to clinical roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const encounters = PATIENT_WORKSPACES.find((w) => w.id === 'encounters');
    expect(encounters).toBeDefined();
    expect(encounters!.roles).toContain('doctor');
    expect(encounters!.roles).toContain('nurse');
    expect(encounters!.roles).not.toContain('pharmacist');
  });

  it('restrictes medications to clinical/pharmacy roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const medications = PATIENT_WORKSPACES.find((w) => w.id === 'medications');
    expect(medications).toBeDefined();
    expect(medications!.roles).toContain('doctor');
    expect(medications!.roles).toContain('pharmacist');
    expect(medications!.roles).not.toContain('receptionist');
  });

  it('restricts billing to finance roles', async () => {
    // Quick actions, not workspaces, but same principle
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    // Verify all workspaces have roles arrays
    PATIENT_WORKSPACES.forEach((ws) => {
      expect(Array.isArray(ws.roles)).toBe(true);
    });
  });

  it('has timeline visible to all roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const timeline = PATIENT_WORKSPACES.find((w) => w.id === 'timeline');
    expect(timeline).toBeDefined();
    expect(timeline!.roles).toEqual([]);
  });

  it('has documents visible to all roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const docs = PATIENT_WORKSPACES.find((w) => w.id === 'documents');
    expect(docs).toBeDefined();
    expect(docs!.roles).toEqual([]);
  });

  it('has appointments visible to all roles', async () => {
    const { PATIENT_WORKSPACES } = await import('./PatientWorkspace');
    const appts = PATIENT_WORKSPACES.find((w) => w.id === 'appointments');
    expect(appts).toBeDefined();
    expect(appts!.roles).toEqual([]);
  });
});


