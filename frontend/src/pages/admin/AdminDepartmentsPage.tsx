import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminDepartmentsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip } from '../../components/ui';
import { ApiError } from '../../api/client';

export function AdminDepartmentsPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);
  const [editItem, setEditItem] = useState<{ id: string; name: string; code: string; status: string } | null>(null);
  const [deleteItem, setDeleteItem] = useState<{ id: string; name: string } | null>(null);

  const departments = useFetch(() => adminDepartmentsApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);

  if (departments.loading) return <Spinner />;
  if (departments.error) return <ErrorState error={departments.error} onRetry={() => void departments.refresh()} />;

  const data = Array.isArray(departments.data) ? departments.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.departments.title')}</h2>
        <Button onClick={() => setCreateOpen(true)}>{t('admin.departments.create')}</Button>
      </div>

      {data.length === 0 ? (
        <EmptyState title={t('admin.departments.empty')} body={t('admin.departments.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.departments.code')}</th>
                <th>{t('admin.departments.name')}</th>
                <th>{t('admin.departments.status')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data.map((d) => (
                <tr key={d.id}>
                  <td data-label={t('admin.departments.code')} className="mono">{d.code}</td>
                  <td data-label={t('admin.departments.name')}>{d.name}</td>
                  <td data-label={t('admin.departments.status')}>
                    <StatusChip tone={d.status === 'active' ? 'success' : 'neutral'} label={d.status} />
                  </td>
                  <td>
                    <Button variant="ghost" onClick={() => setEditItem(d)}>{t('common.edit')}</Button>
                    <Button variant="ghost" onClick={() => void setDeleteItem({ id: d.id, name: d.name })}>{t('common.delete')}</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <CreateDepartmentDialog open={createOpen} onClose={() => setCreateOpen(false)} orgId={organizationId ?? ''}
        onCreated={() => { setCreateOpen(false); void departments.refresh(); }} />

      {editItem && (
        <EditDepartmentDialog open={true} onClose={() => setEditItem(null)} department={editItem}
          onSaved={() => { setEditItem(null); void departments.refresh(); }} />
      )}

      {deleteItem && (
        <Dialog open={true} onClose={() => setDeleteItem(null)} title={t('admin.departments.deleteConfirm')}
          footer={
            <>
              <Button variant="ghost" onClick={() => setDeleteItem(null)}>{t('common.cancel')}</Button>
              <Button variant="danger" onClick={async () => {
                try { await adminDepartmentsApi.remove(deleteItem.id); setDeleteItem(null); void departments.refresh(); }
                catch (err) { alert(err instanceof ApiError ? err.message : 'Delete failed.'); }
              }}>{t('common.confirm')}</Button>
            </>
          }>
          <p>{t('admin.departments.deleteMessage')}</p>
        </Dialog>
      )}
    </div>
  );
}

function CreateDepartmentDialog({ open, onClose, orgId, onCreated }: {
  open: boolean; onClose: () => void; orgId: string; onCreated: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminDepartmentsApi.create(orgId, { name: name.trim(), code: code.trim() });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create department.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.departments.create')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!name || !code}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label={t('admin.departments.name')} value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label={t('admin.departments.code')} value={code} onChange={(e) => setCode(e.target.value)} required hint="Lowercase alphanumeric with hyphens" />
      </div>
    </Dialog>
  );
}

function EditDepartmentDialog({ open, onClose, department, onSaved }: {
  open: boolean; onClose: () => void;
  department: { id: string; name: string; code: string; status: string };
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(department.name);
  const [code, setCode] = useState(department.code);
  const [status, setStatus] = useState(department.status);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminDepartmentsApi.update(department.id, { name: name.trim(), code: code.trim(), status });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update department.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.departments.edit')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label={t('admin.departments.name')} value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label={t('admin.departments.code')} value={code} onChange={(e) => setCode(e.target.value)} required />
        <Select label={t('admin.departments.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </Select>
      </div>
    </Dialog>
  );
}
