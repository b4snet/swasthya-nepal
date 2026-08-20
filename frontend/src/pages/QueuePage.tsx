import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi, encountersApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, AppointmentStatus, Button, Card, ErrorState, SkeletonCard, SkeletonTable, Spinner } from '../components/ui';
import { ApiError } from '../api/client';
import './queue.css';
import './patients.css';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function QueuePage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;
  const [notice, setNotice] = useState<string | null>(null);
  const [noticeTone, setNoticeTone] = useState<'success' | 'danger'>('success');
  const [date, setDate] = useState(today());

  const canCheckIn = hasRole('hospital_admin', 'receptionist', 'nurse');
  const canStartEncounter = hasRole('doctor');

  const queue = useFetch(() => appointmentsApi.queue({ date, facilityId: fac }), [date, fac]);
  const navigate = useNavigate();

  const startEncounter = async (queueEntryId: string) => {
    try {
      const encounter = await encountersApi.start(queueEntryId, fac);
      navigate(`/encounters/${encounter.id}`);
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Failed to start the encounter.');
      setNoticeTone('danger');
    }
  };

  // Full-page swap only on the FIRST load. During refresh the previous list
  // stays visible (stale-while-revalidate); a full-page spinner on refresh
  // would unmount the check-in panel and lose its confirmation notice.
  const initialLoading = queue.loading && queue.data === null;
  const initialError = queue.error && queue.data === null;
  if (initialLoading) return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <div className="skeleton skeleton--heading" style={{ width: 120, height: 28 }} />
          <div className="skeleton skeleton--text-sm" style={{ width: 200, height: 10 }} />
        </div>
      </div>
      <SkeletonCard rows={1} />
      <SkeletonTable rows={4} cols={3} />
    </div>
  );
  if (initialError) return <ErrorState error={queue.error} onRetry={() => void queue.refresh()} />;

  const entries = queue.data ?? [];

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Queue</h1>
          <span className="page__sub">{today()} · {entries.length} in queue</span>
        </div>
      </div>        <div className="patients__search">
        <label className="visually-hidden" htmlFor="queue-date">
          Queue date
        </label>
        <input id="queue-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </div>

      {notice && <Alert tone={noticeTone}>{notice}</Alert>}

      {entries.length === 0 ? (
        <div className="queue__empty">
          <span className="queue__empty-icon" aria-hidden="true">≣</span>
          <h3>Queue is clear</h3>
          <p className="muted">Checked-in patients appear here with their token numbers.</p>
          <Link className="btn btn--secondary" to="/appointments">Book appointment</Link>
        </div>
      ) : (
        <>
          <div className="queue__status">
            <span className="queue__count">
              <span className="queue__count-dot" aria-hidden="true" />
              {entries.length} in queue
            </span>
          </div>
          <div className="queue-list">
            {entries.map((a) => (
              <Card key={a.appointmentId} className="queue__card">
                <div className="queue__token" aria-label={`Token ${a.tokenNo ?? 'none'}`}>{a.tokenNo ? `#${a.tokenNo}` : '—'}</div>
                <div className="queue__info">
                  <Link to={`/patients/${a.patient?.id}`} className="queue__name">
                    {a.patient?.fullName ?? 'Unknown'}
                  </Link>
                  <span className="queue__meta">{a.patient?.mrn ?? ''}</span>
                  <AppointmentStatus status={a.status} />
                </div>
                <div className="queue-card__actions">
                  {canStartEncounter && a.status === 'checked_in' && (
                    <Button onClick={() => void startEncounter(a.appointmentId)}>
                      Start consultation
                    </Button>
                  )}
                  {canStartEncounter && a.status === 'in_consultation' && a.encounterId && (
                    <Link className="btn btn--secondary" to={`/encounters/${a.encounterId}`}>
                      Open encounter
                    </Link>
                  )}
                </div>
              </Card>
            ))}
          </div>
        </>
      )}

      {canCheckIn && <CheckInPanel date={date} onDone={() => void queue.refresh()} />}
    </div>
  );
}

function CheckInPanel({ date, onDone }: { date: string; onDone: () => void }) {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const todayAppts = useFetch(() => appointmentsApi.list({ date, facilityId: fac }), [date, fac]);
  const [notice, setNotice] = useState<string | null>(null);
  const [noticeTone, setNoticeTone] = useState<'success' | 'danger'>('success');

  const checkIn = async (id: string) => {
    try {
      const appt = await appointmentsApi.checkIn(id, fac);
      setNotice(`Checked in — token #${appt.tokenNo}`);
      setNoticeTone('success');
      onDone();
      await todayAppts.refresh();
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Check-in failed.');
      setNoticeTone('danger');
    }
  };

  const bookable = (todayAppts.data ?? []).filter((a) => a.status === 'booked');

  return (
    <Card title="Check in today's patients">
      {notice && <Alert tone={noticeTone}>{notice}</Alert>}
      {todayAppts.loading ? (
        <Spinner label="Loading…" />
      ) : bookable.length === 0 ? (
        <p className="muted">No booked appointments left to check in.</p>
      ) : (
        <ul className="checkin-list">
          {bookable.map((a) => (
            <li key={a.id} className="checkin-list__item">
              <div>
                <strong>{a.patient?.fullName ?? '—'}</strong>
                <span className="muted small"> · {a.provider?.fullName ?? '—'} · {formatTime(a.startsAt)}</span>
              </div>
              <Button variant="secondary" onClick={() => void checkIn(a.id)}>
                Check in
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}
