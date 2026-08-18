import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminServicesApi, adminDepartmentsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip, money } from '../../components/ui';
import { ApiError } from '../../api/client';

export function AdminServicesPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);
  const [editItem, setEditItem] = useState<{ id: string; name: string; code: string; serviceType: string; status: string } | null>(null);
  const [deleteItem, setDeleteItem] = useState<{ id: string; name: string } | null>(null);

  const services = useFetch(() => adminServicesApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);
  const departments = useFetch(() => adminDepartmentsApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);

  if (services.loading) return <Spinner />;
  if (services.error) return <ErrorState error={services.error} onRetry={() => void services.refresh()} />;

  const data = Array.isArray(services.data) ? services.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.services.title')}</h2>
        <Button onClick={() => setCreateOpen(true)}>{t('admin.services.create')}</Button>
      </div>

      {data.length === 0 ? (
        <EmptyState title={t('admin.services.empty')} body={t('admin.services.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.services.code')}</th>
                <th>{t('admin.services.name')}</th>
                <th>{t('admin.services.type')}</th>
                <th>{t('admin.services.department')}</th>
                <th>{t('admin.services.charge')}</th>
                <th>{t('admin.services.status')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.map((s) => (
                <tr key={s.id}>
                  <td data-label={t('admin.services.code')} className="mono">{s.code}</td>
                  <td data-label={t('admin.services.name')}>{s.name}</td>
                  <td data-label={t('admin.services.type')} className="capitalize">{s.serviceType.replace(/_/g, ' ')}</td>
                  <td data-label={t('admin.services.department')}>{s.department?.name ?? '—'}</td>
                  <td data-label={t('admin.services.charge')} className="num">{s.defaultChargeMinor != null ? money(s.defaultChargeMinor) : '—'}</td>
                  <td data-label={t('admin.services.status')}>
                    <StatusChip tone={s.status === 'active' ? 'success' : 'neutral'} label={s.status} />
                  </td>
                  <td>
                    <Button variant="ghost" onClick={() => setEditItem(s)}>{t('common.edit')}</Button>
                    <Button variant="ghost" onClick={() => { setDeleteItem({ id: s.id, name: s.name }); }}>{t('common.delete')}</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <CreateServiceDialog open={createOpen} onClose={() => setCreateOpen(false)} orgId={organizationId ?? ''}
        departments={departments.data ?? []}
        onCreated={() => { setCreateOpen(false); void services.refresh(); }} />

      {editItem && (
        <EditServiceDialog open={true} onClose={() => setEditItem(null)} service={editItem}
          onSaved={() => { setEditItem(null); void services.refresh(); }} />
      )}

      {deleteItem && (
        <Dialog open={true} onClose={() => setDeleteItem(null)} title={t('admin.services.deleteConfirm')}
          footer={
            <>
              <Button variant="ghost" onClick={() => setDeleteItem(null)}>{t('common.cancel')}</Button>
              <Button variant="danger" onClick={async () => {
                try { await adminServicesApi.remove(deleteItem.id); setDeleteItem(null); void services.refresh(); }
                catch (err) { alert(err instanceof ApiError ? err.message : 'Delete failed.'); }
              }}>{t('common.confirm')}</Button>
            </>
          }>
          <p>{t('admin.services.deleteMessage')}</p>
        </Dialog>
      )}
    </div>
  );
}

const SERVICE_TYPES = [
  { value: 'opd_consultation', label: 'OPD Consultation' },
  { value: 'procedure', label: 'Procedure' },
  { value: 'investigation', label: 'Investigation' },
  { value: 'follow_up', label: 'Follow-up' },
  { value: 'other', label: 'Other' },
];

function CreateServiceDialog({ open, onClose, orgId, departments, onCreated }: {
  open: boolean; onClose: () => void; orgId: string;
  departments: Array<{ id: string; name: string }>;
  onCreated: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [serviceType, setServiceType] = useState('opd_consultation');
  const [departmentId, setDepartmentId] = useState('');
  const [duration, setDuration] = useState('');
  const [charge, setCharge] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminServicesApi.create(orgId, {
        name: name.trim(),
        code: code.trim(),
        serviceType,
        departmentId: departmentId || undefined,
        defaultDurationMinutes: duration ? Number(duration) : undefined,
        defaultChargeMinor: charge ? Number(charge) * 100 : undefined,
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create service.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.services.create')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!name || !code}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="grid grid--2">
          <Input label={t('admin.services.name')} value={name} onChange={(e) => setName(e.target.value)} required />
          <Input label={t('admin.services.code')} value={code} onChange={(e) => setCode(e.target.value)} required />
        </div>
        <Select label={t('admin.services.type')} value={serviceType} onChange={(e) => setServiceType(e.target.value)}>
          {SERVICE_TYPES.map((st) => <option key={st.value} value={st.value}>{st.label}</option>)}
        </Select>
        <Select label={t('admin.services.department')} value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
          <option value="">{t('admin.staff.selectDepartment')}</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </Select>
        <div className="grid grid--2">
          <Input label={t('admin.services.duration')} type="number" value={duration} onChange={(e) => setDuration(e.target.value)} placeholder="Minutes" />
          <Input label={t('admin.services.chargeNPR')} type="number" value={charge} onChange={(e) => setCharge(e.target.value)} placeholder="NPR" />
        </div>
      </div>
    </Dialog>
  );
}

function EditServiceDialog({ open, onClose, service, onSaved }: {
  open: boolean; onClose: () => void;
  service: { id: string; name: string; code: string; serviceType: string; status: string };
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(service.name);
  const [code, setCode] = useState(service.code);
  const [serviceType, setServiceType] = useState(service.serviceType);
  const [status, setStatus] = useState(service.status);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminServicesApi.update(service.id, { name: name.trim(), code: code.trim(), serviceType, status });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update service.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.services.edit')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="grid grid--2">
          <Input label={t('admin.services.name')} value={name} onChange={(e) => setName(e.target.value)} required />
          <Input label={t('admin.services.code')} value={code} onChange={(e) => setCode(e.target.value)} required />
        </div>
        <Select label={t('admin.services.type')} value={serviceType} onChange={(e) => setServiceType(e.target.value)}>
          {SERVICE_TYPES.map((st) => <option key={st.value} value={st.value}>{st.label}</option>)}
        </Select>
        <Select label={t('admin.services.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </Select>
      </div>
    </Dialog>
  );
}
