/**
 * ClinicalCommandSurface — Unified Clinical Command Surface (Phase 128)
 *
 * A compact, always-visible panel that answers:
 *   "What requires my attention?"
 *   "How urgent is it?"
 *   "Which patient does it belong to?"
 *   "What is the correct next action?"
 *
 * This is NOT a generic notification center.
 * It is a clinical command surface — a prioritized list of actionable work
 * derived from authoritative facility-wide API data.
 *
 * Data sources (same as ClinicalWorkQueue):
 *   - appointmentsApi.list()        → today's appointments
 *   - appointmentsApi.queue()       → waiting patients
 *   - referralsApi.list()           → pending referrals
 *   - criticalValueApi.list()       → critical lab values
 *   - radiologyApi.queue()          → radiology worklist
 *
 * Safety:
 *   - Reads only; never mutates on load
 *   - Routes to authorized workspaces
 *   - Preserves patient context
 *   - Role filtering is presentation-only; backend remains authoritative
 *   - No clinical priority inference — only authoritative status
 */

import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useClinicalWorkSources } from '../hooks/useClinicalWorkSources';
import {
  CLINICAL_ROLES,
  DOCTOR_ROLES,
  LAB_ROLES,
  RADIOLOGY_ROLES,
  SOURCE_CONFIG,
  type WorkSource,
} from './clinical-work-types';
import {
  AlertTriangle,
  ArrowRight,
  ChevronRight,
  UserRound,
  Bell,
} from 'lucide-react';
import './clinical-command-surface.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */



/* CommandSurface uses simplified priority taxonomy */
type WorkPriority = 'critical' | 'urgent' | 'routine';
type WorkSection = 'critical' | 'urgent' | 'routine';

interface CommandItem {
  id: string;
  source: WorkSource;
  priority: WorkPriority;
  section: WorkSection;
  label: string;
  description: string;
  patientId?: string;
  patientName?: string;
  patientMrn?: string;
  encounterId?: string;
  createdAt: string;
  destination: string;
  actionLabel: string;
  visibleTo: string[];
  /** Why this priority — transparent to user */
  priorityReason: string;
}

/* ────────────────────────────────────────────────────────────────────
   SOURCE CONFIG
   ──────────────────────────────────────────────────────────────────── */



const PRIORITY_CONFIG: Record<WorkPriority, {
  label: string;
  color: string;
  bgColor: string;
  borderColor: string;
}> = {
  critical: {
    label: 'CRITICAL',
    color: 'var(--red-700)',
    bgColor: 'var(--red-50)',
    borderColor: 'var(--red-200)',
  },
  urgent: {
    label: 'URGENT',
    color: 'var(--amber-700)',
    bgColor: 'var(--amber-50)',
    borderColor: 'var(--amber-200)',
  },
  routine: {
    label: 'ROUTINE',
    color: 'var(--text-tertiary)',
    bgColor: 'var(--gray-50)',
    borderColor: 'var(--border-subtle)',
  },
};



/* ────────────────────────────────────────────────────────────────────
   DERIVE COMMAND ITEMS FROM AUTHORITATIVE DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveCommandItems(
  appointments: any[],
  queueEntries: any[],
  referrals: any[],
  criticalValues: any[],
  radiologyQueue: any[],
  now: Date,
): CommandItem[] {
  const items: CommandItem[] = [];
  const today = now.toISOString().split('T')[0];

  // ── Critical values — always highest priority ──
  for (const cv of criticalValues) {
    const status = cv.status?.toLowerCase() ?? '';
    if (['acknowledged', 'resolved', 'cancelled'].includes(status)) continue;

    items.push({
      id: `cs-cv-${cv.id}`,
      source: 'critical_value',
      priority: 'critical',
      section: 'critical',
      label: `Critical: ${cv.testName ?? cv.name ?? 'Lab Result'}`,
      description: cv.value ? `${cv.value} ${cv.unit ?? ''}`.trim() : 'Requires acknowledgment',
      patientId: cv.patientId,
      patientName: cv.patient?.fullName ?? cv.patientName,
      patientMrn: cv.patient?.mrn ?? cv.patientMrn,
      createdAt: cv.createdAt ?? new Date().toISOString(),
      destination: cv.patientId
        ? `/clinical/patients/${cv.patientId}?ws=lab`
        : '/laboratory/critical-values',
      actionLabel: 'Review Result',
      visibleTo: [...CLINICAL_ROLES, ...LAB_ROLES],
      priorityReason: 'Unacknowledged critical lab value',
    });
  }

  // ── Queue entries — patients waiting ──
  for (const entry of queueEntries) {
    const status = entry.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled', 'no_show', 'in_progress'].includes(status)) continue;

    const patientName = entry.patient?.fullName ?? entry.patientName ?? 'Unknown patient';
    const patientMrn = entry.patient?.mrn ?? entry.patientMrn;
    const patientId = entry.patientId ?? entry.patient?.id;

    items.push({
      id: `cs-queue-${entry.id}`,
      source: 'appointment',
      priority: 'urgent',
      section: 'urgent',
      label: 'Patient Waiting',
      description: `${entry.serviceName ?? ''} ${entry.providerName ? `· ${entry.providerName}` : ''}`.trim(),
      patientId,
      patientName,
      patientMrn,
      createdAt: entry.createdAt ?? new Date().toISOString(),
      destination: patientId
        ? `/clinical/patients/${patientId}?ws=overview`
        : '/clinical/appointments',
      actionLabel: 'Start Consultation',
      visibleTo: CLINICAL_ROLES,
      priorityReason: 'Patient in queue awaiting service',
    });
  }

  // ── Appointments — checked in patients ──
  for (const appt of appointments) {
    const status = appt.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled', 'no_show'].includes(status)) continue;

    const apptDate = (appt.startsAt ?? appt.date ?? '').split('T')[0];
    const isPastDue = apptDate < today && status !== 'checked_in';
    const isCheckedIn = status === 'checked_in' || status === 'in_progress';

    let priority: WorkPriority;
    let section: WorkSection;

    if (isPastDue) {
      priority = 'urgent';
      section = 'urgent';
    } else if (isCheckedIn) {
      priority = 'urgent';
      section = 'urgent';
    } else if (apptDate === today) {
      priority = 'routine';
      section = 'routine';
    } else {
      continue; // Future appointments
    }

    const patientName = appt.patient?.fullName ?? appt.patientName ?? 'Unknown patient';
    const patientMrn = appt.patient?.mrn ?? appt.patientMrn;

    items.push({
      id: `cs-appt-${appt.id}`,
      source: 'appointment',
      priority,
      section,
      label: `Appointment — ${appt.appointmentType ?? 'Consultation'}`,
      description: `${appt.serviceName ?? ''} ${appt.providerName ? `· ${appt.providerName}` : ''}`.trim(),
      patientId: appt.patientId,
      patientName,
      patientMrn,
      encounterId: appt.encounterId,
      createdAt: appt.createdAt ?? new Date().toISOString(),
      destination: `/clinical/patients/${appt.patientId}?ws=overview`,
      actionLabel: 'Open Patient',
      visibleTo: CLINICAL_ROLES,
      priorityReason: isPastDue ? 'Past scheduled time' : isCheckedIn ? 'Patient checked in' : "Today's appointment",
    });
  }

  // ── Referrals ──
  for (const ref of referrals) {
    const status = ref.status?.toLowerCase() ?? '';
    if (['completed', 'cancelled'].includes(status)) continue;

    const isStat = status === 'stat';
    const isPending = status === 'pending' || status === 'accepted';

    if (!isStat && !isPending) continue;

    const patientName = ref.patient?.fullName ?? ref.patientName ?? 'Unknown patient';
    const patientMrn = ref.patient?.mrn ?? ref.patientMrn;

    items.push({
      id: `cs-ref-${ref.id}`,
      source: 'referral',
      priority: isStat ? 'critical' : 'urgent',
      section: isStat ? 'critical' : 'urgent',
      label: `Referral — ${ref.specialty ?? ref.toDepartment ?? 'Specialty'}`,
      description: ref.reason ?? 'Pending review',
      patientId: ref.patientId,
      patientName,
      patientMrn,
      createdAt: ref.createdAt ?? new Date().toISOString(),
      destination: ref.patientId
        ? `/clinical/patients/${ref.patientId}?ws=overview`
        : '/clinical/referrals',
      actionLabel: 'Review Referral',
      visibleTo: DOCTOR_ROLES,
      priorityReason: isStat ? 'Stat referral' : 'Requires response',
    });
  }

  // ── Radiology queue ──
  for (const study of radiologyQueue) {
    const status = study.status?.toLowerCase() ?? '';
    if (['reported', 'completed', 'cancelled'].includes(status)) continue;

    const isPerformed = status === 'performed' || status === 'completed';

    items.push({
      id: `cs-rad-${study.id}`,
      source: 'radiology',
      priority: isPerformed ? 'urgent' : 'routine',
      section: isPerformed ? 'urgent' : 'routine',
      label: `Imaging — ${study.modality ?? study.type ?? 'Study'}`,
      description: study.indication ?? study.description ?? 'Awaiting action',
      patientId: study.patientId,
      patientName: study.patient?.fullName ?? study.patientName,
      patientMrn: study.patient?.mrn ?? study.patientMrn,
      createdAt: study.createdAt ?? new Date().toISOString(),
      destination: study.patientId
        ? `/clinical/patients/${study.patientId}?ws=radiology`
        : '/radiology',
      actionLabel: isPerformed ? 'Review Study' : 'View Worklist',
      visibleTo: [...DOCTOR_ROLES, ...RADIOLOGY_ROLES],
      priorityReason: isPerformed ? 'Imaging complete, awaiting report' : 'Ordered study',
    });
  }

  return items;
}

/* ────────────────────────────────────────────────────────────────────
   COMMAND SURFACE ITEM
   ──────────────────────────────────────────────────────────────────── */

function CommandSurfaceItem({
  item,
  onSelect,
}: {
  item: CommandItem;
  onSelect: (item: CommandItem) => void;
}) {
  const sourceCfg = SOURCE_CONFIG[item.source];
  const priorityCfg = PRIORITY_CONFIG[item.priority];

  return (
    <button
      type="button"
      className={`cs-item cs-item--${item.priority}`}
      onClick={() => onSelect(item)}
      data-testid={`cs-item-${item.source}-${item.id}`}
      aria-label={`${item.label} — ${item.patientName ?? 'No patient'} — ${item.actionLabel}`}
    >
      <div className="cs-item__left">
        <span className="cs-item__source" style={{ color: sourceCfg.color, background: sourceCfg.bgColor }}>
          <sourceCfg.Icon size={13} />
        </span>
        <div className="cs-item__content">
          <div className="cs-item__top">
            <span className="cs-item__label">{item.label}</span>
            <span className="cs-item__priority" style={{ color: priorityCfg.color, background: priorityCfg.bgColor, borderColor: priorityCfg.borderColor }}>
              {priorityCfg.label}
            </span>
          </div>
          {item.patientName && (
            <div className="cs-item__patient">
              <UserRound size={11} />
              <span className="cs-item__patient-name">{item.patientName}</span>
              {item.patientMrn && <span className="cs-item__patient-mrn mono">{item.patientMrn}</span>}
            </div>
          )}
          {item.description && (
            <div className="cs-item__description">{item.description}</div>
          )}
        </div>
      </div>
      <div className="cs-item__right">
        <span className="cs-item__action">
          {item.actionLabel}
          <ChevronRight size={12} />
        </span>
      </div>
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   EMPTY STATE
   ──────────────────────────────────────────────────────────────────── */

function EmptyCommandState() {
  return (
    <div className="cs-empty" role="status">
      <div className="cs-empty__icon">
        <Bell size={24} />
      </div>
      <p className="cs-empty__title">All clear</p>
      <p className="cs-empty__body">
        No clinical work requires your attention right now.
      </p>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   ERROR STATE
   ──────────────────────────────────────────────────────────────────── */

function ErrorCommandState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="cs-error" role="alert">
      <div className="cs-error__icon">
        <AlertTriangle size={20} />
      </div>
      <p className="cs-error__title">Unable to load work queue</p>
      <p className="cs-error__body">
        We couldn't refresh your clinical work. Your existing workflow is still available.
      </p>
      <button type="button" className="cs-error__retry" onClick={onRetry}>
        Retry
      </button>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   LOADING STATE
   ──────────────────────────────────────────────────────────────────── */

function LoadingCommandState() {
  return (
    <div className="cs-loading" aria-busy="true" aria-label="Loading clinical work">
      {[1, 2, 3].map((i) => (
        <div key={i} className="cs-loading__item">
          <div className="cs-loading__icon" />
          <div className="cs-loading__text">
            <div className="cs-loading__line cs-loading__line--short" />
            <div className="cs-loading__line cs-loading__line--long" />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL COMMAND SURFACE
   ──────────────────────────────────────────────────────────────────── */

export function ClinicalCommandSurface() {
  const navigate = useNavigate();
  const { hasRole } = useTenant();
  const [activeSection, setActiveSection] = useState<WorkSection | 'all'>('all');

  // ── Fetch from authoritative facility-wide sources (shared hook) ──
  const workSources = useClinicalWorkSources();

  // ── Derive command items ──
  const allItems = useMemo(() => {
    const now = new Date();
    return deriveCommandItems(
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

  // ── Filter by section ──
  const sectionFiltered = useMemo(() => {
    if (activeSection === 'all') return roleFiltered;
    return roleFiltered.filter((i) => i.section === activeSection);
  }, [roleFiltered, activeSection]);

  // ── Sort by priority ──
  const sorted = useMemo(() => {
    const priorityOrder: Record<WorkPriority, number> = { critical: 0, urgent: 1, routine: 2 };
    return [...sectionFiltered].sort((a, b) => priorityOrder[a.priority] - priorityOrder[b.priority]);
  }, [sectionFiltered]);

  // ── Counts per section ──
  const counts = useMemo(() => {
    const c = { critical: 0, urgent: 0, routine: 0 };
    for (const item of roleFiltered) {
      c[item.section]++;
    }
    return c;
  }, [roleFiltered]);

  const totalCount = roleFiltered.length;

  // ── Handle item selection ──
  const handleSelect = (item: CommandItem) => {
    navigate(item.destination);
  };

  // ── Handle retry ──
  const handleRetry = () => workSources.refreshAll();

  // ── Loading state ──
  const isLoading = workSources.isLoading;

  // ── Error state ──
  const hasError = workSources.hasError;

  return (
    <div className="cs-panel" role="region" aria-label="Clinical command surface">
      {/* Header */}
      <div className="cs-panel__header">
        <div className="cs-panel__title-row">
          <h2 className="cs-panel__title">What needs your attention</h2>
          {!isLoading && !hasError && (
            <span className="cs-panel__count">{totalCount}</span>
          )}
        </div>

        {/* Section tabs */}
        {!isLoading && !hasError && totalCount > 0 && (
          <div className="cs-panel__tabs" role="tablist" aria-label="Work sections">
            <button
              type="button"
              className={`cs-tab ${activeSection === 'all' ? 'cs-tab--active' : ''}`}
              onClick={() => setActiveSection('all')}
              role="tab"
              aria-selected={activeSection === 'all'}
            >
              All
            </button>
            {counts.critical > 0 && (
              <button
                type="button"
                className={`cs-tab cs-tab--critical ${activeSection === 'critical' ? 'cs-tab--active' : ''}`}
                onClick={() => setActiveSection('critical')}
                role="tab"
                aria-selected={activeSection === 'critical'}
              >
                Critical
                <span className="cs-tab__badge cs-tab__badge--critical">{counts.critical}</span>
              </button>
            )}
            {counts.urgent > 0 && (
              <button
                type="button"
                className={`cs-tab cs-tab--urgent ${activeSection === 'urgent' ? 'cs-tab--active' : ''}`}
                onClick={() => setActiveSection('urgent')}
                role="tab"
                aria-selected={activeSection === 'urgent'}
              >
                Urgent
                <span className="cs-tab__badge cs-tab__badge--urgent">{counts.urgent}</span>
              </button>
            )}
            {counts.routine > 0 && (
              <button
                type="button"
                className={`cs-tab ${activeSection === 'routine' ? 'cs-tab--active' : ''}`}
                onClick={() => setActiveSection('routine')}
                role="tab"
                aria-selected={activeSection === 'routine'}
              >
                Routine
              </button>
            )}
          </div>
        )}
      </div>

      {/* Content */}
      <div className="cs-panel__content">
        {isLoading ? (
          <LoadingCommandState />
        ) : hasError ? (
          <ErrorCommandState onRetry={handleRetry} />
        ) : sorted.length === 0 ? (
          <EmptyCommandState />
        ) : (
          <div className="cs-panel__list" role="list" aria-label="Clinical work items">
            {sorted.map((item) => (
              <CommandSurfaceItem
                key={item.id}
                item={item}
                onSelect={handleSelect}
              />
            ))}
          </div>
        )}
      </div>

      {/* Footer with full queue link */}
      {!isLoading && !hasError && totalCount > 0 && (
        <div className="cs-panel__footer">
          <button
            type="button"
            className="cs-panel__view-all"
            onClick={() => navigate('/my-work?ws=workqueue')}
            data-testid="cs-view-all"
          >
            View full work queue
            <ArrowRight size={14} />
          </button>
        </div>
      )}
    </div>
  );
}

export default ClinicalCommandSurface;
