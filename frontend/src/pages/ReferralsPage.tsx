import { useCallback, useEffect, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { referralsApi, patientsApi, catalogsApi, type Referral } from '../api/endpoints';
import {
  Alert,
  Button,
  EmptyState,
  ErrorState,
  Input,
  Spinner,
  StatusChip,
  Dialog,
} from '../components/ui';
import type { Staff } from '../api/types';
import './referrals.css';

/* ── helpers ── */
const URGENCY_BADGE: Record<string, string> = {
  routine: 'badge-neutral',
  urgent: 'badge-warn',
  emergent: 'badge-critical',
};

const STATUS_COLOR: Record<string, 'info' | 'success' | 'danger' | 'warning' | 'neutral'> = {
  pending: 'info',
  accepted: 'success',
  rejected: 'danger',
  scheduled: 'warning',
  completed: 'success',
  cancelled: 'neutral',
};

/* ── list view ── */
export function ReferralsPage() {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();

  const [referrals, setReferrals] = useState<Referral[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [showCreate, setShowCreate] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await referralsApi.list({
        status: statusFilter || undefined,
        page,
        perPage: 20,
        facilityId,
      });
      setReferrals(res.data);
      setTotalPages(res.last_page);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load referrals');
    } finally {
      setLoading(false);
    }
  }, [statusFilter, page, facilityId]);

  useEffect(() => { load(); }, [load]);

  const filtered = referrals.filter((r) => {
    if (!searchQuery) return true;
    const q = searchQuery.toLowerCase();
    return (
      r.patient?.fullName?.toLowerCase().includes(q) ||
      r.patient?.mrn?.toLowerCase().includes(q) ||
      r.reason?.toLowerCase().includes(q) ||
      r.receiving_facility_name?.toLowerCase().includes(q) ||
      r.specialty?.toLowerCase().includes(q)
    );
  });

  const handleAction = async (id: string, action: 'accept' | 'reject' | 'complete' | 'cancel') => {
    try {
      if (action === 'accept') await referralsApi.accept(id, facilityId);
      else if (action === 'reject') await referralsApi.reject(id, 'Rejected via management', facilityId);
      else if (action === 'complete') await referralsApi.complete(id, 'Completed via management', facilityId);
      else if (action === 'cancel') await referralsApi.cancel(id, 'Cancelled via management', facilityId);
      load();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Action failed');
    }
  };

  return (
    <div className="referrals-page">
      <div className="referrals-header">
        <div>
          <h1>Referrals</h1>
          <p className="text-muted">Manage patient referrals between providers and facilities</p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>New Referral</Button>
      </div>

      <div className="referrals-filters">
        <Input
          label="Search referrals"
          placeholder="Search by patient, MRN, reason…"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="referrals-search"
        />
        <div className="referrals-status-filters">
          {['', 'pending', 'accepted', 'rejected', 'scheduled', 'completed', 'cancelled'].map((s) => (
            <button
              key={s}
              className={`filter-pill ${statusFilter === s ? 'filter-pill--active' : ''}`}
              onClick={() => { setStatusFilter(s); setPage(1); }}
            >
              {s || 'All'}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <div className="referrals-loading"><Spinner label="Loading referrals…" /></div>
      ) : error ? (
        <ErrorState error={new Error(error)} onRetry={load} />
      ) : filtered.length === 0 ? (
        <EmptyState
          title="No referrals"
          body={searchQuery || statusFilter ? 'No referrals match your filters' : 'No referrals have been created yet'}
        />
      ) : (
        <>
          <div className="referrals-table-wrap">
            <table className="referrals-table">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Reason</th>
                  <th>Destination</th>
                  <th>Urgency</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <div className="referral-patient">
                        <strong>{r.patient?.fullName ?? 'Unknown'}</strong>
                        <span className="text-muted">{r.patient?.mrn}</span>
                      </div>
                    </td>
                    <td>
                      <div className="referral-reason">
                        <span>{r.reason}</span>
                        {r.specialty && <span className="text-muted">{r.specialty}</span>}
                      </div>
                    </td>
                    <td>{r.receiving_facility_name || r.receivingStaff?.fullName || '—'}</td>
                    <td><span className={`badge ${URGENCY_BADGE[r.urgency ?? ''] ?? 'badge-neutral'}`}>{r.urgency}</span></td>
                    <td><StatusChip tone={STATUS_COLOR[r.status] ?? 'neutral'} label={r.status} /></td>
                    <td className="text-muted">{new Date(r.created_at ?? r.createdAt ?? "").toLocaleDateString()}</td>
                    <td>
                      <div className="referral-actions">
                        {r.status === 'pending' && (
                          <>
                            <Button size="sm" variant="primary" onClick={() => handleAction(r.id, 'accept')}>Accept</Button>
                            <Button size="sm" variant="danger" onClick={() => handleAction(r.id, 'reject')}>Reject</Button>
                          </>
                        )}
                        {r.status === 'accepted' && (
                          <Button size="sm" variant="secondary" onClick={() => handleAction(r.id, 'complete')}>Complete</Button>
                        )}
                        {['pending', 'accepted'].includes(r.status) && (
                          <Button size="sm" variant="ghost" onClick={() => handleAction(r.id, 'cancel')}>Cancel</Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="referrals-pagination">
              <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
              <span className="text-muted">Page {page} of {totalPages}</span>
              <Button size="sm" variant="ghost" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
          )}
        </>
      )}

      {showCreate && organizationId && (
        <CreateReferralDialog
          organizationId={organizationId}
          facilityId={facilityId}
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); load(); }}
        />
      )}
    </div>
  );
}

/* ── create dialog ── */
function CreateReferralDialog({
  organizationId,
  facilityId,
  onClose,
  onCreated,
}: {
  organizationId: string;
  facilityId: string | null;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [staff, setStaff] = useState<Staff[]>([]);
  const [patients, setPatients] = useState<Array<{ id: string; fullName: string; mrn: string }>>([]);
  const [patientSearch, setPatientSearch] = useState('');
  const [patientId, setPatientId] = useState('');
  const [patientName, setPatientName] = useState('');
  const [receivingStaffId, setReceivingStaffId] = useState('');
  const [externalFacility, setExternalFacility] = useState('');
  const [receivingDepartment, setReceivingDepartment] = useState('');
  const [reason, setReason] = useState('');
  const [clinicalSummary, setClinicalSummary] = useState('');
  const [urgency, setUrgency] = useState('routine');
  const [specialty, setSpecialty] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const staffList = await catalogsApi.staff(organizationId, facilityId);
        setStaff(staffList);
      } catch { /* ignore */ }
    })();
  }, [organizationId, facilityId]);

  useEffect(() => {
    if (!patientSearch || patientSearch.length < 2) { setPatients([]); return; }
    const timer = setTimeout(async () => {
      try {
        const results = await patientsApi.search(patientSearch, facilityId);
        setPatients(results.map((r) => ({ id: r.id, fullName: r.fullName, mrn: r.mrn })));
      } catch { /* ignore */ }
    }, 300);
    return () => clearTimeout(timer);
  }, [patientSearch, facilityId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!patientId || !reason) { setError('Patient and reason are required'); return; }
    if (!receivingStaffId && !externalFacility) { setError('Either a receiving provider or external facility is required'); return; }

    setSubmitting(true);
    setError(null);
    try {
      await referralsApi.create({
        patient_id: patientId,
        receiving_staff_id: receivingStaffId || undefined,
        receiving_facility_name: externalFacility || undefined,
        receiving_department: receivingDepartment || undefined,
        reason,
        clinical_summary: clinicalSummary || undefined,
        urgency,
        specialty: specialty || undefined,
      }, facilityId);
      onCreated();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to create referral');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onClose={onClose} title="New Referral">
      <form className="referral-form" onSubmit={handleSubmit}>
        {error && <Alert tone="danger">{error}</Alert>}

        {/* Patient search */}
        <div className="form-field">
          <label>Patient *</label>
          {patientId ? (
            <div className="selected-patient">
              <span>{patientName}</span>
              <Button size="sm" variant="ghost" type="button" onClick={() => { setPatientId(''); setPatientName(''); }}>Change</Button>
            </div>
          ) : (
            <>
              <Input
                label=""
                placeholder="Search by name or MRN…"
                value={patientSearch}
                onChange={(e) => setPatientSearch(e.target.value)}
              />
              {patients.length > 0 && (
                <div className="patient-results">
                  {patients.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      className="patient-result-item"
                      onClick={() => { setPatientId(p.id); setPatientName(`${p.fullName} (${p.mrn})`); setPatients([]); setPatientSearch(''); }}
                    >
                      <strong>{p.fullName}</strong> <span className="text-muted">{p.mrn}</span>
                    </button>
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        {/* Destination */}
        <div className="form-field">
          <label>Receiving Provider</label>
          <select value={receivingStaffId} onChange={(e) => setReceivingStaffId(e.target.value)}>
            <option value="">— Select provider (internal) —</option>
            {staff.map((s) => (
              <option key={s.id} value={s.id}>{s.fullName}</option>
            ))}
          </select>
        </div>

        <div className="form-field">
          <label>External Facility</label>
          <Input
            label=""
            placeholder="Or enter external facility name…"
            value={externalFacility}
            onChange={(e) => setExternalFacility(e.target.value)}
          />
        </div>

        <div className="form-row">
          <div className="form-field">
            <label>Department</label>
            <Input
              label=""
              placeholder="e.g. Cardiology"
              value={receivingDepartment}
              onChange={(e) => setReceivingDepartment(e.target.value)}
            />
          </div>
          <div className="form-field">
            <label>Specialty</label>
            <Input
              label=""
              placeholder="e.g. Interventional Cardiology"
              value={specialty}
              onChange={(e) => setSpecialty(e.target.value)}
            />
          </div>
        </div>

        {/* Clinical */}
        <div className="form-field">
          <label>Reason *</label>
          <Input
            label=""
            placeholder="Clinical reason for referral…"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            required
          />
        </div>

        <div className="form-field">
          <label>Clinical Summary</label>
          <textarea
            className="form-textarea"
            placeholder="Relevant clinical history, findings, medications…"
            value={clinicalSummary}
            onChange={(e) => setClinicalSummary(e.target.value)}
            rows={3}
          />
        </div>

        <div className="form-field">
          <label>Urgency</label>
          <select value={urgency} onChange={(e) => setUrgency(e.target.value)}>
            <option value="routine">Routine</option>
            <option value="urgent">Urgent</option>
            <option value="emergent">Emergent</option>
          </select>
        </div>

        <div className="dialog-actions">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={submitting}>
            {submitting ? 'Creating…' : 'Create Referral'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

export default ReferralsPage;
