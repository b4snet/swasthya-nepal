/**
 * PatientWorkspace — Patient-Centric Workspace (Phase 82)
 *
 * The central unit of work in SWASTHYA. Replaces the traditional
 * patient profile page with a workspace model:
 *
 *   PATIENT → PATIENT WORKSPACE → SEE CONTEXT → TAKE ACTION
 *
 * The patient identity remains stable while the user moves between
 * authorized workspaces (Overview, Encounters, Medications, etc.).
 *
 * Role-aware: different roles see different workspace launchers.
 * Context-aware: adapts when patient is in specific care settings.
 */

import { useCallback, useMemo, useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi, encountersApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Card,
  EmptyState,
  ErrorState,
  Spinner,
  StatusChip,
  formatDate,
  formatDateTime,
} from '../components/ui';
import {
  UserRound,
  FileText,
  Pill,
  FlaskConical,
  ScanLine,
  Bed,
  GitPullRequestArrow,
  CalendarDays,
  ClipboardList,
  Activity,
  WalletCards,
  MessageSquare,
  ArrowLeft,
  Stethoscope,
  Clock,
  AlertTriangle,
  CheckCircle2,
  Circle,
  MapPin,
  Users,
} from 'lucide-react';
import './patient-workspace.css';
import '../components/contextual/context-surface.css';
import { ClinicalThread } from '../components/ClinicalThread';
import { PatientJourney } from '../components/PatientJourney';
import { CareTeam } from '../components/CareTeam';
import { ClosedLoopTracker } from '../components/ClosedLoopTracker';
import { ClinicalQuickView } from '../components/ClinicalQuickView';
import { ContextualActionRail, resolveWorkspacePriorities, resolveContextualActions } from '../components/clinical-context';
import { WorkflowTrail } from '../components/WorkflowTrail';

// ─── Timeline helper (reused from PatientProfilePage) ───
function timelineSummary(summary: any): string {
  if (typeof summary === 'string') return summary;
  if (Array.isArray(summary)) return summary.map(String).join(', ');
  if (summary && typeof summary === 'object') {
    return Object.values(summary)
      .flatMap((v) => (Array.isArray(v) ? v.map(String) : [String(v)]))
      .join(' · ');
  }
  return '';
}

// ─── Time grouping helper ───
type TimeGroup = { label: string; items: any[] };
function groupTimelineEntries(entries: any[]): TimeGroup[] {
  const now = new Date();
  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const weekAgo = new Date(todayStart.getTime() - 7 * 24 * 60 * 60 * 1000);

  const today: any[] = [];
  const recent: any[] = [];
  const earlier: any[] = [];

  for (const entry of entries) {
    const date = new Date(entry.occurredAt || entry.createdAt);
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

// ─── Patient status mapping ───
function patientStatusInfo(status: string): { tone: 'success' | 'info' | 'warning' | 'danger' | 'neutral'; label: string } {
  switch (status) {
    case 'active': return { tone: 'success', label: 'Active' };
    case 'deceased': return { tone: 'danger', label: 'Deceased' };
    case 'inactive': return { tone: 'neutral', label: 'Inactive' };
    case 'transferred': return { tone: 'info', label: 'Transferred' };
    case 'discharged': return { tone: 'info', label: 'Discharged' };
    default: return { tone: 'neutral', label: status };
  }
}

// ─── Workspace definitions (role-aware) ───
interface WorkspaceDef {
  id: string;
  label: string;
  Icon: any;
  roles: string[];
  description?: string;
}

export const PATIENT_WORKSPACES: WorkspaceDef[] = [
  { id: 'quickview', label: 'Quick View', Icon: Activity, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: '10-second clinical orientation' },
  { id: 'overview', label: 'Overview', Icon: Activity, roles: [], description: 'Current status and recent activity' },
  { id: 'journey', label: 'Journey', Icon: MapPin, roles: [], description: 'Patient journey through the hospital' },
  { id: 'careteam', label: 'Care Team', Icon: Users, roles: [], description: 'Current care team and responsibilities' },
  { id: 'loops', label: 'Open Loops', Icon: AlertTriangle, roles: ['doctor', 'nurse', 'pharmacist', 'lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Clinical workflow follow-through' },
  { id: 'encounters', label: 'Encounters', Icon: Stethoscope, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Clinical visit records' },
  { id: 'timeline', label: 'Timeline', Icon: Clock, roles: [], description: 'Longitudinal clinical history' },
  { id: 'diagnoses', label: 'Diagnoses', Icon: ClipboardList, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Problems and diagnoses' },
  { id: 'medications', label: 'Medications', Icon: Pill, roles: ['doctor', 'nurse', 'pharmacist', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Prescriptions and medications' },
  { id: 'lab', label: 'Laboratory', Icon: FlaskConical, roles: ['doctor', 'nurse', 'lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Lab orders and results' },
  { id: 'radiology', label: 'Radiology', Icon: ScanLine, roles: ['doctor', 'nurse', 'radiologist', 'radiographer', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Imaging orders and reports' },
  { id: 'admissions', label: 'Admissions', Icon: Bed, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Inpatient admissions' },
  { id: 'referrals', label: 'Referrals', Icon: GitPullRequestArrow, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Internal and external referrals' },
  { id: 'appointments', label: 'Appointments', Icon: CalendarDays, roles: [], description: 'Scheduled visits and follow-ups' },
  { id: 'documents', label: 'Documents', Icon: FileText, roles: [], description: 'Notes, consents, and records' },
  { id: 'communication', label: 'Communication', Icon: MessageSquare, roles: [], description: 'Messages, reminders, care coordination' },
];

// ─── Quick Action definitions ───
interface QuickAction {
  id: string;
  label: string;
  Icon: any;
  roles: string[];
  getLink: (patientId: string) => string;
}

const QUICK_ACTIONS: QuickAction[] = [
  { id: 'new-encounter', label: 'New Encounter', Icon: Stethoscope, roles: ['doctor', 'nurse', 'hospital_admin'], getLink: (id) => `/clinical/encounters?patientId=${id}` },
  { id: 'order-lab', label: 'Order Lab', Icon: FlaskConical, roles: ['doctor', 'nurse', 'hospital_admin'], getLink: (id) => `/clinical/forms?patientId=${id}&type=lab` },
  { id: 'prescribe', label: 'Prescribe', Icon: Pill, roles: ['doctor', 'hospital_admin'], getLink: (id) => `/clinical/forms?patientId=${id}&type=prescription` },
  { id: 'refer', label: 'Refer', Icon: GitPullRequestArrow, roles: ['doctor', 'nurse'], getLink: (id) => `/clinical/referrals?patientId=${id}` },
  { id: 'book', label: 'Book Appt', Icon: CalendarDays, roles: ['doctor', 'nurse', 'receptionist', 'hospital_admin'], getLink: (id) => `/clinical/appointments?patientId=${id}` },
  { id: 'add-task', label: 'Add Task', Icon: ClipboardList, roles: ['nurse', 'hospital_admin'], getLink: (id) => `/nursing?patientId=${id}` },
  { id: 'billing', label: 'Billing', Icon: WalletCards, roles: ['billing_clerk', 'hospital_admin', 'org_admin', 'org_finance'], getLink: (id) => `/finance/billing?patientId=${id}` },
  { id: 'message', label: 'Message', Icon: MessageSquare, roles: ['doctor', 'nurse', 'hospital_admin'], getLink: () => '/communications/messages' },
];

// ════════════════════════════════════════════════════════════════════════════
// PATIENT HEADER — persistent, high-information, wrong-patient defense
// ════════════════════════════════════════════════════════════════════════════
function PatientHeader({
  patient,
  encounters,
  admissions,
  onBack,
}: {
  patient: any;
  encounters: any[];
  admissions: any[];
  onBack: () => void;
}) {
  const statusInfo = patientStatusInfo(patient.status);
  const activeEncounters = (encounters || []).filter((e: any) => e.status === 'open');
  const activeAdmission = (admissions || []).find((a: any) => !a.dischargedAt);

  return (
    <div className="pw-header" role="banner" aria-label={`Patient: ${patient.fullName}`}>
      <button
        type="button"
        className="pw-header__back"
        onClick={onBack}
        aria-label="Back to patients list"
      >
        <ArrowLeft size={18} />
      </button>

      <div className="pw-header__avatar">
        <UserRound size={28} />
      </div>

      <div className="pw-header__info">
        <div className="pw-header__identity">
          <h1 className="pw-header__name">{patient.fullName}</h1>
          <span className="pw-header__mrn mono">{patient.mrn}</span>
        </div>

        <div className="pw-header__meta">
          {patient.dateOfBirth && (
            <span className="pw-header__meta-item">
              {formatDate(patient.dateOfBirth)}
            </span>
          )}
          <span className="pw-header__meta-sep">·</span>
          <span className="pw-header__meta-item capitalize">{patient.sex}</span>
          {patient.bloodGroup && (
            <>
              <span className="pw-header__meta-sep">·</span>
              <span className="pw-header__meta-item">{patient.bloodGroup}</span>
            </>
          )}
        </div>

        <div className="pw-header__status">
          <StatusChip tone={statusInfo.tone} label={statusInfo.label} />

          {activeAdmission && (
            <span className="pw-header__location">
              <Bed size={13} />
              {activeAdmission.wardName || 'Admitted'}
              {activeAdmission.roomNumber ? ` — Rm ${activeAdmission.roomNumber}` : ''}
            </span>
          )}

          {activeEncounters.length > 0 && (
            <span className="pw-header__encounter-badge">
              <Stethoscope size={13} />
              {activeEncounters.length} active encounter{activeEncounters.length > 1 ? 's' : ''}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * CompactIdentitySpine — a pinned, minimal patient identity bar
 * that appears when the user scrolls past the full patient header.
 * Ensures patient identity is never lost during clinical work.
 */
function CompactIdentitySpine({
  patient,
  encounters,
  admissions,
  onBack,
}: {
  patient: any;
  encounters: any[];
  admissions: any[];
  onBack: () => void;
}) {
  const statusInfo = patientStatusInfo(patient.status);
  const activeEncounters = (encounters || []).filter((e: any) => e.status === 'open');
  const activeAdmission = (admissions || []).find((a: any) => !a.dischargedAt);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const header = document.querySelector('.pw-header');
    if (!header) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        setVisible(!entry.isIntersecting);
      },
      { threshold: 0, rootMargin: '-1px 0px 0px 0px' },
    );

    observer.observe(header);
    return () => observer.disconnect();
  }, []);

  if (!visible) return null;

  return (
    <div className="pw-spine" role="banner" aria-label={`Patient context: ${patient.fullName}`}>
      <button
        type="button"
        className="pw-spine__back"
        onClick={onBack}
        aria-label="Back to patients list"
      >
        <ArrowLeft size={14} />
      </button>

      <div className="pw-spine__identity">
        <span className="pw-spine__name">{patient.fullName}</span>
        <span className="pw-spine__mrn mono">{patient.mrn}</span>
      </div>

      <div className="pw-spine__context">
        <StatusChip tone={statusInfo.tone} label={statusInfo.label} />
        {activeAdmission && (
          <span className="pw-spine__location">
            <Bed size={11} />
            {activeAdmission.wardName || 'Admitted'}
          </span>
        )}
        {activeEncounters.length > 0 && (
          <span className="pw-spine__encounter">
            <Stethoscope size={11} />
            {activeEncounters.length} active
          </span>
        )}
      </div>

      <div className="pw-spine__lock" title="Patient context locked">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </div>
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// PATIENT WORKSPACE NAV — contextual workspace launchers for patient
// ════════════════════════════════════════════════════════════════════════════
function PatientWorkspaceNav({
  activeWorkspace,
  onSelect,
  hasRole,
  counts,
  priorities,
}: {
  activeWorkspace: string;
  onSelect: (id: string) => void;
  hasRole: (role: string) => boolean;
  counts: Record<string, number | undefined>;
  priorities?: Record<string, { urgency: string; reason: string }>;
}) {
  const visible = PATIENT_WORKSPACES.filter(
    (ws) => ws.roles.length === 0 || ws.roles.some((r) => hasRole(r as any)),
  );

  return (
    <nav className="pw-nav" role="navigation" aria-label="Patient workspace navigation">
      <div className="pw-nav__grid" role="list">
        {visible.map((ws) => {
          const isActive = ws.id === activeWorkspace;
          const count = counts[ws.id];
          const priority = priorities?.[ws.id];
          const isUrgent = priority && (priority.urgency === 'critical' || priority.urgency === 'urgent');
          return (
            <button
              key={ws.id}
              type="button"
              className={`pw-nav__card ${isActive ? 'pw-nav__card--active' : ''} ${isUrgent ? 'pw-nav__card--urgent' : ''}`}
              onClick={() => onSelect(ws.id)}
              aria-label={`${ws.label}${priority?.reason ? ` — ${priority.reason}` : ''}`}
              aria-current={isActive ? 'page' : undefined}
              title={priority?.reason}
              data-testid={`pw-nav-${ws.id}`}
              role="listitem"
            >
              <div className="pw-nav__icon">
                <ws.Icon size={20} strokeWidth={1.75} />
              </div>
              <div className="pw-nav__label">
                {ws.label}
                {count !== undefined && (
                  <span className="pw-nav__badge">{count}</span>
                )}
              </div>
            </button>
          );
        })}
      </div>
    </nav>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// ACTION BAR — contextual actions for the patient
// ════════════════════════════════════════════════════════════════════════════
function PatientActionBar({
  patientId,
  hasRole,
}: {
  patientId: string;
  hasRole: (role: string) => boolean;
}) {
  const navigate = useNavigate();
  const actions = QUICK_ACTIONS.filter(
    (a) => a.roles.length === 0 || a.roles.some((r) => hasRole(r as any)),
  );

  return (
    <div className="pw-actions" role="toolbar" aria-label="Patient actions">
      {actions.map((action) => (
        <button
          key={action.id}
          type="button"
          className="pw-actions__btn"
          onClick={() => navigate(action.getLink(patientId))}
          title={action.label}
          aria-label={action.label}
          data-testid={`pw-action-${action.id}`}
        >
          <action.Icon size={15} strokeWidth={1.75} />
          <span className="pw-actions__label">{action.label}</span>
        </button>
      ))}
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// WORKSPACE VIEWS
// ════════════════════════════════════════════════════════════════════════════

// ── Overview: What Matters Now ──
function OverviewView({
  encounters,
  diagnoses,
  prescriptions,
  labOrders,
  admissions,
  appointments,
  onEncounterClick,
}: {
  encounters: any[];
  diagnoses: any[];
  prescriptions: any[];
  labOrders: any[];
  admissions: any[];
  appointments: any[];
  onEncounterClick?: (encounter: any) => void;
}) {
  const activeEncounters = encounters.filter((e: any) => e.status === 'open');
  const activeDiagnoses = diagnoses.filter((d: any) => d.status === 'active');
  const activePrescriptions = prescriptions.filter((p: any) => p.status === 'active');
  const pendingLabs = labOrders.filter((o: any) => !['reported', 'verified'].includes(o.status));
  const activeAdmissions = admissions.filter((a: any) => !a.dischargedAt);
  const upcomingAppts = appointments.filter((a: any) => a.status !== 'completed' && a.status !== 'cancelled');

  return (
    <div className="pw-overview">
      {/* What matters now */}
      <section className="pw-overview__section" aria-label="What matters now">
        <h3 className="pw-overview__heading">What Matters Now</h3>
        <div className="pw-overview__cards">
          {activeAdmissions.length > 0 && (
            <div className="pw-status-card pw-status-card--active">
              <div className="pw-status-card__icon"><Bed size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Currently Admitted</span>
                <span className="pw-status-card__detail">
                  {activeAdmissions[0].wardName || 'Inpatient'}
                  {activeAdmissions[0].roomNumber ? ` — Room ${activeAdmissions[0].roomNumber}` : ''}
                </span>
              </div>
            </div>
          )}

          {activeEncounters.length > 0 && (
            <div className="pw-status-card pw-status-card--active">
              <div className="pw-status-card__icon"><Stethoscope size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Active Encounter</span>
                <span className="pw-status-card__detail">
                  {activeEncounters[0].type} with {activeEncounters[0].providerName || 'provider'}
                </span>
              </div>
            </div>
          )}

          {activeDiagnoses.length > 0 && (
            <div className="pw-status-card">
              <div className="pw-status-card__icon"><ClipboardList size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Active Diagnoses</span>
                <span className="pw-status-card__detail">
                  {activeDiagnoses.map((d: any) => d.description || d.code).filter(Boolean).slice(0, 2).join('; ')}
                  {activeDiagnoses.length > 2 && ` +${activeDiagnoses.length - 2}`}
                </span>
              </div>
            </div>
          )}

          {pendingLabs.length > 0 && (
            <div className="pw-status-card pw-status-card--warning">
              <div className="pw-status-card__icon"><AlertTriangle size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Pending Labs</span>
                <span className="pw-status-card__detail">
                  {pendingLabs.length} order{pendingLabs.length !== 1 ? 's' : ''} awaiting results
                </span>
              </div>
            </div>
          )}

          {activePrescriptions.length > 0 && (
            <div className="pw-status-card">
              <div className="pw-status-card__icon"><Pill size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Active Medications</span>
                <span className="pw-status-card__detail">
                  {activePrescriptions.length} prescription{activePrescriptions.length !== 1 ? 's' : ''}
                </span>
              </div>
            </div>
          )}

          {upcomingAppts.length > 0 && (
            <div className="pw-status-card">
              <div className="pw-status-card__icon"><CalendarDays size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">Upcoming</span>
                <span className="pw-status-card__detail">
                  {upcomingAppts.length} appointment{upcomingAppts.length !== 1 ? 's' : ''}
                </span>
              </div>
            </div>
          )}

          {activeEncounters.length === 0 && activeDiagnoses.length === 0 &&
           activePrescriptions.length === 0 && pendingLabs.length === 0 &&
           activeAdmissions.length === 0 && upcomingAppts.length === 0 && (
            <div className="pw-status-card pw-status-card--quiet">
              <div className="pw-status-card__icon"><CheckCircle2 size={18} /></div>
              <div className="pw-status-card__info">
                <span className="pw-status-card__label">No urgent items</span>
                <span className="pw-status-card__detail">No active encounters, pending labs, or upcoming appointments</span>
              </div>
            </div>
          )}
        </div>
      </section>

      {/* Recent Encounters */}
      {encounters.length > 0 && (
        <section className="pw-overview__section">
          <h3 className="pw-overview__heading">Recent Encounters</h3>
          <ul className="pw-list">
            {encounters.slice(0, 5).map((e: any) => (
              <li key={e.id} className="pw-list__item pw-list__item--clickable" onClick={() => onEncounterClick?.(e)} role="button" tabIndex={0} onKeyDown={(ev) => { if (ev.key === 'Enter') onEncounterClick?.(e); }}>
                <span className="pw-list__icon"><Stethoscope size={14} /></span>
                <span className="pw-list__content">
                  <span className="pw-list__primary">{e.type} — {e.providerName || 'Unknown'}</span>
                  <span className="pw-list__secondary">{formatDateTime(e.startedAt)}</span>
                </span>
                <StatusChip
                  tone={e.status === 'signed' ? 'success' : e.status === 'open' ? 'info' : 'neutral'}
                  label={e.status}
                />
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

// ── Timeline view with meaningful grouping ──
function TimelineView({ entries }: { entries: any[] }) {
  const groups = groupTimelineEntries(entries);

  if (groups.length === 0) {
    return (
      <EmptyState title="No activity yet" body="Visits and events will appear here." />
    );
  }

  const iconForType = (type: string) => {
    const t = type?.toLowerCase() || '';
    if (t.includes('encounter') || t.includes('visit')) return <Stethoscope size={14} />;
    if (t.includes('prescription') || t.includes('medication')) return <Pill size={14} />;
    if (t.includes('lab') || t.includes('test')) return <FlaskConical size={14} />;
    if (t.includes('radiology') || t.includes('imaging')) return <ScanLine size={14} />;
    if (t.includes('admit') || t.includes('discharge') || t.includes('transfer')) return <Bed size={14} />;
    if (t.includes('referral')) return <GitPullRequestArrow size={14} />;
    if (t.includes('appointment')) return <CalendarDays size={14} />;
    return <Circle size={14} />;
  };

  return (
    <div className="pw-timeline">
      {groups.map((group) => (
        <div key={group.label} className="pw-timeline__group">
          <h4 className="pw-timeline__group-label">{group.label}</h4>
          <ol className="pw-timeline__list">
            {group.items.map((entry) => (
              <li key={entry.id} className="pw-timeline__item">
                <span className="pw-timeline__dot" />
                <span className="pw-timeline__icon">{iconForType(entry.eventType)}</span>
                <div className="pw-timeline__content">
                  <span className="pw-timeline__type">{entry.eventType}</span>
                  <span className="pw-timeline__summary">{timelineSummary(entry.summary)}</span>
                  <span className="pw-timeline__time">{formatDateTime(entry.occurredAt)}</span>
                </div>
              </li>
            ))}
          </ol>
        </div>
      ))}
    </div>
  );
}

// ── Generic data table view ──
function DataTableView({
  title,
  data,
  loading,
  error,
  emptyTitle,
  emptyBody,
  columns,
  refresh,
  onRowClick,
}: {
  title: string;
  data: any[];
  loading: boolean;
  error: any;
  emptyTitle: string;
  emptyBody: string;
  columns: { key: string; label: string; render?: (item: any) => React.ReactNode; className?: string }[];
  refresh?: () => void;
  onRowClick?: (item: any) => void;
}) {
  if (loading) return <Spinner label={`Loading ${title.toLowerCase()}…`} />;
  if (error) return <ErrorState error={error} onRetry={refresh ? () => refresh() : undefined} />;
  if (!data || data.length === 0) return <EmptyState title={emptyTitle} body={emptyBody} />;

  return (
    <Card title={title}>
      <table className="data-table" aria-label={title}>
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} className={col.className}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>              {data.map((item: any) => (
                <tr key={item.id} onClick={onRowClick ? () => onRowClick(item) : undefined} className={onRowClick ? 'pw-clickable-row' : undefined} role={onRowClick ? 'button' : undefined} tabIndex={onRowClick ? 0 : undefined} onKeyDown={onRowClick ? (e) => { if (e.key === 'Enter') onRowClick(item); } : undefined}>
              {columns.map((col) => (
                <td key={col.key} data-label={col.label} className={col.className}>
                  {col.render ? col.render(item) : (item[col.key] ?? '—')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN PATIENT WORKSPACE PAGE
// ════════════════════════════════════════════════════════════════════════════
export function PatientWorkspace() {
  const { id } = useParams<{ id: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  // Active workspace from URL query param (or default to 'overview')
  const activeWorkspace = searchParams.get('ws') || 'overview';
  const [previousWorkspace, setPreviousWorkspace] = useState<string | null>(null);
  const setActiveWorkspace = useCallback((ws: string) => {
    setPreviousWorkspace(activeWorkspace);
    setSearchParams({ ws }, { replace: true });
  }, [setSearchParams, activeWorkspace]);

  // ── Data fetching ──
  const profile = useFetch(() => patientsApi.show(id!, fac), [id, fac]);
  const encounters = useFetch(() => encountersApi.forPatient(id!, fac), [id, fac]);
  const diagnoses = useFetch(() => patientsApi.diagnoses(id!, fac), [id, fac]);
  const prescriptions = useFetch(() => patientsApi.prescriptions(id!, fac), [id, fac]);
  const labOrders = useFetch(() => patientsApi.labOrders(id!, fac), [id, fac]);
  const radiologyOrders = useFetch(() => patientsApi.radiologyOrders(id!, fac), [id, fac]);
  const admissions = useFetch(() => patientsApi.admissions(id!, fac), [id, fac]);
  const referrals = useFetch(() => patientsApi.referrals(id!, fac), [id, fac]);
  const documents = useFetch(() => patientsApi.documents(id!, fac), [id, fac]);
  const appointments = useFetch(() => patientsApi.followUps(id!, fac), [id, fac]);
  const timeline = useFetch(() => patientsApi.timeline(id!, fac), [id, fac]);

  // ── Tab counts for workspace nav badges ──
  const counts = useMemo(() => ({
    encounters: (encounters.data as any[])?.length,
    diagnoses: (diagnoses.data as any[])?.filter((d: any) => d.status === 'active').length,
    medications: (prescriptions.data as any[])?.filter((p: any) => p.status === 'active').length,
    lab: (labOrders.data as any[])?.length,
    radiology: (radiologyOrders.data as any[])?.length,
    admissions: (admissions.data as any[])?.filter((a: any) => !a.dischargedAt).length,
    referrals: (referrals.data as any[])?.length,
    appointments: (appointments.data as any[])?.filter((a: any) => a.status !== 'completed').length,
    documents: (documents.data as any[])?.length,
    timeline: (timeline.data as any[])?.length,
  }), [encounters.data, diagnoses.data, prescriptions.data, labOrders.data, radiologyOrders.data, admissions.data, referrals.data, appointments.data, documents.data, timeline.data]);

  // ── Loading / error states ──
  if (profile.loading) return <div className="pw-page"><Spinner /></div>;
  if (profile.error) return <div className="pw-page"><ErrorState error={profile.error} onRetry={() => void profile.refresh()} /></div>;
  if (!profile.data) return <div className="pw-page"><EmptyState title="Patient not found" body="This patient may have been removed or is outside your facility scope." /></div>;

  const patient = profile.data;

  // ── Resolve workspace priorities from clinical context ──
  const workspacePriorityMap = useMemo(() => {
    const encountersArr = (encounters.data as any[]) || [];
    const diagnosesArr = (diagnoses.data as any[]) || [];
    const prescriptionsArr = (prescriptions.data as any[]) || [];
    const labOrdersArr = (labOrders.data as any[]) || [];
    const admissionsArr = (admissions.data as any[]) || [];
    const priorities = resolveWorkspacePriorities({
      hasPatient: true,
      encounterContext: 'none',
      activeEncounters: encountersArr.filter((e: any) => e.status === 'open').length,
      isAdmitted: admissionsArr.some((a: any) => !a.dischargedAt),
      activeDiagnoses: diagnosesArr.filter((d: any) => d.status === 'active').length,
      activePrescriptions: prescriptionsArr.filter((p: any) => p.status === 'active').length,
      pendingLabs: labOrdersArr.filter((o: any) => !['reported', 'verified'].includes(o.status)).length,
      criticalItems: labOrdersArr.filter((o: any) => o.priority === 'stat' || o.status === 'critical').length,
      pendingTasks: encountersArr.filter((e: any) => e.status === 'open').length,
      userRole: '',
      urgency: 'routine',
    });
    const map: Record<string, { urgency: string; reason: string }> = {};
    for (const p of priorities) map[p.id] = { urgency: p.urgency, reason: p.reason };
    return map;
  }, [encounters.data, diagnoses.data, prescriptions.data, labOrders.data, admissions.data]);

  // ── Active workspace content ──
  const renderWorkspaceContent = () => {
    switch (activeWorkspace) {
      case 'overview':
        return (
          <OverviewView
            encounters={(encounters.data as any[]) || []}
            diagnoses={(diagnoses.data as any[]) || []}
            prescriptions={(prescriptions.data as any[]) || []}
            labOrders={(labOrders.data as any[]) || []}
            admissions={(admissions.data as any[]) || []}
            appointments={(appointments.data as any[]) || []}
            onEncounterClick={(e) => navigate(`/clinical/encounters/${e.id}`)}
          />
        );

      case 'quickview':
        return <ClinicalQuickView patientId={id!} />;

      case 'journey':
        return <PatientJourney patientId={id!} />;

      case 'careteam':
        return <CareTeam patientId={id!} />;

      case 'encounters':
        return (
          <DataTableView
            title="Encounters"
            data={(encounters.data as any[]) || []}
            loading={encounters.loading}
            error={encounters.error}
            emptyTitle="No encounters yet"
            emptyBody="Consultations and visits will appear here."
            refresh={() => void encounters.refresh()}
            onRowClick={(e) => navigate(`/clinical/encounters/${e.id}`)}
            columns={[
              { key: 'startedAt', label: 'Date', className: 'mono', render: (e) => formatDateTime(e.startedAt) },
              { key: 'type', label: 'Type', className: 'capitalize' },
              { key: 'providerName', label: 'Provider' },
              { key: 'serviceName', label: 'Service' },
              {
                key: 'status', label: 'Status',
                render: (e) => <StatusChip tone={e.status === 'signed' ? 'success' : e.status === 'open' ? 'info' : 'neutral'} label={e.status} />,
              },
            ]}
          />
        );

      case 'timeline':
        return <TimelineView entries={(timeline.data as any[]) || []} />;

      case 'diagnoses':
        return (
          <DataTableView
            title="Diagnoses & Problems"
            data={(diagnoses.data as any[]) || []}
            loading={diagnoses.loading}
            error={diagnoses.error}
            emptyTitle="No diagnoses recorded"
            emptyBody="Diagnoses from encounters will appear here."
            refresh={() => void diagnoses.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (d) => formatDateTime(d.createdAt) },
              { key: 'description', label: 'Diagnosis', render: (d) => d.description ?? d.code ?? '—' },
              { key: 'type', label: 'Type', className: 'capitalize' },
              {
                key: 'status', label: 'Status',
                render: (d) => <StatusChip tone={d.status === 'active' ? 'warning' : d.status === 'resolved' ? 'success' : 'neutral'} label={d.status} />,
              },
              { key: 'providerName', label: 'Provider' },
            ]}
          />
        );

      case 'medications':
        return (
          <DataTableView
            title="Medications & Prescriptions"
            data={(prescriptions.data as any[]) || []}
            loading={prescriptions.loading}
            error={prescriptions.error}
            emptyTitle="No prescriptions"
            emptyBody="Prescriptions from encounters will appear here."
            refresh={() => void prescriptions.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (p) => formatDateTime(p.createdAt) },
              { key: 'medicationName', label: 'Medication', render: (p) => p.medicationName ?? p.medication?.name ?? '—' },
              { key: 'dosage', label: 'Dosage' },
              { key: 'frequency', label: 'Frequency' },
              {
                key: 'status', label: 'Status',
                render: (p) => <StatusChip tone={p.status === 'active' ? 'success' : p.status === 'dispensed' ? 'info' : 'neutral'} label={p.status} />,
              },
              { key: 'prescriberName', label: 'Prescriber' },
            ]}
          />
        );

      case 'lab':
        return (
          <DataTableView
            title="Laboratory Orders"
            data={(labOrders.data as any[]) || []}
            loading={labOrders.loading}
            error={labOrders.error}
            emptyTitle="No lab orders"
            emptyBody="Laboratory orders for this patient will appear here."
            refresh={() => void labOrders.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (o) => formatDateTime(o.createdAt) },
              { key: 'testName', label: 'Test', render: (o) => o.testName ?? o.name ?? '—' },
              { key: 'priority', label: 'Priority', className: 'capitalize' },
              {
                key: 'status', label: 'Status',
                render: (o) => <StatusChip tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'} label={o.status} />,
              },
              { key: 'orderedByName', label: 'Ordered By' },
            ]}
          />
        );

      case 'radiology':
        return (
          <DataTableView
            title="Radiology Orders"
            data={(radiologyOrders.data as any[]) || []}
            loading={radiologyOrders.loading}
            error={radiologyOrders.error}
            emptyTitle="No radiology orders"
            emptyBody="Imaging orders for this patient will appear here."
            refresh={() => void radiologyOrders.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (o) => formatDateTime(o.createdAt) },
              { key: 'studyName', label: 'Study', render: (o) => o.studyName ?? o.name ?? '—' },
              { key: 'modality', label: 'Modality' },
              {
                key: 'status', label: 'Status',
                render: (o) => <StatusChip tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'} label={o.status} />,
              },
              { key: 'orderedByName', label: 'Ordered By' },
            ]}
          />
        );

      case 'admissions':
        return (
          <DataTableView
            title="Admissions"
            data={(admissions.data as any[]) || []}
            loading={admissions.loading}
            error={admissions.error}
            emptyTitle="No admissions"
            emptyBody="Inpatient admissions will appear here."
            refresh={() => void admissions.refresh()}
            columns={[
              { key: 'admittedAt', label: 'Admitted', className: 'mono', render: (a) => formatDateTime(a.admittedAt) },
              { key: 'wardName', label: 'Ward' },
              { key: 'bedNumber', label: 'Bed' },
              { key: 'attendingName', label: 'Attending' },
              {
                key: 'status', label: 'Status',
                render: (a) => <StatusChip tone={a.dischargedAt ? 'success' : 'info'} label={a.dischargedAt ? 'discharged' : 'active'} />,
              },
              { key: 'dischargedAt', label: 'Discharged', render: (a) => a.dischargedAt ? formatDateTime(a.dischargedAt) : '—' },
            ]}
          />
        );

      case 'referrals':
        return (
          <DataTableView
            title="Referrals"
            data={(referrals.data as any[]) || []}
            loading={referrals.loading}
            error={referrals.error}
            emptyTitle="No referrals"
            emptyBody="Internal and external referrals will appear here."
            refresh={() => void referrals.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (r) => formatDateTime(r.createdAt) },
              { key: 'fromDepartment', label: 'From', render: (r) => r.fromDepartment ?? r.referringProvider ?? '—' },
              { key: 'toDepartment', label: 'To', render: (r) => r.toDepartment ?? r.receivingProvider ?? '—' },
              { key: 'reason', label: 'Reason' },
              {
                key: 'status', label: 'Status',
                render: (r) => <StatusChip tone={r.status === 'completed' ? 'success' : r.status === 'accepted' ? 'info' : 'neutral'} label={r.status} />,
              },
            ]}
          />
        );

      case 'appointments':
        return (
          <DataTableView
            title="Appointments & Follow-ups"
            data={(appointments.data as any[]) || []}
            loading={appointments.loading}
            error={appointments.error}
            emptyTitle="No appointments"
            emptyBody="Scheduled appointments and follow-ups will appear here."
            refresh={() => void appointments.refresh()}
            columns={[
              { key: 'scheduledAt', label: 'Date', className: 'mono', render: (a) => formatDateTime(a.scheduledAt ?? a.date) },
              { key: 'type', label: 'Type', className: 'capitalize', render: (a) => a.type ?? 'consultation' },
              { key: 'providerName', label: 'Provider' },
              {
                key: 'status', label: 'Status',
                render: (a) => <StatusChip tone={a.status === 'completed' ? 'success' : a.status === 'cancelled' ? 'danger' : 'info'} label={a.status} />,
              },
            ]}
          />
        );

      case 'documents':
        return (
          <DataTableView
            title="Documents"
            data={(documents.data as any[]) || []}
            loading={documents.loading}
            error={documents.error}
            emptyTitle="No documents"
            emptyBody="Patient documents, consents, and forms will appear here."
            refresh={() => void documents.refresh()}
            columns={[
              { key: 'createdAt', label: 'Date', className: 'mono', render: (d) => formatDateTime(d.createdAt) },
              { key: 'name', label: 'Document', render: (d) => d.name ?? d.title ?? '—' },
              { key: 'type', label: 'Type', className: 'capitalize' },
              { key: 'authorName', label: 'Author' },
            ]}
          />
        );

      case 'communication':
        return <ClinicalThread patientId={id!} />;

      case 'loops':
        return <ClosedLoopTracker patientId={id!} />;

      default:
        return <EmptyState title="Workspace not found" body="Select a workspace from above." />;
    }
  };

  return (
    <div className="pw-page" data-testid="patient-workspace">
      {/* ── Patient Header (persistent across all workspaces) ── */}
      <PatientHeader
        patient={patient}
        encounters={(encounters.data as any[]) || []}
        admissions={(admissions.data as any[]) || []}
        onBack={() => navigate('/clinical/patients')}
      />

      {/* ── Workflow Trail (Phase 120 — clinical continuity breadcrumb) ── */}
      <WorkflowTrail
        patientName={patient.fullName}
        patientMrn={patient.mrn}
        encounterType={(() => {
          const activeEnc = ((encounters.data as any[]) || []).find((e: any) => e.status === 'open');
          return activeEnc?.type;
        })()}
        currentWorkspace={PATIENT_WORKSPACES.find((w) => w.id === activeWorkspace)?.label || activeWorkspace}
        currentRoute={undefined}
        previousWorkspace={previousWorkspace || undefined}
        previousRoute={previousWorkspace ? undefined : undefined}
        patientId={patient.id}
        urgency={workspacePriorityMap[activeWorkspace]?.urgency as any || 'routine'}
        currentActivity={workspacePriorityMap[activeWorkspace]?.reason}
      />

      {/* ── Contextual Action Bar ── */}
      <PatientActionBar patientId={patient.id} hasRole={hasRole as any} />

      {/* ── Contextual Action Rail (Phase 119) ── */}
      <ContextualActionRail
        actions={(() => {
          const encountersArr = (encounters.data as any[]) || [];
          const diagnosesArr = (diagnoses.data as any[]) || [];
          const prescriptionsArr = (prescriptions.data as any[]) || [];
          const labOrdersArr = (labOrders.data as any[]) || [];
          const admissionsArr = (admissions.data as any[]) || [];
          const ctx = {
            hasPatient: true,
            encounterContext: 'none' as const,
            activeEncounters: encountersArr.filter((e: any) => e.status === 'open').length,
            isAdmitted: admissionsArr.some((a: any) => !a.dischargedAt),
            activeDiagnoses: diagnosesArr.filter((d: any) => d.status === 'active').length,
            activePrescriptions: prescriptionsArr.filter((p: any) => p.status === 'active').length,
            pendingLabs: labOrdersArr.filter((o: any) => !['reported', 'verified'].includes(o.status)).length,
            criticalItems: labOrdersArr.filter((o: any) => o.priority === 'stat' || o.status === 'critical').length,
            pendingTasks: encountersArr.filter((e: any) => e.status === 'open').length,
            userRole: '',
            urgency: 'routine' as const,
          };
          return resolveContextualActions(ctx, patient.id);
        })()}
        patientId={patient.id}
      />

      {/* ── Patient Workspace Navigation ── */}
      <PatientWorkspaceNav
        activeWorkspace={activeWorkspace}
        onSelect={setActiveWorkspace}
        hasRole={hasRole as any}
        counts={counts}
        priorities={workspacePriorityMap}
      />

      {/* ── Workspace Content ── */}
      <div className="pw-content">
        {renderWorkspaceContent()}
      </div>

      {/* ── Compact Identity Spine (pinned, visible after scroll) ── */}
      <CompactIdentitySpine
        patient={patient}
        encounters={(encounters.data as any[]) || []}
        admissions={(admissions.data as any[]) || []}
        onBack={() => navigate('/clinical/patients')}
      />
    </div>
  );
}

export default PatientWorkspace;
