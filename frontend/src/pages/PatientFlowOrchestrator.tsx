/**
 * PatientFlowOrchestrator — Access, Scheduling, Queue & Flow (Phase 85)
 *
 * One coherent system for the patient journey:
 *   PATIENT → REQUEST/APPOINTMENT → SCHEDULE → CHECK-IN → QUEUE → SERVICE → NEXT STEP
 *
 * For inpatient/operational flow:
 *   PATIENT → LOCATION → RESOURCE → SERVICE → NEXT LOCATION
 *
 * This is NOT a second scheduling engine or second queue engine.
 * It unifies the existing canonical architecture into one visible flow.
 *
 * Key distinctions:
 *   APPOINTMENT = planned interaction
 *   SCHEDULE = allocated time/resource
 *   CHECK-IN = patient arrival/confirmation
 *   QUEUE = current waiting/work order
 *   SERVICE = actual encounter/service
 *   FOLLOW-UP = what happens after
 */

import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi, encountersApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Spinner,
  StatusChip,
  formatDateTime,
} from '../components/ui';
import {
  CalendarDays,
  CheckCircle,
  Clock,
  ListChecks,
  Stethoscope,
  UserRound,
  ArrowRight,
  Play,
} from 'lucide-react';
import './patient-flow.css';

// ─── Appointment status flow ───

const STATUS_FLOW: Record<string, { next: string | null; label: string; color: string }> = {
  booked: { next: 'checked_in', label: 'Booked', color: '#3b82f6' },
  checked_in: { next: 'in_consultation', label: 'Checked In', color: '#f59e0b' },
  in_consultation: { next: 'completed', label: 'In Consultation', color: '#8b5cf6' },
  completed: { next: null, label: 'Completed', color: '#10b981' },
  cancelled: { next: null, label: 'Cancelled', color: '#ef4444' },
  no_show: { next: null, label: 'No Show', color: '#6b7280' },
};

// ─── Flow step definitions ───
interface FlowStep {
  id: string;
  label: string;
  Icon: any;
  status: 'completed' | 'current' | 'pending' | 'blocked';
  description?: string;
}

function getFlowSteps(appointment: any): FlowStep[] {
  const steps: FlowStep[] = [
    {
      id: 'scheduled',
      label: 'Scheduled',
      Icon: CalendarDays,
      status: 'completed',
      description: `${formatDateTime(appointment.startsAt)}`,
    },
    {
      id: 'checkin',
      label: 'Check-in',
      Icon: UserRound,
      status: appointment.status === 'booked' ? 'current' :
             ['checked_in', 'in_consultation', 'completed'].includes(appointment.status) ? 'completed' : 'pending',
      description: appointment.status === 'checked_in' ? `Token #${appointment.tokenNo}` : undefined,
    },
    {
      id: 'queue',
      label: 'Queue',
      Icon: ListChecks,
      status: appointment.status === 'checked_in' ? 'current' :
             ['in_consultation', 'completed'].includes(appointment.status) ? 'completed' : 'pending',
    },
    {
      id: 'consultation',
      label: 'Consultation',
      Icon: Stethoscope,
      status: appointment.status === 'in_consultation' ? 'current' :
              appointment.status === 'completed' ? 'completed' : 'pending',
      description: appointment.provider?.fullName || undefined,
    },
    {
      id: 'next',
      label: 'Next Step',
      Icon: ArrowRight,
      status: appointment.status === 'completed' ? 'current' : 'pending',
      description: appointment.status === 'completed' ? 'Follow-up' : undefined,
    },
  ];

  if (appointment.status === 'cancelled' || appointment.status === 'no_show') {
    return steps.map(s => ({
      ...s,
      status: s.id === 'scheduled' ? 'completed' : 'blocked',
    }));
  }

  return steps;
}

// ════════════════════════════════════════════════════════════════════════════
// FLOW STEP INDICATOR
// ════════════════════════════════════════════════════════════════════════════
function FlowStepIndicator({ steps }: { steps: FlowStep[] }) {
  return (
    <div className="flow-steps" role="list" aria-label="Patient flow">
      {steps.map((step, i) => (
        <div
          key={step.id}
          className={`flow-step flow-step--${step.status}`}
          role="listitem"
        >
          <div className="flow-step__icon">
            <step.Icon size={16} strokeWidth={2} />
          </div>
          <div className="flow-step__info">
            <span className="flow-step__label">{step.label}</span>
            {step.description && (
              <span className="flow-step__desc">{step.description}</span>
            )}
          </div>
          {i < steps.length - 1 && (
            <div className={`flow-step__connector flow-step__connector--${step.status === 'completed' ? 'complete' : 'pending'}`} />
          )}
        </div>
      ))}
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// APPOINTMENT FLOW CARD
// ════════════════════════════════════════════════════════════════════════════
function AppointmentFlowCard({
  appointment,
  canCheckIn,
  canStartEncounter,
  onCheckIn,
  onStartEncounter,
  actionBusy,
}: {
  appointment: any;
  canCheckIn: boolean;
  canStartEncounter: boolean;
  onCheckIn: (id: string) => void;
  onStartEncounter: (id: string) => void;
  actionBusy: string | null;
}) {
  const steps = getFlowSteps(appointment);
  const statusInfo = STATUS_FLOW[appointment.status] || STATUS_FLOW.booked;
  const isActionable = (appointment.status === 'booked' && canCheckIn) ||
                       (appointment.status === 'checked_in' && canStartEncounter);

  return (
    <Card className={`flow-card ${isActionable ? 'flow-card--actionable' : ''}`}>
      <div className="flow-card__header">
        <div className="flow-card__patient">
          <Link to={`/patients/${appointment.patientId}`} className="flow-card__patient-link">
            <UserRound size={16} />
            <span>{appointment.patient?.fullName ?? 'Unknown Patient'}</span>
            <span className="flow-card__mrn mono">{appointment.patient?.mrn ?? ''}</span>
          </Link>
        </div>
        <div className="flow-card__status">
          <StatusChip tone={statusInfo.color === '#10b981' ? 'success' : statusInfo.color === '#ef4444' ? 'danger' : statusInfo.color === '#8b5cf6' ? 'info' : 'neutral'} label={statusInfo.label} />
        </div>
      </div>

      <div className="flow-card__meta">
        {appointment.tokenNo && (
          <span className="flow-card__token">#{appointment.tokenNo}</span>
        )}
        <span className="flow-card__time">
          <Clock size={13} />
          {formatDateTime(appointment.startsAt)}
        </span>
        {appointment.provider && (
          <span className="flow-card__provider">
            <Stethoscope size={13} />
            {appointment.provider.fullName}
          </span>
        )}
      </div>

      <FlowStepIndicator steps={steps} />

      {isActionable && (
        <div className="flow-card__actions">
          {appointment.status === 'booked' && canCheckIn && (
            <Button
              size="sm"
              onClick={() => onCheckIn(appointment.id)}
              loading={actionBusy === appointment.id}
            >
              <CheckCircle size={14} /> Check In
            </Button>
          )}
          {appointment.status === 'checked_in' && canStartEncounter && (
            <Button
              size="sm"
              onClick={() => onStartEncounter(appointment.id)}
              loading={actionBusy === appointment.id}
            >
              <Play size={14} /> Start Consultation
            </Button>
          )}
        </div>
      )}
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// QUEUE STATUS CARD
// ════════════════════════════════════════════════════════════════════════════
function QueueStatusCard({
  entries,
  onCallNext,
  canCallNext,
  calling,
}: {
  entries: any[];
  onCallNext: () => void;
  canCallNext: boolean;
  calling: boolean;
}) {
  const waiting = entries.filter(e => e.status === 'checked_in');
  const inConsultation = entries.filter(e => e.status === 'in_consultation');
  const next = waiting[0];

  return (
    <Card className="flow-queue-card">
      <div className="flow-queue__header">
        <h3>Current Queue</h3>
        <span className="flow-queue__count">
          <ListChecks size={14} />
          {entries.length} in queue
        </span>
      </div>

      {entries.length === 0 ? (
        <div className="flow-queue__empty">
          <CheckCircle size={24} />
          <span>Queue is clear</span>
        </div>
      ) : (
        <div className="flow-queue__summary">
          {waiting.length > 0 && (
            <div className="flow-queue__section">
              <span className="flow-queue__section-label">Waiting ({waiting.length})</span>
              {waiting.slice(0, 3).map((e, i) => (
                <div key={e.appointmentId} className="flow-queue__patient">
                  <span className="flow-queue__position">#{e.tokenNo || i + 1}</span>
                  <span>{e.patient?.fullName ?? 'Unknown'}</span>
                </div>
              ))}
              {waiting.length > 3 && (
                <span className="flow-queue__more">+{waiting.length - 3} more</span>
              )}
            </div>
          )}

          {inConsultation.length > 0 && (
            <div className="flow-queue__section">
              <span className="flow-queue__section-label">In Consultation ({inConsultation.length})</span>
              {inConsultation.slice(0, 2).map((e) => (
                <div key={e.appointmentId} className="flow-queue__patient flow-queue__patient--active">
                  <span className="flow-queue__position flow-queue__position--active">
                    <Stethoscope size={12} />
                  </span>
                  <span>{e.patient?.fullName ?? 'Unknown'}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {canCallNext && next && (
        <Button onClick={onCallNext} loading={calling} className="flow-queue__call-btn">
          <Play size={14} /> Call Next Patient
        </Button>
      )}
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// SCHEDULE OVERVIEW
// ════════════════════════════════════════════════════════════════════════════
function ScheduleOverview({
  appointments,
}: {
  appointments: any[];
}) {
  const byHour = useMemo(() => {
    const map = new Map<number, any[]>();
    for (const a of appointments) {
      const h = new Date(a.startsAt).getHours();
      if (!map.has(h)) map.set(h, []);
      map.get(h)!.push(a);
    }
    return map;
  }, [appointments]);

  const hours = Array.from({ length: 13 }, (_, i) => i + 7); // 7am-7pm

  return (
    <Card title="Today's Schedule" className="flow-schedule">
      <div className="flow-schedule__hours">
        {hours.map(h => {
          const hourAppts = byHour.get(h) ?? [];
          return (
            <div key={h} className="flow-schedule__hour">
              <span className="flow-schedule__time">{h.toString().padStart(2, '0')}:00</span>
              <div className="flow-schedule__slots">
                {hourAppts.map(a => (
                  <Link
                    key={a.id}
                    to={`/clinical/appointments/${a.id}`}
                    className={`flow-schedule__slot flow-schedule__slot--${a.status}`}
                  >
                    <span className="flow-schedule__slot-time">
                      {new Date(a.startsAt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}
                    </span>
                    <span className="flow-schedule__slot-patient">{a.patient?.fullName ?? '—'}</span>
                  </Link>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN PATIENT FLOW ORCHESTRATOR
// ════════════════════════════════════════════════════════════════════════════
export function PatientFlowOrchestrator() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;
  const navigate = useNavigate();

  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [actionBusy, setActionBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const canCheckIn = hasRole('hospital_admin', 'receptionist', 'nurse');
  const canStartEncounter = hasRole('doctor', 'hospital_admin');
  const canManageQueue = hasRole('hospital_admin', 'receptionist', 'nurse');

  const appointments = useFetch(
    () => appointmentsApi.list({ date, facilityId: fac }),
    [date, fac],
  );

  const queue = useFetch(
    () => appointmentsApi.queue({ date, facilityId: fac }),
    [date, fac],
  );

  const appointmentsList = (appointments.data ?? []) as any[];
  const queueEntries = (queue.data ?? []) as any[];

  // Status summary
  const statusCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const a of appointmentsList) {
      counts[a.status] = (counts[a.status] || 0) + 1;
    }
    return counts;
  }, [appointmentsList]);

  // Check-in handler
  const handleCheckIn = useCallback(async (id: string) => {
    setActionBusy(id);
    try {
      const checked = await appointmentsApi.checkIn(id, fac);
      setNotice({ tone: 'success', text: `Patient checked in — Token #${checked.tokenNo}` });
      void appointments.refresh();
      void queue.refresh();
    } catch (err: any) {
      setNotice({ tone: 'danger', text: err?.message || 'Check-in failed' });
    } finally {
      setActionBusy(null);
    }
  }, [fac, appointments, queue]);

  // Start encounter handler
  const handleStartEncounter = useCallback(async (id: string) => {
    setActionBusy(id);
    try {
      const enc = await encountersApi.start(id, fac);
      navigate(`/clinical/encounters/${enc.id}`);
    } catch (err: any) {
      setNotice({ tone: 'danger', text: err?.message || 'Failed to start encounter' });
    } finally {
      setActionBusy(null);
    }
  }, [fac, navigate]);

  // Date navigation
  const shiftDate = (delta: number) => {
    const d = new Date(date);
    d.setDate(d.getDate() + delta);
    setDate(d.toISOString().slice(0, 10));
  };

  const isToday = date === new Date().toISOString().slice(0, 10);

  if (appointments.loading && !appointments.data) {
    return <div className="flow-page"><Spinner /></div>;
  }

  return (
    <div className="flow-page" data-testid="patient-flow-orchestrator">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {/* Header */}
      <div className="flow-page__header">
        <h1>Patient Flow</h1>
        <div className="flow-page__date-nav">
          <button type="button" className="flow-date-btn" onClick={() => shiftDate(-1)}>← Prev</button>
          <input
            type="date"
            className="flow-date-input"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          {!isToday && (
            <button type="button" className="flow-date-btn" onClick={() => setDate(new Date().toISOString().slice(0, 10))}>
              Today
            </button>
          )}
          <button type="button" className="flow-date-btn" onClick={() => shiftDate(1)}>Next →</button>
        </div>
      </div>

      {/* Status Summary */}
      {appointmentsList.length > 0 && (
        <div className="flow-status-bar">
          <div className="flow-status-item">
            <span className="flow-status-value">{appointmentsList.length}</span>
            <span className="flow-status-label">Total</span>
          </div>
          <div className="flow-status-item">
            <span className="flow-status-value" style={{ color: '#3b82f6' }}>{statusCounts['booked'] ?? 0}</span>
            <span className="flow-status-label">Booked</span>
          </div>
          <div className="flow-status-item">
            <span className="flow-status-value" style={{ color: '#f59e0b' }}>{statusCounts['checked_in'] ?? 0}</span>
            <span className="flow-status-label">Checked In</span>
          </div>
          <div className="flow-status-item">
            <span className="flow-status-value" style={{ color: '#8b5cf6' }}>{statusCounts['in_consultation'] ?? 0}</span>
            <span className="flow-status-label">In Service</span>
          </div>
          <div className="flow-status-item">
            <span className="flow-status-value" style={{ color: '#10b981' }}>{statusCounts['completed'] ?? 0}</span>
            <span className="flow-status-label">Completed</span>
          </div>
        </div>
      )}

      {/* Main Content */}
      <div className="flow-content">
        {/* Left: Appointment Cards */}
        <div className="flow-appointments">
          <h2>Today's Patients</h2>
          {appointments.loading ? (
            <Spinner />
          ) : appointmentsList.length === 0 ? (
            <EmptyState
              title="No appointments today"
              body={canCheckIn ? "Book an appointment to start the patient flow." : "No appointments scheduled."}
            />
          ) : (
            <div className="flow-appointments-list">
              {appointmentsList.map((apt: any) => (
                <AppointmentFlowCard
                  key={apt.id}
                  appointment={apt}
                  canCheckIn={canCheckIn}
                  canStartEncounter={canStartEncounter}
                  onCheckIn={handleCheckIn}
                  onStartEncounter={handleStartEncounter}
                  actionBusy={actionBusy}
                />
              ))}
            </div>
          )}
        </div>

        {/* Right: Queue & Schedule */}
        <div className="flow-sidebar">
          <QueueStatusCard
            entries={queueEntries}
            canCallNext={canManageQueue}
            onCallNext={() => {
              const next = queueEntries.find((e: any) => e.status === 'checked_in');
              if (next) handleStartEncounter(next.appointmentId);
            }}
            calling={actionBusy !== null}
          />

          <ScheduleOverview appointments={appointmentsList} />
        </div>
      </div>
    </div>
  );
}

export default PatientFlowOrchestrator;
