import { Link, useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Card, EmptyState, ErrorState, Spinner, formatDate, formatDateTime } from '../components/ui';
import type { TimelineEntry } from '../api/types';

/**
 * The backend stores structured summary metadata per timeline event (e.g.
 * `{ mrn: 'MRN-…' }`, `{ changed: [...] }`). Render it as plain text — never
 * as a React child directly.
 */
export function timelineSummary(summary: TimelineEntry['summary']): string {
  if (typeof summary === 'string') return summary;
  if (Array.isArray(summary)) return summary.map(String).join(', ');
  if (summary && typeof summary === 'object') {
    return Object.values(summary)
      .flatMap((v) => (Array.isArray(v) ? v.map(String) : [String(v)]))
      .join(' · ');
  }
  return '';
}

export function PatientProfilePage() {
  const { id } = useParams<{ id: string }>();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const profile = useFetch(() => patientsApi.show(id!, fac), [id, fac]);
  const timeline = useFetch(() => patientsApi.timeline(id!, fac), [id, fac]);

  if (profile.loading) return <Spinner />;
  if (profile.error) return <ErrorState error={profile.error} onRetry={() => void profile.refresh()} />;
  const patient = profile.data!;

  const canBook = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>{patient.fullName}</h1>
          <span className="page__sub">
            <span className="mono">{patient.mrn}</span> · {formatDate(patient.dateOfBirth)} · {patient.sex}
          </span>
        </div>
        {canBook && (
          <Link className="btn btn--primary" to={`/appointments/new?patientId=${patient.id}`}>
            Book appointment
          </Link>
        )}
      </div>

      <div className="grid grid--2">
        <Card title="Demographics">
          <dl className="kv">
            <div><dt>MRN</dt><dd className="mono">{patient.mrn}</dd></div>
            <div><dt>Blood group</dt><dd>{patient.bloodGroup ?? '—'}</dd></div>
            <div><dt>Status</dt><dd>{patient.status}</dd></div>
            <div><dt>Registered</dt><dd>{formatDateTime(patient.createdAt)}</dd></div>
          </dl>
        </Card>

        <Card title="Timeline">
          {timeline.loading ? (
            <Spinner label="Loading timeline…" />
          ) : timeline.error ? (
            <ErrorState error={timeline.error} onRetry={() => void timeline.refresh()} />
          ) : (timeline.data ?? []).length === 0 ? (
            <EmptyState title="No activity yet" body="Visits and events will appear here." />
          ) : (
            <ol className="timeline">
              {(timeline.data ?? []).map((t) => (
                <li key={t.id} className="timeline__item">
                  <span className="timeline__time num">{formatDateTime(t.occurredAt)}</span>
                  <span className="timeline__type mono">{t.eventType}</span>
                  <span className="timeline__summary">{timelineSummary(t.summary)}</span>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>
    </div>
  );
}
