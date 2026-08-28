/**
 * WorkActivityFeed — Patient-scoped workflow activity timeline
 *
 * Shows recent clinical workflow events for a patient, derived from
 * the authoritative timeline API. NOT a chat. NOT a notification center.
 *
 * Displays:
 *   - encounter open/close
 *   - clinical notes signed
 *   - prescriptions issued
 *   - lab orders placed
 *   - results reported
 *   - referrals created/completed
 *
 * Safety:
 *   - Patient ID from props (authoritative source)
 *   - Only reads from existing API
 *   - No clinical interpretation
 *   - No priority inference
 */

import { useMemo } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi } from '../api/endpoints';
import {
  Stethoscope,
  FileText,
  Pill,
  FlaskConical,
  ArrowRight,
  ClipboardList,
  CheckCircle2,
  AlertTriangle,
  Clock,
} from 'lucide-react';
import './work-activity-feed.css';

/* ────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────── */

interface TimelineEvent {
  id: string;
  type: string;
  title: string;
  description?: string;
  timestamp: string;
  metadata?: Record<string, unknown>;
}

interface WorkActivityFeedProps {
  patientId: string;
  /** Max events to show — compact default */
  maxEvents?: number;
}

/* ────────────────────────────────────────────────────────────
   EVENT CONFIG
   ──────────────────────────────────────────────────────────── */

const EVENT_ICONS: Record<string, React.ReactNode> = {
  encounter_open: <Stethoscope size={13} />,
  encounter_close: <CheckCircle2 size={13} />,
  encounter_signed: <CheckCircle2 size={13} />,
  note_created: <FileText size={13} />,
  note_signed: <FileText size={13} />,
  prescription_issued: <Pill size={13} />,
  lab_ordered: <FlaskConical size={13} />,
  lab_result: <FlaskConical size={13} />,
  result_reported: <FlaskConical size={13} />,
  referral_created: <ArrowRight size={13} />,
  referral_completed: <CheckCircle2 size={13} />,
  diagnosis_added: <ClipboardList size={13} />,
  procedure_ordered: <ClipboardList size={13} />,
  alert_acknowledged: <AlertTriangle size={13} />,
};

const EVENT_COLORS: Record<string, string> = {
  encounter_open: 'var(--teal-700)',
  encounter_signed: 'var(--green-600)',
  note_signed: 'var(--blue-600)',
  prescription_issued: 'var(--amber-600)',
  lab_ordered: 'var(--violet-600)',
  lab_result: 'var(--violet-600)',
  result_reported: 'var(--violet-600)',
  referral_created: 'var(--blue-600)',
  referral_completed: 'var(--green-600)',
  diagnosis_added: 'var(--teal-700)',
  alert_acknowledged: 'var(--red-600)',
};

function getEventIcon(type: string): React.ReactNode {
  return EVENT_ICONS[type] || <Clock size={13} />;
}

function getEventColor(type: string): string {
  return EVENT_COLORS[type] || 'var(--gray-500)';
}

/* ────────────────────────────────────────────────────────────
   TIME FORMATTING
   ──────────────────────────────────────────────────────────── */

function formatRelativeTime(timestamp: string): string {
  const diff = Date.now() - new Date(timestamp).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d ago`;
  return new Date(timestamp).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatAbsoluteTime(timestamp: string): string {
  return new Date(timestamp).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/* ────────────────────────────────────────────────────────────
   EVENT GROUPING
   ──────────────────────────────────────────────────────────── */

function groupEventsByDay(events: TimelineEvent[]): Map<string, TimelineEvent[]> {
  const groups = new Map<string, TimelineEvent[]>();
  const now = new Date();

  for (const event of events) {
    const date = new Date(event.timestamp);
    const diffDays = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24));

    let label: string;
    if (diffDays === 0) label = 'Today';
    else if (diffDays === 1) label = 'Yesterday';
    else if (diffDays < 7) label = 'Last 7 Days';
    else label = 'Earlier';

    if (!groups.has(label)) groups.set(label, []);
    groups.get(label)!.push(event);
  }

  return groups;
}

/* ────────────────────────────────────────────────────────────
   ACTIVITY EVENT ROW
   ──────────────────────────────────────────────────────────── */

function ActivityEventRow({ event }: { event: TimelineEvent }) {
  const color = getEventColor(event.type);
  const icon = getEventIcon(event.type);

  return (
    <div className="waf-event" role="listitem" aria-label={`${event.title}, ${formatAbsoluteTime(event.timestamp)}`}>
      <div className="waf-event__dot" style={{ color }}>
        {icon}
      </div>
      <div className="waf-event__content">
        <span className="waf-event__title">{event.title}</span>
        {event.description && (
          <span className="waf-event__desc">{event.description}</span>
        )}
      </div>
      <span className="waf-event__time" title={formatAbsoluteTime(event.timestamp)}>
        {formatRelativeTime(event.timestamp)}
      </span>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────
   MAIN WORK ACTIVITY FEED
   ──────────────────────────────────────────────────────────── */

export function WorkActivityFeed({ patientId, maxEvents = 10 }: WorkActivityFeedProps) {
  const { selectedFacilityId } = useTenant();

  const timeline = useFetch(
    () => patientsApi.timeline(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const events = useMemo(() => {
    const data = timeline.data;
    if (!data) return [];
    const raw = Array.isArray(data) ? data : (data as any)?.data ?? [];
    // Map raw timeline events into our format
    return raw.slice(0, maxEvents).map((ev: any) => ({
      id: ev.id ?? `evt-${Math.random()}`,
      type: ev.type ?? ev.eventType ?? ev.event_type ?? 'unknown',
      title: ev.title ?? ev.label ?? ev.type ?? 'Event',
      description: ev.description ?? ev.details ?? undefined,
      timestamp: ev.timestamp ?? ev.createdAt ?? ev.created_at ?? ev.occurred_at ?? new Date().toISOString(),
      metadata: ev.metadata,
    }));
  }, [timeline.data, maxEvents]);

  const grouped = useMemo(() => groupEventsByDay(events), [events]);

  if (timeline.loading && events.length === 0) {
    return (
      <div className="waf-loading" role="status">
        <div className="spinner" />
        <span>Loading activity…</span>
      </div>
    );
  }

  if (events.length === 0) {
    return (
      <div className="waf-empty">
        <Clock size={20} className="waf-empty__icon" />
        <span>No recent activity</span>
      </div>
    );
  }

  return (
    <div className="work-activity-feed" role="region" aria-label="Recent clinical activity">
      <div className="waf-header">
        <h4 className="waf-header__title">Recent Activity</h4>
        <span className="waf-header__count">{events.length} event{events.length !== 1 ? 's' : ''}</span>
      </div>

      <div className="waf-timeline" role="list">
        {Array.from(grouped.entries()).map(([dayLabel, dayEvents]) => (
          <div key={dayLabel} className="waf-group">
            <div className="waf-group__label">{dayLabel}</div>
            {dayEvents.map((event) => (
              <ActivityEventRow key={event.id} event={event} />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

export default WorkActivityFeed;
