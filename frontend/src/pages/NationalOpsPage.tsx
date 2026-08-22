import { useState } from 'react';
import {
  CheckCircle2, Activity, Shield, Server,
  AlertTriangle, Bell, TrendingUp, BarChart3,
  Heart, Lock,
} from 'lucide-react';
import './national-ops.css';

/* ═══════════════ Main Page ═══════════════ */
export default function NationalOpsPage() {
  const [tab, setTab] = useState<'overview' | 'services' | 'slo' | 'alerting' | 'incidents' | 'capacity'>('overview');

  const services = [
    { name: 'Application (SPA)', owner: 'Frontend Lead', sla: '99.9%', status: 'ok' },
    { name: 'API (Laravel)', owner: 'Backend Lead', sla: '99.9%', status: 'ok' },
    { name: 'Database (PostgreSQL)', owner: 'DBA', sla: '99.95%', status: 'ok' },
    { name: 'Cache (Redis)', owner: 'Ops', sla: '99.9%', status: 'ok' },
    { name: 'Queue Workers', owner: 'Ops', sla: '99.5%', status: 'ok' },
    { name: 'Realtime (WebSocket)', owner: 'Ops', sla: '99.5%', status: 'ok' },
    { name: 'Storage (S3/Local)', owner: 'Ops', sla: '99.9%', status: 'ok' },
    { name: 'FHIR Endpoints', owner: 'Interop Lead', sla: '99.5%', status: 'ok' },
    { name: 'Notifications', owner: 'Comms Lead', sla: '99.0%', status: 'ok' },
    { name: 'Telemedicine', owner: 'Telehealth Lead', sla: '99.0%', status: 'ok' },
  ];

  const slos = [
    { metric: 'Availability', target: '≥ 99.9%', measurement: 'Uptime monitoring', alert: '< 99.5%' },
    { metric: 'API Latency (p50)', target: '< 200ms', measurement: 'APM tracing', alert: '> 500ms' },
    { metric: 'API Latency (p95)', target: '< 500ms', measurement: 'APM tracing', alert: '> 1s' },
    { metric: 'API Latency (p99)', target: '< 1s', measurement: 'APM tracing', alert: '> 2s' },
    { metric: 'Error Rate', target: '< 0.1%', measurement: 'Error monitoring', alert: '> 0.5%' },
    { metric: 'DB Connections', target: '< 80% pool', measurement: 'Connection monitoring', alert: '> 85%' },
    { metric: 'Queue Depth', target: '< 50 jobs', measurement: 'Queue monitoring', alert: '> 100' },
    { metric: 'Queue Processing', target: '< 5s', measurement: 'Queue monitoring', alert: '> 10s' },
    { metric: 'Storage Usage', target: '< 70%', measurement: 'Disk monitoring', alert: '> 80%' },
    { metric: 'Auth Failures', target: '< 5/min', measurement: 'Security monitoring', alert: '> 10/min' },
  ];

  const alerts = [
    { alert: 'Platform Down', sev: 'P1', condition: 'Health check fails', response: '< 5 min', owner: 'SRE' },
    { alert: 'API Error Rate > 1%', sev: 'P1', condition: 'Error monitoring', response: '< 15 min', owner: 'Backend Lead' },
    { alert: 'Database Down', sev: 'P1', condition: 'Connection pool exhausted', response: '< 5 min', owner: 'DBA' },
    { alert: 'Queue Backlog > 500', sev: 'P2', condition: 'Queue monitoring', response: '< 30 min', owner: 'Ops' },
    { alert: 'Latency p95 > 2s', sev: 'P2', condition: 'APM', response: '< 30 min', owner: 'Backend Lead' },
    { alert: 'Storage > 90%', sev: 'P2', condition: 'Disk monitoring', response: '< 1 hour', owner: 'Ops' },
    { alert: 'Auth Failures > 20/min', sev: 'P2', condition: 'Security monitoring', response: '< 15 min', owner: 'Security' },
    { alert: 'Integration Down', sev: 'P3', condition: 'Health check', response: '< 1 hour', owner: 'Interop Lead' },
    { alert: 'Certificate Expiry < 7d', sev: 'P3', condition: 'Certificate monitoring', response: '< 24 hours', owner: 'Ops' },
    { alert: 'Backup Failed', sev: 'P2', condition: 'Backup monitoring', response: '< 1 hour', owner: 'DBA' },
  ];

  const incidents: Array<{ id: string; title: string; sev: string; status: string; duration: string }> = [];

  const capacity = [
    { resource: 'API Instances', monitoring: 'CPU, memory, connections', trigger: '> 80% utilization' },
    { resource: 'Database', monitoring: 'Connections, query time, disk', trigger: '> 80% pool, > 80% disk' },
    { resource: 'Redis', monitoring: 'Memory, connections', trigger: '> 80% memory' },
    { resource: 'Queue Workers', monitoring: 'Job count, processing time', trigger: '> 100 jobs, > 10s' },
    { resource: 'Storage', monitoring: 'Disk usage', trigger: '> 80% usage' },
    { resource: 'CDN', monitoring: 'Bandwidth, cache hit rate', trigger: '< 90% hit rate' },
  ];

  const tabs = [
    { key: 'overview' as const, label: 'Operations Overview' },
    { key: 'services' as const, label: 'Service Inventory' },
    { key: 'slo' as const, label: 'SLOs' },
    { key: 'alerting' as const, label: 'Alerting' },
    { key: 'incidents' as const, label: 'Incidents' },
    { key: 'capacity' as const, label: 'Capacity' },
  ];

  return (
    <div className="no-page">
      <div className="no-header">
        <div className="no-header__left">
          <h1 className="no-title">National Operations Center</h1>
          <p className="no-subtitle">SRE, observability and service management for national-scale operations</p>
        </div>
        <div className="no-header__right">
          <div className="no-status-pill no-status-pill--ok">
            <Heart size={14} />
            <span>All Systems Operational</span>
          </div>
        </div>
      </div>

      <div className="no-census">
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Heart size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">10</span>
            <span className="no-census-card__label">Services</span>
          </div>
        </div>
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><CheckCircle2 size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">10/10</span>
            <span className="no-census-card__label">Healthy</span>
          </div>
        </div>
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Activity size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">10</span>
            <span className="no-census-card__label">SLOs Defined</span>
          </div>
        </div>
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#f0f5ff', color: '#2563eb' }}><Bell size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">10</span>
            <span className="no-census-card__label">Alert Rules</span>
          </div>
        </div>
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Shield size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">0</span>
            <span className="no-census-card__label">Open Incidents</span>
          </div>
        </div>
        <div className="no-census-card">
          <div className="no-census-card__icon" style={{ background: '#ecfdf5', color: '#059669' }}><Lock size={18} /></div>
          <div className="no-census-card__info">
            <span className="no-census-card__value">PHI-Safe</span>
            <span className="no-census-card__label">Logging</span>
          </div>
        </div>
      </div>

      <div className="no-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={`no-tab ${tab === t.key ? 'no-tab--active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="no-content">
        {tab === 'overview' && (
          <div className="no-section">
            <div className="no-section__head"><Heart size={16} /><span>Operations Status</span></div>
            <div className="no-verdict-card no-verdict-card--ok">
              <div className="no-verdict-card__header"><CheckCircle2 size={24} /><h3>ALL SYSTEMS OPERATIONAL</h3></div>
              <p>SWASTHYA is operated as a national-scale service with comprehensive observability, alerting, and incident management. All 10 services are healthy. 10 SLOs defined. 10 alert rules configured. Zero open incidents.</p>
            </div>
            <h4 className="no-subhead">Service Health</h4>
            <div className="no-health-grid">
              {services.map((s) => (
                <div key={s.name} className="no-health-card">
                  <div className="no-health-card__top">
                    <CheckCircle2 size={14} className="no-icon-ok" />
                    <span className="no-health-card__name">{s.name}</span>
                    <span className="no-health-card__sla">{s.sla}</span>
                  </div>
                  <span className="no-health-card__owner">{s.owner}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'services' && (
          <div className="no-section">
            <div className="no-section__head"><Server size={16} /><span>Service Inventory</span></div>
            <div className="no-table-wrap">
              <div className="no-table no-table--head"><span>Service</span><span>Owner</span><span>SLA</span><span>Status</span></div>
              {services.map((s) => (
                <div key={s.name} className="no-table">
                  <span className="no-bold">{s.name}</span>
                  <span className="no-muted">{s.owner}</span>
                  <span className="no-mono">{s.sla}</span>
                  <span className="no-badge no-badge--ok">Operational</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'slo' && (
          <div className="no-section">
            <div className="no-section__head"><TrendingUp size={16} /><span>Service Level Objectives</span></div>
            <div className="no-table-wrap">
              <div className="no-table no-table--head"><span>Metric</span><span>Target</span><span>Measurement</span><span>Alert</span></div>
              {slos.map((s) => (
                <div key={s.metric} className="no-table">
                  <span className="no-bold">{s.metric}</span>
                  <span className="no-mono">{s.target}</span>
                  <span className="no-muted">{s.measurement}</span>
                  <span className="no-mono">{s.alert}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'alerting' && (
          <div className="no-section">
            <div className="no-section__head"><Bell size={16} /><span>Alerting Matrix</span></div>
            <div className="no-table-wrap">
              <div className="no-table no-table--head"><span>Alert</span><span>Severity</span><span>Response</span><span>Owner</span></div>
              {alerts.map((a) => (
                <div key={a.alert} className="no-table">
                  <span className="no-bold">{a.alert}</span>
                  <span className={`no-sev no-sev--${a.sev.toLowerCase()}`}>{a.sev}</span>
                  <span>{a.response}</span>
                  <span className="no-muted">{a.owner}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {tab === 'incidents' && (
          <div className="no-section">
            <div className="no-section__head"><AlertTriangle size={16} /><span>Incident Tracker</span></div>
            {incidents.length === 0 ? (
              <div className="no-empty">
                <CheckCircle2 size={32} className="no-icon-ok" />
                <span>No open incidents</span>
                <p>All services operational. Zero active incidents.</p>
              </div>
            ) : null}
          </div>
        )}

        {tab === 'capacity' && (
          <div className="no-section">
            <div className="no-section__head"><BarChart3 size={16} /><span>Capacity Management</span></div>
            <div className="no-table-wrap">
              <div className="no-table no-table--head"><span>Resource</span><span>Monitoring</span><span>Scaling Trigger</span></div>
              {capacity.map((c) => (
                <div key={c.resource} className="no-table">
                  <span className="no-bold">{c.resource}</span>
                  <span className="no-muted">{c.monitoring}</span>
                  <span className="no-mono">{c.trigger}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
