import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { followUpsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Input,
  Spinner,
} from '../components/ui';
import { ApiError } from '../api/client';
import { useI18n } from '../i18n/I18nProvider';
import type { FollowUp } from '../api/types';

function formatDateTime(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

interface FollowUpListProps {
  encounterId?: string;
  patientId?: string;
  onRefresh?: () => void;
}

export function FollowUpList({ encounterId, patientId, onRefresh }: FollowUpListProps) {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const { t } = useI18n();
  const [notice, setNotice] = useState<string | null>(null);
  const [noticeTone, setNoticeTone] = useState<'success' | 'danger'>('success');

  const followUps = useFetch(() => {
    if (encounterId) return followUpsApi.forEncounter(encounterId, fac);
    if (patientId) return followUpsApi.forPatient(patientId, fac);
    return Promise.resolve([]);
  }, [encounterId, patientId, fac]);

  const handleAutoBook = async (id: string) => {
    try {
      await followUpsApi.autoBook(id, fac);
      setNotice(t('followUp.autoBookSuccess'));
      setNoticeTone('success');
      void followUps.refresh();
      onRefresh?.();
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Auto-book failed.');
      setNoticeTone('danger');
    }
  };

  const handleCancel = async (id: string, reason: string) => {
    try {
      await followUpsApi.cancel(id, reason, fac);
      setNotice(t('followUp.cancelSuccess'));
      setNoticeTone('success');
      void followUps.refresh();
      onRefresh?.();
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Cancel failed.');
      setNoticeTone('danger');
    }
  };

  const handleComplete = async (id: string) => {
    try {
      await followUpsApi.complete(id, fac);
      setNotice(t('followUp.completeSuccess'));
      setNoticeTone('success');
      void followUps.refresh();
      onRefresh?.();
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Complete failed.');
      setNoticeTone('danger');
    }
  };

  if (followUps.loading && !followUps.data) return <Spinner />;
  if (followUps.error && !followUps.data) return <ErrorState error={followUps.error} onRetry={() => void followUps.refresh()} />;

  const items = Array.isArray(followUps.data) ? followUps.data : [];

  return (
    <Card title={t('followUp.title')}>
      {notice && <Alert tone={noticeTone}>{notice}</Alert>}

      {items.length === 0 ? (
        <EmptyState title={t('followUp.empty')} body={t('followUp.emptyBody')} />
      ) : (
        <div className="followup-list">
          {items.map((fu) => (
            <FollowUpRow
              key={fu.id}
              followUp={fu}
              onAutoBook={handleAutoBook}
              onCancel={handleCancel}
              onComplete={handleComplete}
            />
          ))}
        </div>
      )}
    </Card>
  );
}

function FollowUpRow({
  followUp: fu,
  onAutoBook,
  onCancel,
  onComplete,
}: {
  followUp: FollowUp;
  onAutoBook: (id: string) => void;
  onCancel: (id: string, reason: string) => void;
  onComplete: (id: string) => void;
}) {
  const { t } = useI18n();
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  const statusClass = `status-chip status-chip--${fu.status === 'cancelled' ? 'danger' : fu.status === 'completed' ? 'success' : fu.status === 'booked' ? 'info' : 'neutral'}`;

  return (
    <div className="followup-row">
      <div className="followup-row__main">
        <div className="followup-row__header">
          <span className="followup-row__type">{fu.followUpType === 'teleconsult' ? '📹' : '🏥'} {fu.followUpType === 'teleconsult' ? t('followUp.teleconsult') : t('followUp.returnVisit')}</span>
          <span className={statusClass}>{fu.status}</span>
        </div>
        <div className="followup-row__meta">
          <span>{t('followUp.plannedAt')}: {formatDateTime(fu.plannedAt)}</span>
          {fu.reason && <span>{t('followUp.reason')}: {fu.reason}</span>}
          {fu.bookedAppointmentId && <span>{t('followUp.bookedAppointment')}</span>}
        </div>
      </div>

      <div className="followup-row__actions">
        {fu.status === 'planned' && (
          <>
            <Button size="sm" onClick={() => onAutoBook(fu.id)}>{t('followUp.autoBook')}</Button>
            <Button size="sm" variant="ghost" onClick={() => setCancelOpen(true)}>{t('followUp.cancel')}</Button>
          </>
        )}
        {fu.status === 'booked' && (
          <>
            <Button size="sm" onClick={() => onComplete(fu.id)}>{t('followUp.markComplete')}</Button>
            <Button size="sm" variant="ghost" onClick={() => setCancelOpen(true)}>{t('followUp.cancel')}</Button>
          </>
        )}
      </div>

      <Dialog
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={t('followUp.cancelConfirm')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setCancelOpen(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={() => { onCancel(fu.id, cancelReason); setCancelOpen(false); setCancelReason(''); }} disabled={!cancelReason.trim()}>
              {t('followUp.cancelConfirm')}
            </Button>
          </>
        }
      >
        <Input
          label={t('followUp.cancelReasonLabel')}
          value={cancelReason}
          onChange={(e) => setCancelReason(e.target.value)}
        />
      </Dialog>
    </div>
  );
}
