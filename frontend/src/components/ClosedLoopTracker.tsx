/**
 * ClosedLoopTracker — Closed-Loop Clinical Safety (Phase 107)
 *
 * Answers: "DID THE IMPORTANT THING ACTUALLY GET FINISHED?"
 *
 * Architecture: CANONICAL SYSTEMS OF RECORD + CONTEXTUAL ORCHESTRATION + HUMAN AUTHORITY
 *
 * This is NOT a new workflow engine.
 * This is NOT a new order system.
 * This is NOT a new result system.
 *
 * This is a COORDINATION VIEW over existing canonical data that identifies:
 * - What was requested?
 * - Did it happen?
 * - What was the result?
 * - Who must review it?
 * - Was it acknowledged?
 * - Does it require action?
 * - Was the action completed?
 *
 * Safety boundary: This component may coordinate deterministic follow-through.
 * It must NOT independently determine diagnosis, treatment, or clinical urgency.
 */

import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi } from '../api/endpoints';
import type { Encounter } from '../api/types';
import {
  EmptyState,
  StatusChip,
  formatDateTime,
} from './ui';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  FlaskConical,
  Pill,
  ScanLine,
  Stethoscope,
  ArrowRight,
} from 'lucide-react';
import './closed-loop.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type LoopDomain = 'lab' | 'radiology' | 'pharmacy' | 'referral' | 'encounter' | 'follow_up';
type LoopStatus = 'open' | 'in_progress' | 'awaiting_result' | 'awaiting_review' | 'overdue' | 'blocked' | 'completed' | 'cancelled';
type LoopPriority = 'critical' | 'high' | 'normal' | 'low';

interface OpenLoop {
  id: string;
  domain: LoopDomain;
  status: LoopStatus;
  priority: LoopPriority;
  label: string;
  description: string;
  /** Current workflow step */
  currentStep: string;
  /** What is the NEXT required step */
  nextStep: string;
  /** Who owns the next step (role or name) */
  nextOwner: string;
  /** When was this initiated */
  createdAt: string;
  /** Due time if applicable */
  dueAt?: string;
  /** Patient context */
  patientId: string;
  patientName: string;
  patientMrn?: string;
  /** Encounter context */
  encounterId?: string;
  /** Route to the relevant workspace */
  actionTo: string;
  /** Source canonical ID */
  sourceId: string;
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE OPEN LOOPS FROM CANONICAL DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveOpenLoops(
  encounters: Encounter[],
  labOrders: any[],
  prescriptions: any[],
  patientName: string,
  patientId: string,
  patientMrn?: string,
): OpenLoop[] {
  const loops: OpenLoop[] = [];

  // ── Lab Order Loops ──
  for (const order of labOrders) {
    const status = order.status?.toLowerCase() ?? '';
    let loopStatus: LoopStatus;
    let currentStep: string;
    let nextStep: string;
    let nextOwner: string;
    let priority: LoopPriority = 'normal';

    if (status === 'ordered' || status === 'pending') {
      loopStatus = 'open';
      currentStep = 'Order placed';
      nextStep = 'Specimen collection';
      nextOwner = 'Laboratory';
    } else if (status === 'collected') {
      loopStatus = 'in_progress';
      currentStep = 'Specimen collected';
      nextStep = 'Processing';
      nextOwner = 'Laboratory';
    } else if (status === 'processing') {
      loopStatus = 'in_progress';
      currentStep = 'Processing';
      nextStep = 'Results entry';
      nextOwner = 'Laboratory';
    } else if (status === 'results_entered' || status === 'resulted') {
      loopStatus = 'awaiting_review';
      currentStep = 'Results available';
      nextStep = 'Verification';
      nextOwner = 'Laboratory Supervisor';
      priority = 'high';
    } else if (status === 'verified' || status === 'reported') {
      loopStatus = 'awaiting_review';
      currentStep = status === 'verified' ? 'Results verified' : 'Results reported';
      nextStep = 'Clinician review';
      nextOwner = 'Ordering Provider';
      priority = 'high';
    } else if (status === 'cancelled') {
      loopStatus = 'cancelled';
      currentStep = 'Cancelled';
      nextStep = '—';
      nextOwner = '—';
    } else {
      continue; // Skip unknown states
    }

    if (loopStatus === 'cancelled') continue;

    loops.push({
      id: `lab-${order.id}`,
      domain: 'lab',
      status: loopStatus,
      priority,
      label: order.testName ?? order.name ?? 'Lab Order',
      description: `Priority: ${order.priority ?? 'routine'} · Status: ${status}`,
      currentStep,
      nextStep,
      nextOwner,
      createdAt: order.createdAt ?? new Date().toISOString(),
      patientId,
      patientName,
      patientMrn,
      encounterId: order.encounterId,
      actionTo: `/clinical/patients/${patientId}?ws=lab`,
      sourceId: order.id,
    });
  }

  // ── Prescription Loops ──
  for (const rx of prescriptions) {
    const status = rx.status?.toLowerCase() ?? '';
    let loopStatus: LoopStatus;
    let currentStep: string;
    let nextStep: string;
    let nextOwner: string;
    let priority: LoopPriority = 'normal';

    if (status === 'pending' || status === 'draft') {
      loopStatus = 'open';
      currentStep = 'Prescription created';
      nextStep = 'Verification';
      nextOwner = 'Pharmacy';
    } else if (status === 'active' || status === 'verified') {
      loopStatus = 'in_progress';
      currentStep = status === 'verified' ? 'Verified' : 'Active';
      nextStep = 'Dispensing';
      nextOwner = 'Pharmacy';
    } else if (status === 'dispensed') {
      loopStatus = 'completed';
      currentStep = 'Dispensed';
      nextStep = '—';
      nextOwner = '—';
    } else if (status === 'cancelled') {
      loopStatus = 'cancelled';
      currentStep = 'Cancelled';
      nextStep = '—';
      nextOwner = '—';
    } else {
      continue;
    }

    if (loopStatus === 'cancelled' || loopStatus === 'completed') continue;

    loops.push({
      id: `rx-${rx.id}`,
      domain: 'pharmacy',
      status: loopStatus,
      priority,
      label: rx.medicationName ?? rx.medication?.name ?? 'Prescription',
      description: `${rx.dosage ?? ''} ${rx.frequency ?? ''}`.trim() || 'Prescription',
      currentStep,
      nextStep,
      nextOwner,
      createdAt: rx.createdAt ?? new Date().toISOString(),
      patientId,
      patientName,
      patientMrn,
      encounterId: rx.encounterId,
      actionTo: `/clinical/patients/${patientId}?ws=medications`,
      sourceId: rx.id,
    });
  }

  // ── Active Encounter Loops (open encounters that need closure) ──
  for (const enc of encounters) {
    const status = enc.status?.toLowerCase() ?? '';
    if (status === 'signed' || status === 'completed' || status === 'cancelled') continue;

    let loopStatus: LoopStatus;
    let currentStep: string;
    let nextStep: string;
    let nextOwner: string;

    if (status === 'open') {
      loopStatus = 'open';
      currentStep = 'Encounter opened';
      nextStep = 'Documentation';
      nextOwner = enc.provider?.fullName ?? 'Provider';
    } else if (status === 'in_progress') {
      loopStatus = 'in_progress';
      currentStep = 'In progress';
      nextStep = 'Completion & signing';
      nextOwner = enc.provider?.fullName ?? 'Provider';
    } else {
      loopStatus = 'open';
      currentStep = `Status: ${status}`;
      nextStep = 'Completion';
      nextOwner = enc.provider?.fullName ?? 'Provider';
    }

    loops.push({
      id: `enc-${enc.id}`,
      domain: 'encounter',
      status: loopStatus,
      priority: 'normal',
      label: `${enc.type} Encounter`,
      description: `Provider: ${enc.provider?.fullName ?? 'Unknown'}`,
      currentStep,
      nextStep,
      nextOwner,
      createdAt: enc.startedAt ?? new Date().toISOString(),
      patientId,
      patientName,
      patientMrn,
      encounterId: enc.id,
      actionTo: `/clinical/encounters/${enc.id}`,
      sourceId: enc.id,
    });
  }

  // ── Sort by priority then status ──
  const priorityOrder: Record<LoopPriority, number> = { critical: 0, high: 1, normal: 2, low: 3 };
  const statusOrder: Record<LoopStatus, number> = {
    blocked: 0, overdue: 1, awaiting_review: 2, open: 3, in_progress: 4,
    awaiting_result: 5, completed: 6, cancelled: 7,
  };

  loops.sort((a, b) => {
    const pd = priorityOrder[a.priority] - priorityOrder[b.priority];
    if (pd !== 0) return pd;
    return statusOrder[a.status] - statusOrder[b.status];
  });

  return loops;
}

/* ────────────────────────────────────────────────────────────────────
   DOMAIN ICON + LABEL
   ──────────────────────────────────────────────────────────────────── */

const DOMAIN_CONFIG: Record<LoopDomain, { icon: React.ReactNode; label: string; color: string }> = {
  lab: { icon: <FlaskConical size={14} />, label: 'Laboratory', color: 'var(--teal-700)' },
  radiology: { icon: <ScanLine size={14} />, label: 'Radiology', color: 'var(--violet-600)' },
  pharmacy: { icon: <Pill size={14} />, label: 'Pharmacy', color: 'var(--amber-600)' },
  referral: { icon: <ArrowRight size={14} />, label: 'Referral', color: 'var(--blue-600)' },
  encounter: { icon: <Stethoscope size={14} />, label: 'Encounter', color: 'var(--teal-700)' },
  follow_up: { icon: <Clock size={14} />, label: 'Follow-up', color: 'var(--pink-600)' },
};

/* ────────────────────────────────────────────────────────────────────
   STATUS CONFIG
   ──────────────────────────────────────────────────────────────────── */

function statusTone(status: LoopStatus): 'success' | 'info' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'completed': return 'success';
    case 'in_progress': return 'info';
    case 'awaiting_review': return 'warning';
    case 'open': return 'info';
    case 'awaiting_result': return 'neutral';
    case 'overdue': return 'danger';
    case 'blocked': return 'danger';
    case 'cancelled': return 'neutral';
  }
}

/* ────────────────────────────────────────────────────────────────────
   LOOP CARD
   ──────────────────────────────────────────────────────────────────── */

function LoopCard({
  loop,
  onClick,
}: {
  loop: OpenLoop;
  onClick: () => void;
}) {
  const domain = DOMAIN_CONFIG[loop.domain];

  return (
    <button
      type="button"
      className={`loop-card loop-card--${loop.status} ${loop.priority === 'critical' || loop.priority === 'high' ? 'loop-card--elevated' : ''}`}
      onClick={onClick}
      aria-label={`${loop.label}: ${loop.currentStep}, next: ${loop.nextStep}`}
      data-testid={`loop-${loop.id}`}
    >
      <div className="loop-card__header">
        <span className="loop-card__domain" style={{ color: domain.color }}>
          {domain.icon}
          {domain.label}
        </span>
        <StatusChip tone={statusTone(loop.status)} label={loop.status.replace(/_/g, ' ')} />
      </div>

      <div className="loop-card__body">
        <span className="loop-card__label">{loop.label}</span>
        <span className="loop-card__desc">{loop.description}</span>
      </div>

      <div className="loop-card__workflow">
        <div className="loop-card__step">
          <span className="loop-card__step-label">Current</span>
          <span className="loop-card__step-value">{loop.currentStep}</span>
        </div>
        <ArrowRight size={12} className="loop-card__arrow" />
        <div className="loop-card__step">
          <span className="loop-card__step-label">Next</span>
          <span className="loop-card__step-value loop-card__step-value--action">{loop.nextStep}</span>
        </div>
      </div>

      <div className="loop-card__footer">
        <span className="loop-card__owner">
          <span className="loop-card__owner-label">Owner:</span> {loop.nextOwner}
        </span>
        <span className="loop-card__time">{formatDateTime(loop.createdAt)}</span>
      </div>
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLOSED-LOOP TRACKER
   ──────────────────────────────────────────────────────────────────── */

export function ClosedLoopTracker({ patientId }: { patientId: string }) {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();

  const patient = useFetch(
    () => patientsApi.show(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const encounters = useFetch(
    () => patientsApi.followUps(patientId, selectedFacilityId).catch(() => []),
    [patientId, selectedFacilityId],
  );

  const labOrders = useFetch(
    () => patientsApi.labOrders(patientId, selectedFacilityId).catch(() => []),
    [patientId, selectedFacilityId],
  );

  const prescriptions = useFetch(
    () => patientsApi.prescriptions(patientId, selectedFacilityId).catch(() => []),
    [patientId, selectedFacilityId],
  );

  const patientData = patient.data as any;
  const patientName = patientData?.fullName ?? 'Patient';
  const patientMrn = patientData?.mrn;

  const loops = useMemo(
    () => deriveOpenLoops(
      (encounters.data as any[]) ?? [],
      (labOrders.data as any[]) ?? [],
      (prescriptions.data as any[]) ?? [],
      patientName,
      patientId,
      patientMrn,
    ),
    [encounters.data, labOrders.data, prescriptions.data, patientName, patientId, patientMrn],
  );

  const openLoops = loops.filter((l) => l.status !== 'completed' && l.status !== 'cancelled');
  const criticalLoops = openLoops.filter((l) => l.priority === 'critical' || l.priority === 'high');
  const awaitingReview = openLoops.filter((l) => l.status === 'awaiting_review');

  const isLoading = patient.loading || encounters.loading || labOrders.loading || prescriptions.loading;

  if (isLoading) {
    return (
      <div className="loop-loading" role="status">
        <div className="spinner" />
        <span>Loading open loops…</span>
      </div>
    );
  }

  return (
    <div className="closed-loop" role="region" aria-label="Closed-loop clinical safety">
      {/* Summary stats */}
      <div className="loop-summary">
        <div className="loop-summary__stat">
          <span className="loop-summary__count">{openLoops.length}</span>
          <span className="loop-summary__label">Open</span>
        </div>
        <div className="loop-summary__stat loop-summary__stat--alert">
          <span className="loop-summary__count">{criticalLoops.length}</span>
          <span className="loop-summary__label">Needs attention</span>
        </div>
        <div className="loop-summary__stat">
          <span className="loop-summary__count">{awaitingReview.length}</span>
          <span className="loop-summary__label">Awaiting review</span>
        </div>
      </div>

      {/* Critical alert */}
      {criticalLoops.length > 0 && (
        <div className="loop-alert" role="alert">
          <AlertTriangle size={14} />
          <span>
            {criticalLoops.length} open loop{criticalLoops.length !== 1 ? 's' : ''} require{criticalLoops.length === 1 ? 's' : ''} attention
          </span>
        </div>
      )}

      {/* Loop list */}
      {openLoops.length === 0 ? (
        <EmptyState
          title="All loops closed"
          body="No open orders, pending results, or incomplete workflows."
        />
      ) : (
        <div className="loop-list" role="list" aria-label="Open clinical loops">
          {openLoops.map((loop) => (
            <LoopCard
              key={loop.id}
              loop={loop}
              onClick={() => navigate(loop.actionTo)}
            />
          ))}
        </div>
      )}

      {/* Boundary notice */}
      <div className="loop-notice" role="note">
        <CheckCircle2 size={12} />
        <span>
          Open loops are derived from canonical order, result, and prescription data.
          They coordinate follow-through — they do not make clinical decisions.
        </span>
      </div>
    </div>
  );
}

export default ClosedLoopTracker;
