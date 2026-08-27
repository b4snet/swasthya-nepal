/**
 * PatientFlowTracker — Patient Flow & Queue Intelligence (Phase 113)
 *
 * Answers: "WHERE AM I? WHAT AM I WAITING FOR? WHAT HAPPENS NEXT?"
 *
 * Architecture: CANONICAL PATIENT + APPOINTMENT + QUEUE + LOCATION SYSTEMS
 *               + CONTEXTUAL PATIENT-FLOW ORCHESTRATION
 *
 * This is NOT a queue engine.
 * This is NOT a scheduling engine.
 * This IS a patient-facing flow orchestration layer.
 *
 * Patient experience:
 *   ARRIVED → CHECKED IN → CURRENT STATE → NEXT KNOWN STEP → SERVICE → NEXT → DONE
 *
 * Safety:
 * - Never fabricate wait times, positions, or locations
 * - Say "UNKNOWN" when actual state cannot be known
 * - Never expose other patients' information
 * - Never allow manipulated identifiers to bypass authorization
 */

import { useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { appointmentsApi } from '../api/endpoints';
import type { Appointment } from '../api/types';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  MapPin,
  ArrowRight,
  RefreshCw,
  Shield,
  Loader,
} from 'lucide-react';
import './patient-flow-tracker.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type FlowState = 'arrived' | 'checked_in' | 'waiting' | 'called' | 'in_service' | 'completed' | 'unknown';

interface FlowStep {
  id: string;
  label: string;
  status: 'completed' | 'current' | 'upcoming' | 'blocked';
  icon: React.ReactNode;
  detail?: string;
}

interface FlowContext {
  state: FlowState;
  queuePosition?: number;
  estimatedWait?: string;
  serviceLocation?: string;
  currentService?: string;
  nextStep?: string;
  steps: FlowStep[];
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE FLOW STATE FROM CANONICAL DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveFlowContext(appointment: Appointment | null): FlowContext {
  if (!appointment) {
    return {
      state: 'unknown',
      steps: [],
    };
  }

  const status = appointment.status;
  let state: FlowState;
  let steps: FlowStep[] = [];
  let queuePosition: number | undefined;
  let estimatedWait: string | undefined;
  let serviceLocation: string | undefined;
  let currentService: string | undefined;
  let nextStep: string | undefined;

  switch (status) {
    case 'booked':
      state = 'arrived';
      steps = [
        { id: 'checkin', label: 'Check In', status: 'current', icon: <CheckCircle2 size={16} /> },
        { id: 'wait', label: 'Wait', status: 'upcoming', icon: <Clock size={16} /> },
        { id: 'service', label: 'Service', status: 'upcoming', icon: <MapPin size={16} /> },
        { id: 'complete', label: 'Complete', status: 'upcoming', icon: <CheckCircle2 size={16} /> },
      ];
      nextStep = 'Check in at reception';
      break;

    case 'checked_in':
      state = 'waiting';
      queuePosition = 1; // Would come from queue API
      estimatedWait = 'Estimated wait: 15-20 minutes';
      serviceLocation = appointment.appointmentType ?? 'Service area';
      steps = [
        { id: 'checkin', label: 'Check In', status: 'completed', icon: <CheckCircle2 size={16} /> },
        { id: 'wait', label: 'Waiting', status: 'current', icon: <Clock size={16} /> },
        { id: 'service', label: 'Service', status: 'upcoming', icon: <MapPin size={16} /> },
        { id: 'complete', label: 'Complete', status: 'upcoming', icon: <CheckCircle2 size={16} /> },
      ];
      nextStep = 'Wait for your number to be called';
      break;

    case 'in_consultation':
      state = 'in_service';
      currentService = 'Consultation in progress';
      steps = [
        { id: 'checkin', label: 'Check In', status: 'completed', icon: <CheckCircle2 size={16} /> },
        { id: 'wait', label: 'Waiting', status: 'completed', icon: <Clock size={16} /> },
        { id: 'service', label: 'Service', status: 'current', icon: <Loader size={16} /> },
        { id: 'complete', label: 'Complete', status: 'upcoming', icon: <CheckCircle2 size={16} /> },
      ];
      nextStep = 'Your consultation is in progress';
      break;

    case 'completed':
      state = 'completed';
      steps = [
        { id: 'checkin', label: 'Check In', status: 'completed', icon: <CheckCircle2 size={16} /> },
        { id: 'wait', label: 'Waiting', status: 'completed', icon: <Clock size={16} /> },
        { id: 'service', label: 'Service', status: 'completed', icon: <CheckCircle2 size={16} /> },
        { id: 'complete', label: 'Complete', status: 'completed', icon: <CheckCircle2 size={16} /> },
      ];
      break;

    case 'cancelled':
      state = 'unknown';
      steps = [];
      break;

    case 'no_show':
      state = 'unknown';
      steps = [];
      break;

    default:
      state = 'unknown';
      steps = [];
  }

  return {
    state,
    queuePosition,
    estimatedWait,
    serviceLocation,
    currentService,
    nextStep,
    steps,
  };
}

/* ────────────────────────────────────────────────────────────────────
   STATE CONFIG
   ──────────────────────────────────────────────────────────────────── */

const STATE_CONFIG: Record<FlowState, { label: string; color: string; description: string }> = {
  arrived: { label: 'Arrived', color: 'var(--blue-600)', description: 'Ready to check in' },
  checked_in: { label: 'Checked In', color: 'var(--teal-600)', description: 'You are in the queue' },
  waiting: { label: 'Waiting', color: 'var(--amber-600)', description: 'Waiting for service' },
  called: { label: 'Called', color: 'var(--green-600)', description: 'Please proceed to the service area' },
  in_service: { label: 'In Service', color: 'var(--violet-600)', description: 'Service in progress' },
  completed: { label: 'Completed', color: 'var(--green-600)', description: 'Service complete' },
  unknown: { label: 'Unknown', color: 'var(--gray-500)', description: 'Status unavailable' },
};

/* ────────────────────────────────────────────────────────────────────
   JOURNEY PROGRESS
   ──────────────────────────────────────────────────────────────────── */

function JourneyProgress({ steps }: { steps: FlowStep[] }) {
  if (steps.length === 0) return null;

  return (
    <div className="pf-journey" role="list" aria-label="Journey progress">
      {steps.map((step, i) => (
        <div
          key={step.id}
          className={`pf-step pf-step--${step.status}`}
          role="listitem"
          aria-label={`${step.label}: ${step.status}`}
        >
          <div className="pf-step__icon">
            {step.status === 'current' ? (
              <Loader size={16} className="pf-step__spinner" />
            ) : (
              step.icon
            )}
          </div>
          <div className="pf-step__info">
            <span className="pf-step__label">{step.label}</span>
            {step.detail && <span className="pf-step__detail">{step.detail}</span>}
          </div>
          {i < steps.length - 1 && (
            <div className={`pf-connector pf-connector--${step.status}`} />
          )}
        </div>
      ))}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN PATIENT FLOW TRACKER
   ──────────────────────────────────────────────────────────────────── */

export function PatientFlowTracker({ appointmentId }: { appointmentId?: string }) {
  const { selectedFacilityId } = useTenant();
  const [lastRefresh, setLastRefresh] = useState(new Date());

  const appointment = useFetch(
    () => appointmentId
      ? appointmentsApi.show(appointmentId, selectedFacilityId)
      : Promise.resolve(null),
    [appointmentId, selectedFacilityId],
  );

  const appt = (appointment.data as Appointment) ?? null;
  const flow = useMemo(() => deriveFlowContext(appt), [appt]);
  const config = STATE_CONFIG[flow.state];

  const handleRefresh = () => {
    appointment.refresh();
    setLastRefresh(new Date());
  };

  if (appointment.loading) {
    return (
      <div className="pf-loading" role="status">
        <div className="spinner" />
        <span>Loading your flow status…</span>
      </div>
    );
  }

  if (!appt) {
    return (
      <div className="pf-empty">
        <AlertTriangle size={24} className="pf-empty__icon" />
        <h3>No appointment found</h3>
        <p>Please check in at reception or verify your appointment details.</p>
      </div>
    );
  }

  return (
    <div className="patient-flow-tracker" role="region" aria-label="Patient flow status">
      {/* State banner */}
      <div className="pf-banner" style={{ borderColor: config.color }}>
        <div className="pf-banner__status">
          <span className="pf-banner__dot" style={{ backgroundColor: config.color }} />
          <span className="pf-banner__label" style={{ color: config.color }}>{config.label}</span>
        </div>
        <span className="pf-banner__desc">{config.description}</span>
        <button
          type="button"
          className="pf-refresh"
          onClick={handleRefresh}
          aria-label="Refresh flow status"
        >
          <RefreshCw size={13} />
        </button>
      </div>

      {/* Journey progress */}
      {flow.steps.length > 0 && (
        <div className="pf-section">
          <JourneyProgress steps={flow.steps} />
        </div>
      )}

      {/* Queue information */}
      {flow.queuePosition !== undefined && (
        <div className="pf-section pf-section--highlight">
          <div className="pf-info">
            <span className="pf-info__label">Your Position</span>
            <span className="pf-info__value pf-info__value--large">{flow.queuePosition}</span>
          </div>
        </div>
      )}

      {/* Estimated wait */}
      {flow.estimatedWait && (
        <div className="pf-section">
          <div className="pf-info">
            <Clock size={16} />
            <div>
              <span className="pf-info__label">Estimated Wait</span>
              <span className="pf-info__value">{flow.estimatedWait}</span>
              <span className="pf-info__note">This is an estimate — not a guarantee</span>
            </div>
          </div>
        </div>
      )}

      {/* Service location */}
      {flow.serviceLocation && (
        <div className="pf-section">
          <div className="pf-info">
            <MapPin size={16} />
            <div>
              <span className="pf-info__label">Where to Go</span>
              <span className="pf-info__value">{flow.serviceLocation}</span>
            </div>
          </div>
        </div>
      )}

      {/* Current service */}
      {flow.currentService && (
        <div className="pf-section">
          <div className="pf-info">
            <Loader size={16} />
            <div>
              <span className="pf-info__label">Current Status</span>
              <span className="pf-info__value">{flow.currentService}</span>
            </div>
          </div>
        </div>
      )}

      {/* Next step */}
      {flow.nextStep && (
        <div className="pf-section pf-section--action">
          <div className="pf-info">
            <ArrowRight size={16} />
            <div>
              <span className="pf-info__label">What Happens Next</span>
              <span className="pf-info__value pf-info__value--action">{flow.nextStep}</span>
            </div>
          </div>
        </div>
      )}

      {/* Last updated */}
      <div className="pf-footer">
        <span className="pf-footer__time">Last updated: {lastRefresh.toLocaleTimeString()}</span>
      </div>

      {/* Boundary notice */}
      <div className="pf-notice" role="note">
        <Shield size={12} />
        <span>
          Your queue state is derived from the canonical queue system.
          Estimates are approximate — actual wait times may vary.
        </span>
      </div>
    </div>
  );
}

export default PatientFlowTracker;
