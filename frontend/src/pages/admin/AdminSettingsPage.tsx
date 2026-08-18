import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminFacilitySettingsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Spinner } from '../../components/ui';
import { ApiError } from '../../api/client';
import type { FacilitySetting } from '../../api/types';

export function AdminSettingsPage() {
  const { selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [addOpen, setAddOpen] = useState(false);
  const [editKey, setEditKey] = useState<string | null>(null);

  const settings = useFetch(
    () => (selectedFacilityId ? adminFacilitySettingsApi.list(selectedFacilityId) : Promise.resolve({} as Record<string, FacilitySetting>)),
    [selectedFacilityId],
  );

  if (!selectedFacilityId) {
    return <EmptyState title={t('admin.settings.selectFacility')} body={t('admin.settings.selectFacilityHint')} />;
  }

  if (settings.loading) return <Spinner />;
  if (settings.error) return <ErrorState error={settings.error} onRetry={() => void settings.refresh()} />;

  const data = settings.data ?? {};
  const entries = Object.entries(data);

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.settings.title')}</h2>
        <Button onClick={() => setAddOpen(true)}>{t('admin.settings.addKey')}</Button>
      </div>

      {entries.length === 0 ? (
        <EmptyState title={t('admin.settings.empty')} body={t('admin.settings.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.settings.key')}</th>
                <th>{t('admin.settings.value')}</th>
                <th>{t('admin.settings.version')}</th>
                <th>{t('admin.settings.updatedAt')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {entries.map(([key, setting]) => (
                <tr key={key}>
                  <td data-label={t('admin.settings.key')} className="mono">{key}</td>
                  <td data-label={t('admin.settings.value')}>{String(setting.value ?? '—')}</td>
                  <td data-label={t('admin.settings.version')} className="num">v{setting.version}</td>
                  <td data-label={t('admin.settings.updatedAt')}>{setting.updatedAt ? new Date(setting.updatedAt).toLocaleString() : '—'}</td>
                  <td>
                    <Button variant="ghost" onClick={() => setEditKey(key)}>{t('common.edit')}</Button>
                    <Button variant="ghost" onClick={async () => {
                      if (!selectedFacilityId) return;
                      try {
                        await adminFacilitySettingsApi.remove(selectedFacilityId, key);
                        void settings.refresh();
                      } catch (err) {
                        alert(err instanceof ApiError ? err.message : 'Delete failed.');
                      }
                    }}>{t('common.delete')}</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {addOpen && (
        <SettingsKeyDialog
          open={true}
          onClose={() => setAddOpen(false)}
          facilityId={selectedFacilityId}
          onSaved={() => { setAddOpen(false); void settings.refresh(); }}
        />
      )}

      {editKey && (
        <SettingsKeyDialog
          open={true}
          onClose={() => setEditKey(null)}
          facilityId={selectedFacilityId}
          existingKey={editKey}
          existingValue={String(data[editKey]?.value ?? '')}
          onSaved={() => { setEditKey(null); void settings.refresh(); }}
        />
      )}
    </div>
  );
}

function SettingsKeyDialog({ open, onClose, facilityId, existingKey, existingValue, onSaved }: {
  open: boolean; onClose: () => void; facilityId: string;
  existingKey?: string; existingValue?: string;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [key, setKey] = useState(existingKey ?? '');
  const [value, setValue] = useState(existingValue ?? '');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEdit = Boolean(existingKey);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminFacilitySettingsApi.update(facilityId, { [key.trim()]: value });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save setting.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={isEdit ? t('admin.settings.editKey') : t('admin.settings.addKey')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!key.trim()}>{t('common.confirm')}</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Input label={t('admin.settings.key')} value={key} onChange={(e) => setKey(e.target.value)} required disabled={isEdit} hint={isEdit ? 'Key cannot be changed' : undefined} />
        <Input label={t('admin.settings.value')} value={value} onChange={(e) => setValue(e.target.value)} />
      </div>
    </Dialog>
  );
}
