import { describe, expect, it } from 'vitest';

// ── Encounter status mapping (extracted from EncounterWorkspace) ──
function encounterStatusInfo(status: string): { tone: string; label: string } {
  switch (status) {
    case 'open': return { tone: 'info', label: 'Open' };
    case 'in_progress': return { tone: 'warning', label: 'In Progress' };
    case 'signed': return { tone: 'success', label: 'Signed' };
    case 'amended': return { tone: 'warning', label: 'Amended' };
    case 'closed': return { tone: 'neutral', label: 'Closed' };
    default: return { tone: 'neutral', label: status };
  }
}

describe('EncounterWorkspace encounterStatusInfo', () => {
  it('maps open to info', () => {
    expect(encounterStatusInfo('open')).toEqual({ tone: 'info', label: 'Open' });
  });

  it('maps in_progress to warning', () => {
    expect(encounterStatusInfo('in_progress')).toEqual({ tone: 'warning', label: 'In Progress' });
  });

  it('maps signed to success', () => {
    expect(encounterStatusInfo('signed')).toEqual({ tone: 'success', label: 'Signed' });
  });

  it('maps amended to warning', () => {
    expect(encounterStatusInfo('amended')).toEqual({ tone: 'warning', label: 'Amended' });
  });

  it('maps closed to neutral', () => {
    expect(encounterStatusInfo('closed')).toEqual({ tone: 'neutral', label: 'Closed' });
  });

  it('maps unknown status to neutral with original text', () => {
    expect(encounterStatusInfo('unknown')).toEqual({ tone: 'neutral', label: 'unknown' });
  });
});

describe('EncounterWorkspace workspace definitions', () => {
  it('has overview workspace visible to all roles', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const overview = ENCOUNTER_WORKSPACES.find((w) => w.id === 'overview');
    expect(overview).toBeDefined();
    expect(overview!.roles).toEqual([]);
  });

  it('restricts clinical note to doctor roles', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const clinical = ENCOUNTER_WORKSPACES.find((w) => w.id === 'clinical');
    expect(clinical).toBeDefined();
    expect(clinical!.roles).toContain('doctor');
    expect(clinical!.roles).not.toContain('pharmacist');
  });

  it('restricts prescriptions to doctor/pharmacy roles', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const prescriptions = ENCOUNTER_WORKSPACES.find((w) => w.id === 'medications');
    expect(prescriptions).toBeDefined();
    expect(prescriptions!.roles).toContain('doctor');
    expect(prescriptions!.roles).toContain('pharmacist');
    expect(prescriptions!.roles).not.toContain('nurse');
  });

  it('restricts billing to finance roles', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const billing = ENCOUNTER_WORKSPACES.find((w) => w.id === 'billing');
    expect(billing).toBeDefined();
    expect(billing!.roles).toContain('billing_clerk');
    expect(billing!.roles).not.toContain('doctor');
  });

  it('restricts lab to clinical/lab roles', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const lab = ENCOUNTER_WORKSPACES.find((w) => w.id === 'lab');
    expect(lab).toBeDefined();
    expect(lab!.roles).toContain('doctor');
    expect(lab!.roles).toContain('lab_technician');
    expect(lab!.roles).not.toContain('billing_clerk');
  });

  it('has all workspaces with roles arrays', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    ENCOUNTER_WORKSPACES.forEach((ws) => {
      expect(Array.isArray(ws.roles)).toBe(true);
    });
  });

  it('has exactly 9 workspace definitions', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    expect(ENCOUNTER_WORKSPACES).toHaveLength(9);
  });

  it('has overview as first workspace', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    expect(ENCOUNTER_WORKSPACES[0].id).toBe('overview');
  });
});

describe('EncounterWorkspace role filtering logic', () => {
  function filterWorkspaces<T extends { roles: string[] }>(workspaces: T[], hasRole: (r: string) => boolean) {
    return workspaces.filter(
      (ws) => ws.roles.length === 0 || ws.roles.some((r) => hasRole(r)),
    );
  }

  it('shows all workspaces to superadmin', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const visible = filterWorkspaces(ENCOUNTER_WORKSPACES, (r) => r === 'superadmin');
    expect(visible).toHaveLength(ENCOUNTER_WORKSPACES.length);
  });

  it('shows only authorized workspaces to pharmacist', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const visible = filterWorkspaces(ENCOUNTER_WORKSPACES, (r) => r === 'pharmacist');
    // Pharmacist should see: overview (all), medications (pharmacist)
    // Pharmacist should NOT see: clinical (doctor only), lab (no pharmacist), billing (no pharmacist)
    const visibleIds = visible.map((w) => w.id);
    expect(visibleIds).toContain('overview');
    expect(visibleIds).toContain('medications');
    expect(visibleIds).not.toContain('clinical');
    expect(visibleIds).not.toContain('billing');
    expect(visibleIds.length).toBeLessThan(ENCOUNTER_WORKSPACES.length);
  });

  it('shows only authorized workspaces to nurse', async () => {
    const { ENCOUNTER_WORKSPACES } = await import('./EncounterWorkspace');
    const visible = filterWorkspaces(ENCOUNTER_WORKSPACES, (r) => r === 'nurse');
    // Nurse should see: overview, lab (has nurse), referrals (has nurse), followup (has nurse)
    // Nurse should NOT see: clinical (doctor only), medications (doctor/pharmacist), billing (finance)
    const visibleIds = visible.map((w) => w.id);
    expect(visibleIds).toContain('overview');
    expect(visibleIds).toContain('lab');
    expect(visibleIds).toContain('referrals');
    expect(visibleIds).toContain('followup');
    expect(visibleIds).not.toContain('clinical');
    expect(visibleIds).not.toContain('medications');
    expect(visibleIds).not.toContain('billing');
  });
});
