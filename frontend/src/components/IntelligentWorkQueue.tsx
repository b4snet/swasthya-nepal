/**
 * IntelligentWorkQueue — Deterministic Work Prioritization (Phase 109)
 *
 * Answers: "WHAT SHOULD I WORK ON NOW?"
 *
 * Architecture: CANONICAL DOMAIN STATE → DERIVED WORK ITEMS → PRIORITIZED QUEUE
 *
 * This is NOT another task management system.
 * This is NOT a duplicate queue engine.
 * This IS a derived operational view over existing canonical data.
 *
 * Sources: appointments, orders, results, prescriptions, encounters, tasks.
 * Priority: deterministic from clinical status, urgency, due time, dependency.
 * Ownership: from existing assignment/encounter/provider data.
 *
 * Safety boundary:
 * May say: "RESULT IS READY FOR REVIEW."
 * Must not say: "THIS RESULT REQUIRES TREATMENT X."
 */

import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi } from '../api/endpoints';
import type { Encounter } from '../api/types';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  ExternalLink,
  FlaskConical,
  Pill,
  ScanLine,
  Stethoscope,
  CalendarDays,
  ArrowRight,
  SortAsc,
} from 'lucide-react';
import './intelligent-workqueue.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type WorkDomain = 'lab' | 'radiology' | 'pharmacy' | 'encounter' | 'appointment' | 'referral' | 'document';
type WorkStatus = 'ready' | 'in_progress' | 'waiting' | 'blocked' | 'overdue' | 'completed';
type WorkPriority = 'critical' | 'high' | 'normal' | 'low';
type WorkSection = 'now' | 'next' | 'waiting' | 'blocked' | 'overdue';

interface WorkItem {
  id: string;
  domain: WorkDomain;
  status: WorkStatus;
  priority: WorkPriority;
  section: WorkSection;
  label: string;
  description: string;
  currentStep: string;
  nextStep: string;
  nextOwner: string;
  patientId: string;
  patientName: string;
  patientMrn?: string;
  encounterId?: string;
  createdAt: string;
  dueAt?: string;
  /** Why this priority — transparent to user */
  priorityReason: string;
  /** Route to the source workspace */
  actionTo: string;
  /** Canonical source ID */
  sourceId: string;
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE WORK ITEMS FROM CANONICAL DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveWorkItems(
  encounters: Encounter[],
  labOrders: any[],
  prescriptions: any[],
  appointments: any[],
  patientMap: Map<string, { name: string; mrn?: string }>,
): WorkItem[] {
  const items: WorkItem[] = [];

  // ── Lab Orders ──
  for (const order of labOrders) {
    const status = order.status?.toLowerCase() ?? '';
    if (['reported', 'verified', 'cancelled'].includes(status)) continue;

    let section: WorkSection;
    let workStatus: WorkStatus;
    let priority: WorkPriority;
    let priorityReason: string;
    let nextStep: string;
    let nextOwner: string;

    if (status === 'results_entered' || status === 'resulted') {
      section = 'now';
      workStatus = 'ready';
      priority = 'high';
      priorityReason = 'Results available for review';
      nextStep = 'Verification';
      nextOwner = 'Laboratory Supervisor';
    } else if (status === 'verified') {
      section = 'now';
      workStatus = 'ready';
      priority = 'high';
      priorityReason = 'Results verified, awaiting clinician review';
      nextStep = 'Clinician review';
      nextOwner = 'Ordering Provider';
    } else if (status === 'ordered' || status === 'pending') {
      section = 'next';
      workStatus = 'waiting';
      priority = 'normal';
      priorityReason = 'Awaiting specimen collection';
      nextStep = 'Specimen collection';
      nextOwner = 'Laboratory';
    } else if (status === 'collected' || status === 'processing') {
      section = 'waiting';
      workStatus = 'in_progress';
      priority = 'normal';
      priorityReason = 'Currently being processed';
      nextStep = 'Results entry';
      nextOwner = 'Laboratory';
    } else {
      continue;
    }

    const patientInfo = patientMap.get(order.patientId) ?? { name: 'Unknown' };

    items.push({
      id: `work-lab-${order.id}`,
      domain: 'lab',
      status: workStatus,
      priority,
      section,
      label: order.testName ?? order.name ?? 'Lab Order',
      description: `Priority: ${order.priority ?? 'routine'}`,
      currentStep: `Status: ${status}`,
      nextStep,
      nextOwner,
      patientId: order.patientId,
      patientName: patientInfo.name,
      patientMrn: patientInfo.mrn,
      encounterId: order.encounterId,
      createdAt: order.createdAt ?? new Date().toISOString(),
      priorityReason,
      actionTo: `/api/v1/lab-orders/${order.id}`,
      sourceId: order.id,
    });
  }

  // ── Prescriptions ──
  for (const rx of prescriptions) {
    const status = rx.status?.toLowerCase() ?? '';
    if (['dispensed', 'cancelled', 'completed'].includes(status)) continue;

    let section: WorkSection;
    let workStatus: WorkStatus;
    let priority: WorkPriority;
    let priorityReason: string;

    if (status === 'pending' || status === 'draft') {
      section = 'next';
      workStatus = 'waiting';
      priority = 'normal';
      priorityReason = 'Awaiting pharmacy verification';
    } else if (status === 'active' || status === 'verified') {
      section = 'now';
      workStatus = 'ready';
      priority = 'normal';
      priorityReason = 'Ready for dispensing';
    } else {
      continue;
    }

    const patientInfo = patientMap.get(rx.patientId) ?? { name: 'Unknown' };

    items.push({
      id: `work-rx-${rx.id}`,
      domain: 'pharmacy',
      status: workStatus,
      priority,
      section,
      label: rx.medicationName ?? rx.medication?.name ?? 'Prescription',
      description: `${rx.dosage ?? ''} ${rx.frequency ?? ''}`.trim(),
      currentStep: `Status: ${status}`,
      nextStep: status === 'verified' ? 'Dispensing' : 'Verification',
      nextOwner: 'Pharmacy',
      patientId: rx.patientId,
      patientName: patientInfo.name,
      patientMrn: patientInfo.mrn,
      encounterId: rx.encounterId,
      createdAt: rx.createdAt ?? new Date().toISOString(),
      priorityReason,
      actionTo: `/api/v1/prescriptions/${rx.id}`,
      sourceId: rx.id,
    });
  }

  // ── Encounters ──
  for (const enc of encounters) {
    const status = enc.status?.toLowerCase() ?? '';
    if (['signed', 'completed', 'closed', 'cancelled'].includes(status)) continue;

    const section: WorkSection = status === 'open' ? 'next' : 'waiting';
    const workStatus: WorkStatus = status === 'in_progress' ? 'in_progress' : 'waiting';

    const patientInfo = patientMap.get(enc.patientId) ?? {
      name: enc.patient?.fullName ?? 'Unknown',
      mrn: enc.patient?.mrn,
    };

    items.push({
      id: `work-enc-${enc.id}`,
      domain: 'encounter',
      status: workStatus,
      priority: 'normal',
      section,
      label: `${enc.type} encounter`,
      description: `Provider: ${enc.provider?.fullName ?? 'Unknown'}`,
      currentStep: `Status: ${status}`,
      nextStep: 'Documentation & signing',
      nextOwner: enc.provider?.fullName ?? 'Provider',
      patientId: enc.patientId,
      patientName: patientInfo.name,
      patientMrn: patientInfo.mrn,
      encounterId: enc.id,
      createdAt: enc.startedAt ?? new Date().toISOString(),
      priorityReason: 'Open encounter requires completion',
      actionTo: `/clinical/encounters/${enc.id}`,
      sourceId: enc.id,
    });
  }

  // ── Appointments (today's upcoming) ──
  const today = new Date().toISOString().split('T')[0];
  for (const appt of appointments) {
    const status = appt.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled', 'no_show'].includes(status)) continue;

    const apptDate = (appt.startsAt ?? appt.date ?? '').split('T')[0];
    if (apptDate > today) continue; // Only today's and past-due

    const isOverdue = apptDate < today && status !== 'checked_in';
    const section: WorkSection = isOverdue ? 'overdue' : status === 'checked_in' ? 'now' : 'next';

    const patientInfo = patientMap.get(appt.patientId) ?? { name: 'Unknown' };

    items.push({
      id: `work-appt-${appt.id}`,
      domain: 'appointment',
      status: isOverdue ? 'overdue' : 'ready',
      priority: isOverdue ? 'high' : 'normal',
      section,
      label: `Appointment`,
      description: `Scheduled: ${appt.startsAt ?? appt.date ?? 'Unknown'}`,
      currentStep: `Status: ${status}`,
      nextStep: status === 'checked_in' ? 'Start consultation' : 'Check-in',
      nextOwner: 'Reception / Provider',
      patientId: appt.patientId,
      patientName: patientInfo.name,
      patientMrn: patientInfo.mrn,
      createdAt: appt.createdAt ?? new Date().toISOString(),
      dueAt: appt.startsAt ?? appt.date,
      priorityReason: isOverdue ? 'Past scheduled time' : 'Today\'s appointment',
      actionTo: `/api/v1/appointments/${appt.id}`,
      sourceId: appt.id,
    });
  }

  return items;
}

/* ────────────────────────────────────────────────────────────────────
   DOMAIN + STATUS CONFIG
   ──────────────────────────────────────────────────────────────────── */

const DOMAIN_CONFIG: Record<WorkDomain, { icon: React.ReactNode; label: string; color: string }> = {
  lab: { icon: <FlaskConical size={13} />, label: 'Lab', color: 'var(--teal-700)' },
  radiology: { icon: <ScanLine size={13} />, label: 'Radiology', color: 'var(--violet-600)' },
  pharmacy: { icon: <Pill size={13} />, label: 'Pharmacy', color: 'var(--amber-600)' },
  encounter: { icon: <Stethoscope size={13} />, label: 'Encounter', color: 'var(--teal-700)' },
  appointment: { icon: <CalendarDays size={13} />, label: 'Appointment', color: 'var(--blue-600)' },
  referral: { icon: <ArrowRight size={13} />, label: 'Referral', color: 'var(--blue-600)' },
  document: { icon: <ExternalLink size={13} />, label: 'Document', color: 'var(--gray-600)' },
};



const SECTION_LABELS: Record<WorkSection, { label: string; icon: React.ReactNode }> = {
  now: { label: 'Now', icon: <CheckCircle2 size={14} /> },
  next: { label: 'Next', icon: <ArrowRight size={14} /> },
  waiting: { label: 'Waiting', icon: <Clock size={14} /> },
  blocked: { label: 'Blocked', icon: <AlertTriangle size={14} /> },
  overdue: { label: 'Overdue', icon: <AlertTriangle size={14} /> },
};

/* ────────────────────────────────────────────────────────────────────
   WORK ITEM CARD
   ──────────────────────────────────────────────────────────────────── */

function WorkItemCard({
  item,
  onClick,
}: {
  item: WorkItem;
  onClick: () => void;
}) {
  const domain = DOMAIN_CONFIG[item.domain];

  return (
    <button
      type="button"
      className={`wq-card wq-card--${item.status} ${item.priority === 'critical' || item.priority === 'high' ? 'wq-card--elevated' : ''}`}
      onClick={onClick}
      aria-label={`${item.label}: ${item.nextStep}, patient ${item.patientName}`}
      data-testid={`wq-${item.id}`}
    >
      <div className="wq-card__header">
        <span className="wq-card__domain" style={{ color: domain.color }}>
          {domain.icon}
          {domain.label}
        </span>
        <div className="wq-card__badges">
          <span className={`wq-priority wq-priority--${item.priority}`}>
            {item.priority}
          </span>
        </div>
      </div>

      <div className="wq-card__body">
        <span className="wq-card__label">{item.label}</span>
        <span className="wq-card__patient">{item.patientName}</span>
        {item.patientMrn && (
          <span className="wq-card__mrn mono">{item.patientMrn}</span>
        )}
      </div>

      <div className="wq-card__workflow">
        <div className="wq-card__step">
          <span className="wq-card__step-label">Current</span>
          <span className="wq-card__step-value">{item.currentStep}</span>
        </div>
        <ArrowRight size={11} className="wq-card__arrow" />
        <div className="wq-card__step">
          <span className="wq-card__step-label">Next</span>
          <span className="wq-card__step-value wq-card__step-value--action">{item.nextStep}</span>
        </div>
      </div>

      <div className="wq-card__footer">
        <span className="wq-card__reason">{item.priorityReason}</span>
        <span className="wq-card__owner">{item.nextOwner}</span>
      </div>
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   SECTION
   ──────────────────────────────────────────────────────────────────── */

function WorkSection({
  section,
  items,
  onItemclick,
}: {
  section: WorkSection;
  items: WorkItem[];
  onItemclick: (item: WorkItem) => void;
}) {
  if (items.length === 0) return null;
  const config = SECTION_LABELS[section];

  return (
    <div className="wq-section" role="region" aria-label={`${config.label} work`}>
      <div className="wq-section__header">
        <span className="wq-section__title">
          {config.icon}
          {config.label}
        </span>
        <span className="wq-section__count">{items.length}</span>
      </div>
      <div className="wq-section__items" role="list">
        {items.map((item) => (
          <WorkItemCard
            key={item.id}
            item={item}
            onClick={() => onItemclick(item)}
          />
        ))}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   FILTER BAR
   ──────────────────────────────────────────────────────────────────── */

function FilterBar({
  domains,
  activeDomain,
  onDomainChange,
  sortBy,
  onSortChange,
}: {
  domains: WorkDomain[];
  activeDomain: WorkDomain | 'all';
  onDomainChange: (d: WorkDomain | 'all') => void;
  sortBy: 'priority' | 'due' | 'recent';
  onSortChange: (s: 'priority' | 'due' | 'recent') => void;
}) {
  return (
    <div className="wq-filters" role="toolbar" aria-label="Queue filters">
      <div className="wq-filters__domains">
        <button
          type="button"
          className={`wq-filter ${activeDomain === 'all' ? 'wq-filter--active' : ''}`}
          onClick={() => onDomainChange('all')}
        >
          All
        </button>
        {domains.map((d) => (
          <button
            key={d}
            type="button"
            className={`wq-filter ${activeDomain === d ? 'wq-filter--active' : ''}`}
            onClick={() => onDomainChange(d)}
          >
            {DOMAIN_CONFIG[d].icon}
            {DOMAIN_CONFIG[d].label}
          </button>
        ))}
      </div>
      <div className="wq-filters__sort">
        <SortAsc size={13} />
        <select
          className="wq-sort-select"
          value={sortBy}
          onChange={(e) => onSortChange(e.target.value as 'priority' | 'due' | 'recent')}
          aria-label="Sort work items"
        >
          <option value="priority">Priority</option>
          <option value="due">Due time</option>
          <option value="recent">Recent</option>
        </select>
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN INTELLIGENT WORK QUEUE
   ──────────────────────────────────────────────────────────────────── */

export function IntelligentWorkQueue({ patientId }: { patientId?: string }) {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();

  const [activeDomain, setActiveDomain] = useState<WorkDomain | 'all'>('all');
  const [sortBy, setSortBy] = useState<'priority' | 'due' | 'recent'>('priority');

  // If patientId is provided, scope to that patient
  const encounters = useFetch(
    () => patientId
      ? patientsApi.followUps(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  const labOrders = useFetch(
    () => patientId
      ? patientsApi.labOrders(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  const prescriptions = useFetch(
    () => patientId
      ? patientsApi.prescriptions(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  const appointments = useFetch(
    () => patientId
      ? patientsApi.followUps(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  // Build patient name map
  const patientMap = useMemo(() => {
    const map = new Map<string, { name: string; mrn?: string }>();
    for (const enc of (encounters.data as any[]) ?? []) {
      if (enc.patientId && enc.patient) {
        map.set(enc.patientId, { name: enc.patient.fullName, mrn: enc.patient.mrn });
      }
    }
    return map;
  }, [encounters.data]);

  const allItems = useMemo(
    () => deriveWorkItems(
      (encounters.data as any[]) ?? [],
      (labOrders.data as any[]) ?? [],
      (prescriptions.data as any[]) ?? [],
      (appointments.data as any[]) ?? [],
      patientMap,
    ),
    [encounters.data, labOrders.data, prescriptions.data, appointments.data, patientMap],
  );

  // Filter by domain
  const filtered = useMemo(() => {
    let items = activeDomain === 'all' ? allItems : allItems.filter((i) => i.domain === activeDomain);

    // Sort
    const priorityOrder: Record<WorkPriority, number> = { critical: 0, high: 1, normal: 2, low: 3 };
    const sectionOrder: Record<WorkSection, number> = { now: 0, overdue: 1, next: 2, waiting: 3, blocked: 4 };

    items = [...items].sort((a, b) => {
      if (sortBy === 'priority') {
        const pd = priorityOrder[a.priority] - priorityOrder[b.priority];
        if (pd !== 0) return pd;
        return sectionOrder[a.section] - sectionOrder[b.section];
      }
      if (sortBy === 'due') {
        const ad = a.dueAt ? new Date(a.dueAt).getTime() : Infinity;
        const bd = b.dueAt ? new Date(b.dueAt).getTime() : Infinity;
        return ad - bd;
      }
      // recent
      return new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime();
    });

    return items;
  }, [allItems, activeDomain, sortBy]);

  // Group by section
  const sections = useMemo(() => {
    const groups: Record<WorkSection, WorkItem[]> = {
      now: [], next: [], waiting: [], blocked: [], overdue: [],
    };
    for (const item of filtered) {
      groups[item.section].push(item);
    }
    return groups;
  }, [filtered]);

  // Available domains
  const availableDomains = useMemo(() => {
    const doms = new Set<WorkDomain>();
    for (const item of allItems) doms.add(item.domain);
    return Array.from(doms);
  }, [allItems]);

  const isLoading = encounters.loading || labOrders.loading || prescriptions.loading;

  if (isLoading) {
    return (
      <div className="wq-loading" role="status">
        <div className="spinner" />
        <span>Loading work queue…</span>
      </div>
    );
  }

  const totalActive = allItems.filter((i) => i.status !== 'completed').length;

  return (
    <div className="intelligent-workqueue" role="region" aria-label="Work queue">
      {/* Summary */}
      <div className="wq-summary">
        <div className="wq-summary__stat">
          <span className="wq-summary__count">{totalActive}</span>
          <span className="wq-summary__label">Total</span>
        </div>
        {sections.now.length > 0 && (
          <div className="wq-summary__stat wq-summary__stat--alert">
            <span className="wq-summary__count">{sections.now.length}</span>
            <span className="wq-summary__label">Now</span>
          </div>
        )}
        {sections.overdue.length > 0 && (
          <div className="wq-summary__stat wq-summary__stat--danger">
            <span className="wq-summary__count">{sections.overdue.length}</span>
            <span className="wq-summary__label">Overdue</span>
          </div>
        )}
        {sections.blocked.length > 0 && (
          <div className="wq-summary__stat wq-summary__stat--warning">
            <span className="wq-summary__count">{sections.blocked.length}</span>
            <span className="wq-summary__label">Blocked</span>
          </div>
        )}
      </div>

      {/* Filters */}
      <FilterBar
        domains={availableDomains}
        activeDomain={activeDomain}
        onDomainChange={setActiveDomain}
        sortBy={sortBy}
        onSortChange={setSortBy}
      />

      {/* Sections */}
      {totalActive === 0 ? (
        <div className="wq-empty">
          <CheckCircle2 size={24} className="wq-empty__icon" />
          <h3 className="wq-empty__title">All work complete</h3>
          <p className="wq-empty__text">No open work items in the queue.</p>
        </div>
      ) : (
        <div className="wq-sections">
          <WorkSection section="overdue" items={sections.overdue} onItemclick={(i) => navigate(i.actionTo)} />
          <WorkSection section="now" items={sections.now} onItemclick={(i) => navigate(i.actionTo)} />
          <WorkSection section="blocked" items={sections.blocked} onItemclick={(i) => navigate(i.actionTo)} />
          <WorkSection section="next" items={sections.next} onItemclick={(i) => navigate(i.actionTo)} />
          <WorkSection section="waiting" items={sections.waiting} onItemclick={(i) => navigate(i.actionTo)} />
        </div>
      )}

      {/* Boundary notice */}
      <div className="wq-notice" role="note">
        <AlertTriangle size={12} />
        <span>
          Work items are derived from canonical order, result, prescription, and appointment data.
          Priority reflects system state, not clinical judgment.
        </span>
      </div>
    </div>
  );
}

export default IntelligentWorkQueue;
