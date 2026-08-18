import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { followUpsApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Dialog, Input, Select } from './ui';
import { useI18n } from '../i18n/I18nProvider';

function defaultPlannedDate(): string {
  const d = new Date();
  d.setDate(d.getDate() + 14);
  return d.toISOString().slice(0, 16);
}

interface CreateFollowUpDialogProps {
  open: boolean;
  onClose: () => void;
  encounterId: string;
  providerStaffId: string;
  onCreated?: () => void;
}

export function CreateFollowUpDialog({ open, onClose, encounterId, providerStaffId, onCreated }: CreateFollowUpDialogProps) {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const { t } = useI18n();

  const [followUpType, setFollowUpType] = useState<string>('return_visit');
  const [plannedAt, setPlannedAt] = useState(defaultPlannedDate);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await followUpsApi.create(encounterId, {
        followUpType,
        plannedAt,
        reason: reason.trim() || undefined,
        providerStaffId,
      }, fac);
      onCreated?.();
      onClose();
      setReason('');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create follow-up.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('followUp.createTitle')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={() => void submit()} loading={submitting} disabled={!plannedAt}>
            {t('followUp.createConfirm')}
          </Button>
        </>
      }
    >
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}

        <Select label={t('followUp.type')} value={followUpType} onChange={(e) => setFollowUpType(e.target.value)}>
          <option value="return_visit">{t('followUp.returnVisit')}</option>
          <option value="teleconsult">{t('followUp.teleconsult')}</option>
        </Select>

        <Input
          label={t('followUp.plannedAtLabel')}
          type="datetime-local"
          value={plannedAt}
          onChange={(e) => setPlannedAt(e.target.value)}
        />

        <Input
          label={t('followUp.reasonLabel')}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder={t('followUp.reasonPlaceholder')}
        />
      </div>
    </Dialog>
  );
}
