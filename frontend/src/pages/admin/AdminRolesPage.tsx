import { useState } from 'react';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminRolesApi, adminPermissionsApi } from '../../api/endpoints';
import { Card, EmptyState, ErrorState, Spinner, Tabs } from '../../components/ui';

export function AdminRolesPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState('roles');

  const roles = useFetch(() => adminRolesApi.list(), []);
  const permissions = useFetch(() => adminPermissionsApi.list(), []);

  const tabs = [
    { id: 'roles', label: t('admin.roles.tabRoles') },
    { id: 'permissions', label: t('admin.roles.tabPermissions') },
  ];

  return (
    <div className="stack">
      <h2>{t('admin.roles.title')}</h2>
      <Tabs tabs={tabs} active={tab} onChange={setTab} />

      {tab === 'roles' && <RolesTab data={roles} />}
      {tab === 'permissions' && <PermissionsTab data={permissions} />}
    </div>
  );
}

function RolesTab({ data }: { data: ReturnType<typeof useFetch<Array<{ id: string; code: string; name: string; scopeType: string; permissions: Array<{ code: string }> }>>> }) {
  const { t } = useI18n();
  if (data.loading) return <Spinner />;
  if (data.error) return <ErrorState error={data.error} onRetry={() => void data.refresh()} />;

  const items = Array.isArray(data.data) ? data.data : [];
  if (items.length === 0) return <EmptyState title={t('admin.roles.empty')} />;

  return (
    <Card>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('admin.roles.name')}</th>
            <th>{t('admin.roles.code')}</th>
            <th>{t('admin.roles.scope')}</th>
            <th>{t('admin.roles.permissionCount')}</th>
          </tr>
        </thead>
        <tbody>
          {items.map((r) => (
            <tr key={r.id}>
              <td data-label={t('admin.roles.name')}>{r.name}</td>
              <td data-label={t('admin.roles.code')} className="mono">{r.code}</td>
              <td data-label={t('admin.roles.scope')} className="capitalize">{r.scopeType}</td>
              <td data-label={t('admin.roles.permissionCount')} className="num">{r.permissions?.length ?? 0}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

function PermissionsTab({ data }: { data: ReturnType<typeof useFetch<Array<{ id: string; code: string; domain: string; description: string | null }>>> }) {
  const { t } = useI18n();
  if (data.loading) return <Spinner />;
  if (data.error) return <ErrorState error={data.error} onRetry={() => void data.refresh()} />;

  const items = Array.isArray(data.data) ? data.data : [];
  if (items.length === 0) return <EmptyState title={t('admin.permissions.empty')} />;

  // Group by domain.
  const grouped = new Map<string, typeof items>();
  for (const p of items) {
    const list = grouped.get(p.domain) ?? [];
    list.push(p);
    grouped.set(p.domain, list);
  }

  return (
    <div className="stack">
      {[...grouped.entries()].map(([domain, perms]) => (
        <Card key={domain} title={domain}>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.permissions.code')}</th>
                <th>{t('admin.permissions.description')}</th>
              </tr>
            </thead>
            <tbody>
              {perms.map((p) => (
                <tr key={p.id}>
                  <td data-label={t('admin.permissions.code')} className="mono">{p.code}</td>
                  <td data-label={t('admin.permissions.description')}>{p.description ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      ))}
    </div>
  );
}
