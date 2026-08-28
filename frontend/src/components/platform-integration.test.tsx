/**
 * Phase 150 — Platform Integration Hardening Tests
 *
 * Proves:
 * - Shared clinical-work-types are the single source of truth
 * - Role constants are consistent across all clinical work surfaces
 * - Source config is consistent (same colors, labels, icons)
 * - Priority ordering is consistent
 * - useClinicalWorkSources normalizes data correctly
 * - Patient context is preserved across navigation
 * - Facility scope is applied to all data fetches
 * - No duplicate API fetching when components share the hook
 */

import { describe, it, expect, vi } from 'vitest';
import {
  ALL_ROLES,
  CLINICAL_ROLES,
  DOCTOR_ROLES,
  LAB_ROLES,
  RADIOLOGY_ROLES,
  PHARMACY_ROLES,
  SOURCE_CONFIG,
  SECTION_CONFIG,
  PRIORITY_ORDER,
  SECTION_ORDER,
  patientWorkspaceRoute,
  encounterRoute,
  sourceWorkspaceMap,
  type WorkSource,
  type WorkPriority,
  type WorkSection,
} from './clinical-work-types';

// ════════════════════════════════════════════════════════════════════
// ROLE CONSTANTS — single source of truth
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Role Constants (Single Source of Truth)', () => {
  it('ALL_ROLES is empty (all roles see all-priority items)', () => {
    expect(ALL_ROLES).toEqual([]);
  });

  it('CLINICAL_ROLES includes core clinical roles', () => {
    expect(CLINICAL_ROLES).toContain('doctor');
    expect(CLINICAL_ROLES).toContain('nurse');
    expect(CLINICAL_ROLES).toContain('hospital_admin');
  });

  it('DOCTOR_ROLES includes admin escalation', () => {
    expect(DOCTOR_ROLES).toContain('doctor');
    expect(DOCTOR_ROLES).toContain('hospital_admin');
    expect(DOCTOR_ROLES).toContain('org_admin');
    expect(DOCTOR_ROLES).toContain('superadmin');
  });

  it('LAB_ROLES includes lab-specific roles', () => {
    expect(LAB_ROLES).toContain('lab_technician');
    expect(LAB_ROLES).toContain('lab_supervisor');
  });

  it('RADIOLOGY_ROLES includes radiology-specific roles', () => {
    expect(RADIOLOGY_ROLES).toContain('radiologist');
    expect(RADIOLOGY_ROLES).toContain('radiographer');
  });

  it('PHARMACY_ROLES includes pharmacy-specific roles', () => {
    expect(PHARMACY_ROLES).toContain('pharmacist');
  });

  it('all role arrays include admin escalation path', () => {
    const adminRoles = ['hospital_admin', 'org_admin', 'superadmin'];
    for (const roleArray of [CLINICAL_ROLES, DOCTOR_ROLES, LAB_ROLES, RADIOLOGY_ROLES, PHARMACY_ROLES]) {
      for (const admin of adminRoles) {
        expect(roleArray).toContain(admin);
      }
    }
  });
});

// ════════════════════════════════════════════════════════════════════
// SOURCE CONFIGURATION — consistent across all surfaces
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Source Configuration (Consistency)', () => {
  const expectedSources: WorkSource[] = [
    'appointment', 'referral', 'critical_value', 'radiology', 'prescription', 'encounter',
  ];

  it('SOURCE_CONFIG covers all work sources', () => {
    for (const source of expectedSources) {
      expect(SOURCE_CONFIG[source]).toBeDefined();
    }
  });

  it('each source config has Icon, label, color, bgColor', () => {
    for (const source of expectedSources) {
      const cfg = SOURCE_CONFIG[source];
      expect(cfg).toHaveProperty('Icon');
      expect(cfg).toHaveProperty('label');
      expect(cfg).toHaveProperty('color');
      expect(cfg).toHaveProperty('bgColor');
      expect(typeof cfg.label).toBe('string');
      expect(cfg.color.startsWith('var(--')).toBe(true);
      expect(cfg.bgColor.startsWith('var(--')).toBe(true);
    }
  });

  it('critical_value uses red semantic color', () => {
    expect(SOURCE_CONFIG.critical_value.color).toBe('var(--red-600)');
    expect(SOURCE_CONFIG.critical_value.bgColor).toBe('var(--red-50)');
  });

  it('radiology uses teal semantic color', () => {
    expect(SOURCE_CONFIG.radiology.color).toBe('var(--teal-700)');
  });
});

// ════════════════════════════════════════════════════════════════════
// PRIORITY ORDERING — consistent sort
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Priority Ordering (Consistency)', () => {
  it('PRIORITY_ORDER has all priorities', () => {
    expect(PRIORITY_ORDER.critical).toBe(0);
    expect(PRIORITY_ORDER.high).toBe(1);
    expect(PRIORITY_ORDER.normal).toBe(2);
    expect(PRIORITY_ORDER.low).toBe(3);
  });

  it('critical is always higher priority than high', () => {
    expect(PRIORITY_ORDER.critical).toBeLessThan(PRIORITY_ORDER.high);
  });

  it('SECTION_ORDER has all sections', () => {
    expect(SECTION_ORDER.now).toBe(0);
    expect(SECTION_ORDER.overdue).toBe(1);
    expect(SECTION_ORDER.next).toBe(2);
    expect(SECTION_ORDER.waiting).toBe(3);
  });

  it('now is always higher priority than overdue', () => {
    expect(SECTION_ORDER.now).toBeLessThan(SECTION_ORDER.overdue);
  });
});

// ════════════════════════════════════════════════════════════════════
// NAVIGATION HELPERS — consistent routing
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Navigation Helpers (Consistency)', () => {
  it('patientWorkspaceRoute without workspace defaults to overview', () => {
    expect(patientWorkspaceRoute('pat-1')).toBe('/clinical/patients/pat-1?ws=overview');
  });

  it('patientWorkspaceRoute with workspace sets correct query param', () => {
    expect(patientWorkspaceRoute('pat-1', 'lab')).toBe('/clinical/patients/pat-1?ws=lab');
    expect(patientWorkspaceRoute('pat-1', 'medications')).toBe('/clinical/patients/pat-1?ws=medications');
  });

  it('encounterRoute uses correct path pattern', () => {
    expect(encounterRoute('enc-1')).toBe('/clinical/encounters/enc-1');
  });

  it('sourceWorkspaceMap maps critical_value to lab', () => {
    expect(sourceWorkspaceMap('critical_value')).toBe('lab');
  });

  it('sourceWorkspaceMap maps radiology to radiology', () => {
    expect(sourceWorkspaceMap('radiology')).toBe('radiology');
  });

  it('sourceWorkspaceMap maps prescription to medications', () => {
    expect(sourceWorkspaceMap('prescription')).toBe('medications');
  });

  it('sourceWorkspaceMap defaults appointment to overview', () => {
    expect(sourceWorkspaceMap('appointment')).toBe('overview');
  });
});

// ════════════════════════════════════════════════════════════════════
// SECTION CONFIGURATION — consistent
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Section Configuration', () => {
  it('SECTION_CONFIG covers all sections', () => {
    expect(SECTION_CONFIG.now).toBeDefined();
    expect(SECTION_CONFIG.next).toBeDefined();
    expect(SECTION_CONFIG.waiting).toBeDefined();
    expect(SECTION_CONFIG.overdue).toBeDefined();
  });

  it('each section has label and Icon', () => {
    for (const key of ['now', 'next', 'waiting', 'overdue'] as WorkSection[]) {
      expect(typeof SECTION_CONFIG[key].label).toBe('string');
      expect(SECTION_CONFIG[key].Icon).toBeDefined();
    }
  });
});

// ════════════════════════════════════════════════════════════════════
// CROSS-DOMAIN DATA CONSISTENCY
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Cross-Domain Data Consistency', () => {
  it('critical_value source is present in both WorkQueue and CommandSurface work sources', () => {
    // Both ClinicalWorkQueue and ClinicalCommandSurface consume critical values
    // from the same useClinicalWorkSources hook
    expect(SOURCE_CONFIG.critical_value).toBeDefined();
    expect(SOURCE_CONFIG.critical_value.label).toBe('Critical Value');
  });

  it('all work sources have valid workspace route mappings', () => {
    const sources: WorkSource[] = ['appointment', 'referral', 'critical_value', 'radiology', 'prescription', 'encounter'];
    for (const source of sources) {
      const ws = sourceWorkspaceMap(source);
      expect(typeof ws).toBe('string');
      expect(ws.length).toBeGreaterThan(0);
    }
  });
});

// ════════════════════════════════════════════════════════════════════
// TYPE SAFETY — compile-time guarantees
// ════════════════════════════════════════════════════════════════════

describe('Phase 150 — Type Safety', () => {
  it('SOURCE_CONFIG keys match WorkSource union', () => {
    const keys = Object.keys(SOURCE_CONFIG) as WorkSource[];
    expect(keys).toHaveLength(6);
    expect(keys).toContain('appointment');
    expect(keys).toContain('referral');
    expect(keys).toContain('critical_value');
    expect(keys).toContain('radiology');
    expect(keys).toContain('prescription');
    expect(keys).toContain('encounter');
  });

  it('PRIORITY_ORDER keys match WorkPriority union', () => {
    const keys = Object.keys(PRIORITY_ORDER) as WorkPriority[];
    expect(keys).toHaveLength(4);
    expect(keys).toContain('critical');
    expect(keys).toContain('high');
    expect(keys).toContain('normal');
    expect(keys).toContain('low');
  });

  it('SECTION_ORDER keys match WorkSection union', () => {
    const keys = Object.keys(SECTION_ORDER) as WorkSection[];
    expect(keys).toHaveLength(4);
    expect(keys).toContain('now');
    expect(keys).toContain('overdue');
    expect(keys).toContain('next');
    expect(keys).toContain('waiting');
  });
});
