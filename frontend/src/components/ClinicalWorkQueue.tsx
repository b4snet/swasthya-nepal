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

import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { appointmentsApi, referralsApi } from '../api/clinical';
import { criticalValueApi, radiologyApi } from '../api/laboratory';
import {
  AlertTriangle,
  CalendarDays,
  CheckCircle2,
  Clock,
  Pill,
  ScanLine,
  Stethoscope,
  ArrowRight,
  ChevronRight,
  UserRound,
  Bell,
  SortAsc,
} from 'lucide-react';
import './clinical-work-queue.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type WorkSource = 'appointment' | 'referral' | 'critical_value' | 'radiology' | 'prescription' | 'encounter';
type WorkStatus = 'ready' | 'in_progress' | 'waiting' | 'overdue' | 'completed';
type WorkPriority = 'critical' | 'high' | 'normal' | 'low';
type WorkSection = 'now' | 'next' | 'waiting' | 'overdue';

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
}

/* ────────────────────────────────────────────────────────────────────
   ROLE CATEGORIES
   ──────────────────────────────────────────────────────────────────── */

const ALL_ROLES = [] as string[];
const CLINICAL_ROLES = ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'];
const DOCTOR_ROLES = ['doctor', 'hospital_admin', 'org_admin', 'superadmin'];
const LAB_ROLES = ['lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'];
const RADIOLOGY_ROLES = ['radiologist', 'radiographer', 'hospital_admin', 'org_admin', 'superadmin'];

/* ────────────────────────────────────────────────────────────────────
   SOURCE CONFIG
   ──────────────────────────────────────────────────────────────────── */

const SOURCE_CONFIG: Record<WorkSource, {
  icon: React.ReactNode;
  label: string;
  color: string;
  bgColor: string;
}> = {
  appointment: {
    icon: <CalendarDays size={13} />,
    label: 'Appointment',
    color: 'var(--blue-600)',
    bgColor: 'var(--blue-50)',
  },
  referral: {
    icon: <ArrowRight size={13} />,
    label: 'Referral',
    color: 'var(--violet-600)',
    bgColor: 'var(--violet-50)',
  },
  critical_value: {
    icon: <AlertTriangle size={13} />,
    label: 'Critical Value',
    color: 'var(--red-600)',
    bgColor: 'var(--red-50)',
  },
  radiology: {
    icon: <ScanLine size={13} />,
    label: 'Radiology',
    color: 'var(--teal-700)',
    bgColor: 'var(--teal-50)',
  },
  prescription: {
    icon: <Pill size={13} />,
    label: 'Prescription',
    color: 'var(--amber-600)',
    bgColor: 'var(--amber-50)',
  },
  encounter: {
    icon: <Stethoscope size={13} />,
    label: 'Encounter',
    color: 'var(--teal-700)',
    bgColor: 'var(--teal-50)',
  },
};

const SECTION_CONFIG: Record<WorkSection, { label: string; icon: React.ReactNode }> = {
  now: { label: 'Needs Attention', icon: <Bell size={14} /> },
  next: { label: 'Next', icon: <ArrowRight size={14} /> },
  waiting: { label: 'Waiting', icon: <Clock size={14} /> },
  overdue: { label: 'Overdue', icon: <AlertTriangle size={14} /> },
};

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
}: {
  item: WorkItem;
  onNavigate: (destination: string) => void;
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
        {src.icon}
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
      <div className="wq-item__action">
        <span className="wq-item__action-label">{item.actionLabel}</span>
        <ChevronRight size={14} />
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
            <span style={{ color: cfg.color }}>{cfg.icon}</span>
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
}: {
  section: WorkSection;
  items: WorkItem[];
  onNavigate: (dest: string) => void;
}) {
  if (items.length === 0) return null;
  const cfg = SECTION_CONFIG[section];

  return (
    <div className="wq-section" role="region" aria-label={`${cfg.label} — ${items.length} items`}>
      <div className="wq-section__header">
        <span className="wq-section__title">
          {cfg.icon}
          {cfg.label}
        </span>
        <span className="wq-section__count">{items.length}</span>
      </div>
      <div className="wq-section__items" role="list">
        {items.map((item) => (
          <WorkItemCard key={item.id} item={item} onNavigate={onNavigate} />
        ))}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL WORK QUEUE
   ──────────────────────────────────────────────────────────────────── */

export function ClinicalWorkQueue() {
  const navigate = useNavigate();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const [activeSource, setActiveSource] = useState<WorkSource | 'all'>('all');
  const [sortBy, setSortBy] = useState<'priority' | 'time'>('priority');

  // ── Fetch from authoritative facility-wide sources ──
  const appointments = useFetch(
    () => appointmentsApi.list({ facilityId: fac }),
    [fac],
  );

  const queue = useFetch(
    () => appointmentsApi.queue({ facilityId: fac }),
    [fac],
  );

  const referrals = useFetch(
    () => referralsApi.list({ facilityId: fac }),
    [fac],
  );

  const criticalValues = useFetch(
    () => criticalValueApi.list(fac),
    [fac],
  );

  const radiologyQueue = useFetch(
    () => radiologyApi.queue(fac),
    [fac],
  );

  // ── Derive work items ──
  const allItems = useMemo(() => {
    const now = new Date();
    const apptsData = Array.isArray(appointments.data) ? appointments.data : [];
    const queueData = Array.isArray(queue.data) ? queue.data : [];
    const refData = Array.isArray(referrals.data) ? referrals.data : ((referrals.data as any)?.data ?? []);
    const cvData = Array.isArray(criticalValues.data) ? criticalValues.data : [];
    const radData = Array.isArray(radiologyQueue.data) ? radiologyQueue.data : [];

    return deriveWorkItems(apptsData, queueData, refData, cvData, radData, now);
  }, [appointments.data, queue.data, referrals.data, criticalValues.data, radiologyQueue.data]);

  // ── Filter by role ──
  const roleFiltered = useMemo(() => {
    return allItems.filter((item) => {
      if (item.visibleTo.length === 0) return true;
      return item.visibleTo.some((r) => hasRole(r as any));
    });
  }, [allItems, hasRole]);

  // ── Filter by source ──
  const sourceFiltered = useMemo(() => {
    if (activeSource === 'all') return roleFiltered;
    return roleFiltered.filter((i) => i.source === activeSource);
  }, [roleFiltered, activeSource]);

  // ── Sort ──
  const sorted = useMemo(() => {
    const priorityOrder: Record<WorkPriority, number> = { critical: 0, high: 1, normal: 2, low: 3 };
    const sectionOrder: Record<WorkSection, number> = { now: 0, overdue: 1, next: 2, waiting: 3 };

    return [...sourceFiltered].sort((a, b) => {
      if (sortBy === 'priority') {
        const pd = priorityOrder[a.priority] - priorityOrder[b.priority];
        if (pd !== 0) return pd;
        return sectionOrder[a.section] - sectionOrder[b.section];
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
  const isLoading = appointments.loading && queue.loading && referrals.loading && criticalValues.loading && radiologyQueue.loading;

  if (isLoading) {
    return (
      <div className="wq-loading" role="status">
        <div className="spinner" />
        <span>Loading work queue…</span>
      </div>
    );
  }

  // ── Error state ──
  const hasError = appointments.error || referrals.error || criticalValues.error || radiologyQueue.error;
  if (hasError && roleFiltered.length === 0) {
    return (
      <div className="wq-error" role="alert">
        <AlertTriangle size={20} />
        <h3>Work Queue Unavailable</h3>
        <p>We couldn't load your current work. This may be a temporary issue.</p>
        <button
          type="button"
          className="wq-retry-btn"
          onClick={() => {
            void appointments.refresh();
            void referrals.refresh();
            void criticalValues.refresh();
            void radiologyQueue.refresh();
          }}
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

      {/* ── Filters & Sort ── */}
      <div className="wq-controls">
        <FilterBar
          sources={availableSources}
          activeSource={activeSource}
          onSourceChange={setActiveSource}
        />
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
      </div>

      {/* ── Work Sections ── */}
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
      ) : (
        <div className="wq-sections">
          <WorkSectionGroup section="overdue" items={sections.overdue} onNavigate={(d) => navigate(d)} />
          <WorkSectionGroup section="now" items={sections.now} onNavigate={(d) => navigate(d)} />
          <WorkSectionGroup section="next" items={sections.next} onNavigate={(d) => navigate(d)} />
          <WorkSectionGroup section="waiting" items={sections.waiting} onNavigate={(d) => navigate(d)} />
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
