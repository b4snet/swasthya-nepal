import { useTenant } from '../context/TenantContext';
import { auditApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Card, EmptyState, ErrorState, Spinner } from '../components/ui';

export function AuditPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  if (!hasRole('hospital_admin', 'org_admin', 'platform_admin')) {
    return (
      <div className="page">
        <EmptyState title="Not authorized" body="You do not have permission to view the audit trail." />
      </div>
    );
  }

  const audit = useFetch(
    // No facility context yet (session still restoring, or platform-only user):
    // do not fire a tenant-less request that could race the session bootstrap.
    () => (fac ? auditApi.list({ limit: 100, facilityId: fac }) : Promise.resolve([])),
    [fac],
  );

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Audit trail</h1>
          <span className="page__sub">Append-only record — read-only in this application</span>
        </div>
      </div>

      {audit.loading ? (
        <Spinner />
      ) : audit.error ? (
        <ErrorState error={audit.error} onRetry={() => void audit.refresh()} />
      ) : (audit.data ?? []).length === 0 ? (
        <EmptyState title="No audit events" body="Actions will appear here as they happen." />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Entity</th>
              </tr>
            </thead>
            <tbody>
              {(audit.data ?? []).map((e) => (
                <tr key={e.id}>
                  <td data-label="When" className="num">{new Date(e.occurredAt).toLocaleString()}</td>
                  <td data-label="Actor">{e.actor?.email ?? 'system'}</td>
                  <td data-label="Action" className="mono">{e.action}</td>
                  <td data-label="Entity">{e.entityType}{e.entityId ? ` ${String(e.entityId).slice(0, 8)}` : ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
