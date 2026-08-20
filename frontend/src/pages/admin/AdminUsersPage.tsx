import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminUsersApi, adminRolesApi, adminFacilitiesApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, SkeletonTable, StatusChip } from '../../components/ui';
import { ApiError } from '../../api/client';

export function AdminUsersPage() {
  const { organizationId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);

  const users = useFetch(() => adminUsersApi.list(), []);
  const roles = useFetch(() => adminRolesApi.list(), []);
  const facilities = useFetch(
    () => (organizationId ? adminFacilitiesApi.list(organizationId) : Promise.resolve([])),
    [organizationId],
  );

  if (users.loading) return (
    <div className="stack">
      <div className="page__head"><h2>Users</h2></div>
      <SkeletonTable rows={5} cols={3} />
    </div>
  );
  if (users.error) return <ErrorState error={users.error} onRetry={() => void users.refresh()} />;

  const data = Array.isArray(users.data) ? users.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.users.title')}</h2>
        <Button onClick={() => setCreateOpen(true)}>{t('admin.users.create')}</Button>
      </div>

      {data.length === 0 ? (
        <EmptyState title={t('admin.users.empty')} body={t('admin.users.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.users.email')}</th>
                <th>{t('admin.users.status')}</th>
                <th>{t('admin.users.roles')}</th>
              </tr>
            </thead>
            <tbody>
              {data.map((u) => (
                <tr key={u.id}>
                  <td data-label={t('admin.users.email')}>{u.email}</td>
                  <td data-label={t('admin.users.status')}>
                    <StatusChip tone={u.status === 'active' ? 'success' : 'neutral'} label={u.status} />
                  </td>
                  <td data-label={t('admin.users.roles')}>
                    {u.assignments.map((a) => (
                      <span key={`${a.role}-${a.facilityId}`} className="mono small" style={{ marginRight: 8 }}>
                        {a.role}{a.facilityId ? ` @${a.facilityId.slice(0, 8)}` : ''}
                      </span>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <CreateUserDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        orgId={organizationId ?? ''}
        roles={roles.data ?? []}
        facilities={facilities.data ?? []}
        onCreated={() => { setCreateOpen(false); void users.refresh(); }}
      />
    </div>
  );
}

function CreateUserDialog({ open, onClose, orgId, roles, facilities, onCreated }: {
  open: boolean; onClose: () => void; orgId: string;
  roles: Array<{ code: string; name: string }>; facilities: Array<{ id: string; name: string }>;
  onCreated: () => void;
}) {
  const { t } = useI18n();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [roleCode, setRoleCode] = useState('');
  const [facilityId, setFacilityId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminUsersApi.create(orgId, {
        email: email.trim(),
        password,
        roleCode,
        facilityId: facilityId || undefined,
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create user.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.users.create')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!email || !password || !roleCode}>
          {t('common.confirm')}
        </Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label={t('admin.users.email')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        <Input label={t('admin.users.password')} type="password" value={password} onChange={(e) => setPassword(e.target.value)} required hint="Minimum 12 characters" />
        <Select label={t('admin.users.role')} value={roleCode} onChange={(e) => setRoleCode(e.target.value)} required>
          <option value="">{t('admin.users.selectRole')}</option>
          {roles.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
        </Select>
        <Select label={t('admin.users.facility')} value={facilityId} onChange={(e) => setFacilityId(e.target.value)}>
          <option value="">{t('admin.users.noFacility')}</option>
          {facilities.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
        </Select>
      </div>
    </Dialog>
  );
}
