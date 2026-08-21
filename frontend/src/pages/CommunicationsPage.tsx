import { useState, useEffect } from 'react';
import { useTenant } from '../context/TenantContext';
import { communicationApi } from '../api/endpoints';
import { Alert, Button, Dialog, EmptyState, ErrorState, Input, StatusChip } from '../components/ui';
import { ApiError } from '../api/client';
import './communications.css';

interface Template {
  id: string;
  code: string;
  name: string;
  category: string;
  type: string;
  channels: { inApp: boolean; email: boolean; sms: boolean; whatsapp: boolean };
  subject: string | null;
  bodyTemplate: string;
  whatsappMessage: string | null;
  smsMessage: string | null;
  variables: Array<{ name: string; label: string; type: string; required: boolean; example: string }> | null;
  retryCount: number;
  retryDelayMinutes: number;
  enabled: boolean;
  locale: string;
  updatedAt: string | null;
}

const CATEGORY_COLORS: Record<string, string> = {
  appointment: '#3b82f6',
  followup: '#8b5cf6',
  result: '#10b981',
  billing: '#f59e0b',
  discharge: '#06b6d4',
  portal: '#ec4899',
  general: '#64748b',
};

const CATEGORY_ICONS: Record<string, string> = {
  appointment: 'AP',
  followup: 'FU',
  result: 'RS',
  billing: 'BL',
  discharge: 'DC',
  portal: 'PT',
  general: 'GN',
};

export function CommunicationsPage() {
  const { organizationId } = useTenant();
  const [templates, setTemplates] = useState<Template[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filterCategory, setFilterCategory] = useState('');
  const [selected, setSelected] = useState<Template | null>(null);
  const [previewData, setPreviewData] = useState<{ subject: string; body: string; sms: string | null; whatsapp: string | null } | null>(null);
  const [previewVars, setPreviewVars] = useState<Record<string, string>>({});
  const [previewLoading, setPreviewLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [categories, setCategories] = useState<Record<string, string>>({});
  const [variablePresets, setVariablePresets] = useState<Record<string, Array<Record<string, unknown>>>>({});

  useEffect(() => {
    if (!organizationId) return;
    setLoading(true);
    communicationApi.list(organizationId)
      .then(data => { setTemplates(data as unknown as Template[]); setLoading(false); })
      .catch(err => { setError(err instanceof ApiError ? err.message : 'Failed to load templates'); setLoading(false); });

    communicationApi.categories()
      .then(data => {
        const d = data as unknown as { categories: Record<string, string>; types: Record<string, string> };
        setCategories(d.categories);
      }).catch(() => {});

    communicationApi.variablePresets()
      .then(data => { setVariablePresets(data as unknown as Record<string, Array<Record<string, unknown>>>); })
      .catch(() => {});
  }, [organizationId]);

  const filtered = filterCategory
    ? templates.filter(t => t.category === filterCategory)
    : templates;

  const openPreview = async (t: Template) => {
    setSelected(t);
    setPreviewLoading(true);
    // Build example variables from schema
    const vars: Record<string, string> = {};
    t.variables?.forEach(v => { vars[v.name] = v.example || 'Example'; });
    setPreviewVars(vars);
    try {
      const res = await communicationApi.preview(t.id, vars);
      setPreviewData(res as unknown as typeof previewData);
    } catch {
      setPreviewData({ subject: t.subject || '', body: t.bodyTemplate, sms: t.smsMessage, whatsapp: t.whatsappMessage });
    } finally {
      setPreviewLoading(false);
    }
  };

  const refreshPreview = async () => {
    if (!selected) return;
    setPreviewLoading(true);
    try {
      const res = await communicationApi.preview(selected.id, previewVars);
      setPreviewData(res as unknown as typeof previewData);
    } catch {
      setPreviewData(null);
    } finally {
      setPreviewLoading(false);
    }
  };

  const refreshList = async () => {
    if (!organizationId) return;
    setLoading(true);
    try {
      const data = await communicationApi.list(organizationId);
      setTemplates(data as unknown as Template[]);
    } catch { /* */ } finally {
      setLoading(false);
    }
  };

  if (loading) return <div className="comm"><div className="comm__header"><h2>Communications</h2></div><div className="comm__loading">Loading templates…</div></div>;
  if (error) return <ErrorState error={error} onRetry={() => window.location.reload()} />;

  return (
    <div className="comm">
      <div className="comm__header">
        <div>
          <h2>Communications &amp; Reminders</h2>
          <p className="comm__subtitle">Manage multi-channel communication templates for patients and staff.</p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>New Template</Button>
      </div>

      {/* Category filter */}
      <div className="comm__filters">
        <button className={`comm__filter ${filterCategory === '' ? 'comm__filter--active' : ''}`} onClick={() => setFilterCategory('')}>
          All ({templates.length})
        </button>
        {Object.entries(categories).map(([key, label]) => (
          <button key={key} className={`comm__filter ${filterCategory === key ? 'comm__filter--active' : ''}`} onClick={() => setFilterCategory(key)}>
            <span className="comm__filter-dot" style={{ background: CATEGORY_COLORS[key] || '#64748b' }} />
            {label} ({templates.filter(t => t.category === key).length})
          </button>
        ))}
      </div>

      {/* Template grid */}
      {filtered.length === 0 ? (
        <EmptyState title="No templates" body="Create your first communication template to get started." />
      ) : (
        <div className="comm__grid">
          {filtered.map(t => (
            <div key={t.id} className="comm__card" onClick={() => void openPreview(t)}>
              <div className="comm__card-header">
                <span className="comm__card-icon" style={{ background: CATEGORY_COLORS[t.category] || '#64748b' }}>
                  {CATEGORY_ICONS[t.category] || 'GN'}
                </span>
                <div className="comm__card-meta">
                  <span className="comm__card-name">{t.name}</span>
                  <span className="comm__card-code">{t.code}</span>
                </div>
                <StatusChip tone={t.enabled ? 'success' : 'neutral'} label={t.enabled ? 'Active' : 'Disabled'} />
              </div>

              <p className="comm__card-subject">{t.subject || t.bodyTemplate.slice(0, 80) + '…'}</p>

              <div className="comm__card-channels">
                {t.channels.inApp && <span className="comm__channel comm__channel--inapp">In-App</span>}
                {t.channels.email && <span className="comm__channel comm__channel--email">Email</span>}
                {t.channels.sms && <span className="comm__channel comm__channel--sms">SMS</span>}
                {t.channels.whatsapp && <span className="comm__channel comm__channel--whatsapp">WhatsApp</span>}
              </div>

              <div className="comm__card-footer">
                <span className="comm__card-type">{t.category} · {t.type}</span>
                {t.retryCount > 0 && <span className="comm__card-retry">Retry {t.retryCount}×</span>}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Preview / Edit dialog */}
      {selected && (
        <Dialog open={true} onClose={() => { setSelected(null); setPreviewData(null); }} title={`Template: ${selected.name}`} footer={
          <>
            <Button variant="ghost" onClick={() => { setSelected(null); setPreviewData(null); }}>Close</Button>
          </>
        }>
          <div className="comm__preview">
            <div className="comm__preview-info">
              <span className="comm__card-code">{selected.code}</span>
              <StatusChip tone={selected.enabled ? 'success' : 'neutral'} label={selected.enabled ? 'Active' : 'Disabled'} />
              <span className="comm__preview-category" style={{ color: CATEGORY_COLORS[selected.category] }}>{selected.category}</span>
              <span className="comm__preview-type">{selected.type}</span>
            </div>

            {/* Variable inputs */}
            {selected.variables && selected.variables.length > 0 && (
              <div className="comm__preview-vars">
                <h4>Variables</h4>
                <div className="comm__vars-grid">
                  {selected.variables.map(v => (
                    <Input
                      key={v.name}
                      label={`${v.label}${v.required ? ' *' : ''}`}
                      value={previewVars[v.name] ?? ''}
                      onChange={e => setPreviewVars(prev => ({ ...prev, [v.name]: e.target.value }))}
                      placeholder={v.example}
                    />
                  ))}
                </div>
                <Button size="sm" onClick={() => void refreshPreview()} loading={previewLoading}>Refresh Preview</Button>
              </div>
            )}

            {/* Preview results */}
            {previewData && (
              <div className="comm__preview-results">
                <h4>Preview</h4>
                {previewData.subject && (
                  <div className="comm__preview-block">
                    <span className="comm__preview-label">Subject</span>
                    <div className="comm__preview-text">{previewData.subject}</div>
                  </div>
                )}
                <div className="comm__preview-block">
                  <span className="comm__preview-label">In-App / Email Body</span>
                  <div className="comm__preview-text comm__preview-text--body">{previewData.body}</div>
                </div>
                {previewData.sms && (
                  <div className="comm__preview-block">
                    <span className="comm__preview-label">SMS ({previewData.sms.length} chars)</span>
                    <div className="comm__preview-text comm__preview-text--sms">{previewData.sms}</div>
                  </div>
                )}
                {previewData.whatsapp && (
                  <div className="comm__preview-block">
                    <span className="comm__preview-label">WhatsApp</span>
                    <div className="comm__preview-text comm__preview-text--whatsapp">{previewData.whatsapp}</div>
                  </div>
                )}
              </div>
            )}
          </div>
        </Dialog>
      )}

      {/* Create dialog */}
      {createOpen && (
        <CreateTemplateDialog
          open={true}
          onClose={() => setCreateOpen(false)}
          orgId={organizationId ?? ''}
          variablePresets={variablePresets}
          onCreated={() => { setCreateOpen(false); void refreshList(); }}
        />
      )}
    </div>
  );
}

function CreateTemplateDialog({ open, onClose, orgId, variablePresets, onCreated }: {
  open: boolean; onClose: () => void; orgId: string;
  variablePresets: Record<string, Array<Record<string, unknown>>>;
  onCreated: () => void;
}) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [category, setCategory] = useState('appointment');
  const [type, setType] = useState('reminder');
  const [subject, setSubject] = useState('');
  const [bodyTemplate, setBodyTemplate] = useState('');
  const [channelInApp, setChannelInApp] = useState(true);
  const [channelEmail, setChannelEmail] = useState(false);
  const [channelSms, setChannelSms] = useState(false);
  const [channelWhatsapp, setChannelWhatsapp] = useState(false);
  const [smsMessage, setSmsMessage] = useState('');
  const [whatsappMessage, setWhatsappMessage] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await communicationApi.create(orgId, {
        code: code.trim(),
        name: name.trim(),
        category,
        type,
        subject: subject || null,
        bodyTemplate,
        channelInApp,
        channelEmail,
        channelSms,
        channelWhatsapp,
        smsMessage: smsMessage || null,
        whatsappMessage: whatsappMessage || null,
        variables: variablePresets[category]?.map(v => ({
          name: String(v.name),
          label: String(v.label),
          type: String(v.type),
          required: Boolean(v.required),
          example: String(v.example || ''),
        })) ?? null,
      });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create template');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="New Communication Template" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!code.trim() || !name.trim() || !bodyTemplate.trim()}>Create</Button>
      </>
    }>
      <div className="comm__create">
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="comm__create-grid">
          <Input label="Template Code" value={code} onChange={e => setCode(e.target.value)} required hint="lowercase, underscores only (e.g. appt_reminder_24h)" />
          <Input label="Template Name" value={name} onChange={e => setName(e.target.value)} required />
          <div>
            <label className="form-label">Category</label>
            <select className="form-select" value={category} onChange={e => setCategory(e.target.value)}>
              {Object.entries(CATEGORY_COLORS).map(([k]) => <option key={k} value={k}>{k}</option>)}
            </select>
          </div>
          <div>
            <label className="form-label">Type</label>
            <select className="form-select" value={type} onChange={e => setType(e.target.value)}>
              <option value="confirmation">Confirmation</option>
              <option value="reminder">Reminder</option>
              <option value="missed">Missed</option>
              <option value="invitation">Invitation</option>
              <option value="notification">Notification</option>
              <option value="alert">Alert</option>
            </select>
          </div>
        </div>

        <Input label="Subject" value={subject} onChange={e => setSubject(e.target.value)} hint="Used for email and in-app notifications" />

        <div className="comm__channels-row">
          <label className="comm__channel-toggle">
            <input type="checkbox" checked={channelInApp} onChange={e => setChannelInApp(e.target.checked)} /> In-App
          </label>
          <label className="comm__channel-toggle">
            <input type="checkbox" checked={channelEmail} onChange={e => setChannelEmail(e.target.checked)} /> Email
          </label>
          <label className="comm__channel-toggle">
            <input type="checkbox" checked={channelSms} onChange={e => setChannelSms(e.target.checked)} /> SMS
          </label>
          <label className="comm__channel-toggle">
            <input type="checkbox" checked={channelWhatsapp} onChange={e => setChannelWhatsapp(e.target.checked)} /> WhatsApp
          </label>
        </div>

        <div className="comm__create-grid">
          <div className="comm__create-full">
            <label className="form-label">Body Template *</label>
            <textarea className="form-textarea comm__textarea" value={bodyTemplate} onChange={e => setBodyTemplate(e.target.value)} rows={6} required placeholder="Dear {{patient_name}},&#10;&#10;Your appointment with {{doctor_name}} is scheduled for {{date}} at {{time}}.&#10;&#10;Thank you, {{hospital_name}}" />
            <span className="form-hint">Use {'{{variable_name}}'} syntax for dynamic content</span>
          </div>
        </div>

        {channelSms && (
          <div className="comm__create-full">
            <label className="form-label">SMS Message (max 320 chars)</label>
            <textarea className="form-textarea comm__textarea" value={smsMessage} onChange={e => setSmsMessage(e.target.value)} rows={3} placeholder="Hi {{patient_name}}, reminder: appt with {{doctor_name}} on {{date}} at {{time}}." />
          </div>
        )}

        {channelWhatsapp && (
          <div className="comm__create-full">
            <label className="form-label">WhatsApp Message</label>
            <textarea className="form-textarea comm__textarea" value={whatsappMessage} onChange={e => setWhatsappMessage(e.target.value)} rows={3} placeholder="WhatsApp-specific message (shorter than email)" />
          </div>
        )}
      </div>
    </Dialog>
  );
}
