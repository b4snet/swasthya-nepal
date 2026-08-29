/**
 * Appointments Page — Day View with Slot Visualization
 *
 * Shows the day's appointments in a timeline layout with
 * provider columns. Supports booking, check-in, and status transitions.
 */

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi, patientsApi, catalogsApi, scheduleApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  AppointmentStatus,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Input,
  Select,
  Spinner,
  StatusChip,
  formatDateTime,
} from '../components/ui';
import { ApiError } from '../api/client';
import {
  Clock,
  UserRound,
  Plus,
  CheckCircle,
} from 'lucide-react';
import './appointments.css';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/* ════════════════════════════════════════════════════════════════════════════
   MAIN PAGE
   ════════════════════════════════════════════════════════════════════════════ */

export function AppointmentsPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;
  const [date, setDate] = useState(today());
  const [view, setView] = useState<'list' | 'timeline'>('list');
  const [showBookDialog, setShowBookDialog] = useState(false);

  const list = useFetch(() => appointmentsApi.list({ date, facilityId: fac }), [date, fac]);

  const canBook = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');
  const canCheckIn = hasRole('hospital_admin', 'receptionist', 'nurse');

  const appointments = (list.data ?? []) as any[];

  /* ── Status counts ── */
  const statusCounts = appointments.reduce(
    (acc: Record<string, number>, a: any) => {
      acc[a.status] = (acc[a.status] || 0) + 1;
      return acc;
    },
    {} as Record<string, number>,
  );

  /* ── Navigate days ── */
  const shiftDate = (delta: number) => {
    const d = new Date(date);
    d.setDate(d.getDate() + delta);
    setDate(d.toISOString().slice(0, 10));
  };

  const isToday = date === today();

  return (
    <div className="page">
      {/* ── Header ── */}
      <div className="page__head">
        <div className="page__title">
          <h1>Appointments</h1>
          <span className="page__sub">
            {new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
              weekday: 'long',
              year: 'numeric',
              month: 'long',
              day: 'numeric',
            })}
          </span>
        </div>
        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
          <div className="appts__view-toggle">
            <button
              type="button"
              className={`appts__view-btn ${view === 'list' ? 'appts__view-btn--active' : ''}`}
              onClick={() => setView('list')}
            >
              List
            </button>
            <button
              type="button"
              className={`appts__view-btn ${view === 'timeline' ? 'appts__view-btn--active' : ''}`}
              onClick={() => setView('timeline')}
            >
              Timeline
            </button>
          </div>
          {canBook && (
            <Button onClick={() => setShowBookDialog(true)}>
              <Plus size={16} /> Book appointment
            </Button>
          )}
        </div>
      </div>

      {/* ── Date navigation ── */}
      <div className="appts__datebar">
        <button type="button" className="appts__nav-btn" onClick={() => shiftDate(-1)}>
          ← Prev
        </button>
        <input
          id="appt-date"
          className="input"
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          style={{ width: 180 }}
        />
        {!isToday && (
          <button type="button" className="appts__nav-btn" onClick={() => setDate(today())}>
            Today
          </button>
        )}
        <button type="button" className="appts__nav-btn" onClick={() => shiftDate(1)}>
          Next →
        </button>
      </div>

      {/* ── Status summary ── */}
      {appointments.length > 0 && (
        <div className="appts__summary">
          <div className="appts__stat">
            <span className="appts__stat-value">{appointments.length}</span>
            <span className="appts__stat-label">Total</span>
          </div>
          <div className="appts__stat">
            <span className="appts__stat-value appts__stat-value--booked">{statusCounts['booked'] ?? 0}</span>
            <span className="appts__stat-label">Booked</span>
          </div>
          <div className="appts__stat">
            <span className="appts__stat-value appts__stat-value--checked">{statusCounts['checked_in'] ?? 0}</span>
            <span className="appts__stat-label">Checked in</span>
          </div>
          <div className="appts__stat">
            <span className="appts__stat-value appts__stat-value--active">{statusCounts['in_consultation'] ?? 0}</span>
            <span className="appts__stat-label">In progress</span>
          </div>
          <div className="appts__stat">
            <span className="appts__stat-value appts__stat-value--done">{statusCounts['completed'] ?? 0}</span>
            <span className="appts__stat-label">Completed</span>
          </div>
        </div>
      )}

      {/* ── Content ── */}
      {list.loading ? (
        <Spinner label="Loading appointments…" />
      ) : list.error ? (
        <ErrorState error={list.error} onRetry={() => void list.refresh()} />
      ) : appointments.length === 0 ? (
        <EmptyState
          title="No appointments this day"
          body={canBook ? "Book a slot to get started." : "No appointments scheduled."}
        />
      ) : view === 'list' ? (
        <AppointmentList
          appointments={appointments}
          canCheckIn={canCheckIn}
          onRefresh={() => void list.refresh()}
        />
      ) : (
        <AppointmentTimeline appointments={appointments} />
      )}

      {/* ── Book dialog ── */}
      {showBookDialog && (
        <BookAppointmentDialog
          date={date}
          facilityId={fac}
          onClose={() => setShowBookDialog(false)}
          onBooked={() => { setShowBookDialog(false); void list.refresh(); }}
        />
      )}
    </div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   APPOINTMENT LIST
   ════════════════════════════════════════════════════════════════════════════ */

function AppointmentList({
  appointments,
  canCheckIn,
  onRefresh,
}: {
  appointments: any[];
  canCheckIn: boolean;
  onRefresh: () => void;
}) {
  const [checkingIn, setCheckingIn] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const handleCheckIn = async (appointmentId: string) => {
    setCheckingIn(appointmentId);
    try {
      await appointmentsApi.checkIn(appointmentId);
      setNotice({ tone: 'success', text: 'Patient checked in.' });
      onRefresh();
    } catch (err) {
      setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Check-in failed.' });
    } finally {
      setCheckingIn(null);
    }
  };

  return (
    <>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      <Card>
        <table className="data-table appts__table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Patient</th>
              <th>Provider</th>
              <th>Type</th>
              <th>Token</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {appointments.map((a: any) => (
              <tr key={a.id} className={`appts__row appts__row--${a.status}`}>
                <td data-label="Time" className="num">
                  <Clock size={14} style={{ marginRight: 4, opacity: 0.5 }} />
                  {formatDateTime(a.startsAt)}
                </td>
                <td data-label="Patient">
                  <Link to={`/clinical/patients/${a.patientId}`} className="appts__patient-link">
                    <UserRound size={14} />
                    {a.patient?.fullName ?? '—'}
                  </Link>
                </td>
                <td data-label="Provider">{a.provider?.fullName ?? '—'}</td>
                <td data-label="Type" className="capitalize">{a.type ?? 'opd'}</td>
                <td data-label="Token" className="mono">{a.tokenNo ?? '—'}</td>
                <td data-label="Status">
                  <AppointmentStatus status={a.status} />
                </td>
                <td>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <Link to={`/clinical/appointments/${a.id}`} className="appts__view-link">
                      View
                    </Link>
                    {canCheckIn && a.status === 'booked' && (
                      <button
                        type="button"
                        className="btn btn--sm btn--secondary"
                        onClick={() => void handleCheckIn(a.id)}
                        disabled={checkingIn === a.id}
                      >
                        <CheckCircle size={14} /> Check in
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   APPOINTMENT TIMELINE (Day View)
   ════════════════════════════════════════════════════════════════════════════ */

function AppointmentTimeline({ appointments }: { appointments: any[] }) {
  /* Group by hour */
  const hours = Array.from({ length: 14 }, (_, i) => i + 7); // 7am - 8pm
  const byHour = new Map<number, any[]>();
  for (const a of appointments) {
    const h = new Date(a.startsAt).getHours();
    if (!byHour.has(h)) byHour.set(h, []);
    byHour.get(h)!.push(a);
  }

  return (
    <div className="appts__timeline">
      {hours.map((h) => {
        const hourAppts = byHour.get(h) ?? [];
        if (hourAppts.length === 0) return null;
        return (
          <div key={h} className="appts__timeline-hour">
            <div className="appts__timeline-label">
              {h.toString().padStart(2, '0')}:00
            </div>
            <div className="appts__timeline-slots">
              {hourAppts.map((a: any) => (
                <Link
                  key={a.id}
                  to={`/clinical/appointments/${a.id}`}
                  className={`appts__timeline-card appts__timeline-card--${a.status}`}
                >
                  <span className="appts__timeline-time">
                    {new Date(a.startsAt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}
                  </span>
                  <span className="appts__timeline-patient">{a.patient?.fullName ?? '—'}</span>
                  <span className="appts__timeline-provider">{a.provider?.fullName ?? '—'}</span>
                  <StatusChip
                    tone={a.status === 'completed' ? 'success' : a.status === 'in_consultation' ? 'info' : 'neutral'}
                    label={a.status.replace('_', ' ')}
                  />
                </Link>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   BOOK APPOINTMENT DIALOG
   ════════════════════════════════════════════════════════════════════════════ */

function BookAppointmentDialog({
  date,
  facilityId,
  onClose,
  onBooked,
}: {
  date: string;
  facilityId: string | null;
  onClose: () => void;
  onBooked: () => void;
}) {
  const { organizationId } = useTenant();
  const [patientSearch, setPatientSearch] = useState("");
  const [selectedPatient, setSelectedPatient] = useState<any>(null);
  const [selectedProvider, setSelectedProvider] = useState<any>(null);
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const [type, setType] = useState("opd");
  const [notes, setNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [patientResults, setPatientResults] = useState<any[]>([]);
  const searchPatients = async (q: string) => {
    setPatientSearch(q);
    if (q.length < 2) { setPatientResults([]); return; }
    try {
      const results = await patientsApi.search(q, facilityId);
      setPatientResults(Array.isArray(results) ? results : []);
    } catch { setPatientResults([]); }
  };

  const [providerResults, setProviderResults] = useState<any[]>([]);
  const [providerSearch, setProviderSearch] = useState("");
  const searchProviders = async (q: string) => {
    setProviderSearch(q);
    if (q.length < 1) { setProviderResults([]); return; }
    try {
      if (!organizationId) { setProviderResults([]); return; }
      const staff = await catalogsApi.staff(organizationId, facilityId);
      const filtered = (Array.isArray(staff) ? staff : []).filter((s: any) =>
        s.fullName?.toLowerCase().includes(q.toLowerCase()) ||
        s.designation?.toLowerCase().includes(q.toLowerCase())
      );
      setProviderResults(filtered.slice(0, 8));
    } catch { setProviderResults([]); }
  };

  const [slots, setSlots] = useState<any[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const loadSlots = async (staffId: string) => {
    setSlotsLoading(true);
    setSelectedSlot(null);
    try {
      const available = await scheduleApi.availability(staffId, date, facilityId);
      setSlots(Array.isArray(available) ? available : []);
    } catch { setSlots([]); }
    setSlotsLoading(false);
  };

  const submit = async () => {
    if (!selectedPatient) {
      setError("Please select a patient.");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const slotData = slots.find((s: any) => s.startsAt === selectedSlot);
      await appointmentsApi.book({
        patientId: selectedPatient.id,
        providerStaffId: selectedProvider?.id ?? "",
        startsAt: selectedSlot ?? new Date().toISOString(),
        endsAt: slotData?.endsAt ?? new Date(Date.now() + 30 * 60000).toISOString(),
        appointmentType: type,
        facilityId: facilityId ?? "",
      });
      onBooked();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Booking failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onClose={onClose} title="Book appointment" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!selectedPatient}>
          Book
        </Button>
      </>
    }>
      {error && <Alert tone="danger">{error}</Alert>}

      <div style={{ marginBottom: 16 }}>
        <Input
          label="Patient"
          value={selectedPatient ? selectedPatient.fullName : patientSearch}
          onChange={(e) => { setSelectedPatient(null); void searchPatients(e.target.value); }}
          placeholder="Search by name or MRN"
          required
        />
        {patientResults.length > 0 && !selectedPatient && (
          <div className="appts__search-results">
            {patientResults.slice(0, 5).map((p: any) => (
              <button key={p.id} type="button" className="appts__search-result"
                onClick={() => { setSelectedPatient(p); setPatientSearch(p.fullName); setPatientResults([]); }}>
                {p.fullName} <span className="mono">{p.mrn}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
        <option value="opd">Consultation</option>
        <option value="follow_up">Follow-up</option>
        <option value="procedure">Procedure</option>
        <option value="teleconsult">Teleconsultation</option>
      </Select>

      <div style={{ marginBottom: 16 }}>
        <Input
          label="Provider"
          value={selectedProvider ? selectedProvider.fullName + " — " + (selectedProvider.designation ?? "") : providerSearch}
          onChange={(e) => { setSelectedProvider(null); setSlots([]); void searchProviders(e.target.value); }}
          placeholder="Search provider name or designation"
        />
        {providerResults.length > 0 && !selectedProvider && (
          <div className="appts__search-results">
            {providerResults.map((s: any) => (
              <button key={s.id} type="button" className="appts__search-result"
                onClick={() => { setSelectedProvider(s); setProviderSearch(s.fullName); setProviderResults([]); void loadSlots(s.id); }}>
                {s.fullName} <span className="muted">{s.designation ?? ""}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {selectedProvider && (
        <div style={{ marginBottom: 16 }}>
          <label className="field-label">Available times</label>
          {slotsLoading ? (
            <p className="muted" style={{ fontSize: 13 }}>Loading available slots…</p>
          ) : slots.length === 0 ? (
            <p className="muted" style={{ fontSize: 13 }}>No available slots for this provider on {date}.</p>
          ) : (
            <div className="appts__slot-grid">
              {slots.filter((s: any) => s.available).map((s: any) => {
                const time = new Date(s.startsAt).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
                const isSelected = selectedSlot === s.startsAt;
                return (
                  <button key={s.startsAt} type="button"
                    className={"appts__slot " + (isSelected ? "appts__slot--selected" : "")}
                    onClick={() => setSelectedSlot(s.startsAt)}>
                    {time}
                  </button>
                );
              })}
            </div>
          )}
        </div>
      )}

      <Input label="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes" />
    </Dialog>
  );
}
