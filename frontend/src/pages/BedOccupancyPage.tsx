import { useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { bedWardApi } from '../api/endpoints';
import { Button, Card, EmptyState, Spinner } from '../components/ui';
import { ApiError } from '../api/client';

/* ── Types ── */
interface BedInfo {
  id: string;
  bedCode: string;
  status: string;
  lockVersion: number;
  admissionId: string | null;
}

interface RoomInfo {
  id: string;
  name: string;
  roomType: string;
  counts: Record<string, number>;
  beds: BedInfo[];
}

interface WardInfo {
  id: string;
  name: string;
  wardType: string;
  counts: Record<string, number>;
  rooms: RoomInfo[];
}

/* ── Status config ── */
const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string; border: string }> = {
  available: { label: 'Available', color: '#12b76a', bg: '#ecfdf3', border: '#d1fadf' },
  occupied: { label: 'Occupied', color: '#dc2626', bg: '#fef2f2', border: '#fecaca' },
  reserved: { label: 'Reserved', color: '#f59e0b', bg: '#fffbeb', border: '#fde68a' },
  cleaning: { label: 'Cleaning', color: '#2563eb', bg: '#eff6ff', border: '#bfdbfe' },
  maintenance: { label: 'Maintenance', color: '#7c3aed', bg: '#f5f3ff', border: '#ddd6fe' },
  out_of_service: { label: 'Out of Service', color: '#667085', bg: '#f9fafb', border: '#e5e7eb' },
};

const STATUS_OPTIONS = ['available', 'occupied', 'reserved', 'cleaning', 'maintenance', 'out_of_service'];

/* ══════════════════════════════════════════════════════════════════
   MAIN PAGE
   ══════════════════════════════════════════════════════════════════ */

export function BedOccupancyPage() {
  const { organizationId } = useTenant();
  const [selectedWard, setSelectedWard] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [updateTarget, setUpdateTarget] = useState<{ bed: BedInfo; wardId: string } | null>(null);

  const { data, loading, error, refresh } = useFetch(
    () => bedWardApi.occupancy(organizationId ?? ''),
    [organizationId],
  );

  if (loading) return <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}><Spinner /></div>;
  if (error) return <div style={{ padding: 32, textAlign: 'center', color: 'var(--text-secondary)' }}>Error loading bed occupancy</div>;

  const summary = data?.summary ?? { total: 0, available: 0, occupied: 0, reserved: 0, cleaning: 0, maintenance: 0, out_of_service: 0 };
  const wards: WardInfo[] = data?.wards ?? [];
  const filteredWards = selectedWard ? wards.filter((w) => w.id === selectedWard) : wards;

  const occupancyRate = summary.total > 0 ? Math.round((summary.occupied / summary.total) * 100) : 0;

  return (
    <div className="page page-transition" style={{ maxWidth: 1400, margin: '0 auto' }}>
      {/* ── Header ── */}
      <div className="page__head">
        <div className="page__title">
          <h1>Bed Occupancy Command Center</h1>
          <span className="page__sub">{summary.total} beds · {occupancyRate}% occupied</span>
        </div>
      </div>

      {/* ── Summary Cards ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12 }}>
        {Object.entries(STATUS_CONFIG).map(([key, cfg]) => (
          <div
            key={key}
            onClick={() => setStatusFilter(statusFilter === key ? '' : key)}
            style={{
              padding: '12px 16px', borderRadius: 'var(--radius-lg)', cursor: 'pointer',
              border: `2px solid ${statusFilter === key ? cfg.color : 'var(--border-subtle)'}`,
              background: statusFilter === key ? cfg.bg : 'var(--surface-card)',
              transition: 'all 120ms',
            }}
          >
            <div style={{ fontSize: 24, fontWeight: 700, color: cfg.color, fontVariantNumeric: 'tabular-nums' }}>
              {summary[key] ?? 0}
            </div>
            <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-tertiary)', textTransform: 'uppercase', letterSpacing: '0.03em' }}>
              {cfg.label}
            </div>
          </div>
        ))}
      </div>

      {/* ── Ward Filter ── */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <button
          onClick={() => setSelectedWard(null)}
          style={{
            padding: '4px 12px', borderRadius: 9999, fontSize: 12, fontWeight: 600, cursor: 'pointer',
            border: `1px solid ${!selectedWard ? 'var(--blue-600)' : 'var(--border-subtle)'}`,
            background: !selectedWard ? '#f0fdfa' : 'transparent',
            color: !selectedWard ? 'var(--blue-600)' : 'var(--text-secondary)',
          }}
        >
          All Wards ({wards.length})
        </button>
        {wards.map((w) => (
          <button
            key={w.id}
            onClick={() => setSelectedWard(selectedWard === w.id ? null : w.id)}
            style={{
              padding: '4px 12px', borderRadius: 9999, fontSize: 12, fontWeight: 600, cursor: 'pointer',
              border: `1px solid ${selectedWard === w.id ? 'var(--blue-600)' : 'var(--border-subtle)'}`,
              background: selectedWard === w.id ? '#f0fdfa' : 'transparent',
              color: selectedWard === w.id ? 'var(--blue-600)' : 'var(--text-secondary)',
            }}
          >
            {w.name} ({w.counts.total})
          </button>
        ))}
      </div>

      {/* ── Empty ── */}
      {filteredWards.length === 0 && (
        <EmptyState title="No wards found" body="Create wards and beds to see the occupancy map." />
      )}

      {/* ── Ward → Room → Bed Grid ── */}
      {filteredWards.map((ward) => (
        <Card key={ward.id} style={{ padding: 0, overflow: 'hidden' }}>
          {/* Ward header */}
          <div style={{
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: '12px 20px', borderBottom: '1px solid var(--border-subtle)',
            background: 'var(--gray-50)',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0 }}>{ward.name}</h3>
              <span style={{ fontSize: 12, color: 'var(--text-tertiary)' }}>{ward.wardType}</span>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              {Object.entries(STATUS_CONFIG).map(([key, cfg]) => (
                (ward.counts[key] ?? 0) > 0 && (
                  <span key={key} style={{
                    display: 'inline-flex', alignItems: 'center', gap: 4,
                    padding: '2px 8px', borderRadius: 9999, fontSize: 11, fontWeight: 600,
                    color: cfg.color, background: cfg.bg,
                  }}>
                    <span style={{ width: 6, height: 6, borderRadius: '50%', background: cfg.color }} />
                    {ward.counts[key]}
                  </span>
                )
              ))}
            </div>
          </div>

          {/* Rooms */}
          <div style={{ padding: 16 }}>
            {ward.rooms.map((room) => {
              const filteredBeds = statusFilter
                ? room.beds.filter((b) => b.status === statusFilter)
                : room.beds;

              if (filteredBeds.length === 0 && statusFilter) return null;

              return (
                <div key={room.id} style={{ marginBottom: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                    <span style={{ fontSize: 13, fontWeight: 600 }}>{room.name}</span>
                    <span style={{ fontSize: 11, color: 'var(--text-tertiary)' }}>{room.roomType}</span>
                    <span style={{ fontSize: 11, color: 'var(--text-tertiary)' }}>
                      {room.counts.available ?? 0}/{room.counts.total ?? 0} available
                    </span>
                  </div>

                  {/* Bed grid */}
                  <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    {filteredBeds.map((bed) => {
                      const cfg = STATUS_CONFIG[bed.status] ?? STATUS_CONFIG.out_of_service;
                      return (
                        <div
                          key={bed.id}
                          onClick={() => setUpdateTarget({ bed, wardId: ward.id })}
                          style={{
                            width: 80, height: 80, borderRadius: 'var(--radius-md)',
                            border: `2px solid ${cfg.border}`, background: cfg.bg,
                            display: 'flex', flexDirection: 'column', alignItems: 'center',
                            justifyContent: 'center', cursor: 'pointer', gap: 2,
                            transition: 'all 120ms',
                          }}
                          title={`${bed.bedCode} — ${cfg.label}${bed.admissionId ? ' (Patient admitted)' : ''}`}
                        >
                          <span style={{ fontSize: 14, fontWeight: 700, color: cfg.color }}>{bed.bedCode}</span>
                          <span style={{ fontSize: 9, fontWeight: 600, color: cfg.color, textTransform: 'uppercase' }}>
                            {cfg.label}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </div>
        </Card>
      ))}

      {/* ═══════════════════════════════════════════════════════════
          STATUS UPDATE DIALOG
          ═══════════════════════════════════════════════════════════ */}
      {updateTarget && (
        <UpdateBedStatusDialog
          bed={updateTarget.bed}
          onClose={() => setUpdateTarget(null)}
          onUpdated={() => { setUpdateTarget(null); void refresh(); }}
        />
      )}
    </div>
  );
}

/* ══════════════════════════════════════════════════════════════════
   UPDATE BED STATUS DIALOG
   ══════════════════════════════════════════════════════════════════ */

function UpdateBedStatusDialog({ bed, onClose, onUpdated }: {
  bed: BedInfo; onClose: () => void; onUpdated: () => void;
}) {
  const [newStatus, setNewStatus] = useState(bed.status);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const cfg = STATUS_CONFIG[bed.status] ?? STATUS_CONFIG.out_of_service;
  const newCfg = STATUS_CONFIG[newStatus] ?? STATUS_CONFIG.out_of_service;

  const submit = async () => {
    if (newStatus === bed.status) { onClose(); return; }
    setSubmitting(true);
    setError(null);
    try {
      await bedWardApi.updateStatus(bed.id, { status: newStatus, lockVersion: bed.lockVersion });
      onUpdated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update bed status.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="dialog-backdrop" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="dialog" style={{ maxWidth: 440 }}>
        <h3 className="dialog__title">Update Bed Status</h3>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '12px 0' }}>
          <div style={{
            width: 64, height: 64, borderRadius: 'var(--radius-md)',
            border: `2px solid ${cfg.border}`, background: cfg.bg,
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
          }}>
            <span style={{ fontSize: 16, fontWeight: 700, color: cfg.color }}>{bed.bedCode}</span>
            <span style={{ fontSize: 9, fontWeight: 600, color: cfg.color }}>{cfg.label}</span>
          </div>
          <div style={{ fontSize: 20, color: 'var(--text-tertiary)' }}>→</div>
          <div style={{
            width: 64, height: 64, borderRadius: 'var(--radius-md)',
            border: `2px solid ${newCfg.border}`, background: newCfg.bg,
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
          }}>
            <span style={{ fontSize: 16, fontWeight: 700, color: newCfg.color }}>{bed.bedCode}</span>
            <span style={{ fontSize: 9, fontWeight: 600, color: newCfg.color }}>{newCfg.label}</span>
          </div>
        </div>

        {error && (
          <div style={{ padding: '8px 12px', borderRadius: 'var(--radius-md)', background: 'var(--red-50)', color: 'var(--red-700)', fontSize: 13, marginBottom: 12 }}>
            {error}
          </div>
        )}

        <div className="field">
          <label className="field__label">New Status</label>
          <select
            className="input"
            value={newStatus}
            onChange={(e) => setNewStatus(e.target.value)}
          >
            {STATUS_OPTIONS.map((s) => {
              const c = STATUS_CONFIG[s];
              return <option key={s} value={s}>{c.label}</option>;
            })}
          </select>
        </div>

        {bed.admissionId && newStatus !== 'occupied' && (
          <div style={{ padding: '8px 12px', borderRadius: 'var(--radius-md)', background: 'var(--amber-50)', color: 'var(--amber-700)', fontSize: 13, marginTop: 8 }}>
            This bed has an active admission. Changing status will affect the admission record.
          </div>
        )}

        <div className="dialog__footer">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={() => void submit()} disabled={submitting || newStatus === bed.status}>
            {submitting ? 'Updating...' : 'Update Status'}
          </Button>
        </div>
      </div>
    </div>
  );
}

export default BedOccupancyPage;
