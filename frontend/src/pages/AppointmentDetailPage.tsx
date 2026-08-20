import { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { appointmentsApi, encountersApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  AppointmentStatus,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Input,
  SkeletonCard,
  formatDateTime,
} from '../components/ui';
import { ApiError } from '../api/client';
import { useI18n } from '../i18n/I18nProvider';
import './appointments.css';

export function AppointmentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;
  const navigate = useNavigate();
  const { t } = useI18n();
  const [notice, setNotice] = useState<string | null>(null);
  const [noticeTone, setNoticeTone] = useState<'success' | 'danger'>('success');
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const appointment = useFetch(
    () => (id ? appointmentsApi.show(id, fac) : Promise.resolve(null)),
    [id, fac],
  );

  const data = appointment.data;

  const handleCancel = async () => {
    if (!id || !cancelReason.trim()) return;
    setSubmitting(true);
    try {
      await appointmentsApi.cancel(id, cancelReason.trim(), fac);
      setNotice(t('appointment.cancelSuccess'));
      setNoticeTone('success');
      setCancelOpen(false);
      setCancelReason('');
      void appointment.refresh();
    } catch (err) {
      setNotice(err instanceof ApiError ? err.message : 'Cancellation failed.');
      setNoticeTone('danger');
    } finally {
      setSubmitting(false);
    }
  };

  if (appointment.loading && !data) return (
    <div className="page">
      <div className="page__head"><div className="page__title">
        <div className="skeleton skeleton--text-sm" style={{ width: 120, height: 12 }} />
        <div className="skeleton skeleton--heading" style={{ width: 240, height: 24 }} />
      </div></div>
      <SkeletonCard rows={6} />
    </div>
  );
  if (appointment.error && !data) return <ErrorState error={appointment.error} onRetry={() => void appointment.refresh()} />;
  if (!data) return <EmptyState title="Appointment not found" />;

  const canCancel = data.status === 'booked' || data.status === 'checked_in';
  const canCheckIn = data.status === 'booked';
  const canStartEncounter = data.status === 'checked_in';

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <Link to="/appointments" className="breadcrumb-link">{t('nav.appointments')}</Link>
          <h1>{t('appointment.detail')}</h1>
        </div>
      </div>

      {notice && <Alert tone={noticeTone}>{notice}</Alert>}

      <Card>
        <div className="detail-grid">
          <div className="detail-row">
            <span className="detail-label">{t('appointment.status')}</span>
            <AppointmentStatus status={data.status} />
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.type')}</span>
            <span>{data.appointmentType}</span>
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.source')}</span>
            <span>{data.source}</span>
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.scheduledTime')}</span>
            <span>{formatDateTime(data.startsAt)} – {formatDateTime(data.endsAt)}</span>
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.tokenNo')}</span>
            <span className="mono">{data.tokenNo != null ? `#${data.tokenNo}` : '—'}</span>
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.patient')}</span>
            {data.patient ? (
              <Link to={`/patients/${data.patientId}`}>{data.patient.fullName} <span className="mono muted small">{data.patient.mrn}</span></Link>
            ) : '—'}
          </div>
          <div className="detail-row">
            <span className="detail-label">{t('appointment.provider')}</span>
            <span>{data.provider?.fullName ?? '—'}</span>
          </div>
          {data.cancelReason && (
            <div className="detail-row">
              <span className="detail-label">{t('appointment.cancelReason')}</span>
              <span className="text-danger">{data.cancelReason}</span>
            </div>
          )}
        </div>
      </Card>

      <div className="action-bar">
        {canCheckIn && (
          <Button onClick={async () => {
            try {
              const checked = await appointmentsApi.checkIn(id!, fac);
              setNotice(`${t('appointment.checkedIn')} #${checked.tokenNo}`);
              setNoticeTone('success');
              void appointment.refresh();
            } catch (err) {
              setNotice(err instanceof ApiError ? err.message : 'Check-in failed.');
              setNoticeTone('danger');
            }
          }}>
            {t('appointment.checkIn')}
          </Button>
        )}
        {canStartEncounter && (
          <Button onClick={async () => {
            try {
              const enc = await encountersApi.start(id!, fac);
              navigate(`/encounters/${enc.id}`);
            } catch (err) {
              setNotice(err instanceof ApiError ? err.message : 'Failed to start encounter.');
              setNoticeTone('danger');
            }
          }}>
            {t('appointment.startEncounter')}
          </Button>
        )}
        {canCancel && (
          <Button variant="danger" onClick={() => setCancelOpen(true)}>
            {t('appointment.cancel')}
          </Button>
        )}
      </div>

      <Dialog
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={t('appointment.cancelConfirm')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setCancelOpen(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={() => void handleCancel()} loading={submitting} disabled={!cancelReason.trim()}>
              {t('appointment.cancelConfirm')}
            </Button>
          </>
        }
      >
        <Input
          label={t('appointment.cancelReasonLabel')}
          value={cancelReason}
          onChange={(e) => setCancelReason(e.target.value)}
          placeholder="Patient requested cancellation"
        />
      </Dialog>
    </div>
  );
}
