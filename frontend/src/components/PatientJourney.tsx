import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi, encountersApi } from '../api/endpoints';
import type { Appointment, Encounter } from '../api/types';
import { Button } from './ui';
import {
  MapPin, Clock, AlertTriangle, CheckCircle, ArrowRight,
  Calendar, Stethoscope, FlaskConical, FileText,
  Bed, Activity, ChevronRight, Circle,
} from 'lucide-react';
import './patient-journey.css';

/* ------------------------------------------------------------------
   PATIENT JOURNEY — One Continuous Thread Through the Hospital
   
   This is NOT a new patient record. It is an orchestration layer
   over canonical systems that answers:
   
   WHERE IS THIS PATIENT NOW?
   WHAT IS HAPPENING?
   WHAT IS WAITING?
   WHAT HAPPENS NEXT?
   
   Architecture: PATIENT → EPISODE → LOCATION → STATE → NEXT STEP
   ------------------------------------------------------------------ */

export type JourneyState =
  | 'arrived'
  | 'waiting'
  | 'in-care'
  | 'awaiting-diagnostics'
  | 'awaiting-review'
  | 'admitted'
  | 'transferred'
  | 'discharge-ready'
  | 'discharged'
  | 'follow-up';

export interface JourneyEvent {
  id: string;
  type: 'clinical' | 'operational' | 'financial' | 'diagnostic' | 'location' | 'document';
  label: string;
  timestamp: string;
  department?: string;
  status: 'completed' | 'in-progress' | 'pending' | 'blocked';
}

interface PatientJourneyProps {
  patientId: string;
}

/* ── Derive journey state from real data ── */

function deriveJourneyState(
  encounters: Encounter[],
  appointments: Appointment[],
): { state: JourneyState; label: string; nextStep: string; nextTo: string } {
  const activeEncounter = encounters.find((e) => e.status === 'open' || e.status === 'in_progress');
  const activeAppointment = appointments.find(
    (a) => a.status === 'checked_in' || a.status === 'in_consultation',
  );
  const upcomingAppointment = appointments.find(
    (a) => a.status === 'booked' && new Date(a.startsAt) > new Date(),
  );
  const completedToday = appointments.filter(
    (a) => a.status === 'completed' && isToday(a.startsAt),
  );

  if (activeEncounter) {
    return {
      state: 'in-care',
      label: 'In care',
      nextStep: 'Continue encounter',
      nextTo: `/clinical/encounters/${activeEncounter.id}`,
    };
  }

  if (activeAppointment) {
    return {
      state: 'waiting',
      label: activeAppointment.status === 'checked_in' ? 'Checked in' : 'In consultation',
      nextStep: activeAppointment.status === 'checked_in' ? 'Start consultation' : 'Continue',
      nextTo: `/clinical/appointments/${activeAppointment.id}`,
    };
  }

  if (upcomingAppointment) {
    return {
      state: 'follow-up',
      label: 'Follow-up scheduled',
      nextStep: `Appointment ${formatJourneyTime(upcomingAppointment.startsAt)}`,
      nextTo: `/clinical/appointments/${upcomingAppointment.id}`,
    };
  }

  if (completedToday.length > 0) {
    return {
      state: 'follow-up',
      label: 'Visit completed',
      nextStep: 'View encounter',
      nextTo: '/clinical/encounters',
    };
  }

  return {
    state: 'arrived',
    label: 'No active care',
    nextStep: 'Book appointment',
    nextTo: '/clinical/appointments',
  };
}

/* ── Build journey events from real data ── */

function buildJourneyEvents(
  encounters: Encounter[],
  appointments: Appointment[],
): JourneyEvent[] {
  const events: JourneyEvent[] = [];

  // Recent appointments
  const recentAppts = [...appointments]
    .sort((a, b) => new Date(b.startsAt).getTime() - new Date(a.startsAt).getTime())
    .slice(0, 5);

  for (const apt of recentAppts) {
    events.push({
      id: apt.id,
      type: 'clinical',
      label: `Appointment — ${apt.appointmentType}`,
      timestamp: apt.startsAt,
      status:
        apt.status === 'completed' ? 'completed' :
        apt.status === 'in_consultation' || apt.status === 'checked_in' ? 'in-progress' :
        apt.status === 'cancelled' ? 'blocked' :
        'pending',
    });
  }

  // Recent encounters
  const recentEncs = [...encounters]
    .sort((a, b) => new Date(b.startedAt).getTime() - new Date(a.startedAt).getTime())
    .slice(0, 5);

  for (const enc of recentEncs) {
    events.push({
      id: enc.id,
      type: 'clinical',
      label: `Encounter — ${enc.type}`,
      timestamp: enc.startedAt,
      status:
        enc.status === 'signed' || enc.status === 'closed' ? 'completed' :
        enc.status === 'open' || enc.status === 'in_progress' ? 'in-progress' :
        'pending',
    });
  }

  return events.sort(
    (a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime(),
  );
}

/* ── Helpers ── */

function isToday(dateStr: string): boolean {
  if (!dateStr) return false;
  const d = new Date(dateStr);
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
}

function formatJourneyTime(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  } catch { return iso; }
}

function formatJourneyDateTime(iso: string): string {
  try {
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  } catch { return iso; }
}

/* ── Journey State Indicator ── */

function JourneyStateIndicator({ state, label }: { state: JourneyState; label: string }) {
  const config: Record<JourneyState, { color: string; bg: string; icon: any }> = {
    'arrived': { color: 'var(--text-secondary)', bg: 'var(--gray-100)', icon: MapPin },
    'waiting': { color: 'var(--amber-700)', bg: 'var(--amber-50)', icon: Clock },
    'in-care': { color: 'var(--teal-700)', bg: 'var(--teal-50)', icon: Stethoscope },
    'awaiting-diagnostics': { color: 'var(--blue-700)', bg: 'var(--blue-50)', icon: FlaskConical },
    'awaiting-review': { color: 'var(--amber-700)', bg: 'var(--amber-50)', icon: FileText },
    'admitted': { color: 'var(--teal-700)', bg: 'var(--teal-50)', icon: Bed },
    'transferred': { color: 'var(--blue-700)', bg: 'var(--blue-50)', icon: ArrowRight },
    'discharge-ready': { color: 'var(--green-700)', bg: 'var(--green-50)', icon: CheckCircle },
    'discharged': { color: 'var(--text-secondary)', bg: 'var(--gray-100)', icon: CheckCircle },
    'follow-up': { color: 'var(--blue-700)', bg: 'var(--blue-50)', icon: Calendar },
  };

  const c = config[state] ?? config['arrived'];
  const Icon = c.icon;

  return (
    <div className="journey-state" style={{ background: c.bg, color: c.color }}>
      <Icon size={16} />
      <span className="journey-state__label">{label}</span>
    </div>
  );
}

/* ── Journey Timeline ── */

function JourneyTimeline({ events }: { events: JourneyEvent[] }) {
  if (events.length === 0) return null;

  return (
    <div className="journey-timeline" role="list" aria-label="Patient journey events">
      {events.map((event, i) => (
        <div
          key={event.id}
          className={`journey-event journey-event--${event.status}`}
          role="listitem"
        >
          <div className="journey-event__dot">
            {event.status === 'completed' ? (
              <CheckCircle size={14} />
            ) : event.status === 'in-progress' ? (
              <Activity size={14} />
            ) : event.status === 'blocked' ? (
              <AlertTriangle size={14} />
            ) : (
              <Circle size={14} />
            )}
          </div>
          {i < events.length - 1 && <div className="journey-event__line" />}
          <div className="journey-event__content">
            <span className="journey-event__label">{event.label}</span>
            <span className="journey-event__time">{formatJourneyDateTime(event.timestamp)}</span>
            {event.department && (
              <span className="journey-event__dept">{event.department}</span>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

/* ── Main PatientJourney ── */

export function PatientJourney({ patientId }: PatientJourneyProps) {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();

  const patient = useFetch(
    () => patientsApi.show(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const encounters = useFetch(
    () => encountersApi.forPatient(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const appointments = useFetch(
    () => patientsApi.followUps(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const journeyState = useMemo(
    () => deriveJourneyState(
      (encounters.data as unknown as Encounter[] ?? []),
      (appointments.data as Appointment[] ?? []),
    ),
    [encounters.data, appointments.data],
  );

  const journeyEvents = useMemo(
    () => buildJourneyEvents(
      (encounters.data as unknown as Encounter[] ?? []),
      (appointments.data as Appointment[] ?? []),
    ),
    [encounters.data, appointments.data],
  );

  const patientData = patient.data as any;

  if (patient.loading) {
    return <div className="journey-loading" role="status"><div className="spinner" /><span>Loading journey…</span></div>;
  }

  if (!patientData) {
    return null;
  }

  return (
    <div className="patient-journey" role="region" aria-label={`Patient journey for ${patientData.fullName}`}>
      {/* Journey header — state + next step */}
      <div className="journey-header">
        <JourneyStateIndicator state={journeyState.state} label={journeyState.label} />
        <div className="journey-next">
          <span className="journey-next__label">Next:</span>
          <span className="journey-next__step">{journeyState.nextStep}</span>
          <Button
            variant="primary"
            size="sm"
            onClick={() => navigate(journeyState.nextTo)}
            className="journey-next__action"
          >
            Go
            <ChevronRight size={14} />
          </Button>
        </div>
      </div>

      {/* Journey timeline */}
      {journeyEvents.length > 0 && (
        <div className="journey-section">
          <h3 className="journey-section__title">Recent Journey</h3>
          <JourneyTimeline events={journeyEvents.slice(0, 8)} />
        </div>
      )}
    </div>
  );
}

export default PatientJourney;
