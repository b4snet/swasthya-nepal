import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { auditApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Card, EmptyState, ErrorState, Input, Select, Spinner } from '../components/ui';
import { AUDIT_ROLES } from '../auth/roles';
import { ChevronRight, ChevronDown, Clock, User, Activity } from 'lucide-react';

const PAGE_SIZE = 25;

/** Expandable audit row with metadata display — Phase 153 */
function AuditRow({ event }: { event: { id: string; action: string; entityType: string; entityId: string | null; actor: { id: string; email: string } | null; facilityId: string | null; occurredAt: string; metadata: Record<string, unknown> | null } }) {
  const [expanded, setExpanded] = useState(false);
  const hasMetadata = event.metadata && Object.keys(event.metadata).length > 0;

  return (
    <>
      <tr className={expanded ? 'audit-row--expanded' : undefined}>
        <td data-label="When" className="mono">
          <span className="audit-when">
            <Clock size={12} />
            {new Date(event.occurredAt).toLocaleString()}
          </span>
        </td>
        <td data-label="Actor">
          <span className="audit-actor">
            <User size={12} />
            {event.actor?.email ?? 'system'}
          </span>
        </td>
        <td data-label="Action" className="mono">
          <span className="audit-action">
            <Activity size={12} />
            {event.action}
          </span>
        </td>
        <td data-label="Entity">{event.entityType}</td>
        <td data-label="Entity ID" className="mono muted small">
          {event.entityId ? String(event.entityId).slice(0, 8) : '—'}
        </td>
        {hasMetadata && (
          <td className="audit-expand">
            <button
              type="button"
              className="audit-expand-btn"
              onClick={() => setExpanded(!expanded)}
              aria-label={expanded ? 'Collapse details' : 'Expand details'}
              aria-expanded={expanded}
            >
              {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
            </button>
          </td>
        )}
      </tr>
      {expanded && hasMetadata && (
        <tr className="audit-metadata-row">
          <td colSpan={6}>
            <div className="audit-metadata">
              <span className="audit-metadata__label">Metadata</span>
              <pre className="audit-metadata__json">
                {JSON.stringify(event.metadata, null, 2)}
              </pre>
            </div>
          </td>
        </tr>
      )}
    </>
  );
}

export function AuditPage() {
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  if (!hasRole(...AUDIT_ROLES)) {
    return (
      <div className="page">
        <EmptyState title="Not authorized" body="You do not have permission to view the audit trail." />
      </div>
    );
  }

  const [page, setPage] = useState(0);
  const [actionFilter, setActionFilter] = useState('');
  const [entityFilter, setEntityFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const audit = useFetch(
    () => (fac ? auditApi.list({ limit: 200, facilityId: fac }) : Promise.resolve([])),
    [fac],
  );

  const allEvents = audit.data ?? [];

  // Client-side filtering (backend doesn't support query params for these yet)
  const filtered = allEvents.filter((e) => {
    if (actionFilter && !e.action.toLowerCase().includes(actionFilter.toLowerCase())) return false;
    if (entityFilter && e.entityType !== entityFilter) return false;
    if (dateFrom && new Date(e.occurredAt) < new Date(dateFrom)) return false;
    if (dateTo && new Date(e.occurredAt) > new Date(dateTo + 'T23:59:59')) return false;
    return true;
  });

  const totalPages = Math.ceil(filtered.length / PAGE_SIZE);
  const paged = filtered.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);

  // Unique entity types for filter dropdown
  const entityTypes = [...new Set(allEvents.map((e) => e.entityType))].sort();

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Audit trail</h1>
          <span className="page__sub">Append-only record — read-only in this application</span>
        </div>
      </div>

      <Card title="Filters">
        <div className="filter-row">
          <Input
            label="Action"
            value={actionFilter}
            onChange={(e) => { setActionFilter(e.target.value); setPage(0); }}
            placeholder="e.g. appointment.booked"
          />
          <Select label="Entity type" value={entityFilter} onChange={(e) => { setEntityFilter(e.target.value); setPage(0); }}>
            <option value="">All entities</option>
            {entityTypes.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </Select>
          <Input label="From" type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(0); }} />
          <Input label="To" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(0); }} />
        </div>
        <p className="muted small">{filtered.length} events{filtered.length !== allEvents.length ? ` (filtered from ${allEvents.length})` : ''}</p>
      </Card>

      {audit.loading ? (
        <Spinner />
      ) : audit.error ? (
        <ErrorState error={audit.error} onRetry={() => void audit.refresh()} />
      ) : filtered.length === 0 ? (
        <EmptyState title="No audit events" body={allEvents.length > 0 ? 'No events match your filters.' : 'Actions will appear here as they happen.'} />
      ) : (
        <>
          <Card>
            <table className="data-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Entity ID</th>
                </tr>
              </thead>
              <tbody>
                {paged.map((e) => (
                  <AuditRow key={e.id} event={e} />
                ))}
              </tbody>
            </table>
          </Card>

          {totalPages > 1 && (
            <div className="pagination">
              <button disabled={page === 0} onClick={() => setPage(page - 1)}>← Previous</button>
              <span>Page {page + 1} of {totalPages}</span>
              <button disabled={page >= totalPages - 1} onClick={() => setPage(page + 1)}>Next →</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
