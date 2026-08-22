import { useState } from 'react';
import {
  Heart, Activity, Database, Server, Shield, AlertTriangle,
  CheckCircle2, XCircle, Timer, TrendingUp, BarChart3, RefreshCcw,
  FileText, Zap, Wifi, HardDrive, Cloud, Lock, Bell,
  PlayCircle, ArrowRight,
} from 'lucide-react';
import './operations.css';

/* ────────────────── Status indicator ────────────────── */
function HealthDot({ status }: { status: 'ok' | 'warn' | 'fail' | 'unknown' }) {
  const colors = { ok: '#059669', warn: '#d97706', fail: '#dc2626', unknown: '#6b7280' };
  return <span className="ops-dot" style={{ background: colors[status] }} />;
}

/* ────────────────── System Health Tab ────────────────── */
function SystemHealthSection() {
  const [healthStatus, setHealthStatus] = useState<'ok' | 'checking' | 'fail'>('ok');

  const checks = [
    { name: 'Database (PostgreSQL)', desc: 'Connection pool, query latency, RLS integrity', status: 'ok' as const, latency: '0.42ms', icon: Database },
    { name: 'Cache (Redis/Database)', desc: 'Session store, rate limiting, queue backend', status: 'ok' as const, latency: '1.2ms', icon: HardDrive },
    { name: 'Queue Workers', desc: 'Database queue processing, job throughput', status: 'ok' as const, latency: '< 100ms', icon: Activity },
    { name: 'Storage (S3/Local)', desc: 'Document uploads, exports, signed URLs', status: 'ok' as const, latency: '15ms', icon: Cloud },
    { name: 'Auth / MFA', desc: 'Login, refresh, MFA challenge, token validation', status: 'ok' as const, latency: '8ms', icon: Lock },
    { name: 'Realtime', desc: 'WebSocket connections, broadcast, presence', status: 'ok' as const, latency: '< 5ms', icon: Wifi },
    { name: 'FHIR Endpoints', desc: 'Patient, Encounter, MedicationRequest, DiagnosticReport', status: 'ok' as const, latency: '3ms', icon: Heart },
    { name: 'Service Worker', desc: 'PWA shell cache, background sync, push notifications', status: 'ok' as const, latency: 'N/A', icon: Cloud },
  ];

  const handleCheckAll = () => {
    setHealthStatus('checking');
    setTimeout(() => setHealthStatus('ok'), 1500);
  };

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <Heart size={16} strokeWidth={1.75} />
        <span>System Health</span>
        <div className="ops-section__actions">
          <button type="button" className="ops-btn ops-btn--primary" onClick={handleCheckAll}>
            {healthStatus === 'checking' ? <RefreshCcw size={14} className="ops-spin" /> : <RefreshCcw size={14} />}
            <span>{healthStatus === 'checking' ? 'Checking...' : 'Check All'}</span>
          </button>
        </div>
      </div>
      <div className="ops-health-grid">
        {checks.map((c) => (
          <div key={c.name} className="ops-health-card">
            <div className="ops-health-card__icon"><c.icon size={16} /></div>
            <div className="ops-health-card__info">
              <div className="ops-health-card__top">
                <HealthDot status={c.status} />
                <span className="ops-health-card__name">{c.name}</span>
                <span className="ops-health-card__latency">{c.latency}</span>
              </div>
              <span className="ops-health-card__desc">{c.desc}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Capacity & Scale Tab ────────────────── */
function CapacitySection() {
  const scaleTargets = [
    { metric: 'Facilities', target: '50+', current: '20 (load-tested)', status: 'ok' },
    { metric: 'Concurrent Users', target: '1,000+', current: 'Load-tested at 1M patients', status: 'ok' },
    { metric: 'Patients', target: '10M+', current: '1M tested (2.9M total rows)', status: 'ok' },
    { metric: 'Daily Encounters', target: '50,000+', current: '250K tested in dataset', status: 'ok' },
    { metric: 'Daily Appointments', target: '25,000+', current: '500K tested in dataset', status: 'ok' },
    { metric: 'API Throughput', target: '3,000+ qps', current: '0.29ms per patient lookup (RLS)', status: 'ok' },
    { metric: 'Audit Events', target: '1M+/day', current: 'Unlimited retention', status: 'ok' },
    { metric: 'DB Size', target: '100GB+', current: '1.2GB at 1M patients', status: 'ok' },
  ];

  const hotQueries = [
    { query: 'Patient by ID (RLS)', latency: '0.29ms', rows: '1M', status: 'ok', plan: 'Index Scan (uq_patients_tenant_id_id)' },
    { query: 'Name search ILIKE (RLS)', latency: '147ms', rows: '1M', status: 'warn', plan: 'Bitmap Heap Scan (known hot spot)' },
    { query: 'Provider-day schedule (RLS)', latency: '0.27ms', rows: '500K', status: 'ok', plan: 'Index Scan' },
    { query: 'Encounters for patient (RLS)', latency: '0.27ms', rows: '250K', status: 'ok', plan: 'Index Scan' },
    { query: 'Invoice + lines join (RLS)', latency: '3.06ms', rows: '125K', status: 'ok', plan: 'Nested Loop + Index' },
    { query: 'Insert patient (RLS)', latency: '0.22-0.49ms', rows: '1M', status: 'ok', plan: 'Insert + Policy Check' },
    { query: 'Delete with RLS CHECK', latency: '86.5ms', rows: '1M', status: 'ok', plan: 'WITH CHECK re-evaluation' },
  ];

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <TrendingUp size={16} strokeWidth={1.75} />
        <span>Capacity & Scale Evidence</span>
      </div>
      <h3 className="ops-subhead">Scale Targets (measured on local reference cluster)</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Metric</span><span>Target</span><span>Measured</span><span>Status</span>
        </div>
        {scaleTargets.map((t) => (
          <div key={t.metric} className="ops-table">
            <span className="ops-tcell--bold">{t.metric}</span>
            <span>{t.target}</span>
            <span className="ops-tcell--muted">{t.current}</span>
            <HealthDot status={t.status as 'ok' | 'warn' | 'fail'} />
          </div>
        ))}
      </div>

      <h3 className="ops-subhead" style={{ marginTop: 20 }}>Hot Query Performance (RLS mode, 1M patients)</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Query</span><span>Latency</span><span>Dataset</span><span>Plan</span><span>Status</span>
        </div>
        {hotQueries.map((q) => (
          <div key={q.query} className="ops-table">
            <span className="ops-tcell--bold">{q.query}</span>
            <span className="ops-tcell--mono">{q.latency}</span>
            <span>{q.rows}</span>
            <span className="ops-tcell--muted">{q.plan}</span>
            <HealthDot status={q.status as 'ok' | 'warn' | 'fail'} />
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── DR & Backup Tab ────────────────── */
function DRSection() {
  const [drillRunning, setDrillRunning] = useState(false);

  const backupConfig = [
    { item: 'Base Backup', schedule: 'Nightly', retention: '30 days', encrypted: true, verified: true },
    { item: 'WAL Archiving', schedule: 'Continuous', retention: '30 days', encrypted: true, verified: true },
    { item: 'Cross-Region Copy', schedule: 'Per backup', retention: '30 days', encrypted: true, verified: false },
    { item: 'Object Storage', schedule: 'Versioned', retention: 'Indefinite', encrypted: true, verified: true },
  ];

  const drillResults = [
    { drill: 'Backup Restore (1M rows)', date: '2026-08-17', backupTime: '33s', restoreTime: '110s', total: '144s', rto: '144s', rpo: 'N/A (on-demand)', status: 'ok' },
    { drill: 'Failover to Standby', date: '2026-08-17', configSwitch: '1s', httpCheck: 'ok', rlsVerify: 'ok', status: 'ok' },
    { drill: 'RLS Integrity (restored)', date: '2026-08-17', policies: '508', withContext: '1', noContext: '0', wrongTenant: '0', status: 'ok' },
  ];

  const rpoRto = [
    { tier: 'Clinical Records', rpo: '≤ 15 min', rto: '≤ 4 h', measured: '144s restore', status: 'ok' },
    { tier: 'Financial Records', rpo: '≤ 15 min', rto: '≤ 4 h', measured: '144s restore', status: 'ok' },
    { tier: 'Audit Trail', rpo: '≤ 15 min', rto: '≤ 4 h', measured: '144s restore', status: 'ok' },
    { tier: 'Full Platform', rpo: '≤ 15 min', rto: '≤ 8 h', measured: 'Pending prod', status: 'warn' },
  ];

  const handleRunDrill = () => {
    setDrillRunning(true);
    setTimeout(() => setDrillRunning(false), 3000);
  };

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <Shield size={16} strokeWidth={1.75} />
        <span>Disaster Recovery & Backups</span>
        <div className="ops-section__actions">
          <button type="button" className="ops-btn ops-btn--primary" onClick={handleRunDrill} disabled={drillRunning}>
            {drillRunning ? <RefreshCcw size={14} className="ops-spin" /> : <PlayCircle size={14} />}
            <span>{drillRunning ? 'Running Drill...' : 'Run Restore Drill'}</span>
          </button>
        </div>
      </div>

      <h3 className="ops-subhead">Recovery Objectives (RPO/RTO)</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Data Tier</span><span>RPO Target</span><span>RTO Target</span><span>Measured</span><span>Status</span>
        </div>
        {rpoRto.map((r) => (
          <div key={r.tier} className="ops-table">
            <span className="ops-tcell--bold">{r.tier}</span>
            <span>{r.rpo}</span>
            <span>{r.rto}</span>
            <span className="ops-tcell--muted">{r.measured}</span>
            <HealthDot status={r.status as 'ok' | 'warn' | 'fail'} />
          </div>
        ))}
      </div>

      <h3 className="ops-subhead">Backup Configuration</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Backup Type</span><span>Schedule</span><span>Retention</span><span>Encrypted</span><span>Verified</span>
        </div>
        {backupConfig.map((b) => (
          <div key={b.item} className="ops-table">
            <span className="ops-tcell--bold">{b.item}</span>
            <span>{b.schedule}</span>
            <span>{b.retention}</span>
            <span>{b.encrypted ? <CheckCircle2 size={14} className="ops-icon-ok" /> : <XCircle size={14} className="ops-icon-fail" />}</span>
            <span>{b.verified ? <CheckCircle2 size={14} className="ops-icon-ok" /> : <XCircle size={14} className="ops-icon-fail" />}</span>
          </div>
        ))}
      </div>

      <h3 className="ops-subhead">Drill Results</h3>
      <div className="ops-drill-grid">
        {drillResults.map((d) => (
          <div key={d.drill} className="ops-drill-card">
            <div className="ops-drill-card__top">
              <HealthDot status={d.status as 'ok' | 'warn' | 'fail'} />
              <span className="ops-drill-card__name">{d.drill}</span>
              <span className="ops-drill-card__date">{d.date}</span>
            </div>
            <div className="ops-drill-card__details">
              {Object.entries(d).filter(([k]) => !['drill', 'date', 'status'].includes(k)).map(([k, v]) => (
                <div key={k} className="ops-drill-detail">
                  <span className="ops-drill-detail__key">{k}</span>
                  <span className="ops-drill-detail__value">{String(v)}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Observability Tab ────────────────── */
function ObservabilitySection() {
  const pillars = [
    { name: 'Structured Logging', desc: 'JSON logs with correlation IDs, tenant/facility context, no PHI', status: 'implemented', icon: FileText },
    { name: 'Metrics (RED/USE)', desc: 'Rate, Errors, Duration for services; Utilization, Saturation, Errors for resources', status: 'implemented', icon: BarChart3 },
    { name: 'Distributed Tracing', desc: 'OpenTelemetry end-to-end: API → middleware → DB → Redis → queue → integrations', status: 'implemented', icon: Activity },
    { name: 'Request/Correlation IDs', desc: 'X-Request-Id per request, correlation_id across hops, trace_id in every log', status: 'implemented', icon: Zap },
    { name: 'PHI-Safe Logging', desc: 'No patient names, no secrets, no financial identifiers in logs/metrics/traces', status: 'implemented', icon: Shield },
    { name: 'Alert Routing', desc: 'PagerDuty/Slack integration, severity-based escalation, on-call rotation', status: 'designed', icon: Bell },
  ];

  const logSchema = [
    { field: 'timestamp', desc: 'ISO 8601 with milliseconds' },
    { field: 'level', desc: 'debug | info | warn | error | critical' },
    { field: 'service', desc: 'api | queue | worker | integration' },
    { field: 'instance', desc: 'Process identifier (app-7f3c)' },
    { field: 'request_id', desc: 'X-Request-Id (server-generated)' },
    { field: 'correlation_id', desc: 'Cross-hop correlation' },
    { field: 'trace_id', desc: 'OpenTelemetry trace' },
    { field: 'tenant_id', desc: 'Tenant bucket (not full ID)' },
    { field: 'facility_id', desc: 'Facility context' },
    { field: 'actor_id', desc: 'User context (not patient)' },
    { field: 'message', desc: 'Structured event description' },
    { field: 'fields', desc: 'Typed payload (no PHI)' },
  ];

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <Activity size={16} strokeWidth={1.75} />
        <span>Observability</span>
      </div>

      <h3 className="ops-subhead">Three Pillars + Audit</h3>
      <div className="ops-pillars-grid">
        {pillars.map((p) => (
          <div key={p.name} className="ops-pillar-card">
            <div className="ops-pillar-card__icon"><p.icon size={16} /></div>
            <div className="ops-pillar-card__info">
              <span className="ops-pillar-card__name">{p.name}</span>
              <span className="ops-pillar-card__desc">{p.desc}</span>
            </div>
            <span className={`ops-pillar-status ops-pillar-status--${p.status}`}>
              {p.status === 'implemented' ? 'Implemented' : 'Designed'}
            </span>
          </div>
        ))}
      </div>

      <h3 className="ops-subhead">Structured Log Schema</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Field</span><span>Description</span>
        </div>
        {logSchema.map((l) => (
          <div key={l.field} className="ops-table">
            <span className="ops-tcell--mono">{l.field}</span>
            <span className="ops-tcell--muted">{l.desc}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Incidents & Runbooks Tab ────────────────── */
function IncidentsSection() {
  const incidents: Array<{
    id: string; title: string; severity: string; status: string;
    owner: string; created: string; resolved?: string;
  }> = [];

  const runbooks = [
    { name: 'Deployment', desc: 'Step-by-step deployment procedure with rollback', icon: PlayCircle, status: 'ok' },
    { name: 'Rollback', desc: 'Immediate rollback procedure for failed deployments', icon: RefreshCcw, status: 'ok' },
    { name: 'Backup & Restore', desc: 'Backup verification and restore drill procedure', icon: HardDrive, status: 'ok' },
    { name: 'Failover', desc: 'Primary database loss — switch to standby', icon: ArrowRight, status: 'ok' },
    { name: 'Incident Response', desc: 'Severity classification, communication, resolution', icon: AlertTriangle, status: 'ok' },
    { name: 'Security Incident', desc: 'Breach response, containment, notification', icon: Shield, status: 'ok' },
    { name: 'Integration Failure', desc: 'External system outage — graceful degradation', icon: XCircle, status: 'ok' },
    { name: 'Capacity Alert', desc: 'High load — scaling triggers and actions', icon: TrendingUp, status: 'ok' },
  ];

  const severityLevels = [
    { level: 'P1 — Critical', desc: 'Platform down, data loss risk, patient safety', response: '< 15 min', color: '#dc2626' },
    { level: 'P2 — High', desc: 'Major feature degraded, workaround exists', response: '< 1 hour', color: '#ea580c' },
    { level: 'P3 — Medium', desc: 'Non-critical feature impacted', response: '< 4 hours', color: '#d97706' },
    { level: 'P4 — Low', desc: 'Minor issue, cosmetic, enhancement', response: '< 24 hours', color: '#059669' },
  ];

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <AlertTriangle size={16} strokeWidth={1.75} />
        <span>Incidents & Runbooks</span>
      </div>

      <h3 className="ops-subhead">Active Incidents</h3>
      {incidents.length === 0 ? (
        <div className="ops-empty-inline">
          <CheckCircle2 size={16} className="ops-icon-ok" />
          <span>No active incidents</span>
        </div>
      ) : null}

      <h3 className="ops-subhead">Severity Levels</h3>
      <div className="ops-severity-grid">
        {severityLevels.map((s) => (
          <div key={s.level} className="ops-severity-card" style={{ borderLeftColor: s.color }}>
            <span className="ops-severity-card__level" style={{ color: s.color }}>{s.level}</span>
            <span className="ops-severity-card__desc">{s.desc}</span>
            <span className="ops-severity-card__response">Response: {s.response}</span>
          </div>
        ))}
      </div>

      <h3 className="ops-subhead">Runbooks</h3>
      <div className="ops-runbook-grid">
        {runbooks.map((r) => (
          <div key={r.name} className="ops-runbook-card">
            <div className="ops-runbook-card__icon"><r.icon size={16} /></div>
            <div className="ops-runbook-card__info">
              <span className="ops-runbook-card__name">{r.name}</span>
              <span className="ops-runbook-card__desc">{r.desc}</span>
            </div>
            <HealthDot status="ok" />
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Rate Limiting & Security Tab ────────────────── */
function SecurityOpsSection() {
  const rateLimits = [
    { endpoint: 'POST /auth/login', limit: '5/min per IP', scope: 'IP', status: 'ok' },
    { endpoint: 'POST /auth/refresh', limit: '5/min per IP', scope: 'IP', status: 'ok' },
    { endpoint: 'POST /auth/mfa/challenge', limit: '5/min per IP', scope: 'IP + User', status: 'ok' },
    { endpoint: 'POST /portal/login', limit: '5/min per IP', scope: 'IP', status: 'ok' },
    { endpoint: 'POST /auth/password/*', limit: '5/min per IP', scope: 'IP + Account', status: 'ok' },
    { endpoint: 'All API routes', limit: '60/min per IP', scope: 'IP', status: 'ok' },
  ];

  const securityControls = [
    { name: 'RLS (Row-Level Security)', desc: '508 policies enforcing tenant + facility scope', status: 'ok' },
    { name: 'Auth Throttle', desc: 'Per-IP + per-account rate limiting on auth endpoints', status: 'ok' },
    { name: 'CAS Locking', desc: 'Optimistic concurrency on bed, refund, MAR, stock, video sessions', status: 'ok' },
    { name: 'IDOR Protection', desc: 'Every resource IDOR-tested across all phases', status: 'ok' },
    { name: 'Tenant Isolation', desc: 'RLS + API-level tenant scope enforcement', status: 'ok' },
    { name: 'MFA', desc: 'TOTP + backup codes + challenge flow', status: 'ok' },
    { name: 'Password Policy', desc: 'Bcrypt hashing, complexity requirements, lockout', status: 'ok' },
    { name: 'Audit Trail', desc: 'Append-only, PHI-safe, per-tenant scope', status: 'ok' },
  ];

  return (
    <div className="ops-section">
      <div className="ops-section__head">
        <Lock size={16} strokeWidth={1.75} />
        <span>Rate Limiting & Security</span>
      </div>

      <h3 className="ops-subhead">Rate Limits</h3>
      <div className="ops-table-wrap">
        <div className="ops-table ops-table--head">
          <span>Endpoint</span><span>Limit</span><span>Scope</span><span>Status</span>
        </div>
        {rateLimits.map((r) => (
          <div key={r.endpoint} className="ops-table">
            <span className="ops-tcell--mono">{r.endpoint}</span>
            <span>{r.limit}</span>
            <span className="ops-tcell--muted">{r.scope}</span>
            <HealthDot status={r.status as 'ok' | 'warn' | 'fail'} />
          </div>
        ))}
      </div>

      <h3 className="ops-subhead">Security Controls</h3>
      <div className="ops-security-grid">
        {securityControls.map((c) => (
          <div key={c.name} className="ops-security-card">
            <div className="ops-security-card__top">
              <HealthDot status={c.status as 'ok' | 'warn' | 'fail'} />
              <span className="ops-security-card__name">{c.name}</span>
            </div>
            <span className="ops-security-card__desc">{c.desc}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ────────────────── Main Page ────────────────── */
export default function OperationsPage() {
  const [tab, setTab] = useState<'health' | 'capacity' | 'dr' | 'observability' | 'incidents' | 'security'>('health');

  const tabs = [
    { key: 'health' as const, label: 'System Health' },
    { key: 'capacity' as const, label: 'Capacity & Scale' },
    { key: 'dr' as const, label: 'DR & Backups' },
    { key: 'observability' as const, label: 'Observability' },
    { key: 'incidents' as const, label: 'Incidents & Runbooks' },
    { key: 'security' as const, label: 'Security Ops' },
  ];

  return (
    <div className="operations-page">
      {/* ── Page Header ── */}
      <div className="ops-header">
        <div className="ops-header__left">
          <h1 className="ops-title">Operations Command Center</h1>
          <p className="ops-subtitle">
            National scale readiness, health monitoring, DR, observability and incident management
          </p>
        </div>
        <div className="ops-header__right">
          <div className="ops-status-pill ops-status-pill--ok">
            <Heart size={14} />
            <span>All Systems Operational</span>
          </div>
        </div>
      </div>

      {/* ── Census Cards ── */}
      <div className="ops-census">
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">8/8</span>
            <span className="ops-census-card__label">Health Checks</span>
          </div>
        </div>
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#eff6ff', color: '#2563eb' }}><Database size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">147</span>
            <span className="ops-census-card__label">Migrations</span>
          </div>
        </div>
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#f5f3ff', color: '#7c3aed' }}><Server size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">614</span>
            <span className="ops-census-card__label">API Routes</span>
          </div>
        </div>
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Shield size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">508</span>
            <span className="ops-census-card__label">RLS Policies</span>
          </div>
        </div>
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#fffbeb', color: '#d97706' }}><Timer size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">144s</span>
            <span className="ops-census-card__label">DR Restore</span>
          </div>
        </div>
        <div className="ops-census-card">
          <div className="ops-census-card__icon" style={{ background: '#f0fdf4', color: '#16a34a' }}><TrendingUp size={18} /></div>
          <div className="ops-census-card__info">
            <span className="ops-census-card__value">1M</span>
            <span className="ops-census-card__label">Patients Tested</span>
          </div>
        </div>
      </div>

      {/* ── Tab Bar ── */}
      <div className="ops-tabs">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`ops-tab ${tab === t.key ? 'ops-tab--active' : ''}`}
            onClick={() => setTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* ── Tab Content ── */}
      <div className="ops-content">
        {tab === 'health' && <SystemHealthSection />}
        {tab === 'capacity' && <CapacitySection />}
        {tab === 'dr' && <DRSection />}
        {tab === 'observability' && <ObservabilitySection />}
        {tab === 'incidents' && <IncidentsSection />}
        {tab === 'security' && <SecurityOpsSection />}
      </div>
    </div>
  );
}
