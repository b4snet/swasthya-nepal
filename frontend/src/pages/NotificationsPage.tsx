import { useState, useEffect } from 'react';
import { useTenant } from '../context/TenantContext';
import { notificationsApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Card, EmptyState, Spinner } from '../components/ui';
import { Circle, AlertTriangle, Bell, Siren } from 'lucide-react';

type Campaign = {
  id: string;
  code: string;
  name: string;
  status: string;
  priority: string;
  severity: string;
  is_emergency: boolean;
  total_recipients: number;
  delivered_count: number;
  failed_count: number;
  acknowledged_count: number;
  scheduled_at: string | null;
  created_at: string;
};

type Stats = {
  active_campaigns: number;
  active_emergencies: number;
  recent_sent: number;
  total_delivered_30d: number;
};

const STATUS_COLORS: Record<string, string> = {
  draft: '#6b7280',
  review: '#f59e0b',
  approved: '#10b981',
  scheduled: '#3b82f6',
  sending: '#8b5cf6',
  sent: '#10b981',
  partially_delivered: '#f59e0b',
  failed: '#ef4444',
  cancelled: '#6b7280',
  expired: '#6b7280',
};

const PRIORITY_COLORS: Record<string, string> = {
  low: 'var(--blue-500)',
  normal: 'var(--gray-400)',
  high: 'var(--amber-500)',
  urgent: 'var(--amber-600)',
  emergency: 'var(--red-500)',
};

export function NotificationsPage() {
  const { selectedFacilityId } = useTenant();
  const fac = selectedFacilityId;

  const [activeTab, setActiveTab] = useState<'campaigns' | 'emergency'>('campaigns');
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [emergencyForm, setEmergencyForm] = useState({
    name: '',
    message: '',
    channels: ['in_app'] as string[],
  });

  const [showCreateCampaign, setShowCreateCampaign] = useState(false);
  const [campaignForm, setCampaignForm] = useState({
    code: '',
    name: '',
    description: '',
    priority: 'normal',
    severity: 'info',
    message_content: { subject: '', body: '' },
    channels: ['in_app'] as string[],
    acknowledgement_required: false,
  });

  useEffect(() => {
    loadData();
  }, [activeTab, fac]);

  async function loadData() {
    setLoading(true);
    setError(null);
    try {
      if (activeTab === 'campaigns') {
        const [campaignsRes, statsRes] = await Promise.all([
          notificationsApi.campaigns(undefined, fac),
          notificationsApi.stats(fac),
        ]);
        setCampaigns((campaignsRes as any)?.data || []);
        setStats(statsRes as any);
      }
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to load data');
    } finally {
      setLoading(false);
    }
  }

  async function handleCreateCampaign() {
    if (!campaignForm.code || !campaignForm.name || !campaignForm.message_content.body) return;
    setBusy(true);
    try {
      await notificationsApi.storeCampaign({
        ...campaignForm,
        delivery_config: { channels: campaignForm.channels },
      }, fac);
      setShowCreateCampaign(false);
      setCampaignForm({ code: '', name: '', description: '', priority: 'normal', severity: 'info', message_content: { subject: '', body: '' }, channels: ['in_app'], acknowledgement_required: false });
      loadData();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to create campaign');
    } finally {
      setBusy(false);
    }
  }

  async function handleCampaignAction(campaignId: string, action: string) {
    setBusy(true);
    try {
      await notificationsApi.transitionCampaign(campaignId, action, fac);
      loadData();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to perform action');
    } finally {
      setBusy(false);
    }
  }

  async function handleEmergencyBroadcast() {
    if (!emergencyForm.name || !emergencyForm.message) return;
    setBusy(true);
    try {
      await notificationsApi.emergencyBroadcast({
        name: emergencyForm.name,
        message_content: { subject: emergencyForm.name, body: emergencyForm.message },
        channels: emergencyForm.channels,
        targeting_criteria: { role_codes: ['superadmin', 'org_admin', 'hospital_admin', 'doctor', 'nurse'] },
      }, fac);
      setEmergencyForm({ name: '', message: '', channels: ['in_app'] });
      loadData();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Failed to send emergency broadcast');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="page">
      <h1 style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Bell size={24} /> Notifications</h1>

      {error && <div className="alert alert-error" role="alert">{error}</div>}

      {stats && (
        <div className="stats-bar">
          <div className="stat-card">
            <span className="stat-value">{stats.active_campaigns}</span>
            <span className="stat-label">Active Campaigns</span>
          </div>
          <div className="stat-card">
            <span className="stat-value" style={{ color: stats.active_emergencies > 0 ? '#ef4444' : undefined }}>
              {stats.active_emergencies}
            </span>
            <span className="stat-label">Active Emergencies</span>
          </div>
          <div className="stat-card">
            <span className="stat-value">{stats.recent_sent}</span>
            <span className="stat-label">Sent (7d)</span>
          </div>
          <div className="stat-card">
            <span className="stat-value">{stats.total_delivered_30d}</span>
            <span className="stat-label">Delivered (30d)</span>
          </div>
        </div>
      )}

      <div className="tab-nav">
        <button className={`tab ${activeTab === 'campaigns' ? 'active' : ''}`} onClick={() => setActiveTab('campaigns')}>
          📢 Campaigns
        </button>
        <button className={`tab ${activeTab === 'emergency' ? 'active' : ''}`} onClick={() => setActiveTab('emergency')} style={{ color: '#ef4444' }}>           <Siren size={14} /> Emergency
        </button>
      </div>

      {activeTab === 'campaigns' && (
        <div>
          <div className="flex justify-between items-center mb-4">
            <h2>Broadcast Campaigns</h2>
            <button className="btn btn-primary" onClick={() => setShowCreateCampaign(true)}>+ New Campaign</button>
          </div>

          {loading ? (
            <Spinner />
          ) : campaigns.length === 0 ? (
            <EmptyState title="No campaigns" body="Create your first broadcast campaign." />
          ) : (
            <div className="table-responsive">
              <table>
                <thead>
                  <tr>
                    <th>Priority</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Recipients</th>
                    <th>Delivered</th>
                    <th>Failed</th>
                    <th>Ack'd</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {campaigns.map((c) => (
                    <tr key={c.id} style={c.is_emergency ? { backgroundColor: '#fef2f2' } : undefined}>
                      <td><span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><Circle size={8} fill={PRIORITY_COLORS[c.priority] || 'var(--gray-400)'} color={PRIORITY_COLORS[c.priority] || 'var(--gray-400)'} /> {c.priority}</span></td>
                      <td><code>{c.code}</code></td>
                      <td>
                        {c.name}
                        {c.is_emergency && <span className="badge badge-danger" style={{ marginLeft: 8 }}>EMERGENCY</span>}
                      </td>
                      <td>
                        <span className="badge" style={{ backgroundColor: STATUS_COLORS[c.status] || '#6b7280', color: 'white', padding: '2px 8px', borderRadius: 4 }}>
                          {c.status}
                        </span>
                      </td>
                      <td>{c.total_recipients}</td>
                      <td>{c.delivered_count}</td>
                      <td>{c.failed_count}</td>
                      <td>{c.acknowledged_count}</td>
                      <td>
                        {c.status === 'draft' && <button className="btn btn-sm" onClick={() => handleCampaignAction(c.id, 'submit')} disabled={busy}>Submit</button>}
                        {c.status === 'review' && <button className="btn btn-sm btn-success" onClick={() => handleCampaignAction(c.id, 'approve')} disabled={busy}>Approve</button>}
                        {c.status === 'approved' && <button className="btn btn-sm btn-primary" onClick={() => handleCampaignAction(c.id, 'dispatch')} disabled={busy}>Dispatch</button>}
                        {['draft', 'review', 'approved', 'scheduled'].includes(c.status) && <button className="btn btn-sm btn-danger" onClick={() => handleCampaignAction(c.id, 'cancel')} disabled={busy}>Cancel</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {showCreateCampaign && (
            <div className="dialog-overlay" onClick={() => setShowCreateCampaign(false)}>
              <div className="dialog" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
                <h3>Create Broadcast Campaign</h3>
                <div className="form-group">
                  <label htmlFor="camp-code">Code *</label>
                  <input id="camp-code" value={campaignForm.code} onChange={(e) => setCampaignForm({ ...campaignForm, code: e.target.value })} placeholder="e.g. STAFF-ALERT-001" />
                </div>
                <div className="form-group">
                  <label htmlFor="camp-name">Name *</label>
                  <input id="camp-name" value={campaignForm.name} onChange={(e) => setCampaignForm({ ...campaignForm, name: e.target.value })} />
                </div>
                <div className="form-group">
                  <label htmlFor="camp-desc">Description</label>
                  <textarea id="camp-desc" value={campaignForm.description} onChange={(e) => setCampaignForm({ ...campaignForm, description: e.target.value })} rows={2} />
                </div>
                <div className="form-group">
                  <label htmlFor="camp-priority">Priority</label>
                  <select id="camp-priority" value={campaignForm.priority} onChange={(e) => setCampaignForm({ ...campaignForm, priority: e.target.value })}>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                  </select>
                </div>
                <div className="form-group">
                  <label htmlFor="camp-subject">Subject</label>
                  <input id="camp-subject" value={campaignForm.message_content.subject} onChange={(e) => setCampaignForm({ ...campaignForm, message_content: { ...campaignForm.message_content, subject: e.target.value } })} />
                </div>
                <div className="form-group">
                  <label htmlFor="camp-body">Message *</label>
                  <textarea id="camp-body" value={campaignForm.message_content.body} onChange={(e) => setCampaignForm({ ...campaignForm, message_content: { ...campaignForm.message_content, body: e.target.value } })} rows={4} />
                </div>
                <div className="form-group">
                  <label>Channels</label>
                  <div style={{ display: 'flex', gap: 16 }}>
                    {['in_app', 'email', 'sms', 'push'].map((ch) => (
                      <label key={ch} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                        <input type="checkbox" checked={campaignForm.channels.includes(ch)} onChange={(e) => {
                          const channels = e.target.checked ? [...campaignForm.channels, ch] : campaignForm.channels.filter((c) => c !== ch);
                          setCampaignForm({ ...campaignForm, channels });
                        }} />
                        {ch}
                      </label>
                    ))}
                  </div>
                </div>
                <div className="dialog-actions">
                  <button className="btn" onClick={() => setShowCreateCampaign(false)}>Cancel</button>
                  <button className="btn btn-primary" onClick={handleCreateCampaign} disabled={busy || !campaignForm.code || !campaignForm.name || !campaignForm.message_content.body}>
                    {busy ? 'Creating...' : 'Create'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {activeTab === 'emergency' && (
        <div>           <h2 style={{ color: 'var(--red-600)', display: 'flex', alignItems: 'center', gap: 8 }}><Siren size={20} /> Emergency Broadcast Console</h2>
          <Card>
            <div className="form-group">
              <label htmlFor="emrg-name">Broadcast Name *</label>
              <input id="emrg-name" value={emergencyForm.name} onChange={(e) => setEmergencyForm({ ...emergencyForm, name: e.target.value })} placeholder="e.g. COVID-19 Outbreak Alert" style={{ borderColor: '#ef4444' }} />
            </div>
            <div className="form-group">
              <label htmlFor="emrg-msg">Emergency Message *</label>
              <textarea id="emrg-msg" value={emergencyForm.message} onChange={(e) => setEmergencyForm({ ...emergencyForm, message: e.target.value })} rows={5} placeholder="Enter the emergency message." style={{ borderColor: '#ef4444' }} />
            </div>
            <div className="form-group">
              <label>Channels</label>
              <div style={{ display: 'flex', gap: 16 }}>
                {['in_app', 'email', 'sms', 'push'].map((ch) => (
                  <label key={ch} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                    <input type="checkbox" checked={emergencyForm.channels.includes(ch)} onChange={(e) => {
                      const channels = e.target.checked ? [...emergencyForm.channels, ch] : emergencyForm.channels.filter((c) => c !== ch);
                      setEmergencyForm({ ...emergencyForm, channels });
                    }} />
                    {ch}
                  </label>
                ))}
              </div>
            </div>
            <div className="alert alert--warning">
              <AlertTriangle size={14} /> Emergency broadcasts bypass approval and are sent immediately. This action cannot be undone.
            </div>
            <button className="btn btn--danger" onClick={handleEmergencyBroadcast} disabled={busy || !emergencyForm.name || !emergencyForm.message}>
              {busy ? 'Sending...' : 'Send Emergency Broadcast'}
            </button>
          </Card>
        </div>
      )}
    </div>
  );
}
