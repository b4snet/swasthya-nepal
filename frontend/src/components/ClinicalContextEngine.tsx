/**
 * ClinicalContextEngine — Adaptive Workspace Orchestration (Phase 119)
 *
 * Deterministic context resolution: given the current user, patient,
 * encounter, role, route, permissions, and workflow state, determine
 * which existing workspace surfaces should be emphasized.
 *
 * NOT AI. NOT autonomous clinical decision-making.
 * A deterministic orchestration layer that combines existing data.
 *
 * Context priority:
 *   Patient → Encounter → Clinical State → Urgency → Role → Permissions → Route
 *
 * Safety:
 * - Never diagnose, prescribe, or modify treatment
 * - Never automatically acknowledge critical results
 * - Never bypass backend authorization
 * - Never create new clinical facts
 * - Only consume authoritative existing data
 */

import { useMemo } from 'react';
import {
  Stethoscope,
  Pill,
  FlaskConical,
  ClipboardList,
  Bed,
  AlertTriangle,
  Clock,
} from 'lucide-react';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

export type ClinicalUrgency = 'routine' | 'attention' | 'urgent' | 'critical';
export type EncounterContext = 'none' | 'opd' | 'ipd' | 'emergency' | 'nursing' | 'diagnostic';

export interface ClinicalContext {
  /** Patient is active */
  hasPatient: boolean;
  /** Active encounter */
  encounterContext: EncounterContext;
  /** Number of active encounters */
  activeEncounters: number;
  /** Is patient admitted */
  isAdmitted: boolean;
  /** Active diagnoses count */
  activeDiagnoses: number;
  /** Active prescriptions count */
  activePrescriptions: number;
  /** Pending lab orders */
  pendingLabs: number;
  /** Critical items (results, alerts) */
  criticalItems: number;
  /** Pending tasks */
  pendingTasks: number;
  /** User role */
  userRole: string;
  /** Overall urgency */
  urgency: ClinicalUrgency;
}

export interface WorkspacePriority {
  id: string;
  priority: number;
  urgency: ClinicalUrgency;
  reason: string;
}

export interface ContextualAction {
  id: string;
  label: string;
  icon: React.ReactNode;
  route: string;
  urgency: ClinicalUrgency;
  reason: string;
}

/* ────────────────────────────────────────────────────────────────────
   CONTEXT RESOLUTION
   ──────────────────────────────────────────────────────────────────── */

/**
 * Resolve clinical context from patient data.
 * Deterministic. No inference. No AI.
 */
export function resolveClinicalContext(params: {
  patient: any;
  encounters: any[];
  diagnoses: any[];
  prescriptions: any[];
  labOrders: any[];
  admissions: any[];
  userRole: string;
}): ClinicalContext {
  const { patient, encounters, diagnoses, prescriptions, labOrders, admissions, userRole } = params;

  const activeEncounters = (encounters || []).filter((e: any) => e.status === 'open');
  const isAdmitted = (admissions || []).some((a: any) => !a.dischargedAt);
  const activeDiagnoses = (diagnoses || []).filter((d: any) => d.status === 'active');
  const activePrescriptions = (prescriptions || []).filter((p: any) => p.status === 'active');
  const pendingLabs = (labOrders || []).filter((o: any) => !['reported', 'verified'].includes(o.status));

  // Determine encounter context from active encounter type
  let encounterContext: EncounterContext = 'none';
  if (activeEncounters.length > 0) {
    const primaryType = activeEncounters[0].type?.toLowerCase() || '';
    if (primaryType.includes('emergency') || primaryType.includes('er')) {
      encounterContext = 'emergency';
    } else if (primaryType.includes('inpatient') || primaryType.includes('ipd') || isAdmitted) {
      encounterContext = 'ipd';
    } else if (primaryType.includes('nursing')) {
      encounterContext = 'nursing';
    } else if (primaryType.includes('lab') || primaryType.includes('radiology') || primaryType.includes('diagnostic')) {
      encounterContext = 'diagnostic';
    } else {
      encounterContext = 'opd';
    }
  } else if (isAdmitted) {
    encounterContext = 'ipd';
  }

  // Count critical items (lab orders with priority or status)
  const criticalItemCount = (labOrders || []).filter(
    (o: any) => o.priority === 'stat' || o.priority === 'critical' || o.status === 'critical',
  ).length;

  // Determine urgency
  let urgency: ClinicalUrgency = 'routine';
  if (encounterContext === 'emergency') {
    urgency = 'critical';
  } else if (criticalItemCount > 0 || pendingLabs.length > 3) {
    urgency = 'urgent';
  } else if (activeEncounters.length > 0 || pendingLabs.length > 0) {
    urgency = 'attention';
  }

  // Count pending tasks (admissions without discharge, encounters without notes)
  const pendingTasks = activeEncounters.length + (isAdmitted ? 1 : 0);

  return {
    hasPatient: !!patient,
    encounterContext,
    activeEncounters: activeEncounters.length,
    isAdmitted,
    activeDiagnoses: activeDiagnoses.length,
    activePrescriptions: activePrescriptions.length,
    pendingLabs: pendingLabs.length,
    criticalItems: criticalItemCount,
    pendingTasks,
    userRole,
    urgency,
  };
}

/* ────────────────────────────────────────────────────────────────────
   WORKSPACE PRIORITY RESOLUTION
   ──────────────────────────────────────────────────────────────────── */

/**
 * Determine workspace priorities based on clinical context.
 * Deterministic mapping from context to workspace emphasis.
 */
export function resolveWorkspacePriorities(
  context: ClinicalContext,
): WorkspacePriority[] {
  const priorities: WorkspacePriority[] = [];

  // Base priorities for all contexts
  const base: { id: string; basePriority: number; urgency: ClinicalUrgency; reason: string }[] = [
    { id: 'quickview', basePriority: 100, urgency: 'routine', reason: 'Clinical orientation' },
    { id: 'overview', basePriority: 90, urgency: 'routine', reason: 'Patient status' },
    { id: 'encounters', basePriority: 70, urgency: 'routine', reason: 'Clinical visits' },
    { id: 'timeline', basePriority: 60, urgency: 'routine', reason: 'Clinical history' },
    { id: 'diagnoses', basePriority: 65, urgency: 'routine', reason: 'Active problems' },
    { id: 'medications', basePriority: 68, urgency: 'routine', reason: 'Active prescriptions' },
    { id: 'lab', basePriority: 66, urgency: 'routine', reason: 'Lab orders' },
    { id: 'radiology', basePriority: 64, urgency: 'routine', reason: 'Imaging' },
    { id: 'admissions', basePriority: 55, urgency: 'routine', reason: 'Inpatient status' },
    { id: 'referrals', basePriority: 50, urgency: 'routine', reason: 'Referrals' },
    { id: 'appointments', basePriority: 52, urgency: 'routine', reason: 'Scheduling' },
    { id: 'documents', basePriority: 48, urgency: 'routine', reason: 'Documents' },
    { id: 'communication', basePriority: 45, urgency: 'routine', reason: 'Messages' },
    { id: 'careteam', basePriority: 40, urgency: 'routine', reason: 'Care team' },
    { id: 'journey', basePriority: 42, urgency: 'routine', reason: 'Journey' },
    { id: 'loops', basePriority: 58, urgency: 'routine', reason: 'Open loops' },
  ];

  for (const item of base) {
    let priority = item.basePriority;
    let urgency = item.urgency;
    let reason = item.reason;

    // ── Encounter context adjustments ──
    if (context.encounterContext === 'emergency') {
      if (item.id === 'encounters' || item.id === 'lab' || item.id === 'medications') {
        priority += 40;
        urgency = 'critical';
        reason = 'Emergency encounter active';
      }
    }

    if (context.encounterContext === 'ipd') {
      if (item.id === 'admissions' || item.id === 'medications') {
        priority += 30;
        urgency = 'urgent';
        reason = 'Inpatient admission active';
      }
    }

    if (context.encounterContext === 'nursing') {
      if (item.id === 'medications' || item.id === 'loops') {
        priority += 25;
        urgency = 'attention';
        reason = 'Nursing workflow active';
      }
    }

    // ── Clinical state adjustments ──
    if (context.pendingLabs > 0 && item.id === 'lab') {
      priority += 15;
      urgency = 'attention';
      reason = `${context.pendingLabs} pending lab result${context.pendingLabs > 1 ? 's' : ''}`;
    }

    if (context.criticalItems > 0 && item.id === 'lab') {
      priority += 35;
      urgency = 'urgent';
      reason = `${context.criticalItems} critical value${context.criticalItems > 1 ? 's' : ''}`;
    }

    if (context.activePrescriptions > 0 && item.id === 'medications') {
      priority += 10;
      reason = `${context.activePrescriptions} active prescription${context.activePrescriptions > 1 ? 's' : ''}`;
    }

    if (context.activeDiagnoses > 0 && item.id === 'diagnoses') {
      priority += 10;
      reason = `${context.activeDiagnoses} active diagnosis${context.activeDiagnoses > 1 ? 'es' : ''}`;
    }

    if (context.isAdmitted && item.id === 'admissions') {
      priority += 20;
      urgency = 'attention';
      reason = 'Patient currently admitted';
    }

    // ── Role adjustments ──
    if (context.userRole === 'nurse') {
      if (item.id === 'medications' || item.id === 'loops') {
        priority += 5;
      }
    }

    if (context.userRole === 'pharmacist') {
      if (item.id === 'medications') {
        priority += 15;
        reason = 'Pharmacy role — medication focus';
      }
    }

    if (context.userRole === 'lab_technician' || context.userRole === 'lab_supervisor') {
      if (item.id === 'lab') {
        priority += 15;
        reason = 'Lab role — results focus';
      }
    }

    priorities.push({ id: item.id, priority, urgency, reason });
  }

  // Sort by priority (highest first)
  priorities.sort((a, b) => b.priority - a.priority);

  return priorities;
}

/* ────────────────────────────────────────────────────────────────────
   CONTEXTUAL ACTION RESOLUTION
   ──────────────────────────────────────────────────────────────────── */

/**
 * Determine contextual actions based on clinical context.
 * Shows the most relevant next actions for the current situation.
 */
export function resolveContextualActions(
  context: ClinicalContext,
  patientId: string,
): ContextualAction[] {
  const actions: ContextualAction[] = [];

  // ── Emergency context ──
  if (context.encounterContext === 'emergency') {
    actions.push({
      id: 'emergency-encounter',
      label: 'Emergency Encounter',
      icon: <Stethoscope size={14} />,
      route: `/clinical/encounters?patientId=${patientId}`,
      urgency: 'critical',
      reason: 'Emergency encounter active',
    });
  }

  // ── Pending labs ──
  if (context.pendingLabs > 0) {
    actions.push({
      id: 'review-labs',
      label: `Review ${context.pendingLabs} Lab${context.pendingLabs > 1 ? 's' : ''}`,
      icon: <FlaskConical size={14} />,
      route: `/clinical/patients/${patientId}?ws=lab`,
      urgency: context.criticalItems > 0 ? 'urgent' : 'attention',
      reason: context.criticalItems > 0 ? 'Critical results pending' : 'Lab results available',
    });
  }

  // ── Active prescriptions ──
  if (context.activePrescriptions > 0) {
    actions.push({
      id: 'medications',
      label: `${context.activePrescriptions} Active Medication${context.activePrescriptions > 1 ? 's' : ''}`,
      icon: <Pill size={14} />,
      route: `/clinical/patients/${patientId}?ws=medications`,
      urgency: 'routine',
      reason: 'Active prescriptions',
    });
  }

  // ── Active diagnoses ──
  if (context.activeDiagnoses > 0) {
    actions.push({
      id: 'diagnoses',
      label: `${context.activeDiagnoses} Active Diagnosis${context.activeDiagnoses > 1 ? 'es' : ''}`,
      icon: <ClipboardList size={14} />,
      route: `/clinical/patients/${patientId}?ws=diagnoses`,
      urgency: 'routine',
      reason: 'Active problems',
    });
  }

  // ── Open loops ──
  if (context.pendingTasks > 0) {
    actions.push({
      id: 'open-loops',
      label: `${context.pendingTasks} Open Loop${context.pendingTasks > 1 ? 's' : ''}`,
      icon: <AlertTriangle size={14} />,
      route: `/clinical/patients/${patientId}?ws=loops`,
      urgency: 'attention',
      reason: 'Pending clinical follow-through',
    });
  }

  // ── Admitted ──
  if (context.isAdmitted) {
    actions.push({
      id: 'admission',
      label: 'Current Admission',
      icon: <Bed size={14} />,
      route: `/clinical/patients/${patientId}?ws=admissions`,
      urgency: 'attention',
      reason: 'Patient currently admitted',
    });
  }

  // ── Quick clinical actions ──
  actions.push({
    id: 'new-encounter',
    label: 'New Encounter',
    icon: <Stethoscope size={14} />,
    route: `/clinical/encounters?patientId=${patientId}`,
    urgency: 'routine',
    reason: 'Start new clinical encounter',
  });

  actions.push({
    id: 'timeline',
    label: 'Timeline',
    icon: <Clock size={14} />,
    route: `/clinical/patients/${patientId}?ws=timeline`,
    urgency: 'routine',
    reason: 'View clinical history',
  });

  // Sort by urgency then priority
  const urgencyOrder: Record<ClinicalUrgency, number> = { critical: 0, urgent: 1, attention: 2, routine: 3 };
  actions.sort((a, b) => urgencyOrder[a.urgency] - urgencyOrder[b.urgency]);

  // Limit to top 6 most relevant
  return actions.slice(0, 6);
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CONTEXT ENGINE COMPONENT
   ──────────────────────────────────────────────────────────────────── */

export interface ClinicalContextEngineProps {
  patient: any;
  encounters: any[];
  diagnoses: any[];
  prescriptions: any[];
  labOrders: any[];
  admissions: any[];
  userRole: string;
  children: (context: {
    clinicalContext: ClinicalContext;
    workspacePriorities: WorkspacePriority[];
    contextualActions: ContextualAction[];
  }) => React.ReactNode;
}

/**
 * ClinicalContextEngine — wraps children with resolved clinical context.
 * Provides deterministic context resolution for adaptive workspace orchestration.
 */
export function ClinicalContextEngine({
  patient,
  encounters,
  diagnoses,
  prescriptions,
  labOrders,
  admissions,
  userRole,
  children,
}: ClinicalContextEngineProps) {
  const clinicalContext = useMemo(
    () => resolveClinicalContext({ patient, encounters, diagnoses, prescriptions, labOrders, admissions, userRole }),
    [patient, encounters, diagnoses, prescriptions, labOrders, admissions, userRole],
  );

  const workspacePriorities = useMemo(
    () => resolveWorkspacePriorities(clinicalContext),
    [clinicalContext],
  );

  const contextualActions = useMemo(
    () => resolveContextualActions(clinicalContext, patient?.id || ''),
    [clinicalContext, patient?.id],
  );

  return (
    <>
      {children({ clinicalContext, workspacePriorities, contextualActions })}
    </>
  );
}

export default ClinicalContextEngine;
