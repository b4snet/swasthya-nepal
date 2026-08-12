import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { AppointmentStatus, Card, EmptyState, ErrorState, Spinner, Stat, formatDateTime } from '../components/ui';
import { BILLING_ROLES } from '../auth/roles';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function DashboardPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const todayAppts = useFetch(() => appointmentsApi.list({ date: today(), facilityId: fac }), [fac]);
  const queue = useFetch(() => appointmentsApi.queue({ date: today(), facilityId: fac }), [fac]);

  const paid = (a: { status: string }) => a.status === 'completed';

  if (todayAppts.loading || queue.loading) return <Spinner />;
  const apptError = todayAppts.error ?? queue.error;
  if (apptError) return <ErrorState error={apptError} onRetry={() => { void todayAppts.refresh(); void queue.refresh(); }} />;

  const appointments = todayAppts.data ?? [];
  const queueEntries = queue.data ?? [];

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Today</h1>
          <span className="page__sub">{today()} — {fac ? 'facility view' : 'all authorized facilities'}</span>
        </div>
      </div>

      <div className="grid grid--3">
        <Stat value={appointments.length} label="Today's appointments" />
        <Stat value={queueEntries.length} label="In queue now" />
        <Stat value={appointments.filter(paid).length} label="Completed today" />
      </div>

      <Card title="Waiting and in consultation" action={<Link to="/queue">Open queue →</Link>}>
        {queueEntries.length === 0 ? (
          <EmptyState title="Queue is clear" body="No patients are checked in right now." />
        ) : (
          <ul className="queue-mini">
            {queueEntries.slice(0, 8).map((a) => (
              <li key={a.appointmentId} className="queue-mini__item">
                <span className="mono queue-mini__token">{a.tokenNo ? `#${a.tokenNo}` : '—'}</span>
                <span className="queue-mini__name">{a.patient?.fullName ?? 'Unknown patient'}</span>
                <AppointmentStatus status={a.status} />
              </li>
            ))}
          </ul>
        )}
      </Card>

      {hasRole(...BILLING_ROLES) && <OutstandingCard facilityId={fac} />}

      <Card title="Today's appointments" action={<Link to="/appointments">View all →</Link>}>
        {appointments.length === 0 ? (
          <EmptyState title="No appointments today" body="Book a patient at the front desk to get started." />
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Patient</th>
                <th>Provider</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {appointments.map((a) => (
                <tr key={a.id}>
                  <td data-label="Time" className="num">{formatDateTime(a.startsAt)}</td>
                  <td data-label="Patient">
                    <Link to={`/patients/${a.patientId}`}>{a.patient?.fullName ?? '—'}</Link>
                    <span className="mono muted small"> {a.patient?.mrn ?? ''}</span>
                  </td>
                  <td data-label="Provider">{a.provider?.fullName ?? '—'}</td>
                  <td data-label="Status">
                    <AppointmentStatus status={a.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}

function OutstandingCard({ facilityId: _facilityId }: { facilityId: string | null }) {
  // Outstanding billing is not directly exposed; show the paid/captured
  // snapshot that IS exposed honestly, or nothing if unavailable.
  return (
    <Card title="Billing today" action={<Link to="/billing">Billing →</Link>}>
      <p className="muted small">
        Invoice and payment capture happen at <Link to="/billing">Billing</Link>. Outstanding-balance
        aggregation is a Phase 13 (Finance) item — the frontend does not invent it.
      </p>
    </Card>
  );
}
