import { useState } from 'react';
import { useNetworkStatus } from '../hooks/useNetworkStatus';
import { useOfflineQueue, type OfflineAction } from '../hooks/useOfflineQueue';
import {
  Wifi, WifiOff, Smartphone, Camera, Download, RefreshCcw,
  Trash2, CheckCircle2, Clock, Database, Signal,
  Zap, Shield, Activity, BarChart3, Bell, QrCode,
  CheckCircle, XCircle, Loader2,
} from 'lucide-react';
import './mobile-offline.css';

/* ────────────────── Status badge ────────────────── */
function StatusBadge({ status }: { status: string }) {
  const cls =
    status === 'online' ? 'sc-badge sc-badge--ok'
    : status === 'offline' ? 'sc-badge sc-badge--warn'
    : status === 'low' ? 'sc-badge sc-badge--low'
    : 'sc-badge sc-badge--muted';
  return <span className={cls}>{status}</span>;
}

/* ────────────────── Action status badge ────────────────── */
function ActionBadge({ status }: { status: OfflineAction['status'] }) {
  const map: Record<string, { cls: string; label: string }> = {
    pending: { cls: 'sc-badge sc-badge--pending', label: 'Pending' },
    syncing: { cls: 'sc-badge sc-badge--syncing', label: 'Syncing' },
    failed: { cls: 'sc-badge sc-badge--err', label: 'Failed' },
    completed: { cls: 'sc-badge sc-badge--ok', label: 'Done' },
  };
  const { cls, label } = map[status] ?? map.pending;
  return <span className={cls}>{label}</span>;
}

/* ────────────────── Barcodes / QR Scanning ────────────────── */
function BarcodeScannerSection() {
  const [scanResult, setScanResult] = useState('');
  const [scanType, setScanType] = useState('patient');

  const handleScan = () => {
    // Simulated scan result for demonstration
    const mockResults: Record<string, string> = {
      patient: 'MRN-2026-08-00142',
      medication: 'PARA-500-BTL-0892',
      specimen: 'SPEC-HEM-20260822-007',
      inventory: 'INV-SUP-2026-1542',
    };
    setScanResult(mockResults[scanType] ?? 'SCANNED');
  };

  const scanTypes = [
    { key: 'patient', label: 'Patient', icon: Smartphone },
    { key: 'medication', label: 'Medication', icon: QrCode },
    { key: 'specimen', label: 'Specimen', icon: QrCode },
    { key: 'inventory', label: 'Inventory', icon: QrCode },
  ];

  return (
    <div className="moc-section">
      <div className="moc-section__head">
        <Camera size={16} strokeWidth={1.75} />
        <span>Barcode / QR Scanner</span>
      </div>
      <div className="moc-scanner">
        <div className="moc-scan-types">
          {scanTypes.map((st) => (
            <button
              key={st.key}
              type="button"
              className={`moc-scan-type ${scanType === st.key ? 'moc-scan-type--active' : ''}`}
              onClick={() => { setScanType(st.key); setScanResult(''); }}
            >
              <st.icon size={14} />
              <span>{st.label}</span>
            </button>
          ))}
        </div>
        <button type="button" className="moc-scan-btn" onClick={handleScan}>
          <Camera size={18} />
          <span>Tap to Scan</span>
        </button>
        {scanResult && (
          <div className="moc-scan-result">
            <CheckCircle size={14} />
            <span className="moc-scan-result__code">{scanResult}</span>
            <span className="moc-scan-result__type">{scanType}</span>
          </div>
        )}
      </div>
    </div>
  );
}

/* ────────────────── Mobile Workflows ────────────────── */
function MobileWorkflowsSection() {
  const workflows = [
    { name: 'Patient Lookup', desc: 'Search patients by name, MRN, or barcode', status: 'active', mobile: true },
    { name: 'Vitals Recording', desc: 'Record temperature, BP, HR, SpO2, pain score', status: 'active', mobile: true },
    { name: 'Nursing Tasks', desc: 'View and complete assigned tasks with offline support', status: 'active', mobile: true },
    { name: 'Queue Management', desc: 'Check-in, view wait times, call patients', status: 'active', mobile: true },
    { name: 'Medication Scan', desc: 'Scan medication barcode for verification', status: 'active', mobile: true },
    { name: 'Specimen Collection', desc: 'Scan specimen barcode at point of collection', status: 'active', mobile: true },
    { name: 'Inventory Quick Check', desc: 'Scan item barcode for stock levels', status: 'active', mobile: true },
    { name: 'Bed Board', desc: 'View bed status and availability by ward', status: 'active', mobile: true },
    { name: 'Notifications', desc: 'Push notifications for alerts and tasks', status: 'active', mobile: true },
    { name: 'Messaging', desc: 'Secure messaging with care team', status: 'active', mobile: true },
    { name: 'Telemedicine', desc: 'Video consultations via WebRTC', status: 'partial', mobile: true },
    { name: 'Radiology Viewer', desc: 'DICOM image viewer for mobile', status: 'partial', mobile: false },
    { name: 'Offline Clinical Notes', desc: 'Draft notes when offline, sync when connected', status: 'planned', mobile: true },
    { name: 'Emergency Triage', desc: 'Quick triage with barcode patient ID', status: 'planned', mobile: true },
  ];

  return (
    <div className="moc-section">
      <div className="moc-section__head">
        <Smartphone size={16} strokeWidth={1.75} />
        <span>Mobile Workflows</span>
      </div>
      <div className="moc-workflow-grid">
        {workflows.map((w) => (
          <div key={w.name} className="moc-workflow-card">
            <div className="moc-workflow-card__top">
              <span className="moc-workflow-card__name">{w.name}</span>
              <span className={`moc-wf-status moc-wf-status--${w.status}`}>
                {w.status === 'active' ? 'Active' : w.status === 'partial' ? 'Partial' : 'Planned'}
              </span>
            </div>
            <p className="moc-workflow-card__desc">{w.desc}</p>
            <div className="moc-workflow-card__meta">
              {w.mobile ? <Smartphone size={12} /> : <BarChart3 size={12} />}
              <span>{w.mobile ? 'Mobile-optimized' : 'Desktop'}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Network Quality ────────────────── */
function NetworkQualitySection() {
  const network = useNetworkStatus();

  const quality = !network.online ? 'offline'
    : network.effectiveType === '4g' ? 'excellent'
    : network.effectiveType === '3g' ? 'good'
    : network.effectiveType === '2g' ? 'low'
    : 'unknown';

  const metrics = [
    { label: 'Status', value: network.online ? 'Online' : 'Offline', icon: network.online ? Wifi : WifiOff },
    { label: 'Connection', value: network.effectiveType ?? 'N/A', icon: Signal },
    { label: 'Downlink', value: network.downlink != null ? `${network.downlink} Mbps` : 'N/A', icon: Zap },
    { label: 'RTT', value: network.rtt != null ? `${network.rtt}ms` : 'N/A', icon: Activity },
    { label: 'Quality', value: quality, icon: BarChart3 },
  ];

  return (
    <div className="moc-section">
      <div className="moc-section__head">
        <Signal size={16} strokeWidth={1.75} />
        <span>Network Quality</span>
      </div>
      <div className="moc-net-grid">
        {metrics.map((m) => (
          <div key={m.label} className="moc-net-card">
            <m.icon size={16} />
            <span className="moc-net-card__label">{m.label}</span>
            <span className="moc-net-card__value">{m.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Security Section ────────────────── */
function SecuritySection() {
  const controls = [
    { name: 'Session Timeout', desc: 'Automatic logout after inactivity', status: 'active', icon: Shield },
    { name: 'Local Storage Encryption', desc: 'Sensitive data encrypted at rest', status: 'active', icon: Database },
    { name: 'Screenshot Prevention', desc: 'Prevent screen capture of PHI on mobile', status: 'partial', icon: Shield },
    { name: 'Push Notification Privacy', desc: 'No PHI in notification previews', status: 'active', icon: Bell },
    { name: 'Device Binding', desc: 'Bind session to device fingerprint', status: 'planned', icon: Smartphone },
    { name: 'Biometric Unlock', desc: 'Face/fingerprint for quick re-auth', status: 'planned', icon: Shield },
    { name: 'Remote Wipe', desc: 'Remote session invalidation', status: 'active', icon: Trash2 },
    { name: 'Deep Link Auth', desc: 'Secure deep link authentication', status: 'partial', icon: Zap },
  ];

  return (
    <div className="moc-section">
      <div className="moc-section__head">
        <Shield size={16} strokeWidth={1.75} />
        <span>Mobile Security Controls</span>
      </div>
      <div className="moc-security-grid">
        {controls.map((c) => (
          <div key={c.name} className="moc-security-card">
            <div className="moc-security-card__icon">
              <c.icon size={16} />
            </div>
            <div className="moc-security-card__info">
              <span className="moc-security-card__name">{c.name}</span>
              <span className="moc-security-card__desc">{c.desc}</span>
            </div>
            <span className={`moc-wf-status moc-wf-status--${c.status === 'active' ? 'active' : c.status === 'partial' ? 'partial' : 'planned'}`}>
              {c.status === 'active' ? 'Active' : c.status === 'partial' ? 'Partial' : 'Planned'}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── PWA & Cache ────────────────── */
function PWASection() {
  return (
    <div className="moc-section">
      <div className="moc-section__head">
        <Download size={16} strokeWidth={1.75} />
        <span>PWA & Offline Cache</span>
      </div>
      <div className="moc-pwa-info">
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">App Shell</span>
          <span className="moc-pwa-value">Cached (network-first for API)</span>
          <StatusBadge status="online" />
        </div>
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">Static Assets</span>
          <span className="moc-pwa-value">Cache-first with background update</span>
          <StatusBadge status="online" />
        </div>
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">API Calls</span>
          <span className="moc-pwa-value">Always network (never cached)</span>
          <StatusBadge status="online" />
        </div>
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">Auth Requests</span>
          <span className="moc-pwa-value">Always network (never cached)</span>
          <StatusBadge status="online" />
        </div>
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">Clinical Data</span>
          <span className="moc-pwa-value">Not cached offline (PHI protection)</span>
          <StatusBadge status="online" />
        </div>
        <div className="moc-pwa-row">
          <span className="moc-pwa-label">Manifest</span>
          <span className="moc-pwa-value">Standalone display, white background</span>
          <StatusBadge status="online" />
        </div>
      </div>
    </div>
  );
}

/* ────────────────── Main Page ────────────────── */
export default function MobileOfflinePage() {
  const network = useNetworkStatus();
  const queue = useOfflineQueue();
  const [tab, setTab] = useState<'overview' | 'queue' | 'scanner' | 'workflows' | 'security'>('overview');

  const tabs = [
    { key: 'overview' as const, label: 'Overview' },
    { key: 'queue' as const, label: `Offline Queue (${queue.pendingCount})` },
    { key: 'scanner' as const, label: 'Scanner' },
    { key: 'workflows' as const, label: 'Workflows' },
    { key: 'security' as const, label: 'Security' },
  ];

  return (
    <div className="mobile-offline-page">
      {/* ── Page Header ── */}
      <div className="moc-header">
        <div className="moc-header__left">
          <h1 className="moc-title">Mobile & Offline</h1>
          <p className="moc-subtitle">
            Device management, offline queue, barcode scanning and mobile workflows
          </p>
        </div>
        <div className="moc-header__right">
          <div className={`moc-net-pill ${!network.online ? 'moc-net-pill--off' : ''}`}>
            {network.online ? <Wifi size={14} /> : <WifiOff size={14} />}
            <span>{network.online ? 'Online' : 'Offline'}</span>
          </div>
        </div>
      </div>

      {/* ── Census Cards ── */}
      <div className="moc-census">
        <div className="moc-census-card">
          <div className="moc-census-card__icon"><Smartphone size={18} /></div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">14</span>
            <span className="moc-census-card__label">Mobile Workflows</span>
          </div>
        </div>
        <div className="moc-census-card">
          <div className="moc-census-card__icon"><CheckCircle2 size={18} /></div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">10</span>
            <span className="moc-census-card__label">Active Flows</span>
          </div>
        </div>
        <div className="moc-census-card">
          <div className="moc-census-card__icon"><Clock size={18} /></div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">{queue.pendingCount}</span>
            <span className="moc-census-card__label">Queued Actions</span>
          </div>
        </div>
        <div className="moc-census-card">
          <div className="moc-census-card__icon"><Shield size={18} /></div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">8</span>
            <span className="moc-census-card__label">Security Controls</span>
          </div>
        </div>
        <div className="moc-census-card">
          <div className="moc-census-card__icon">
            {network.online ? <Wifi size={18} /> : <WifiOff size={18} />}
          </div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">{network.effectiveType ?? 'N/A'}</span>
            <span className="moc-census-card__label">Connection</span>
          </div>
        </div>
        <div className="moc-census-card">
          <div className="moc-census-card__icon"><Database size={18} /></div>
          <div className="moc-census-card__info">
            <span className="moc-census-card__value">SW</span>
            <span className="moc-census-card__label">Service Worker</span>
          </div>
        </div>
      </div>

      {/* ── Tab Bar ── */}
      <div className="moc-tabs">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`moc-tab ${tab === t.key ? 'moc-tab--active' : ''}`}
            onClick={() => setTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* ── Tab Content ── */}
      <div className="moc-content">
        {tab === 'overview' && (
          <>
            <NetworkQualitySection />
            <PWASection />
          </>
        )}

        {tab === 'queue' && (
          <div className="moc-section">
            <div className="moc-section__head">
              <Database size={16} strokeWidth={1.75} />
              <span>Offline Action Queue</span>
              <div className="moc-section__actions">
                {queue.pendingCount > 0 && (
                  <button type="button" className="moc-action-btn moc-action-btn--primary" onClick={queue.retryAll}>
                    <RefreshCcw size={14} /> Retry All
                  </button>
                )}
                <button type="button" className="moc-action-btn" onClick={queue.clearCompleted}>
                  <Trash2 size={14} /> Clear Done
                </button>
              </div>
            </div>

            {queue.isSyncing && (
              <div className="moc-sync-banner">
                <Loader2 size={14} className="moc-spin" />
                <span>Syncing queued actions...</span>
              </div>
            )}

            {queue.actions.length === 0 ? (
              <div className="moc-empty">
                <Database size={32} />
                <span>No queued actions</span>
                <p>Offline actions (vitals, task completions, barcode scans) will appear here when the device loses connectivity.</p>
              </div>
            ) : (
              <div className="moc-queue-table">
                <div className="moc-queue-row moc-queue-row--head">
                  <span>Type</span>
                  <span>Payload</span>
                  <span>Created</span>
                  <span>Retries</span>
                  <span>Status</span>
                  <span></span>
                </div>
                {queue.actions.map((a) => (
                  <div key={a.id} className="moc-queue-row">
                    <span className="moc-queue-type">{a.type}</span>
                    <span className="moc-queue-payload">
                      {Object.keys(a.payload).length > 0
                        ? JSON.stringify(a.payload).slice(0, 60) + (JSON.stringify(a.payload).length > 60 ? '...' : '')
                        : '—'}
                    </span>
                    <span className="moc-queue-time">
                      {new Date(a.createdAt).toLocaleTimeString()}
                    </span>
                    <span className="moc-queue-retries">{a.retries}</span>
                    <ActionBadge status={a.status} />
                    <button
                      type="button"
                      className="moc-queue-remove"
                      onClick={() => queue.removeAction(a.id)}
                      title="Remove"
                    >
                      <XCircle size={14} />
                    </button>
                  </div>
                ))}
              </div>
            )}

            <div className="moc-queue-footer">
              <span className="moc-queue-footer__label">Approved offline action types:</span>
              <div className="moc-queue-footer__tags">
                {['vitals.record', 'nursing.task.complete', 'nursing.alert.acknowledge', 'patient.search', 'barcode.scan', 'notification.read'].map((t) => (
                  <span key={t} className="moc-tag">{t}</span>
                ))}
              </div>
            </div>
          </div>
        )}

        {tab === 'scanner' && <BarcodeScannerSection />}

        {tab === 'workflows' && <MobileWorkflowsSection />}

        {tab === 'security' && <SecuritySection />}
      </div>
    </div>
  );
}
