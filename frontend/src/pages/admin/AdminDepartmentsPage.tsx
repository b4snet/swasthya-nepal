import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminDepartmentsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip } from '../../components/ui';
import { ApiError } from '../../api/client';

/* ── Types ── */
interface Department {
  id: string;
  facilityId: string;
  branchId: string | null;
  name: string;
  code: string;
  status: string;
  departmentType: string;
  description: string | null;
  phone: string | null;
  location: string | null;
  operatingHours: Array<{ day: string; open: string; close: string }> | null;
  appointmentAvailability: Record<string, unknown> | null;
  queueSettings: Record<string, unknown> | null;
  responsibleRoles: string[] | null;
  sortOrder: number;
  parentDepartmentId: string | null;
}

const DEPT_TYPES = [
  { value: 'medical', label: 'Medical' },
  { value: 'surgical', label: 'Surgical' },
  { value: 'supportive', label: 'Supportive Services' },
  { value: 'diagnostic', label: 'Diagnostic' },
  { value: 'emergency', label: 'Emergency' },
  { value: 'pharmacy', label: 'Pharmacy' },
  { value: 'laboratory', label: 'Laboratory' },
  { value: 'radiology', label: 'Radiology' },
  { value: 'administrative', label: 'Administrative' },
  { value: 'other', label: 'Other' },
];

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const TYPE_COLORS: Record<string, { color: string; bg: string }> = {
  medical: { color: 'var(--blue-700)', bg: 'var(--blue-50)' },
  surgical: { color: '#dc2626', bg: '#fef2f2' },
  supportive: { color: '#2563eb', bg: '#eff6ff' },
  diagnostic: { color: '#7c3aed', bg: '#f5f3ff' },
  emergency: { color: '#f59e0b', bg: '#fffbeb' },
  pharmacy: { color: '#16a34a', bg: '#f0fdf4' },
  laboratory: { color: '#059669', bg: '#ecfdf5' },
  radiology: { color: '#0891b2', bg: '#ecfeff' },
  administrative: { color: '#667085', bg: '#f9fafb' },
  other: { color: '#667085', bg: '#f9fafb' },
};

/* ── Chip ── */
function Chip({ color, bg, children }: { color: string; bg: string; children: React.ReactNode }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      padding: '2px 8px', borderRadius: 9999, fontSize: 11,
      fontWeight: 600, color, background: bg, whiteSpace: 'nowrap',
    }}>{children}</span>
  );
}

/* ══════════════════════════════════════════════════════════════════
   MAIN PAGE
   ══════════════════════════════════════════════════════════════════ */

export function AdminDepartmentsPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);
  const [editItem, setEditItem] = useState<Department | null>(null);
  const [deleteItem, setDeleteItem] = useState<{ id: string; name: string } | null>(null);
  const [typeFilter, setTypeFilter] = useState<string>('');

  const departments = useFetch(() => organizationId ? adminDepartmentsApi.list(organizationId, selectedFacilityId) : Promise.resolve([]), [organizationId, selectedFacilityId]);

  if (departments.loading) return <Spinner />;
  if (departments.error) return <ErrorState error={departments.error} onRetry={() => void departments.refresh()} />;

  const data: Department[] = Array.isArray(departments.data) ? departments.data : [];
  const filtered = typeFilter ? data.filter((d) => d.departmentType === typeFilter) : data;

  // Stats by type
  const typeCounts = data.reduce<Record<string, number>>((acc, d) => {
    acc[d.departmentType] = (acc[d.departmentType] ?? 0) + 1;
    return acc;
  }, {});

  return (
    <div className="stack">
      <div className="page__head">
        <div className="page__title">
          <h2>{t('admin.departments.title')}</h2>
          <span className="page__sub">{data.length} departments · {Object.keys(typeCounts).length} types</span>
        </div>
        <Button onClick={() => setCreateOpen(true)}>Create Department</Button>
      </div>

      {/* ── Type filter pills ── */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <button
          onClick={() => setTypeFilter('')}
          style={{
            padding: '4px 12px', borderRadius: 9999, fontSize: 12, fontWeight: 600, cursor: 'pointer',
            border: `1px solid ${!typeFilter ? 'var(--blue-600)' : 'var(--border-subtle)'}`,
            background: !typeFilter ? '#f0fdfa' : 'transparent',
            color: !typeFilter ? 'var(--blue-600)' : 'var(--text-secondary)',
          }}
        >
          All ({data.length})
        </button>
        {DEPT_TYPES.filter((dt) => (typeCounts[dt.value] ?? 0) > 0).map((dt) => {
          const c = TYPE_COLORS[dt.value] ?? TYPE_COLORS.other;
          return (
            <button
              key={dt.value}
              onClick={() => setTypeFilter(typeFilter === dt.value ? '' : dt.value)}
              style={{
                padding: '4px 12px', borderRadius: 9999, fontSize: 12, fontWeight: 600, cursor: 'pointer',
                border: `1px solid ${typeFilter === dt.value ? c.color : 'var(--border-subtle)'}`,
                background: typeFilter === dt.value ? c.bg : 'transparent',
                color: typeFilter === dt.value ? c.color : 'var(--text-secondary)',
              }}
            >
              {dt.label} ({typeCounts[dt.value] ?? 0})
            </button>
          );
        })}
      </div>

      {filtered.length === 0 ? (
        <EmptyState title="No departments found" body="Create your first department to get started." />
      ) : (
        <Card style={{ padding: 0, overflow: 'hidden' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Location</th>
                <th>Phone</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {filtered.map((d) => {
                const tc = TYPE_COLORS[d.departmentType] ?? TYPE_COLORS.other;
                return (
                  <tr key={d.id}>
                    <td className="mono" style={{ fontSize: 13 }}>{d.code}</td>
                    <td>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span style={{ fontWeight: 600 }}>{d.name}</span>
                        {d.description && (
                          <span style={{ fontSize: 12, color: 'var(--text-tertiary)' }}>
                            {d.description.length > 60 ? d.description.slice(0, 60) + '…' : d.description}
                          </span>
                        )}
                      </div>
                    </td>
                    <td>
                      <Chip color={tc.color} bg={tc.bg}>
                        {DEPT_TYPES.find((dt) => dt.value === d.departmentType)?.label ?? d.departmentType}
                      </Chip>
                    </td>
                    <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{d.location ?? '—'}</td>
                    <td style={{ fontSize: 13, fontFamily: 'var(--font-mono)' }}>{d.phone ?? '—'}</td>
                    <td>
                      <StatusChip tone={d.status === 'active' ? 'success' : 'neutral'} label={d.status} />
                    </td>
                    <td>
                      <Button variant="ghost" onClick={() => setEditItem(d)}>Edit</Button>
                      <Button variant="ghost" onClick={() => setDeleteItem({ id: d.id, name: d.name })}>Delete</Button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Card>
      )}

      {createOpen && (
        <CreateDepartmentDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
          orgId={organizationId ?? ''}
          facilityId={selectedFacilityId}
          onCreated={() => { setCreateOpen(false); void departments.refresh(); }}
        />
      )}

      {editItem && (
        <EditDepartmentDialog
          open={true}
          onClose={() => setEditItem(null)}
          department={editItem}
          onSaved={() => { setEditItem(null); void departments.refresh(); }}
        />
      )}

      {deleteItem && (
        <Dialog open={true} onClose={() => setDeleteItem(null)} title="Delete Department"
          footer={
            <>
              <Button variant="ghost" onClick={() => setDeleteItem(null)}>Cancel</Button>
              <Button variant="danger" onClick={async () => {
                try { await adminDepartmentsApi.remove(deleteItem.id); setDeleteItem(null); void departments.refresh(); }
                catch (err) { alert(err instanceof ApiError ? err.message : 'Delete failed.'); }
              }}>Delete</Button>
            </>
          }>
          <p>Are you sure you want to delete <strong>{deleteItem.name}</strong>? This action cannot be undone.</p>
        </Dialog>
      )}
    </div>
  );
}

/* ══════════════════════════════════════════════════════════════════
   CREATE DIALOG
   ══════════════════════════════════════════════════════════════════ */

function CreateDepartmentDialog({ open, onClose, orgId, facilityId, onCreated }: {
  open: boolean; onClose: () => void; orgId: string; facilityId: string | null; onCreated: () => void;
}) {
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [departmentType, setDepartmentType] = useState('medical');
  const [description, setDescription] = useState('');
  const [phone, setPhone] = useState('');
  const [location, setLocation] = useState('');
  const [responsibleRoles, setResponsibleRoles] = useState('');
  const [operatingHours, setOperatingHours] = useState<Array<{ day: string; open: string; close: string }>>(
    DAYS.map((day) => ({ day, open: '09:00', close: '17:00' }))
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminDepartmentsApi.create(orgId, {
        name: name.trim(),
        code: code.trim(),
        departmentType,
        description: description.trim() || undefined,
        phone: phone.trim() || undefined,
        location: location.trim() || undefined,
        facilityId: facilityId ?? undefined,
        responsibleRoles: responsibleRoles ? responsibleRoles.split(',').map((r) => r.trim()) : undefined,
        operatingHours,
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create department.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Create Department" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!name || !code}>Create</Button>
      </>
    }>
      <div className="stack" style={{ maxHeight: '60vh', overflowY: 'auto' }}>
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label="Department Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="Code" value={code} onChange={(e) => setCode(e.target.value)} required hint="Lowercase alphanumeric with hyphens" />
        <Select label="Department Type" value={departmentType} onChange={(e) => setDepartmentType(e.target.value)}>
          {DEPT_TYPES.map((dt) => <option key={dt.value} value={dt.value}>{dt.label}</option>)}
        </Select>
        <div className="field">
          <label className="field__label">Description</label>
          <textarea className="input input--area" value={description} onChange={(e) => setDescription(e.target.value)} rows={2} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Input label="Phone/Extension" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <Input label="Location" value={location} onChange={(e) => setLocation(e.target.value)} hint="e.g. Building A, Floor 2" />
        </div>
        <Input label="Responsible Roles" value={responsibleRoles} onChange={(e) => setResponsibleRoles(e.target.value)} hint="Comma-separated: doctor, nurse, pharmacist" />

        <div>
          <label className="field__label">Operating Hours</label>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 4 }}>
            {operatingHours.map((oh, i) => (
              <div key={oh.day} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ width: 90, fontSize: 13, fontWeight: 500, textTransform: 'capitalize' }}>{oh.day}</span>
                <input
                  type="time"
                  className="input"
                  style={{ width: 110, height: 32 }}
                  value={oh.open}
                  onChange={(e) => {
                    const next = [...operatingHours];
                    next[i] = { ...next[i], open: e.target.value };
                    setOperatingHours(next);
                  }}
                />
                <span style={{ fontSize: 13, color: 'var(--text-tertiary)' }}>to</span>
                <input
                  type="time"
                  className="input"
                  style={{ width: 110, height: 32 }}
                  value={oh.close}
                  onChange={(e) => {
                    const next = [...operatingHours];
                    next[i] = { ...next[i], close: e.target.value };
                    setOperatingHours(next);
                  }}
                />
              </div>
            ))}
          </div>
        </div>
      </div>
    </Dialog>
  );
}

/* ══════════════════════════════════════════════════════════════════
   EDIT DIALOG
   ══════════════════════════════════════════════════════════════════ */

function EditDepartmentDialog({ open, onClose, department, onSaved }: {
  open: boolean; onClose: () => void; department: Department; onSaved: () => void;
}) {
  const [name, setName] = useState(department.name);
  const [code, setCode] = useState(department.code);
  const [status, setStatus] = useState(department.status);
  const [departmentType, setDepartmentType] = useState(department.departmentType);
  const [description, setDescription] = useState(department.description ?? '');
  const [phone, setPhone] = useState(department.phone ?? '');
  const [location, setLocation] = useState(department.location ?? '');
  const [responsibleRoles, setResponsibleRoles] = useState((department.responsibleRoles ?? []).join(', '));
  const [operatingHours, setOperatingHours] = useState<Array<{ day: string; open: string; close: string }>>(
    department.operatingHours ?? DAYS.map((day) => ({ day, open: '09:00', close: '17:00' }))
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminDepartmentsApi.update(department.id, {
        name: name.trim(),
        code: code.trim(),
        status,
        departmentType,
        description: description.trim() || undefined,
        phone: phone.trim() || undefined,
        location: location.trim() || undefined,
        responsibleRoles: responsibleRoles ? responsibleRoles.split(',').map((r) => r.trim()) : undefined,
        operatingHours,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update department.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Edit Department" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting}>Save</Button>
      </>
    }>
      <div className="stack" style={{ maxHeight: '60vh', overflowY: 'auto' }}>
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label="Department Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="Code" value={code} onChange={(e) => setCode(e.target.value)} required />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
          <Select label="Department Type" value={departmentType} onChange={(e) => setDepartmentType(e.target.value)}>
            {DEPT_TYPES.map((dt) => <option key={dt.value} value={dt.value}>{dt.label}</option>)}
          </Select>
        </div>
        <div className="field">
          <label className="field__label">Description</label>
          <textarea className="input input--area" value={description} onChange={(e) => setDescription(e.target.value)} rows={2} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Input label="Phone/Extension" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <Input label="Location" value={location} onChange={(e) => setLocation(e.target.value)} />
        </div>
        <Input label="Responsible Roles" value={responsibleRoles} onChange={(e) => setResponsibleRoles(e.target.value)} hint="Comma-separated" />

        <div>
          <label className="field__label">Operating Hours</label>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 4 }}>
            {operatingHours.map((oh, i) => (
              <div key={oh.day} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ width: 90, fontSize: 13, fontWeight: 500, textTransform: 'capitalize' }}>{oh.day}</span>
                <input type="time" className="input" style={{ width: 110, height: 32 }} value={oh.open}
                  onChange={(e) => { const next = [...operatingHours]; next[i] = { ...next[i], open: e.target.value }; setOperatingHours(next); }} />
                <span style={{ fontSize: 13, color: 'var(--text-tertiary)' }}>to</span>
                <input type="time" className="input" style={{ width: 110, height: 32 }} value={oh.close}
                  onChange={(e) => { const next = [...operatingHours]; next[i] = { ...next[i], close: e.target.value }; setOperatingHours(next); }} />
              </div>
            ))}
          </div>
        </div>
      </div>
    </Dialog>
  );
}

export default AdminDepartmentsPage;
