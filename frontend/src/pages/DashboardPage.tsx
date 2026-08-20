import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import { appointmentsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { AppointmentStatus, Card, EmptyState, ErrorState, Spinner, formatDateTime } from '../components/ui';
import { BILLING_ROLES } from '../auth/roles';
import './dashboard.css';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function greeting(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

export function DashboardPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const { user } = useAuth();
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
    <div className="page dashboard">
      {/* Welcome header */}
      <div className="dashboard__welcome">
        <div>
          <h1 className="dashboard__greeting">{greeting()}</h1>
          <p className="dashboard__date">{new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
        </div>
        {user?.email && (
          <span className="dashboard__user-badge">{user.email.split('@')[0]}</span>
        )}
      </div>

      {/* Quick stats */}
      <div className="grid grid--3 dashboard__stats">
        <div className="stat stat--teal">
          <span className="stat__icon" aria-hidden="true">◷</span>
          <div className="stat__content">
            <span className="stat__value">{appointments.length}</span>
            <span className="stat__label">Appointments today</span>
          </div>
        </div>
        <div className="stat stat--info">
          <span className="stat__icon" aria-hidden="true">≣</span>
          <div className="stat__content">
            <span className="stat__value">{queueEntries.length}</span>
            <span className="stat__label">In queue now</span>
          </div>
        </div>
        <div className="stat stat--success">
          <span className="stat__icon" aria-hidden="true">✓</span>
          <div className="stat__content">
            <span className="stat__value">{appointments.filter(paid).length}</span>
            <span className="stat__label">Completed today</span>
          </div>
        </div>
      </div>

      {/* Queue */}
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

      {/* Today's appointments */}
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
  return (
    <Card title="Billing today" action={<Link to="/billing">Billing →</Link>}>
      <p className="muted small">
        Invoice and payment capture happen at <Link to="/billing">Billing</Link>. Outstanding-balance
        aggregation is a Phase 13 (Finance) item — the frontend does not invent it.
      </p>
    </Card>
  );
}
