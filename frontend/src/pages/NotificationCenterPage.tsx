import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { portalApi, communicationApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/notification-center.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface PortalNotification {
  id: string;
  title: string;
  message: string;
  type: string;
  severity: string;
  readAt: string | null;
  createdAt: string;
}

interface CommTemplate {
  id: string;
  name: string;
  category: string;
  type: string;
  channel: string;
  subject: string | null;
  body: string;
  isActive: boolean;
}

/* ── Constants ───────────────────────────────────────────────────── */

const NOTIF_TYPE: Record<string, { label: string; color: string; bg: string; icon: string }> = {
  appointment: { label: 'Appointment', color: '#3b82f6', bg: '#dbeafe', icon: '📅' },
  result: { label: 'Result', color: '#10b981', bg: '#ecfdf5', icon: '🧪' },
  prescription: { label: 'Prescription', color: '#8b5cf6', bg: '#f5f3ff', icon: '💊' },
  billing: { label: 'Billing', color: '#f59e0b', bg: '#fef3c7', icon: '💳' },
  message: { label: 'Message', color: '#06b6d4', bg: '#cffafe', icon: '✉️' },
  followup: { label: 'Follow-up', color: '#ec4899', bg: '#fce7f3', icon: '📋' },
  system: { label: 'System', color: '#6b7280', bg: '#f3f4f6', icon: '⚙️' },
  urgent: { label: 'Urgent', color: '#ef4444', bg: '#fee2e2', icon: '🚨' },
};

const CHANNEL_COLORS: Record<string, string> = {
  in_app: '#3b82f6',
  sms: '#10b981',
  email: '#8b5cf6',
  push: '#f59e0b',
  whatsapp: '#25d366',
};

function TypeBadge({ type }: { type: string }) {
  const c = NOTIF_TYPE[type] ?? NOTIF_TYPE.system;
  return (
    <span className="nc-type-badge" style={{ color: c.color, backgroundColor: c.bg }}>
      {c.label}
    </span>
  );
}

/* ── Main Component ──────────────────────────────────────────────── */

export function NotificationCenterPage() {
  const { organizationId: org } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'notifications' | 'templates' | 'preferences'>('notifications');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Filter
  const [filterType, setFilterType] = useState('all');
  const [showUnreadOnly, setShowUnreadOnly] = useState(false);

  // Message compose
  const [msgForm, setMsgForm] = useState({ recipientStaffId: '', subject: '', body: '', category: 'general' });

  // Data fetching — use portal API for notifications
  const notifications = useFetch(
    () => portalApi.messages().catch(() => []),
    [],
  );

  const templates = useFetch(
    () => org ? communicationApi.list(org) : Promise.resolve([]),
    [org],
  );

  const allNotifications = useMemo(() => (notifications.data ?? []) as PortalNotification[], [notifications.data]);
  const allTemplates = useMemo(() => (templates.data ?? []) as unknown as CommTemplate[], [templates.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  // Filtered notifications
  const filteredNotifications = useMemo(() => {
    let result = allNotifications;
    if (filterType !== 'all') {
      result = result.filter(n => (n as unknown as Record<string, unknown>).category === filterType || (n as unknown as Record<string, unknown>).type === filterType);
    }
    if (showUnreadOnly) {
      result = result.filter(n => !n.readAt);
    }
    return result;
  }, [allNotifications, filterType, showUnreadOnly]);

  const unreadCount = allNotifications.filter(n => !n.readAt).length;

  const handleSendMessage = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!msgForm.recipientStaffId || !msgForm.subject || !msgForm.body) return;
    await go(() => portalApi.sendMessage({
      recipientStaffId: msgForm.recipientStaffId,
      subject: msgForm.subject,
      body: msgForm.body,
      category: msgForm.category,
    }));
    setDlg(null);
    setMsgForm({ recipientStaffId: '', subject: '', body: '', category: 'general' });
    notifications.refresh();
  }, [msgForm, go, notifications]);

  const handleSendTemplate = useCallback(async (templateId: string) => {
    await go(() => communicationApi.send(templateId, {
      variables: {},
      channel: 'in_app',
    }));
  }, [go]);

  return (
    <div className="page nc-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Notifications</h1>
          <p className="page__subtitle">Messages, alerts, templates, communication preferences</p>
        </div>
        <div className="nc-actions">
          <Button variant="ghost" onClick={() => { notifications.refresh(); templates.refresh(); }}>Refresh</Button>
          <Button variant="primary" size="sm" onClick={() => setDlg('compose')}>Compose Message</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="nc-census">
        <div className="nc-census-card nc-census-card--total">
          <span className="nc-census-value">{allNotifications.length}</span>
          <span className="nc-census-label">Total Messages</span>
        </div>
        <div className="nc-census-card nc-census-card--unread">
          <span className="nc-census-value" style={{ color: unreadCount > 0 ? '#ef4444' : undefined }}>{unreadCount}</span>
          <span className="nc-census-label">Unread</span>
        </div>
        <div className="nc-census-card nc-census-card--templates">
          <span className="nc-census-value">{allTemplates.length}</span>
          <span className="nc-census-label">Templates</span>
        </div>
        <div className="nc-census-card nc-census-card--channels">
          <span className="nc-census-value">5</span>
          <span className="nc-census-label">Channels</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="nc-tabs">
        {(['notifications', 'templates', 'preferences'] as const).map(t => (
          <button key={t} className={`nc-tab ${activeTab === t ? 'nc-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'notifications' ? `Notifications${unreadCount > 0 ? ` (${unreadCount})` : ''}` : t === 'templates' ? 'Templates' : 'Preferences'}
          </button>
        ))}
      </div>

      {/* ── Notifications Tab ─────────────────────────────── */}
      {activeTab === 'notifications' && (
        <Card className="nc-section-card">
          <div className="nc-section-header">
            <h3>Notifications</h3>
            <div className="nc-section-actions">
              <label className="nc-toggle">
                <input type="checkbox" checked={showUnreadOnly} onChange={e => setShowUnreadOnly(e.target.checked)} />
                <span>Unread only</span>
              </label>
              <select className="nc-select" value={filterType} onChange={e => setFilterType(e.target.value)}>
                <option value="all">All Types</option>
                {Object.entries(NOTIF_TYPE).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>
            </div>
          </div>
          {filteredNotifications.length === 0 ? (
            <EmptyState title="No notifications" body={showUnreadOnly ? "All caught up! No unread notifications." : "Notifications appear here."} />
          ) : (
            <div className="nc-notif-list">
              {filteredNotifications.map(n => {
                const typeInfo = NOTIF_TYPE[(n as unknown as Record<string, unknown>).type as string] ?? NOTIF_TYPE.system;
                return (
                  <div key={n.id} className={`nc-notif-item ${!n.readAt ? 'nc-notif-item--unread' : ''}`}>
                    <div className="nc-notif-icon" style={{ backgroundColor: typeInfo.bg }}>
                      <span style={{ color: typeInfo.color }}>{typeInfo.label.charAt(0)}</span>
                    </div>
                    <div className="nc-notif-content">
                      <div className="nc-notif-header">
                        <span className="nc-notif-subject">{String((n as unknown as Record<string, unknown>).subject ?? n.title ?? 'Notification')}</span>
                        <TypeBadge type={(n as unknown as Record<string, unknown>).type as string ?? 'system'} />
                      </div>
                      <p className="nc-notif-body">{String((n as unknown as Record<string, unknown>).body ?? n.message ?? '')}</p>
                      <span className="nc-notif-time">{n.createdAt ? new Date(n.createdAt).toLocaleString() : '—'}</span>
                    </div>
                    {!n.readAt && <div className="nc-unread-dot" />}
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* ── Templates Tab ─────────────────────────────────── */}
      {activeTab === 'templates' && (
        <Card className="nc-section-card">
          <div className="nc-section-header">
            <h3>Communication Templates</h3>
          </div>
          {allTemplates.length === 0 ? (
            <EmptyState title="No templates" body="Communication templates are configured by administrators." />
          ) : (
            <div className="nc-template-list">
              {allTemplates.map(t => (
                <div key={t.id} className="nc-template-item">
                  <div className="nc-template-header">
                    <span className="nc-template-name">{t.name}</span>
                    <div className="nc-template-meta">
                      <span className="nc-channel-badge" style={{ color: CHANNEL_COLORS[t.channel] ?? '#6b7280' }}>
                        {t.channel}
                      </span>
                      <span className="nc-template-category">{t.category}</span>
                    </div>
                  </div>
                  <p className="nc-template-preview">{t.body?.slice(0, 120)}...</p>
                  <div className="nc-template-actions">
                    <Button variant="ghost" size="sm" onClick={() => void handleSendTemplate(t.id)} loading={busy}>
                      Send Test
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Preferences Tab ───────────────────────────────── */}
      {activeTab === 'preferences' && (
        <Card className="nc-section-card">
          <div className="nc-section-header">
            <h3>Notification Preferences</h3>
          </div>
          <div className="nc-prefs-grid">
            <div className="nc-pref-item">
              <span className="nc-pref-label">Email Notifications</span>
              <span className="nc-pref-status nc-pref-status--enabled">Enabled</span>
            </div>
            <div className="nc-pref-item">
              <span className="nc-pref-label">SMS Notifications</span>
              <span className="nc-pref-status nc-pref-status--enabled">Enabled</span>
            </div>
            <div className="nc-pref-item">
              <span className="nc-pref-label">Push Notifications</span>
              <span className="nc-pref-status nc-pref-status--enabled">Enabled</span>
            </div>
            <div className="nc-pref-item">
              <span className="nc-pref-label">Appointment Reminders</span>
              <span className="nc-pref-status nc-pref-status--enabled">Enabled</span>
            </div>
            <div className="nc-pref-item">
              <span className="nc-pref-label">Result Notifications</span>
              <span className="nc-pref-status nc-pref-status--enabled">Enabled</span>
            </div>
            <div className="nc-pref-item">
              <span className="nc-pref-label">Billing Notifications</span>
              <span className="nc-pref-status nc-pref-status--disabled">Disabled</span>
            </div>
          </div>
          <EmptyState title="Preferences" body="Patient notification preferences are managed through the patient portal." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Compose Dialog */}
      {dlg === 'compose' && (
        <Dialog open onClose={() => setDlg(null)} title="Compose Message" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleSendMessage} loading={busy} disabled={!msgForm.recipientStaffId || !msgForm.subject || !msgForm.body}>Send Message</Button>
          </>
        }>
          <form onSubmit={handleSendMessage} className="nc-form">
            <Input label="Recipient Staff ID" value={msgForm.recipientStaffId} onChange={e => setMsgForm(f => ({ ...f, recipientStaffId: e.target.value }))} placeholder="Staff member UUID" required />
            <Input label="Subject" value={msgForm.subject} onChange={e => setMsgForm(f => ({ ...f, subject: e.target.value }))} placeholder="Message subject" required />
            <div className="nc-form-field">
              <label className="nc-label">Message</label>
              <textarea className="nc-textarea" value={msgForm.body} onChange={e => setMsgForm(f => ({ ...f, body: e.target.value }))} placeholder="Type your message..." rows={4} required />
            </div>
            <div className="nc-form-field">
              <label className="nc-label">Category</label>
              <select className="nc-input" value={msgForm.category} onChange={e => setMsgForm(f => ({ ...f, category: e.target.value }))}>
                <option value="general">General</option>
                <option value="appointment">Appointment</option>
                <option value="billing">Billing</option>
                <option value="clinical">Clinical</option>
                <option value="support">Support</option>
              </select>
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
