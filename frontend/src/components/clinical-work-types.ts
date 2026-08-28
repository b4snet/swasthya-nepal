/**
 * clinical-work-types — Shared Clinical Work Integration Types (Phase 150)
 *
 * Single source of truth for:
 *   - Clinical role categories
 *   - Work priority taxonomy
 *   - Work source configuration
 *   - Work item derivation parameters
 *
 * Both ClinicalWorkQueue and ClinicalCommandSurface import from here.
 * This eliminates duplicated role arrays, source configs, and priority logic.
 *
 * Safety:
 *   - Presentation-only role filtering; backend remains authoritative
 *   - No clinical priority inference — only authoritative status
 *   - No mutation on load
 */

import type { LucideIcon } from 'lucide-react';
import {
  AlertTriangle,
  ArrowRight,
  Bell,
  CalendarDays,
  Clock,
  Pill,
  ScanLine,
  Stethoscope,
} from 'lucide-react';

/* ────────────────────────────────────────────────────────────────────
   ROLE CATEGORIES — canonical, single source of truth
   ──────────────────────────────────────────────────────────────────── */

export const ALL_ROLES: string[] = [];
export const CLINICAL_ROLES = ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'];
export const DOCTOR_ROLES = ['doctor', 'hospital_admin', 'org_admin', 'superadmin'];
export const LAB_ROLES = ['lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'];
export const RADIOLOGY_ROLES = ['radiologist', 'radiographer', 'hospital_admin', 'org_admin', 'superadmin'];
export const PHARMACY_ROLES = ['pharmacist', 'hospital_admin', 'org_admin', 'superadmin'];

/* ────────────────────────────────────────────────────────────────────
   WORK SOURCE TYPES
   ──────────────────────────────────────────────────────────────────── */

export type WorkSource =
  | 'appointment'
  | 'referral'
  | 'critical_value'
  | 'radiology'
  | 'prescription'
  | 'encounter';

/* ────────────────────────────────────────────────────────────────────
   PRIORITY TAXONOMY — unified across all clinical work surfaces
   ──────────────────────────────────────────────────────────────────── */

export type WorkPriority = 'critical' | 'high' | 'normal' | 'low';

export type WorkSection = 'now' | 'next' | 'waiting' | 'overdue';

/** Canonical priority ordering (lower = higher priority) */
export const PRIORITY_ORDER: Record<WorkPriority, number> = {
  critical: 0,
  high: 1,
  normal: 2,
  low: 3,
};

/** Canonical section ordering (lower = higher urgency) */
export const SECTION_ORDER: Record<WorkSection, number> = {
  now: 0,
  overdue: 1,
  next: 2,
  waiting: 3,
};

/* ────────────────────────────────────────────────────────────────────
   SOURCE CONFIGURATION — icons, labels, colors
   ──────────────────────────────────────────────────────────────────── */

export interface SourceConfig {
  Icon: LucideIcon;
  label: string;
  color: string;
  bgColor: string;
}

export const SOURCE_CONFIG: Record<WorkSource, SourceConfig> = {
  appointment: {
    Icon: CalendarDays,
    label: 'Appointment',
    color: 'var(--blue-600)',
    bgColor: 'var(--blue-50)',
  },
  referral: {
    Icon: ArrowRight,
    label: 'Referral',
    color: 'var(--violet-600)',
    bgColor: 'var(--violet-50)',
  },
  critical_value: {
    Icon: AlertTriangle,
    label: 'Critical Value',
    color: 'var(--red-600)',
    bgColor: 'var(--red-50)',
  },
  radiology: {
    Icon: ScanLine,
    label: 'Radiology',
    color: 'var(--teal-700)',
    bgColor: 'var(--teal-50)',
  },
  prescription: {
    Icon: Pill,
    label: 'Prescription',
    color: 'var(--amber-600)',
    bgColor: 'var(--amber-50)',
  },
  encounter: {
    Icon: Stethoscope,
    label: 'Encounter',
    color: 'var(--teal-700)',
    bgColor: 'var(--teal-50)',
  },
};

/* ────────────────────────────────────────────────────────────────────
   SECTION CONFIGURATION
   ──────────────────────────────────────────────────────────────────── */

export interface SectionConfig {
  label: string;
  Icon: LucideIcon;
}

export const SECTION_CONFIG: Record<WorkSection, SectionConfig> = {
  now: { label: 'Needs Attention', Icon: Bell },
  next: { label: 'Next', Icon: ArrowRight },
  waiting: { label: 'Waiting', Icon: Clock },
  overdue: { label: 'Overdue', Icon: AlertTriangle },
};

/* ────────────────────────────────────────────────────────────────────
   NAVIGATION HELPERS — consistent routing across surfaces
   ──────────────────────────────────────────────────────────────────── */

/** Build patient workspace route with optional workspace switch */
export function patientWorkspaceRoute(
  patientId: string,
  workspace?: string,
): string {
  return workspace
    ? `/clinical/patients/${patientId}?ws=${workspace}`
    : `/clinical/patients/${patientId}?ws=overview`;
}

/** Build encounter route */
export function encounterRoute(encounterId: string): string {
  return `/clinical/encounters/${encounterId}`;
}

/** Map work source to default patient workspace */
export function sourceWorkspaceMap(source: WorkSource): string {
  switch (source) {
    case 'radiology':
      return 'radiology';
    case 'critical_value':
      return 'lab';
    case 'prescription':
      return 'medications';
    case 'encounter':
      return 'encounters';
    default:
      return 'overview';
  }
}
