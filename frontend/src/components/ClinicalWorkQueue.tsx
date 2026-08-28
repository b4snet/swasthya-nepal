/**
 * ClinicalWorkQueue — Global Work Queue (Phase 121)
 *
 * Derives a role-aware, patient-aware work queue from authoritative
 * facility-wide API data. NOT a task database. NOT AI prioritization.
 *
 * Sources:
 *   - appointmentsApi.list()        → today's appointments, queue
 *   - appointmentsApi.queue()       → waiting patients
 *   - referralsApi.list()           → pending referrals
 *   - criticalValueApi.list()       → critical lab values
 *   - radiologyApi.queue()          → radiology worklist
 *
 * Safety:
 *   - Reads only; never mutates on load
 *   - Routes to authorized workspaces
 *   - Preserves patient context via Phase 120 WorkflowTrail
 *   - Role filtering is presentation-only; backend remains authoritative
 *   - No clinical priority inference — only authoritative status
 */

import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useClinicalWorkSources } from '../hooks/useClinicalWorkSources';
import { referralsApi } from '../api/clinical';
import { criticalValueApi } from '../api/laboratory';
import {
  ALL_ROLES,
  CLINICAL_ROLES,
  DOCTOR_ROLES,
  LAB_ROLES,
  RADIOLOGY_ROLES,
  SOURCE_CONFIG,
  SECTION_CONFIG,
  PRIORITY_ORDER,
  SECTION_ORDER,
  type WorkSource,
  type WorkPriority,
  type WorkSection,
} from './clinical-work-types';
import {
  AlertTriangle,
  CheckCircle2,
  ChevronRight,
  UserRound,
  SortAsc,
  LayoutList,
  Table,
  Search,
  CheckCircle,
  Reply,
} from 'lucide-react';
import './clinical-work-queue.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type WorkStatus = 'ready' | 'in_progress' | 'waiting' | 'overdue' | 'completed';

interface WorkItem {
  id: string;
  source: WorkSource;
  status: WorkStatus;
  priority: WorkPriority;
  section: WorkSection;
  label: string;
  description: string;
  patientId: string;
  patientName: string;
  patientMrn?: string;
  encounterId?: string;
  createdAt: string;
  dueAt?: string;
  /** Why this priority — transparent to user */
  priorityReason: string;
  /** Navigation destination with patient context */
  destination: string;
  /** What the user would do */
  actionLabel: string;
  /** Roles that should see this item */
  visibleTo: string[];
  /** Direct mutation action — if present, user can act inline */
  mutationAction?: {
    type: 'acknowledge' | 'complete' | 'sign';
    entityId: string;
    endpoint: string;
  };
  /** Once acted upon, the item is removed from queue */
  completed?: boolean;
}





/* ────────────────────────────────────────────────────────────────────
   DERIVE WORK ITEMS FROM AUTHORITATIVE DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveWorkItems(
  appointments: any[],
  queueEntries: any[],
  referrals: any[],
  criticalValues: any[],
  radiologyQueue: any[],
  now: Date,
): WorkItem[] {
  const items: WorkItem[] = [];
  const today = now.toISOString().split('T')[0];

  // ── Appointments ──
  for (const appt of appointments) {
    const status = appt.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled', 'no_show'].includes(status)) continue;

    const apptDate = (appt.startsAt ?? appt.date ?? '').split('T')[0];
    const isPastDue = apptDate < today && status !== 'checked_in';
    const isCheckedIn = status === 'checked_in' || status === 'in_progress';

    let section: WorkSection;
    let workStatus: WorkStatus;
    let priority: WorkPriority;
    let priorityReason: string;

    if (isPastDue) {
      section = 'overdue';
      workStatus = 'overdue';
      priority = 'high';
      priorityReason = 'Past scheduled time';
    } else if (isCheckedIn) {
      section = 'now';
      workStatus = 'ready';
      priority = 'high';
      priorityReason = 'Patient checked in, awaiting consultation';
    } else if (apptDate === today) {
      section = 'next';
      workStatus = 'waiting';
      priority = 'normal';
      priorityReason = "Today's scheduled appointment";
    } else {
      continue; // Future appointments
    }

    const patientName = appt.patient?.fullName ?? appt.patientName ?? 'Unknown patient';
    const patientMrn = appt.patient?.mrn ?? appt.patientMrn;

    items.push({
      id: `wq-appt-${appt.id}`,
      source: 'appointment',
      status: workStatus,
      priority,
      section,
      label: `Appointment — ${appt.appointmentType ?? 'Consultation'}`,
      description: `${appt.serviceName ?? ''} ${appt.providerName ? `· ${appt.providerName}` : ''}`.trim(),
      patientId: appt.patientId,
      patientName,
      patientMrn,
      encounterId: appt.encounterId,
      createdAt: appt.createdAt ?? new Date().toISOString(),
      dueAt: appt.startsAt ?? appt.date,
      priorityReason,
      destination: `/clinical/patients/${appt.patientId}?ws=overview`,
      actionLabel: 'Open Patient',
      visibleTo: ALL_ROLES,
    });
  }

  // ── Queue entries (checked-in patients waiting) ──
  for (const entry of queueEntries) {
    const status = entry.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled', 'no_show', 'in_progress'].includes(status)) continue;

    const patientName = entry.patient?.fullName ?? entry.patientName ?? 'Unknown patient';
    const patientMrn = entry.patient?.mrn ?? entry.patientMrn;
    const patientId = entry.patientId ?? entry.patient?.id;

    items.push({
      id: `wq-queue-${entry.id}`,
      source: 'appointment',
      status: 'ready',
      priority: 'high',
      section: 'now',
      label: 'Patient Waiting',
      description: `${entry.serviceName ?? ''} ${entry.providerName ? `· ${entry.providerName}` : ''}`.trim(),
      patientId,
      patientName,
      patientMrn,
      createdAt: entry.createdAt ?? new Date().toISOString(),
      dueAt: entry.startsAt,
      priorityReason: 'Patient in queue awaiting service',
      destination: patientId ? `/clinical/patients/${patientId}?ws=overview` : '/clinical/appointments',
      actionLabel: 'Start Consultation',
      visibleTo: CLINICAL_ROLES,
    });
  }

  // ── Referrals ──
  for (const ref of referrals) {
    const status = ref.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled'].includes(status)) continue;

    let section: WorkSection;
    let workStatus: WorkStatus;
    let priority: WorkPriority;
    let priorityReason: string;

    if (status === 'pending' || status === 'requested') {
      section = 'now';
      workStatus = 'ready';
      priority = ref.urgency === 'stat' || ref.urgency === 'urgent' ? 'critical' : 'high';
      priorityReason = ref.urgency === 'stat' ? 'Stat referral' : 'Referral requires response';
    } else if (status === 'accepted' || status === 'scheduled') {
      section = 'next';
      workStatus = 'in_progress';
      priority = 'normal';
      priorityReason = 'Referral accepted, action pending';
    } else {
      continue;
    }

    const patientName = ref.patient?.fullName ?? ref.patientName ?? 'Unknown patient';
    const patientMrn = ref.patient?.mrn;

    items.push({
      id: `wq-ref-${ref.id}`,
      source: 'referral',
      status: workStatus,
      priority,
      section,
      label: `Referral — ${ref.receivingDepartment ?? ref.toDepartment ?? 'Specialty'}`,
      description: ref.reason ?? '',
      patientId: ref.patientId,
      patientName,
      patientMrn,
      encounterId: ref.encounterId,
      createdAt: ref.createdAt ?? new Date().toISOString(),
      priorityReason,
      destination: ref.patientId ? `/clinical/patients/${ref.patientId}?ws=overview` : '/clinical/referrals',
      actionLabel: 'Review Referral',
      visibleTo: CLINICAL_ROLES,
      mutationAction: (status === 'pending' || status === 'requested')
        ? { type: 'complete', entityId: ref.id, endpoint: 'referral' }
        : undefined,
    });
  }

  // ── Critical Values ──
  for (const cv of criticalValues) {
    const acknowledged = cv.acknowledgedAt || cv.acknowledged;
    if (acknowledged) continue;

    const patientName = cv.patient?.fullName ?? cv.patientName ?? cv.patient_name ?? 'Unknown patient';
    const patientMrn = cv.patient?.mrn ?? cv.patient_mrn;
    const patientId = cv.patientId ?? cv.patient_id;

    items.push({
      id: `wq-cv-${cv.id}`,
      source: 'critical_value',
      status: 'ready',
      priority: 'critical',
      section: 'now',
      label: `Critical Value — ${cv.testName ?? cv.test_name ?? 'Lab Result'}`,
      description: cv.resultSummary ?? cv.result_summary ?? '',
      patientId,
      patientName,
      patientMrn,
      encounterId: cv.encounterId ?? cv.encounter_id,
      createdAt: cv.createdAt ?? cv.created_at ?? new Date().toISOString(),
      priorityReason: 'Unacknowledged critical lab value',
      destination: patientId ? `/clinical/patients/${patientId}?ws=lab` : '/laboratory/reports',
      actionLabel: 'Acknowledge',
      visibleTo: [...DOCTOR_ROLES, ...LAB_ROLES],
      mutationAction: { type: 'acknowledge', entityId: cv.id ?? cv.eventId ?? '', endpoint: 'critical_value' },
    });
  }

  // ── Radiology Queue ──
  for (const study of radiologyQueue) {
    const status = study.status?.toLowerCase() ?? '';
    if (['reported', 'completed', 'cancelled'].includes(status)) continue;

    let section: WorkSection;
    let workStatus: WorkStatus;
    let priority: WorkPriority;
    let priorityReason: string;

    if (status === 'pending' || status === 'ordered') {
      section = 'next';
      workStatus = 'waiting';
      priority = 'normal';
      priorityReason = 'Awaiting imaging';
    } else if (status === 'scheduled') {
      section = 'next';
      workStatus = 'waiting';
      priority = 'normal';
      priorityReason = 'Scheduled for imaging';
    } else if (status === 'performed' || status === 'in_progress') {
      section = 'now';
      workStatus = 'ready';
      priority = 'high';
      priorityReason = 'Imaging complete, awaiting report';
    } else {
      continue;
    }

    const patientName = study.patient?.fullName ?? study.patientName ?? 'Unknown patient';
    const patientMrn = study.patient?.mrn;
    const patientId = study.patientId ?? study.patient?.id;

    items.push({
      id: `wq-rad-${study.id}`,
      source: 'radiology',
      status: workStatus,
      priority,
      section,
      label: `${study.modality ?? 'Imaging'} — ${study.studyName ?? study.name ?? 'Study'}`,
      description: `Status: ${status}`,
      patientId,
      patientName,
      patientMrn,
      createdAt: study.createdAt ?? new Date().toISOString(),
      priorityReason,
      destination: patientId ? `/clinical/patients/${patientId}?ws=radiology` : '/radiology',
      actionLabel: 'Open Study',
      visibleTo: [...DOCTOR_ROLES, ...RADIOLOGY_ROLES],
    });
  }

  return items;
}

/* ────────────────────────────────────────────────────────────────────
   WORK ITEM CARD
   ──────────────────────────────────────────────────────────────────── */

function WorkItemCard({
  item,
  onNavigate,
  onAct,
  acted,
}: {
  item: WorkItem;
  onNavigate: (destination: string) => void;
  onAct: (item: WorkItem) => void;
  acted: boolean;
}) {
  const src = SOURCE_CONFIG[item.source];
  const isElevated = item.priority === 'critical' || item.priority === 'high';

  const timeAgo = useMemo(() => {
    const diff = Date.now() - new Date(item.createdAt).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
  }, [item.createdAt]);

  if (acted) {
    return (
      <div className={`wq-item wq-item--completed wq-item--${item.source}`} data-testid={`wq-item-${item.id}`}>
        <div className="wq-item__source wq-item__source--completed">
          <CheckCircle size={13} />
        </div>
        <div className="wq-item__content">
          <span className="wq-item__label">{item.label}</span>
          <span className="wq-item__completed-text">Action completed</span>
        </div>
      </div>
    );
  }

  return (
    <button
      type="button"
      className={`wq-item ${isElevated ? 'wq-item--elevated' : ''} wq-item--${item.source}`}
      onClick={() => onNavigate(item.destination)}
      aria-label={`${item.label}, patient ${item.patientName}. ${item.priorityReason}`}
      data-testid={`wq-item-${item.id}`}
    >
      {/* Source indicator */}
      <div className="wq-item__source" style={{ color: src.color, background: src.bgColor }}>
        <src.Icon size={13} />
      </div>

      {/* Content */}
      <div className="wq-item__content">
        <div className="wq-item__header">
          <span className="wq-item__label">{item.label}</span>
          <div className="wq-item__badges">
            {item.priority === 'critical' && (
              <span className="wq-badge wq-badge--critical">CRITICAL</span>
            )}
            {item.priority === 'high' && (
              <span className="wq-badge wq-badge--high">HIGH</span>
            )}
          </div>
        </div>

        <div className="wq-item__patient">
          <UserRound size={11} />
          <span className="wq-item__patient-name">{item.patientName}</span>
          {item.patientMrn && (
            <span className="wq-item__mrn mono">{item.patientMrn}</span>
          )}
        </div>

        {item.description && (
          <span className="wq-item__desc">{item.description}</span>
        )}

        <div className="wq-item__reason">
          <span>{item.priorityReason}</span>
          <span className="wq-item__time">{timeAgo}</span>
        </div>
      </div>

      {/* Action */}
      <div className="wq-item__actions">
        {item.mutationAction && (
          <button
            type="button"
            className="wq-item__act-btn"
            onClick={(e) => { e.stopPropagation(); onAct(item); }}
            aria-label={`Mark ${item.actionLabel.toLowerCase()} for ${item.patientName}`}
            data-testid={`wq-act-${item.id}`}
          >
            <CheckCircle size={13} />
            <span>{item.actionLabel}</span>
          </button>
        )}
        <span className="wq-item__nav-label">
          {item.mutationAction ? <Reply size={12} /> : <ChevronRight size={14} />}
          <span>{item.mutationAction ? 'View' : item.actionLabel}</span>
        </span>
      </div>
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   FILTER BAR
   ──────────────────────────────────────────────────────────────────── */

function FilterBar({
  sources,
  activeSource,
  onSourceChange,
}: {
  sources: WorkSource[];
  activeSource: WorkSource | 'all';
  onSourceChange: (s: WorkSource | 'all') => void;
}) {
  return (
    <div className="wq-filters" role="toolbar" aria-label="Queue filters">
      <button
        type="button"
        className={`wq-filter-btn ${activeSource === 'all' ? 'wq-filter-btn--active' : ''}`}
        onClick={() => onSourceChange('all')}
      >
        All
      </button>
      {sources.map((s) => {
        const cfg = SOURCE_CONFIG[s];
        return (
          <button
            key={s}
            type="button"
            className={`wq-filter-btn ${activeSource === s ? 'wq-filter-btn--active' : ''}`}
            onClick={() => onSourceChange(s)}
          >
            <span style={{ color: cfg.color }}><cfg.Icon size={13} /></span>
            {cfg.label}
          </button>
        );
      })}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   SECTION
   ──────────────────────────────────────────────────────────────────── */

function WorkSectionGroup({
  section,
  items,
  onNavigate,
  onAct,
  actedIds,
}: {
  section: WorkSection;
  items: WorkItem[];
  onNavigate: (dest: string) => void;
  onAct: (item: WorkItem) => void;
  actedIds: Set<string>;
}) {
  if (items.length === 0) return null;
  const cfg = SECTION_CONFIG[section];

  return (
    <div className="wq-section" role="region" aria-label={`${cfg.label} — ${items.length} items`}>
      <div className="wq-section__header">
        <span className="wq-section__title">
          <cfg.Icon size={14} />
          {cfg.label}
        </span>
        <span className="wq-section__count">{items.length}</span>
      </div>
      <div className="wq-section__items" role="list">
        {items.map((item) => (
          <WorkItemCard key={item.id} item={item} onNavigate={onNavigate} onAct={onAct} acted={actedIds.has(item.id)} />
        ))}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL WORK QUEUE
   ──────────────────────────────────────────────────────────────────── */

/* ────────────────────────────────────────────────────────────────────
   TABLE VIEW — compact dense scanning for desktop
   ──────────────────────────────────────────────────────────────────── */

function WorkTable({
  items,
  onNavigate,
  onAct,
  actedIds,
}: {
  items: WorkItem[];
  onNavigate: (dest: string) => void;
  onAct: (item: WorkItem) => void;
  actedIds: Set<string>;
}) {
  return (
    <div className="wq-table-wrap" role="region" aria-label="Work items table">
      <table className="wq-table" role="grid">
        <thead>
          <tr>
            <th scope="col" className="wq-table__th">Patient</th>
            <th scope="col" className="wq-table__th">Work</th>
            <th scope="col" className="wq-table__th">Status</th>
            <th scope="col" className="wq-table__th wq-table__th--action">Action</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const src = SOURCE_CONFIG[item.source];
            return (
              <tr
                key={item.id}
                className={`wq-table__row ${item.priority === 'critical' ? 'wq-table__row--critical' : item.priority === 'high' ? 'wq-table__row--high' : ''}`}
                onClick={() => onNavigate(item.destination)}
                role="row"
                tabIndex={0}
                onKeyDown={(e) => { if (e.key === 'Enter') onNavigate(item.destination); }}
                data-testid={`wq-table-${item.id}`}
              >
                <td className="wq-table__td wq-table__td--patient">
                  <span className="wq-table__patient-name">{item.patientName}</span>
                  {item.patientMrn && <span className="wq-table__mrn mono">{item.patientMrn}</span>}
                </td>
                <td className="wq-table__td">
                  <span className="wq-table__work-type" style={{ color: src.color }}><src.Icon size={13} /></span>
                  <span className="wq-table__label">{item.label}</span>
                  {item.priority === 'critical' && <span className="wq-badge wq-badge--critical">CRIT</span>}
                  {item.priority === 'high' && <span className="wq-badge wq-badge--high">HIGH</span>}
                </td>
                <td className="wq-table__td wq-table__td--status">
                  <span className="wq-table__reason">{item.priorityReason}</span>
                </td>
                <td className="wq-table__td wq-table__td--action">
                  {item.mutationAction && !actedIds.has(item.id) && (
                    <button
                      type="button"
                      className="wq-table__act-btn"
                      onClick={(e) => { e.stopPropagation(); onAct(item); }}
                      aria-label={`${item.actionLabel} — ${item.patientName}`}
                      data-testid={`wq-act-${item.id}`}
                    >
                      <CheckCircle size={12} />
                      {item.actionLabel}
                    </button>
                  )}
                  {actedIds.has(item.id) ? (
                    <span className="wq-table__acted-label">
                      <CheckCircle size={12} />
                      Done
                    </span>
                  ) : (
                    <button
                      type="button"
                      className="wq-table__action-btn"
                      onClick={(e) => { e.stopPropagation(); onNavigate(item.destination); }}
                      aria-label={`View ${item.patientName}`}
                    >
                      View
                      <ChevronRight size={12} />
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export function ClinicalWorkQueue() {
  const navigate = useNavigate();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const [activeSource, setActiveSource] = useState<WorkSource | 'all'>('all');
  const [sortBy, setSortBy] = useState<'priority' | 'time'>('priority');
  const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');
  const [patientFilter, setPatientFilter] = useState('');
  const [actedIds, setActedIds] = useState<Set<string>>(new Set());

  // ── Direct mutation handler ──
  const handleAct = useCallback(async (item: WorkItem) => {
    if (!item.mutationAction || actedIds.has(item.id)) return;
    try {
      if (item.mutationAction.endpoint === 'critical_value') {
        await criticalValueApi.acknowledge(item.mutationAction.entityId, fac);
      } else if (item.mutationAction.endpoint === 'referral') {
        await referralsApi.complete(item.mutationAction.entityId, undefined, fac);
      }
      setActedIds((prev) => new Set(prev).add(item.id));
    } catch {
      // Mutation failed — item remains actionable, user can retry
    }
  }, [actedIds, fac]);

  // ── Fetch from authoritative facility-wide sources (shared hook) ──
  const workSources = useClinicalWorkSources();

  // ── Derive work items ──
  const allItems = useMemo(() => {
    const now = new Date();
    return deriveWorkItems(
      workSources.appointments,
      workSources.queueEntries,
      workSources.referrals,
      workSources.criticalValues,
      workSources.radiologyQueue,
      now,
    );
  }, [workSources.appointments, workSources.queueEntries, workSources.referrals, workSources.criticalValues, workSources.radiologyQueue]);

  // ── Filter by role ──
  const roleFiltered = useMemo(() => {
    return allItems.filter((item) => {
      if (item.visibleTo.length === 0) return true;
      return item.visibleTo.some((r) => hasRole(r as any));
    });
  }, [allItems, hasRole]);

  // ── Filter by source ──
  const sourceFiltered = useMemo(() => {
    let items = activeSource === 'all' ? roleFiltered : roleFiltered.filter((i) => i.source === activeSource);
    // Patient filter
    if (patientFilter.trim()) {
      const q = patientFilter.toLowerCase();
      items = items.filter((i) =>
        i.patientName.toLowerCase().includes(q) ||
        (i.patientMrn && i.patientMrn.toLowerCase().includes(q))
      );
    }
    return items;
  }, [roleFiltered, activeSource, patientFilter]);

  // ── Sort ──
  const sorted = useMemo(() => {
    return [...sourceFiltered].sort((a, b) => {
      if (sortBy === 'priority') {
        const pd = PRIORITY_ORDER[a.priority] - PRIORITY_ORDER[b.priority];
        if (pd !== 0) return pd;
        return SECTION_ORDER[a.section] - SECTION_ORDER[b.section];
      }
      // time
      return new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime();
    });
  }, [sourceFiltered, sortBy]);

  // ── Group by section ──
  const sections = useMemo(() => {
    const groups: Record<WorkSection, WorkItem[]> = {
      now: [], overdue: [], next: [], waiting: [],
    };
    for (const item of sorted) {
      groups[item.section].push(item);
    }
    return groups;
  }, [sorted]);

  // ── Available sources ──
  const availableSources = useMemo(() => {
    const srcs = new Set<WorkSource>();
    for (const item of roleFiltered) srcs.add(item.source);
    return Array.from(srcs);
  }, [roleFiltered]);

  // ── Loading state ──
  if (workSources.isLoading) {
    return (
      <div className="wq-loading" role="status">
        <div className="spinner" />
        <span>Loading work queue…</span>
      </div>
    );
  }

  // ── Error state ──
  if (workSources.hasError && roleFiltered.length === 0) {
    return (
      <div className="wq-error" role="alert">
        <AlertTriangle size={20} />
        <h3>Work Queue Unavailable</h3>
        <p>We couldn't load your current work. This may be a temporary issue.</p>
        <button
          type="button"
          className="wq-retry-btn"
          onClick={() => workSources.refreshAll()}
        >
          Retry
        </button>
      </div>
    );
  }

  const totalActive = roleFiltered.filter((i) => i.status !== 'completed').length;
  const criticalCount = roleFiltered.filter((i) => i.priority === 'critical').length;

  return (
    <div className="clinical-work-queue" role="region" aria-label="Clinical work queue">
      {/* ── Summary Stats ── */}
      <div className="wq-summary">
        <div className="wq-summary__stat">
          <span className="wq-summary__count">{totalActive}</span>
          <span className="wq-summary__label">Total</span>
        </div>
        {criticalCount > 0 && (
          <div className="wq-summary__stat wq-summary__stat--critical">
            <span className="wq-summary__count">{criticalCount}</span>
            <span className="wq-summary__label">Critical</span>
          </div>
        )}
        {sections.now.length > 0 && (
          <div className="wq-summary__stat wq-summary__stat--attention">
            <span className="wq-summary__count">{sections.now.length}</span>
            <span className="wq-summary__label">Attention</span>
          </div>
        )}
        {sections.overdue.length > 0 && (
          <div className="wq-summary__stat wq-summary__stat--overdue">
            <span className="wq-summary__count">{sections.overdue.length}</span>
            <span className="wq-summary__label">Overdue</span>
          </div>
        )}
      </div>

      {/* ── Filters, Search & View Toggle ── */}
      <div className="wq-controls">
        <div className="wq-controls__left">
          <FilterBar
            sources={availableSources}
            activeSource={activeSource}
            onSourceChange={setActiveSource}
          />
          <div className="wq-search">
            <Search size={13} />
            <input
              type="text"
              className="wq-search-input"
              placeholder="Filter by patient name or MRN…"
              value={patientFilter}
              onChange={(e) => setPatientFilter(e.target.value)}
              aria-label="Filter by patient"
            />
          </div>
        </div>
        <div className="wq-controls__right">
          <div className="wq-sort">
            <SortAsc size={13} />
            <select
              className="wq-sort-select"
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as 'priority' | 'time')}
              aria-label="Sort work items"
            >
              <option value="priority">Priority</option>
              <option value="time">Recent</option>
            </select>
          </div>
          <div className="wq-view-toggle" role="radiogroup" aria-label="View mode">
            <button
              type="button"
              className={`wq-view-btn ${viewMode === 'cards' ? 'wq-view-btn--active' : ''}`}
              onClick={() => setViewMode('cards')}
              aria-label="Card view"
              aria-pressed={viewMode === 'cards'}
            >
              <LayoutList size={14} />
            </button>
            <button
              type="button"
              className={`wq-view-btn ${viewMode === 'table' ? 'wq-view-btn--active' : ''}`}
              onClick={() => setViewMode('table')}
              aria-label="Table view"
              aria-pressed={viewMode === 'table'}
            >
              <Table size={14} />
            </button>
          </div>
        </div>
      </div>

      {/* ── Work Items ── */}
      {totalActive === 0 ? (
        <div className="wq-empty">
          <CheckCircle2 size={28} className="wq-empty__icon" />
          <h3 className="wq-empty__title">All Clear</h3>
          <p className="wq-empty__text">
            No pending work items in your queue.
            <br />
            Your clinical work will appear here when authorized work requires your attention.
          </p>
        </div>
      ) : viewMode === 'table' ? (
        <WorkTable items={sorted} onNavigate={(d) => navigate(d)} onAct={handleAct} actedIds={actedIds} />
      ) : (
        <div className="wq-sections">
          <WorkSectionGroup section="overdue" items={sections.overdue} onNavigate={(d) => navigate(d)} onAct={handleAct} actedIds={actedIds} />
          <WorkSectionGroup section="now" items={sections.now} onNavigate={(d) => navigate(d)} onAct={handleAct} actedIds={actedIds} />
          <WorkSectionGroup section="next" items={sections.next} onNavigate={(d) => navigate(d)} onAct={handleAct} actedIds={actedIds} />
          <WorkSectionGroup section="waiting" items={sections.waiting} onNavigate={(d) => navigate(d)} onAct={handleAct} actedIds={actedIds} />
        </div>
      )}

      {/* ── Safety Boundary ── */}
      <div className="wq-notice" role="note">
        <AlertTriangle size={12} />
        <span>
          Work items are derived from canonical appointment, referral, critical-value, and radiology data.
          Priority reflects system state, not clinical judgment.
        </span>
      </div>
    </div>
  );
}

export default ClinicalWorkQueue;
