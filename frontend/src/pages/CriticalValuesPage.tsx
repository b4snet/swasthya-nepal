import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { criticalValueApi } from '../api/endpoints';
import type { CriticalValueEvent } from '../api/types';
import {
  AlertTriangle, Clock, CheckCircle, ArrowUpCircle,
  User, TestTube, RefreshCw, Filter,
} from 'lucide-react';
import './dashboard-premium.css';

/* ── Status helpers ── */
const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  triggered: { label: 'Triggered', color: 'var(--red-600)', bg: 'var(--red-50)' },
  escalated: { label: 'Escalated', color: 'var(--amber-600)', bg: 'var(--amber-50)' },
  acknowledged: { label: 'Acknowledged', color: 'var(--green-600)', bg: 'var(--green-50)' },
};

function StatusBadge({ status }: { status: string }) {
  const cfg = STATUS_CONFIG[status] ?? { label: status, color: 'var(--text-tertiary)', bg: 'var(--bg-subtle)' };
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      padding: '3px 10px', borderRadius: 12, fontSize: 12, fontWeight: 600,
      color: cfg.color, background: cfg.bg,
    }}>
      <span style={{ width: 6, height: 6, borderRadius: '50%', background: cfg.color }} />
      {cfg.label}
    </span>
  );
}

function timeAgo(iso: string) {
  if (!iso) return '';
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function minutesSince(iso: string) {
  if (!iso) return 0;
  return Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
}

/* ── Page ── */
export function CriticalValuesPage() {
  const { selectedFacilityId } = useTenant();
  const [events, setEvents] = useState<CriticalValueEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | 'triggered' | 'escalated' | 'acknowledged'>('all');
  const [actionLoading, setActionLoading] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await criticalValueApi.list(selectedFacilityId);
      setEvents(data);
    } catch {
      setError('Failed to load critical values');
    } finally {
      setLoading(false);
    }
  }, [selectedFacilityId]);

  useEffect(() => {
    fetchData();
    const id = setInterval(fetchData, 30000); // Refresh every 30s
    return () => clearInterval(id);
  }, [fetchData]);

  const handleAcknowledge = async (eventId: string) => {
    setActionLoading(eventId);
    try {
      await criticalValueApi.acknowledge(eventId, selectedFacilityId);
      await fetchData();
    } catch (err: any) {
      setError(err?.message || 'Failed to acknowledge');
    } finally {
      setActionLoading(null);
    }
  };

  const handleEscalate = async (eventId: string) => {
    setActionLoading(eventId);
    try {
      await criticalValueApi.escalate(eventId, { reason: 'Auto-escalated: requiring immediate attention' }, selectedFacilityId);
      await fetchData();
    } catch (err: any) {
      setError(err?.message || 'Failed to escalate');
    } finally {
      setActionLoading(null);
    }
  };

  const filtered = filter === 'all' ? events : events.filter((e) => e.status === filter);
  const triggeredCount = events.filter((e) => e.status === 'triggered').length;
  const escalatedCount = events.filter((e) => e.status === 'escalated').length;
  const acknowledgedCount = events.filter((e) => e.status === 'acknowledged').length;

  return (
    <div className="dashboard">
      {/* Header */}
      <div className="dash-pulse dash-animate">
        <div className="dash-pulse__left">
          <h1 className="dash-pulse__greeting">Critical Lab Values</h1>
          <div className="dash-pulse__meta">
            <span>Escalation queue — requires clinical acknowledgment</span>
            <span className="dash-pulse__live">
              <span className="dash-pulse__dot" />
              {events.length} total
            </span>
          </div>
        </div>
        <div className="dash-pulse__actions">
          <button className="btn btn--secondary btn--sm" onClick={fetchData} disabled={loading}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="dash-alert dash-alert--danger dash-animate" style={{ marginBottom: 16 }}>
          <AlertTriangle size={16} className="dash-alert__icon" />
          <span className="dash-alert__text">{error}</span>
        </div>
      )}

      {/* Summary KPIs */}
      <div className="dash-section dash-animate">
        <div className="dash-hero-kpis" style={{ gridTemplateColumns: 'repeat(4, 1fr)' }}>
          <div className="dash-hero-kpi">
            <div className="dash-hero-kpi__top">
              <span className="dash-hero-kpi__label">Triggered</span>
              <span className="dash-hero-kpi__icon dash-hero-kpi__icon--red"><AlertTriangle size={16} /></span>
            </div>
            <span className="dash-hero-kpi__value" style={{ color: triggeredCount > 0 ? 'var(--red-600)' : undefined }}>
              {triggeredCount}
            </span>
            <span className="dash-hero-kpi__trend dash-hero-kpi__trend--down" style={{ color: 'var(--red-500)' }}>
              Requires immediate action
            </span>
          </div>
          <div className="dash-hero-kpi">
            <div className="dash-hero-kpi__top">
              <span className="dash-hero-kpi__label">Escalated</span>
              <span className="dash-hero-kpi__icon dash-hero-kpi__icon--amber"><ArrowUpCircle size={16} /></span>
            </div>
            <span className="dash-hero-kpi__value" style={{ color: escalatedCount > 0 ? 'var(--amber-600)' : undefined }}>
              {escalatedCount}
            </span>
            <span className="dash-hero-kpi__trend dash-hero-kpi__trend--down" style={{ color: 'var(--amber-500)' }}>
              Auto-escalated after timeout
            </span>
          </div>
          <div className="dash-hero-kpi">
            <div className="dash-hero-kpi__top">
              <span className="dash-hero-kpi__label">Acknowledged</span>
              <span className="dash-hero-kpi__icon dash-hero-kpi__icon--green"><CheckCircle size={16} /></span>
            </div>
            <span className="dash-hero-kpi__value">{acknowledgedCount}</span>
            <span className="dash-hero-kpi__trend dash-hero-kpi__trend--neutral" style={{ color: 'var(--green-500)' }}>
              Closed loop
            </span>
          </div>
          <div className="dash-hero-kpi">
            <div className="dash-hero-kpi__top">
              <span className="dash-hero-kpi__label">Oldest open</span>
              <span className="dash-hero-kpi__icon dash-hero-kpi__icon--blue"><Clock size={16} /></span>
            </div>
            <span className="dash-hero-kpi__value">
              {events.filter((e) => e.status !== 'acknowledged').length > 0
                ? `${minutesSince(events.filter((e) => e.status !== 'acknowledged').sort((a, b) => new Date(a.createdAt || 0).getTime() - new Date(b.createdAt || 0).getTime())[0].createdAt || '')}m`
                : '—'}
            </span>
            <span className="dash-hero-kpi__trend dash-hero-kpi__trend--neutral">
              Time since detection
            </span>
          </div>
        </div>
      </div>

      {/* Filter tabs */}
      <div className="dash-filter dash-animate" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <Filter size={14} style={{ color: 'var(--text-tertiary)' }} />
        {(['all', 'triggered', 'escalated', 'acknowledged'] as const).map((f) => (
          <button
            key={f}
            className={`btn btn--${filter === f ? "primary" : "secondary"} btn--sm`} onClick={() => setFilter(f)}>
            {f === "triggered" && triggeredCount > 0 && "(" + triggeredCount + ")"}{f === "escalated" && escalatedCount > 0 && "(" + escalatedCount + ")"}
          </button>))}
      </div>

      {loading && events.length === 0 && (<div className="dash-hero-kpis" style={{ gridTemplateColumns: "repeat(2, 1fr)" }}>{Array.from({ length: 4 }).map((_, i) => <div key={i} className="dash-skeleton dash-skeleton--kpi" />)}</div>)}

      {!loading && filtered.length === 0 && (<div className="dash-section dash-animate"><div className="dash-empty" style={{ padding: 48 }}><CheckCircle size={32} style={{ color: "var(--green-500)", marginBottom: 12 }} /><p className="dash-empty__title">No critical values</p><p className="dash-empty__sub">{filter === "all" ? "No critical lab values have been flagged" : "No " + filter + " critical values"}</p></div></div>)}

      {filtered.length > 0 && (<div className="dash-section dash-animate"><div className="dash-card"><div className="dash-card__body" style={{ padding: 0 }}>
        <table className="dash-table"><thead><tr><th>Status</th><th>Test</th><th>Result</th><th>Patient</th><th>Detected</th><th>Target</th><th style={{ textAlign: "right" }}>Actions</th></tr></thead>
        <tbody>{filtered.map((evt) => (
          <tr key={evt.id} style={{ background: evt.status === "triggered" ? "var(--red-25)" : undefined }}>
            <td><StatusBadge status={evt.status} /></td>
            <td><div style={{ display: "flex", alignItems: "center", gap: 6 }}><TestTube size={14} style={{ color: "var(--blue-500)" }} /><span style={{ fontWeight: 600 }}>{evt.testName || "Unknown"}</span></div></td>
            <td><span style={{ fontWeight: 700, color: evt.status === "acknowledged" ? "var(--text-secondary)" : "var(--red-600)", fontFamily: "Manrope, monospace" }}>{evt.resultValue}</span>{evt.referenceRange && <span style={{ fontSize: 11, color: "var(--text-tertiary)", marginLeft: 4 }}>(ref: {evt.referenceRange})</span>}</td>
            <td><Link to={"/patients/" + evt.patientId} style={{ color: "var(--blue-600)", textDecoration: "none", fontWeight: 500 }}>{evt.patientId ? "Patient" : "—"}</Link></td>
            <td><div style={{ display: "flex", alignItems: "center", gap: 4 }}><Clock size={12} style={{ color: "var(--text-tertiary)" }} /><span style={{ fontSize: 13 }}>{timeAgo(evt.createdAt || "")}</span></div></td>
            <td><div style={{ display: "flex", alignItems: "center", gap: 4 }}><User size={12} style={{ color: "var(--text-tertiary)" }} /><span style={{ fontSize: 13 }}>{evt.orderedByStaffId ? "Assigned" : "—"}</span></div></td>
            <td style={{ textAlign: "right" }}>
              {evt.status !== "acknowledged" && (<div style={{ display: "flex", gap: 6, justifyContent: "flex-end" }}>
                <button className="btn btn--primary btn--sm" onClick={() => handleAcknowledge(evt.id)} disabled={actionLoading === evt.id} style={{ fontSize: 12 }}><CheckCircle size={12} /> Acknowledge</button>
                {evt.status === "triggered" && <button className="btn btn--secondary btn--sm" onClick={() => handleEscalate(evt.id)} disabled={actionLoading === evt.id} style={{ fontSize: 12, color: "var(--amber-600)" }}><ArrowUpCircle size={12} /> Escalate</button>}
              </div>)}
              {evt.status === "acknowledged" && evt.acknowledgedAt && <span style={{ fontSize: 12, color: "var(--text-tertiary)" }}>Closed {timeAgo(evt.acknowledgedAt)}</span>}
            </td>
          </tr>))}</tbody></table>
      </div></div></div>)}

      <div className="dash-section dash-animate"><div className="dash-card" style={{ padding: "12px 16px", background: "var(--blue-25)", borderLeft: "3px solid var(--blue-500)" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, color: "var(--text-secondary)" }}>
          <AlertTriangle size={14} style={{ color: "var(--blue-500)" }} />
          <span><strong>Clinical safety:</strong> Critical values are automatically escalated after 30 minutes if unacknowledged. Escalation notifications are sent to the ordering clinician via in-app alerts.</span>
        </div></div></div>
    </div>
  );
}

export default CriticalValuesPage;
