import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useFetch } from '../../hooks/useFetch';
import { adminStaffApi, adminDepartmentsApi } from '../../api/endpoints';
import { ApiError } from '../../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../../components/ui';
import '../../pages/admin/workforce-cmd.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface StaffMember {
  id: string;
  employeeCode: string;
  fullName: string;
  designation: string | null;
  departmentId: string | null;
  department?: { id: string; name: string } | null;
  facilityId: string | null;
  userId: string | null;
  licenseNumber: string | null;
  status: string;
  hireDate: string | null;
  createdAt: string;
}

interface Department {
  id: string;
  name: string;
  code: string;
  departmentType: string | null;
  status: string;
}

/* ── Constants ───────────────────────────────────────────────────── */

const STAFF_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  inactive: { label: 'Inactive', color: '#6b7280', bg: '#f3f4f6' },
  suspended: { label: 'Suspended', color: '#ef4444', bg: '#fee2e2' },
  on_leave: { label: 'On Leave', color: '#f59e0b', bg: '#fef3c7' },
  terminated: { label: 'Terminated', color: '#ef4444', bg: '#fee2e2' },
  pending_onboarding: { label: 'Pending Onboarding', color: '#8b5cf6', bg: '#f5f3ff' },
};

const DEPT_TYPE_COLORS: Record<string, string> = {
  clinical: '#10b981',
  administrative: '#3b82f6',
  support: '#6b7280',
  laboratory: '#8b5cf6',
  radiology: '#06b6d4',
  pharmacy: '#f59e0b',
  nursing: '#ec4899',
};

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return <span className="wf-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>;
}

/* ── Main Component ──────────────────────────────────────────────── */

export function AdminStaffPage() {
  const { organizationId: org, selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'directory' | 'departments' | 'schedules' | 'credentials' | 'onboarding'>('directory');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Filters
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');

  // Create form
  const [createForm, setCreateForm] = useState({ employeeCode: '', fullName: '', designation: '', departmentId: '', licenseNumber: '' });

  // Data fetching
  const staff = useFetch(
    () => org ? adminStaffApi.list(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const departments = useFetch(
    () => org ? adminDepartmentsApi.list(org, fac) : Promise.resolve([]),
    [org, fac],
  );

  const allStaff = useMemo(() => (staff.data ?? []) as StaffMember[], [staff.data]);
  const allDepts = useMemo(() => (departments.data ?? []) as Department[], [departments.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Filtered staff
  const filteredStaff = useMemo(() => {
    let result = allStaff;
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      result = result.filter(s =>
        s.fullName.toLowerCase().includes(q) ||
        s.employeeCode.toLowerCase().includes(q) ||
        (s.designation ?? '').toLowerCase().includes(q) ||
        (s.licenseNumber ?? '').toLowerCase().includes(q)
      );
    }
    if (statusFilter !== 'all') {
      result = result.filter(s => s.status === statusFilter);
    }
    return result;
  }, [allStaff, searchQuery, statusFilter]);

  // Census
  const activeStaff = allStaff.filter(s => s.status === 'active').length;
  const onLeave = allStaff.filter(s => s.status === 'on_leave').length;
  const pendingOnboarding = allStaff.filter(s => s.status === 'pending_onboarding').length;
  const withLicense = allStaff.filter(s => s.licenseNumber).length;

  // Department staffing
  const deptStaffCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    allStaff.forEach(s => {
      if (s.departmentId) {
        counts[s.departmentId] = (counts[s.departmentId] || 0) + 1;
      }
    });
    return counts;
  }, [allStaff]);

  const handleCreateStaff = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!org || !createForm.employeeCode || !createForm.fullName) return;
    await go(() => adminStaffApi.create(org, {
      facilityId: fac ?? '',
      employeeCode: createForm.employeeCode,
      fullName: createForm.fullName,
      designation: createForm.designation || undefined,
      departmentId: createForm.departmentId || undefined,
      licenseNumber: createForm.licenseNumber || undefined,
    }));
    setDlg(null);
    setCreateForm({ employeeCode: '', fullName: '', designation: '', departmentId: '', licenseNumber: '' });
    staff.refresh();
  }, [org, createForm, go, staff]);

  const handleUpdateStatus = useCallback(async (id: string, status: string) => {
    await go(() => adminStaffApi.update(id, { status }));
    staff.refresh();
  }, [go, staff]);

  return (
    <div className="page wf-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Workforce</h1>
          <p className="page__subtitle">Staff directory, credentials, departments, schedules, onboarding</p>
        </div>
        <div className="wf-actions">
          <Button variant="ghost" onClick={() => { staff.refresh(); departments.refresh(); }}>Refresh</Button>
          <Button variant="primary" size="sm" onClick={() => setDlg('create-staff')}>+ Add Staff</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="wf-census">
        <div className="wf-census-card wf-census-card--total">
          <span className="wf-census-value">{allStaff.length}</span>
          <span className="wf-census-label">Total Staff</span>
        </div>
        <div className="wf-census-card wf-census-card--active">
          <span className="wf-census-value" style={{ color: '#10b981' }}>{activeStaff}</span>
          <span className="wf-census-label">Active</span>
        </div>
        <div className="wf-census-card wf-census-card--leave">
          <span className="wf-census-value" style={{ color: '#f59e0b' }}>{onLeave}</span>
          <span className="wf-census-label">On Leave</span>
        </div>
        <div className="wf-census-card wf-census-card--onboarding">
          <span className="wf-census-value" style={{ color: '#8b5cf6' }}>{pendingOnboarding}</span>
          <span className="wf-census-label">Pending Onboarding</span>
        </div>
        <div className="wf-census-card wf-census-card--licensed">
          <span className="wf-census-value">{withLicense}</span>
          <span className="wf-census-label">Licensed</span>
        </div>
        <div className="wf-census-card wf-census-card--depts">
          <span className="wf-census-value">{allDepts.length}</span>
          <span className="wf-census-label">Departments</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="wf-tabs">
        {(['directory', 'departments', 'schedules', 'credentials', 'onboarding'] as const).map(t => (
          <button key={t} className={`wf-tab ${activeTab === t ? 'wf-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'directory' ? 'Staff Directory' : t === 'departments' ? 'Departments' : t === 'schedules' ? 'Schedules' : t === 'credentials' ? 'Credentials' : 'Onboarding'}
          </button>
        ))}
      </div>

      {/* ── Staff Directory Tab ────────────────────────────── */}
      {activeTab === 'directory' && (
        <Card className="wf-section-card">
          <div className="wf-section-header">
            <h3>Staff Directory</h3>
            <div className="wf-section-actions">
              <Input label="" value={searchQuery} onChange={e => setSearchQuery(e.target.value)} placeholder="Search staff..." />
              <select className="wf-select" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
                <option value="all">All Status</option>
                {Object.entries(STAFF_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>
            </div>
          </div>
          {filteredStaff.length === 0 ? (
            <EmptyState title="No staff found" body={searchQuery ? "No staff match your search criteria." : "Add staff members to get started."} />
          ) : (
            <div className="wf-table">
              <div className="wf-table-header">
                <span>Employee</span>
                <span>Name</span>
                <span>Designation</span>
                <span>Department</span>
                <span>License</span>
                <span>Status</span>
                <span>Actions</span>
              </div>
              {filteredStaff.map(s => (
                <div key={s.id} className="wf-table-row">
                  <span className="wf-mono">{s.employeeCode}</span>
                  <span className="wf-staff-name">{s.fullName}</span>
                  <span>{s.designation ?? '—'}</span>
                  <span>{s.department?.name ?? '—'}</span>
                  <span className="wf-mono">{s.licenseNumber ?? '—'}</span>
                  <StatusBadge status={s.status} config={STAFF_STATUS} />
                  <span className="wf-table-actions">
                    {s.status === 'active' && (
                      <Button variant="ghost" size="sm" onClick={() => void handleUpdateStatus(s.id, 'inactive')}>Deactivate</Button>
                    )}
                    {s.status === 'inactive' && (
                      <Button variant="ghost" size="sm" onClick={() => void handleUpdateStatus(s.id, 'active')}>Activate</Button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Departments Tab ────────────────────────────────── */}
      {activeTab === 'departments' && (
        <Card className="wf-section-card">
          <div className="wf-section-header">
            <h3>Departments</h3>
          </div>
          {allDepts.length === 0 ? (
            <EmptyState title="No departments" body="Departments are configured during hospital setup." />
          ) : (
            <div className="wf-dept-grid">
              {allDepts.map(d => (
                <div key={d.id} className="wf-dept-card">
                  <div className="wf-dept-card__header">
                    <span className="wf-dept-card__name">{d.name}</span>
                    <span className="wf-dept-card__code">{d.code}</span>
                  </div>
                  <div className="wf-dept-card__meta">
                    <span style={{ color: DEPT_TYPE_COLORS[d.departmentType ?? ''] ?? '#6b7280' }}>
                      {d.departmentType ?? 'General'}
                    </span>
                    <span>{deptStaffCounts[d.id] ?? 0} staff</span>
                  </div>
                  <StatusBadge status={d.status} config={STAFF_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Schedules Tab ──────────────────────────────────── */}
      {activeTab === 'schedules' && (
        <Card className="wf-section-card">
          <div className="wf-section-header">
            <h3>Staff Schedules</h3>
          </div>
          <EmptyState title="Schedule management" body="Doctor and staff schedules are managed through the scheduling module." />
        </Card>
      )}

      {/* ── Credentials Tab ────────────────────────────────── */}
      {activeTab === 'credentials' && (
        <Card className="wf-section-card">
          <div className="wf-section-header">
            <h3>Credential Management</h3>
          </div>
          {allStaff.filter(s => s.licenseNumber).length === 0 ? (
            <EmptyState title="No credentials" body="Staff license and credential information appears here." />
          ) : (
            <div className="wf-table">
              <div className="wf-table-header">
                <span>Staff</span>
                <span>License Number</span>
                <span>Designation</span>
                <span>Status</span>
              </div>
              {allStaff.filter(s => s.licenseNumber).map(s => (
                <div key={s.id} className="wf-table-row">
                  <span className="wf-staff-name">{s.fullName}</span>
                  <span className="wf-mono">{s.licenseNumber}</span>
                  <span>{s.designation ?? '—'}</span>
                  <StatusBadge status={s.status} config={STAFF_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Onboarding Tab ─────────────────────────────────── */}
      {activeTab === 'onboarding' && (
        <Card className="wf-section-card">
          <div className="wf-section-header">
            <h3>Staff Onboarding</h3>
          </div>
          {pendingOnboarding === 0 ? (
            <EmptyState title="No pending onboarding" body="All staff have completed onboarding." />
          ) : (
            <div className="wf-table">
              <div className="wf-table-header">
                <span>Employee</span>
                <span>Name</span>
                <span>Designation</span>
                <span>Department</span>
                <span>Status</span>
              </div>
              {allStaff.filter(s => s.status === 'pending_onboarding').map(s => (
                <div key={s.id} className="wf-table-row">
                  <span className="wf-mono">{s.employeeCode}</span>
                  <span className="wf-staff-name">{s.fullName}</span>
                  <span>{s.designation ?? '—'}</span>
                  <span>{s.department?.name ?? '—'}</span>
                  <StatusBadge status={s.status} config={STAFF_STATUS} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Create Staff Dialog */}
      {dlg === 'create-staff' && (
        <Dialog open onClose={() => setDlg(null)} title="Add Staff Member" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateStaff} loading={busy} disabled={!createForm.employeeCode || !createForm.fullName}>Create Staff</Button>
          </>
        }>
          <form onSubmit={handleCreateStaff} className="wf-form">
            <Input label="Employee Code" value={createForm.employeeCode} onChange={e => setCreateForm(f => ({ ...f, employeeCode: e.target.value }))} placeholder="e.g. EMP-001" required />
            <Input label="Full Name" value={createForm.fullName} onChange={e => setCreateForm(f => ({ ...f, fullName: e.target.value }))} placeholder="Staff member name" required />
            <Input label="Designation" value={createForm.designation} onChange={e => setCreateForm(f => ({ ...f, designation: e.target.value }))} placeholder="e.g. Senior Nurse" />
            <div className="wf-form-field">
              <label className="wf-label">Department</label>
              <select className="wf-input" value={createForm.departmentId} onChange={e => setCreateForm(f => ({ ...f, departmentId: e.target.value }))}>
                <option value="">Select department...</option>
                {allDepts.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>
            <Input label="License Number" value={createForm.licenseNumber} onChange={e => setCreateForm(f => ({ ...f, licenseNumber: e.target.value }))} placeholder="Professional license (optional)" />
          </form>
        </Dialog>
      )}
    </div>
  );
}
