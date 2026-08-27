import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { appointmentsApi, encountersApi } from '../api/endpoints';
import type { Appointment, Encounter } from '../api/types';
import { Button, EmptyState } from './ui';
import {
  Clock, FileText, AlertTriangle, CheckCircle, ArrowRight,
  User, Calendar, Stethoscope, Pill, TestTube,
  ChevronRight,
} from 'lucide-react';
import './workbench.css';

/* ------------------------------------------------------------------
   SWASTHYA WORKBENCH — Unified Workflow Orchestration
   
   This is NOT a new task engine. It is a coordination layer
   over canonical systems (appointments, encounters, orders, etc.).
   
   Architecture: ROLE → CURRENT WORK → PATIENT → CONTEXT → ACTION → NEXT
   ------------------------------------------------------------------ */

export type WorkStatus = 'ready' | 'waiting' | 'blocked' | 'completed' | 'cancelled';
export type WorkPriority = 'critical' | 'high' | 'normal' | 'low';

export interface WorkItem {
  id: string;
  /** What kind of work */
  type: 'appointment' | 'encounter' | 'order' | 'result' | 'prescription' | 'document' | 'referral' | 'task';
  /** Human-readable label */
  label: string;
  /** Patient context (if patient-related) */
  patient?: { id: string; name: string; mrn?: string };
  /** Encounter context */
  encounter?: { id: string; status: string };
  /** Current status */
  status: WorkStatus;
  /** Priority signal */
  priority: WorkPriority;
  /** What action should the user take next? */
  nextAction: string;
  /** Route to navigate to for the action */
  actionTo: string;
  /** Due time if applicable */
  dueAt?: string;
  /** Who assigned / owns this */
  assignee?: string;
  /** What blocks this work */
  blockedBy?: string;
  /** Source module */
  source: string;
}

interface WorkbenchProps {
  /** Filter work by type */
  filter?: WorkItem['type'][];
}

/* ── Build work items from real API data ── */

function useWorkItems(): { items: WorkItem[]; loading: boolean; refresh: () => void } {
  const { selectedFacilityId } = useTenant();
  const todayStr = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const appointments = useFetch(
    () => appointmentsApi.list({ facilityId: selectedFacilityId }),
    [selectedFacilityId],
  );

  const encounters = useFetch(
    () => appointmentsApi.list({ date: todayStr, facilityId: selectedFacilityId }).then(async (appts) => {
      const patientIds = new Set((appts as Appointment[]).map((a) => a.patientId).filter(Boolean));
      const allEncs: Encounter[] = [];
      for (const pid of patientIds) {
        try {
          const encs = await encountersApi.forPatient(pid, selectedFacilityId);
          allEncs.push(...(encs as unknown as Encounter[]));
        } catch { /* skip */ }
      }
      return allEncs;
    }),
    [selectedFacilityId, todayStr],
  );

  const items = useMemo(() => {
    const workItems: WorkItem[] = [];

    // Appointments → work items
    const appts = (appointments.data as Appointment[] | undefined) ?? [];
    for (const apt of appts) {
      const isToday = isDay(apt.startsAt, todayStr);
      if (!isToday && apt.status !== 'checked_in' && apt.status !== 'in_consultation') continue;

      const status: WorkStatus =
        apt.status === 'completed' ? 'completed' :
        apt.status === 'cancelled' || apt.status === 'no_show' ? 'cancelled' :
        apt.status === 'in_consultation' ? 'ready' :
        apt.status === 'checked_in' ? 'ready' :
        'waiting';

      const priority: WorkPriority =
        apt.status === 'in_consultation' ? 'high' :
        apt.status === 'checked_in' ? 'high' :
        'normal';

      const nextAction =
        apt.status === 'checked_in' ? 'Start consultation' :
        apt.status === 'in_consultation' ? 'Continue consultation' :
        apt.status === 'booked' ? 'Check in patient' :
        apt.status === 'completed' ? 'View encounter' :
        'View appointment';

      workItems.push({
        id: apt.id,
        type: 'appointment',
        label: `${apt.appointmentType} — ${formatTime(apt.startsAt)}`,
        patient: apt.patient ? { id: apt.patient.id, name: apt.patient.fullName } : undefined,
        status,
        priority,
        nextAction,
        actionTo: `/clinical/appointments/${apt.id}`,
        dueAt: apt.startsAt,
        source: 'appointments',
      });
    }

    // Encounters → work items
    const encs = (encounters.data as unknown as Encounter[] | undefined) ?? [];
    for (const enc of encs) {
      const status: WorkStatus =
        enc.status === 'signed' || enc.status === 'closed' ? 'completed' :
        enc.status === 'open' || enc.status === 'in_progress' ? 'ready' :
        'waiting';

      const nextAction =
        enc.status === 'open' ? 'Document encounter' :
        enc.status === 'in_progress' ? 'Continue documentation' :
        'View encounter';

      workItems.push({
        id: enc.id,
        type: 'encounter',
        label: `Encounter — ${enc.type}`,
        patient: enc.patient ? { id: enc.patient.id, name: enc.patient.fullName } : undefined,
        encounter: { id: enc.id, status: enc.status },
        status,
        priority: enc.status === 'in_progress' ? 'high' : 'normal',
        nextAction,
        actionTo: `/clinical/encounters/${enc.id}`,
        source: 'encounters',
      });
    }

    return workItems;
  }, [appointments.data, encounters.data, todayStr]);

  const refresh = useCallback(() => {
    appointments.refresh();
    encounters.refresh();
  }, [appointments, encounters]);

  return { items, loading: appointments.loading || encounters.loading, refresh };
}

/* ── Helpers ── */

function isDay(dateStr: string, dayStr: string): boolean {
  return dateStr?.slice(0, 10) === dayStr;
}

function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  } catch { return iso; }
}

function timeUntil(iso: string): string {
  const diff = new Date(iso).getTime() - Date.now();
  if (diff < 0) return 'overdue';
  const mins = Math.floor(diff / 60000);
  if (mins < 60) return `${mins}m`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h`;
  return `${Math.floor(hrs / 24)}d`;
}

/* ── Work Item Card ── */

function WorkItemCard({
  item,
  onAction,
}: {
  item: WorkItem;
  onAction: (item: WorkItem) => void;
}) {
  const priorityIcon =
    item.priority === 'critical' ? <AlertTriangle size={14} className="wb-priority-icon wb-priority-icon--critical" /> :
    item.priority === 'high' ? <AlertTriangle size={14} className="wb-priority-icon wb-priority-icon--high" /> :
    null;

  const statusIcon =
    item.status === 'completed' ? <CheckCircle size={14} className="wb-status-icon wb-status-icon--completed" /> :
    item.status === 'blocked' ? <AlertTriangle size={14} className="wb-status-icon wb-status-icon--blocked" /> :
    <Clock size={14} className="wb-status-icon wb-status-icon--default" />;

  const typeIcon =
    item.type === 'appointment' ? <Calendar size={14} /> :
    item.type === 'encounter' ? <Stethoscope size={14} /> :
    item.type === 'order' ? <FileText size={14} /> :
    item.type === 'result' ? <TestTube size={14} /> :
    item.type === 'prescription' ? <Pill size={14} /> :
    item.type === 'referral' ? <ArrowRight size={14} /> :
    <FileText size={14} />;

  return (
    <div
      className={`wb-item wb-item--${item.status} wb-item--${item.priority}`}
      role="article"
      aria-label={`${item.type}: ${item.label}${item.patient ? `, patient ${item.patient.name}` : ''}, status ${item.status}`}
    >
      <div className="wb-item__header">
        <span className="wb-item__type">
          {typeIcon}
          <span>{item.type}</span>
        </span>
        <div className="wb-item__status">
          {priorityIcon}
          {statusIcon}
          <span className={`wb-item__status-label wb-item__status-label--${item.status}`}>
            {item.status}
          </span>
        </div>
      </div>

      {item.patient && (
        <div className="wb-item__patient">
          <User size={12} />
          <span className="wb-item__patient-name">{item.patient.name}</span>
          {item.patient.mrn && (
            <span className="wb-item__patient-mrn mono">{item.patient.mrn}</span>
          )}
        </div>
      )}

      <div className="wb-item__label">{item.label}</div>

      {item.blockedBy && (
        <div className="wb-item__blocked">
          <AlertTriangle size={12} />
          <span>Blocked: {item.blockedBy}</span>
        </div>
      )}

      <div className="wb-item__footer">
        <div className="wb-item__meta">
          {item.dueAt && item.status !== 'completed' && (
            <span className={`wb-item__due ${timeUntil(item.dueAt) === 'overdue' ? 'wb-item__due--overdue' : ''}`}>
              {timeUntil(item.dueAt)}
            </span>
          )}
          <span className="wb-item__source">{item.source}</span>
        </div>
        {item.status !== 'completed' && item.status !== 'cancelled' && (
          <Button
            variant="primary"
            size="sm"
            onClick={() => onAction(item)}
            className="wb-item__action"
          >
            {item.nextAction}
            <ChevronRight size={14} />
          </Button>
        )}
      </div>
    </div>
  );
}

/* ── Summary Stats ── */

function WorkSummary({ items }: { items: WorkItem[] }) {
  const stats = useMemo(() => ({
    total: items.length,
    ready: items.filter((i) => i.status === 'ready').length,
    waiting: items.filter((i) => i.status === 'waiting').length,
    blocked: items.filter((i) => i.status === 'blocked').length,
    completed: items.filter((i) => i.status === 'completed').length,
    critical: items.filter((i) => i.priority === 'critical').length,
    high: items.filter((i) => i.priority === 'high').length,
  }), [items]);

  return (
    <div className="wb-summary">
      <div className="wb-summary__stat">
        <span className="wb-summary__value num">{stats.total}</span>
        <span className="wb-summary__label">Total</span>
      </div>
      <div className="wb-summary__stat wb-summary__stat--ready">
        <span className="wb-summary__value num">{stats.ready}</span>
        <span className="wb-summary__label">Ready</span>
      </div>
      <div className="wb-summary__stat wb-summary__stat--waiting">
        <span className="wb-summary__value num">{stats.waiting}</span>
        <span className="wb-summary__label">Waiting</span>
      </div>
      {stats.blocked > 0 && (
        <div className="wb-summary__stat wb-summary__stat--blocked">
          <span className="wb-summary__value num">{stats.blocked}</span>
          <span className="wb-summary__label">Blocked</span>
        </div>
      )}
      {stats.critical > 0 && (
        <div className="wb-summary__stat wb-summary__stat--critical">
          <span className="wb-summary__value num">{stats.critical}</span>
          <span className="wb-summary__label">Critical</span>
        </div>
      )}
      <div className="wb-summary__stat wb-summary__stat--completed">
        <span className="wb-summary__value num">{stats.completed}</span>
        <span className="wb-summary__label">Done</span>
      </div>
    </div>
  );
}

/* ── Main Workbench ── */

export function Workbench({ filter }: WorkbenchProps) {
  const navigate = useNavigate();
  const { items, loading, refresh } = useWorkItems();
  const [statusFilter, setStatusFilter] = useState<WorkStatus | 'all'>('all');

  const filteredItems = useMemo(() => {
    let result = items;
    if (filter?.length) {
      result = result.filter((i) => filter.includes(i.type));
    }
    if (statusFilter !== 'all') {
      result = result.filter((i) => i.status === statusFilter);
    }
    // Sort: critical first, then high, then by status (ready > waiting > blocked)
    return result.sort((a, b) => {
      const priorityOrder: Record<WorkPriority, number> = { critical: 0, high: 1, normal: 2, low: 3 };
      const statusOrder: Record<WorkStatus, number> = { ready: 0, waiting: 1, blocked: 2, completed: 3, cancelled: 4 };
      const pd = priorityOrder[a.priority] - priorityOrder[b.priority];
      if (pd !== 0) return pd;
      return statusOrder[a.status] - statusOrder[b.status];
    });
  }, [items, filter, statusFilter]);

  const handleAction = useCallback((item: WorkItem) => {
    navigate(item.actionTo);
  }, [navigate]);

  if (loading && items.length === 0) {
    return (
      <div className="wb-loading" role="status" aria-label="Loading work items">
        <div className="spinner" />
        <span>Loading work items…</span>
      </div>
    );
  }

  return (
    <div className="wb-workbench" role="region" aria-label="Work items">
      <div className="wb-header">
        <h2 className="wb-title">Current Work</h2>
        <Button variant="ghost" size="sm" onClick={refresh}>
          Refresh
        </Button>
      </div>

      <WorkSummary items={items} />

      {/* Status filter */}
      <div className="wb-filters" role="tablist" aria-label="Filter by status">
        {(['all', 'ready', 'waiting', 'blocked', 'completed'] as const).map((s) => (
          <button
            key={s}
            role="tab"
            aria-selected={statusFilter === s}
            className={`wb-filter ${statusFilter === s ? 'wb-filter--active' : ''}`}
            onClick={() => setStatusFilter(s)}
          >
            {s === 'all' ? 'All' : s}
            {s !== 'all' && (
              <span className="wb-filter__count">
                {items.filter((i) => i.status === s).length}
              </span>
            )}
          </button>
        ))}
      </div>

      {/* Work items */}
      {filteredItems.length === 0 ? (
        <EmptyState
          title="No work items"
          body={statusFilter === 'all'
            ? "No current work items. New work appears as patients arrive and orders are placed."
            : `No ${statusFilter} work items.`
          }
        />
      ) : (
        <div className="wb-list" role="list" aria-label="Work items">
          {filteredItems.map((item) => (
            <WorkItemCard key={item.id} item={item} onAction={handleAction} />
          ))}
        </div>
      )}
    </div>
  );
}

export default Workbench;
