import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminStaffApi, adminDepartmentsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip } from '../../components/ui';
import { ApiError } from '../../api/client';

export function AdminStaffPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);
  const [editItem, setEditItem] = useState<{ id: string; fullName: string; designation: string | null; departmentId: string | null; status: string } | null>(null);

  const staffList = useFetch(() => adminStaffApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);
  const departments = useFetch(() => adminDepartmentsApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);

  if (staffList.loading) return <Spinner />;
  if (staffList.error) return <ErrorState error={staffList.error} onRetry={() => void staffList.refresh()} />;

  const data = Array.isArray(staffList.data) ? staffList.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.staff.title')}</h2>
        <Button onClick={() => setCreateOpen(true)}>{t('admin.staff.create')}</Button>
      </div>

      {data.length === 0 ? (
        <EmptyState title={t('admin.staff.empty')} body={t('admin.staff.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.staff.employeeCode')}</th>
                <th>{t('admin.staff.fullName')}</th>
                <th>{t('admin.staff.designation')}</th>
                <th>{t('admin.staff.department')}</th>
                <th>{t('admin.staff.status')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.map((s) => (
                <tr key={s.id}>
                  <td data-label={t('admin.staff.employeeCode')} className="mono">{s.employeeCode}</td>
                  <td data-label={t('admin.staff.fullName')}>{s.fullName}</td>
                  <td data-label={t('admin.staff.designation')}>{s.designation ?? '—'}</td>
                  <td data-label={t('admin.staff.department')}>{s.department?.name ?? '—'}</td>
                  <td data-label={t('admin.staff.status')}>
                    <StatusChip tone={s.status === 'active' ? 'success' : 'neutral'} label={s.status} />
                  </td>
                  <td>
                    <Button variant="ghost" onClick={() => setEditItem(s)}>{t('common.edit')}</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <CreateStaffDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        orgId={organizationId ?? ''}
        departments={departments.data ?? []}
        onCreated={() => { setCreateOpen(false); void staffList.refresh(); }}
      />

      {editItem && (
        <EditStaffDialog
          open={true}
          onClose={() => setEditItem(null)}
          staff={editItem}
          departments={departments.data ?? []}
          onSaved={() => { setEditItem(null); void staffList.refresh(); }}
        />
      )}
    </div>
  );
}

function CreateStaffDialog({ open, onClose, orgId, departments, onCreated }: {
  open: boolean; onClose: () => void; orgId: string;
  departments: Array<{ id: string; name: string }>;
  onCreated: () => void;
}) {
  const { t } = useI18n();
  const [employeeCode, setEmployeeCode] = useState('');
  const [fullName, setFullName] = useState('');
  const [designation, setDesignation] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [hireDate, setHireDate] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminStaffApi.create(orgId, {
        employeeCode: employeeCode.trim(),
        fullName: fullName.trim(),
        designation: designation || undefined,
        departmentId: departmentId || undefined,
        hireDate: hireDate || undefined,
        facilityId: '', // resolved from context
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create staff member.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.staff.create')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!employeeCode || !fullName}>
          {t('common.confirm')}
        </Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="grid grid--2">
          <Input label={t('admin.staff.employeeCode')} value={employeeCode} onChange={(e) => setEmployeeCode(e.target.value)} required />
          <Input label={t('admin.staff.fullName')} value={fullName} onChange={(e) => setFullName(e.target.value)} required />
        </div>
        <Input label={t('admin.staff.designation')} value={designation} onChange={(e) => setDesignation(e.target.value)} />
        <Select label={t('admin.staff.department')} value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
          <option value="">{t('admin.staff.selectDepartment')}</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </Select>
        <Input label={t('admin.staff.hireDate')} type="date" value={hireDate} onChange={(e) => setHireDate(e.target.value)} />
      </div>
    </Dialog>
  );
}

function EditStaffDialog({ open, onClose, staff, departments, onSaved }: {
  open: boolean; onClose: () => void;
  staff: { id: string; fullName: string; designation: string | null; departmentId: string | null; status: string };
  departments: Array<{ id: string; name: string }>;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [fullName, setFullName] = useState(staff.fullName);
  const [designation, setDesignation] = useState(staff.designation ?? '');
  const [departmentId, setDepartmentId] = useState(staff.departmentId ?? '');
  const [status, setStatus] = useState(staff.status);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminStaffApi.update(staff.id, {
        fullName: fullName.trim(),
        designation: designation || undefined,
        departmentId: departmentId || undefined,
        status,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update staff member.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.staff.edit')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label={t('admin.staff.fullName')} value={fullName} onChange={(e) => setFullName(e.target.value)} required />
        <Input label={t('admin.staff.designation')} value={designation} onChange={(e) => setDesignation(e.target.value)} />
        <Select label={t('admin.staff.department')} value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
          <option value="">{t('admin.staff.selectDepartment')}</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </Select>
        <Select label={t('admin.staff.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="active">Active</option>
          <option value="departed">Departed</option>
        </Select>
      </div>
    </Dialog>
  );
}
