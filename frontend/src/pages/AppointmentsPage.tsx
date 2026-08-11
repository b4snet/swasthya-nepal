import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi, catalogsApi, patientsApi, scheduleApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, AppointmentStatus, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, formatDateTime } from '../components/ui';
import { ApiError } from '../api/client';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function AppointmentsPage() {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const [date, setDate] = useState(today());

  const list = useFetch(() => appointmentsApi.list({ date, facilityId: fac }), [date, fac]);

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Appointments</h1>
          <span className="page__sub">Real slots from provider schedules</span>
        </div>
        <BookDialogLink patientIdFromQuery />
      </div>

      <div className="searchbar">
        <label className="visually-hidden" htmlFor="appt-date">
          Appointment date
        </label>
        <input id="appt-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </div>

      {list.loading ? (
        <Spinner />
      ) : list.error ? (
        <ErrorState error={list.error} onRetry={() => void list.refresh()} />
      ) : (list.data ?? []).length === 0 ? (
        <EmptyState title="No appointments this day" body="Book a slot to get started." />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Patient</th>
                <th>Provider</th>
                <th>Token</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {(list.data ?? []).map((a) => (
                <tr key={a.id}>
                  <td data-label="Time" className="num">{formatDateTime(a.startsAt)}</td>
                  <td data-label="Patient">
                    <Link to={`/patients/${a.patientId}`}>{a.patient?.fullName ?? '—'}</Link>
                  </td>
                  <td data-label="Provider">{a.provider?.fullName ?? '—'}</td>
                  <td data-label="Token" className="mono">{a.tokenNo ?? '—'}</td>
                  <td data-label="Status"><AppointmentStatus status={a.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}

function BookDialogLink({ patientIdFromQuery }: { patientIdFromQuery: boolean }) {
  const [open, setOpen] = useState(false);
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const initialPatientId = params.get('patientId') ?? '';
  const [bookedId, setBookedId] = useState<string | null>(null);

  const onBooked = useCallback(
    (id: string) => {
      setBookedId(id);
      navigate('/queue', { replace: true });
    },
    [navigate],
  );

  if (patientIdFromQuery) {
    // Deep-link from the patient profile: show the booking dialog on load.
    return (
      <>
        <Button onClick={() => setOpen(true)}>Book appointment</Button>
        <BookingDialog open={open || Boolean(initialPatientId && !bookedId)} onClose={() => { setOpen(false); navigate('/appointments'); }} onBooked={onBooked} initialPatientId={initialPatientId} />
      </>
    );
  }

  return (
    <>
      <Button onClick={() => setOpen(true)}>Book appointment</Button>
      <BookingDialog open={open || Boolean(initialPatientId && !bookedId)} onClose={() => setOpen(false)} onBooked={onBooked} initialPatientId={initialPatientId} />
    </>
  );
}

function BookingDialog({ open, onClose, onBooked, initialPatientId }: { open: boolean; onClose: () => void; onBooked: (id: string) => void; initialPatientId: string }) {
  const { selectedFacilityId, organizationId } = useTenant();
  const fac = selectedFacilityId;

  const [patientId, setPatientId] = useState(initialPatientId);
  const [patientQuery, setPatientQuery] = useState('');
  const [patientResults, setPatientResults] = useState<Array<{ id: string; mrn: string; fullName: string }>>([]);
  const [providerId, setProviderId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [date, setDate] = useState(today());
  const [slot, setSlot] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const staff = useFetch(() => catalogsApi.staff(organizationId ?? '', fac), [organizationId, fac, open]);
  const services = useFetch(() => catalogsApi.services(organizationId ?? '', fac), [organizationId, fac, open]);
  const slots = useFetch(() => (providerId ? scheduleApi.availability(providerId, date, fac) : Promise.resolve([])), [providerId, date, fac, open]);

  useEffect(() => {
    if (!open) return;
    setPatientId(initialPatientId);
    setSlot('');
    setError(null);
  }, [open, initialPatientId]);

  const searchPatients = async (q: string) => {
    setPatientQuery(q);
    if (q.trim().length < 2) {
      setPatientResults([]);
      return;
    }
    try {
      setPatientResults(await patientsApi.search(q.trim(), fac));
    } catch {
      setPatientResults([]);
    }
  };

  const submit = async () => {
    if (!slot) return;
    const [startsAt, endsAt] = slot.split('|');
    setSubmitting(true);
    setError(null);
    try {
      const appt = await appointmentsApi.book({
        patientId,
        providerStaffId: providerId,
        serviceId: serviceId || undefined,
        startsAt,
        endsAt,
        appointmentType: 'opd',
        source: 'counter',
        facilityId: fac ?? '',
      });
      onBooked(appt.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Booking failed.');
    } finally {
      setSubmitting(false);
    }
  };

  const selectedPatient = patientResults.find((p) => p.id === patientId) ?? (patientId ? { id: patientId, mrn: '', fullName: 'Selected patient' } : null);

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Book appointment"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={submitting} disabled={!patientId || !providerId || !slot}>
            Confirm booking
          </Button>
        </>
      }
    >
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}

        <div className="field">
          <label className="field__label" htmlFor="bk-patient-q">
            Patient
          </label>
          {selectedPatient ? (
            <div className="pick">
              <span>
                <strong>{selectedPatient.fullName}</strong> <span className="mono muted small">{selectedPatient.mrn}</span>
              </span>
              <Button variant="ghost" onClick={() => { setPatientId(''); setPatientQuery(''); setPatientResults([]); }}>
                Change
              </Button>
            </div>
          ) : (
            <>
              <input
                id="bk-patient-q"
                className="input"
                placeholder="Search name or MRN"
                value={patientQuery}
                onChange={(e) => void searchPatients(e.target.value)}
              />
              {patientResults.length > 0 && (
                <ul className="pick-list">
                  {patientResults.map((p) => (
                    <li key={p.id}>
                      <button type="button" className="pick-list__item" onClick={() => setPatientId(p.id)}>
                        <strong>{p.fullName}</strong>
                        <span className="mono muted small">{p.mrn}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>

        <Select label="Provider" value={providerId} onChange={(e) => setProviderId(e.target.value)}>
          <option value="">Select doctor…</option>
          {(staff.data ?? []).map((s) => (
            <option key={s.id} value={s.id}>
              {s.fullName} ({s.employeeCode})
            </option>
          ))}
        </Select>

        <Select label="Service" value={serviceId} onChange={(e) => setServiceId(e.target.value)}>
          <option value="">Default (consultation)</option>
          {(services.data ?? []).map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>

        <Input label="Consultation date" type="date" value={date} onChange={(e) => { setDate(e.target.value); setSlot(''); }} />

        <div className="field">
          <span className="field__label">Available slots</span>
          {slots.loading ? (
            <Spinner label="Checking availability…" />
          ) : (slots.data ?? []).filter((s) => s.available).length === 0 ? (
            <p className="muted">No open slots for this provider on this date.</p>
          ) : (
            <div className="slots" role="radiogroup" aria-label="Available slots">
              {(slots.data ?? [])
                .filter((s) => s.available)
                .map((s) => (
                  <button
                    key={s.startsAt}
                    type="button"
                    role="radio"
                    aria-checked={slot === `${s.startsAt}|${s.endsAt}`}
                    className={`slot ${slot === `${s.startsAt}|${s.endsAt}` ? 'slot--selected' : ''}`}
                    onClick={() => setSlot(`${s.startsAt}|${s.endsAt}`)}
                  >
                    {new Date(s.startsAt).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                  </button>
                ))}
            </div>
          )}
        </div>
      </div>
    </Dialog>
  );
}
