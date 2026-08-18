import { useState } from 'react';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { useFetch } from '../../hooks/useFetch';
import { adminMedicationsApi } from '../../api/endpoints';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip, money } from '../../components/ui';
import { ApiError } from '../../api/client';
import type { Medication } from '../../api/types';

export function AdminMedicationsPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const { t } = useI18n();
  const [createOpen, setCreateOpen] = useState(false);

  const meds = useFetch(() => adminMedicationsApi.list(organizationId ?? '', selectedFacilityId), [organizationId, selectedFacilityId]);

  if (meds.loading) return <Spinner />;
  if (meds.error) return <ErrorState error={meds.error} onRetry={() => void meds.refresh()} />;

  const data = Array.isArray(meds.data) ? meds.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>{t('admin.medications.title')}</h2>
        <Button onClick={() => setCreateOpen(true)}>{t('admin.medications.create')}</Button>
      </div>

      {data.length === 0 ? (
        <EmptyState title={t('admin.medications.empty')} body={t('admin.medications.emptyHint')} />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('admin.medications.code')}</th>
                <th>{t('admin.medications.genericName')}</th>
                <th>{t('admin.medications.brandName')}</th>
                <th>{t('admin.medications.strength')}</th>
                <th>{t('admin.medications.form')}</th>
                <th>{t('admin.medications.price')}</th>
                <th>{t('admin.medications.status')}</th>
              </tr>
            </thead>
            <tbody>
              {data.map((m: Medication) => (
                <tr key={m.id}>
                  <td data-label={t('admin.medications.code')} className="mono">{m.code}</td>
                  <td data-label={t('admin.medications.genericName')}>{m.genericName}</td>
                  <td data-label={t('admin.medications.brandName')}>{m.brandName ?? '—'}</td>
                  <td data-label={t('admin.medications.strength')}>{m.strength}</td>
                  <td data-label={t('admin.medications.form')} className="capitalize">{m.form}</td>
                  <td data-label={t('admin.medications.price')} className="num">{money(m.priceMinor, m.currency)}</td>
                  <td data-label={t('admin.medications.status')}>
                    <StatusChip tone={m.status === 'active' ? 'success' : 'neutral'} label={m.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <CreateMedicationDialog open={createOpen} onClose={() => setCreateOpen(false)} orgId={organizationId ?? ''}
        onCreated={() => { setCreateOpen(false); void meds.refresh(); }} />
    </div>
  );
}

function CreateMedicationDialog({ open, onClose, orgId, onCreated }: {
  open: boolean; onClose: () => void; orgId: string; onCreated: () => void;
}) {
  const { t } = useI18n();
  const [code, setCode] = useState('');
  const [genericName, setGenericName] = useState('');
  const [brandName, setBrandName] = useState('');
  const [strength, setStrength] = useState('');
  const [form, setForm] = useState('tablet');
  const [unit, setUnit] = useState('');
  const [price, setPrice] = useState('');
  const [isControlled, setIsControlled] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await adminMedicationsApi.create(orgId, {
        code: code.trim(),
        genericName: genericName.trim(),
        brandName: brandName || undefined,
        strength: strength.trim(),
        form,
        unit: unit.trim(),
        priceMinor: Math.round(Number(price) * 100),
        isControlled,
        facilityId: '', // resolved from context
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to add medication.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title={t('admin.medications.create')} footer={
      <>
        <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!code || !genericName || !strength || !unit || !price}>
          {t('common.confirm')}
        </Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="grid grid--2">
          <Input label={t('admin.medications.code')} value={code} onChange={(e) => setCode(e.target.value)} required />
          <Input label={t('admin.medications.genericName')} value={genericName} onChange={(e) => setGenericName(e.target.value)} required />
        </div>
        <Input label={t('admin.medications.brandName')} value={brandName} onChange={(e) => setBrandName(e.target.value)} />
        <div className="grid grid--2">
          <Input label={t('admin.medications.strength')} value={strength} onChange={(e) => setStrength(e.target.value)} required placeholder="e.g. 500mg" />
          <Select label={t('admin.medications.form')} value={form} onChange={(e) => setForm(e.target.value)}>
            <option value="tablet">Tablet</option>
            <option value="capsule">Capsule</option>
            <option value="syrup">Syrup</option>
            <option value="injection">Injection</option>
            <option value="cream">Cream</option>
            <option value="drops">Drops</option>
            <option value="inhaler">Inhaler</option>
          </Select>
        </div>
        <div className="grid grid--2">
          <Input label={t('admin.medications.unit')} value={unit} onChange={(e) => setUnit(e.target.value)} required placeholder="e.g. strip, bottle" />
          <Input label={t('admin.medications.priceNPR')} type="number" value={price} onChange={(e) => setPrice(e.target.value)} required placeholder="NPR" />
        </div>
        <label className="check">
          <input type="checkbox" checked={isControlled} onChange={(e) => setIsControlled(e.target.checked)} />
          {t('admin.medications.controlled')}
        </label>
      </div>
    </Dialog>
  );
}
