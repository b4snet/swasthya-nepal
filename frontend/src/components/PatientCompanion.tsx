/**
 * PatientCompanion — Secure Patient Self-Service (Phase 114)
 *
 * Answers: "WHAT DO I NEED TO KNOW OR DO TODAY?"
 *
 * Architecture: CANONICAL HOSPITAL SYSTEMS + SECURE PATIENT EXPERIENCE + EXPLICIT AUTHORIZATION
 *
 * This is NOT a generic consumer super-app.
 * This is NOT a duplicate clinical record.
 * This IS a secure experience layer over canonical systems.
 *
 * Patient experience:
 *   TODAY → APPOINTMENTS → CHECK-IN → QUEUE → RESULTS → DOCUMENTS → PAYMENTS → FOLLOW-UP
 *
 * Safety:
 * - Patients cannot alter diagnoses, results, or signed clinical records
 * - Every state derived from canonical authoritative systems
 * - No duplicate appointment, payment, result, or document systems
 * - AI (if present) cannot diagnose, prescribe, or access other patients' data
 */

import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { appointmentsApi, portalApi } from '../api/endpoints';
import type { Appointment } from '../api/types';
import {
  CheckCircle2,
  CalendarDays,
  FileText,
  Pill,
  FlaskConical,
  Wallet,
  MessageSquare,
  ChevronRight,
  RefreshCw,
  Shield,
  CreditCard,
} from 'lucide-react';
import './patient-companion.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface CompanionSection {
  id: string;
  label: string;
  Icon: React.ReactNode;
  count?: number;
  color: string;
  description: string;
  actionTo: string;
  isEmpty?: boolean;
  emptyMessage?: string;
}

/* ────────────────────────────────────────────────────────────────────
   MAIN PATIENT COMPANION
   ──────────────────────────────────────────────────────────────────── */

export function PatientCompanion() {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();

  // ── Fetch patient data ──
  const appointments = useFetch(
    () => appointmentsApi.list({ date: new Date().toISOString().split('T')[0], facilityId: selectedFacilityId }),
    [selectedFacilityId],
  );

  const patient = useFetch(
    () => portalApi.me().catch(() => null),
    [],
  );

  const messages = useFetch(
    () => portalApi.messages().catch(() => []),
    [],
  );

  const patientData = patient.data as any;
  const patientName = patientData?.fullName ?? patientData?.name ?? 'Patient';
  const todayAppts = (appointments.data as Appointment[]) ?? [];
  const messageData = (messages.data as any[]) ?? [];

  // ── Today's appointments ──
  const todayAppointments = useMemo(() => {
    const today = new Date().toISOString().split('T')[0];
    return todayAppts.filter((a) => {
      const d = (a.startsAt ?? '').split('T')[0];
      return d === today && !['cancelled', 'no_show'].includes(a.status);
    });
  }, [todayAppts]);

  const nextAppointment = todayAppointments.find((a) => a.status === 'booked' || a.status === 'checked_in');

  // ── Unread messages ──
  const unreadMessages = messageData.filter((m: any) => !m.readAt);

  // ── Sections ──
  const sections: CompanionSection[] = useMemo(() => [
    {
      id: 'appointments',
      label: 'Today\'s Appointments',
      Icon: <CalendarDays size={20} />,
      count: todayAppointments.length,
      color: 'var(--teal-600)',
      description: 'Your scheduled visits for today',
      actionTo: '/portal/appointments',
      isEmpty: todayAppointments.length === 0,
      emptyMessage: 'No appointments scheduled for today',
    },
    {
      id: 'results',
      label: 'Lab Results',
      Icon: <FlaskConical size={20} />,
      count: 0,
      color: 'var(--violet-600)',
      description: 'Available test results',
      actionTo: '/portal/results',
    },
    {
      id: 'documents',
      label: 'Documents',
      Icon: <FileText size={20} />,
      count: 0,
      color: 'var(--blue-600)',
      description: 'Medical records and reports',
      actionTo: '/portal/documents',
    },
    {
      id: 'medications',
      label: 'Medications',
      Icon: <Pill size={20} />,
      count: 0,
      color: 'var(--amber-600)',
      description: 'Current prescriptions',
      actionTo: '/portal/medications',
    },
    {
      id: 'payments',
      label: 'Payments',
      Icon: <Wallet size={20} />,
      count: 0,
      color: 'var(--green-600)',
      description: 'Bills and payment status',
      actionTo: '/portal/bills',
    },
    {
      id: 'messages',
      label: 'Messages',
      Icon: <MessageSquare size={20} />,
      count: unreadMessages.length,
      color: 'var(--pink-600)',
      description: 'Communications from the hospital',
      actionTo: '/portal/messages',
      isEmpty: unreadMessages.length === 0,
      emptyMessage: 'No new messages',
    },
  ], [todayAppointments, unreadMessages]);

  const handleRefresh = () => {
    appointments.refresh();
    messages.refresh();
  };

  if (patient.loading) {
    return (
      <div className="pc-loading" role="status">
        <div className="spinner" />
        <span>Loading your health companion…</span>
      </div>
    );
  }

  return (
    <div className="patient-companion" role="main" aria-label="Patient companion">
      {/* Header */}
      <div className="pc-header">
        <div className="pc-header__info">
          <div className="pc-header__greeting">
            <h1 className="pc-header__title">Hello, {patientName}</h1>
            <span className="pc-header__date">
              {new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })}
            </span>
          </div>
        </div>
        <button
          type="button"
          className="pc-refresh"
          onClick={handleRefresh}
          aria-label="Refresh"
        >
          <RefreshCw size={14} />
        </button>
      </div>

      {/* Next appointment highlight */}
      {nextAppointment && (
        <div className="pc-highlight" role="region" aria-label="Next appointment">
          <div className="pc-highlight__icon">
            <CalendarDays size={20} />
          </div>
          <div className="pc-highlight__info">
            <span className="pc-highlight__label">Upcoming</span>
            <span className="pc-highlight__time">
              {new Date(nextAppointment.startsAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
            </span>
            <span className="pc-highlight__type">{nextAppointment.appointmentType ?? 'Appointment'}</span>
          </div>
          <div className="pc-highlight__actions">
            {nextAppointment.status === 'booked' && (
              <button
                type="button"
                className="pc-btn pc-btn--primary pc-btn--sm"
                onClick={() => navigate('/checkin')}
              >
                Check In
              </button>
            )}
            {nextAppointment.status === 'checked_in' && (
              <button
                type="button"
                className="pc-btn pc-btn--primary pc-btn--sm"
                onClick={() => navigate('/flow')}
              >
                View Queue
              </button>
            )}
          </div>
        </div>
      )}

      {/* Sections grid */}
      <div className="pc-sections" role="list" aria-label="Health services">
        {sections.map((section) => (
          <button
            key={section.id}
            type="button"
            className="pc-section"
            onClick={() => navigate(section.actionTo)}
            aria-label={`${section.label}${section.count ? ` (${section.count})` : ''}`}
            data-testid={`pc-section-${section.id}`}
          >
            <div className="pc-section__icon" style={{ color: section.color }}>
              {section.Icon}
            </div>
            <div className="pc-section__info">
              <span className="pc-section__label">{section.label}</span>
              <span className="pc-section__desc">{section.description}</span>
            </div>
            <div className="pc-section__right">
              {section.count !== undefined && section.count > 0 && (
                <span className="pc-section__badge">{section.count}</span>
              )}
              {section.isEmpty && (
                <CheckCircle2 size={14} className="pc-section__check" />
              )}
              <ChevronRight size={14} className="pc-section__arrow" />
            </div>
          </button>
        ))}
      </div>

      {/* Quick actions */}
      <div className="pc-actions">
        <h3 className="pc-actions__title">Quick Actions</h3>
        <div className="pc-actions__grid">
          <button
            type="button"
            className="pc-action"
            onClick={() => navigate('/checkin')}
          >
            <CheckCircle2 size={16} />
            <span>Check In</span>
          </button>
          <button
            type="button"
            className="pc-action"
            onClick={() => navigate('/portal/appointments')}
          >
            <CalendarDays size={16} />
            <span>Book Appointment</span>
          </button>
          <button
            type="button"
            className="pc-action"
            onClick={() => navigate('/portal/messages')}
          >
            <MessageSquare size={16} />
            <span>Send Message</span>
          </button>
          <button
            type="button"
            className="pc-action"
            onClick={() => navigate('/portal/bills')}
          >
            <CreditCard size={16} />
            <span>Pay Bill</span>
          </button>
        </div>
      </div>

      {/* Privacy notice */}
      <div className="pc-notice" role="note">
        <Shield size={12} />
        <span>
          Your information is securely managed through the hospital's authorized systems.
          You can only see information explicitly released for your access.
        </span>
      </div>
    </div>
  );
}

export default PatientCompanion;
