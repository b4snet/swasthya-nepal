import { useCallback, useMemo, useState } from 'react';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';

const docApi = {
  dashboard: () => api.request<Record<string, unknown>>('/api/v1/documents/platform/dashboard'),
  list: (cat?: string) => {
    const qs = cat ? `?category=${cat}` : '';
    return api.request<{ data: unknown[] }>(`/api/v1/documents/platform${qs}`);
  },
  create: (p: Record<string, unknown>) => api.request<unknown>('/api/v1/documents/platform', { method: 'POST', body: p }),
  finalize: (id: string) => api.request<unknown>(`/api/v1/documents/platform/${id}/finalize`, { method: 'POST', body: {} }),
  archive: (id: string) => api.request<unknown>(`/api/v1/documents/platform/${id}/archive`, { method: 'POST', body: {} }),
  versions: (id: string) => api.request<unknown[]>(`/api/v1/documents/platform/${id}/versions`),
  createVersion: (id: string, p: Record<string, unknown>) => api.request<unknown>(`/api/v1/documents/platform/${id}/versions`, { method: 'POST', body: p }),
  requestAck: (id: string, userIds: string[]) => api.request<unknown>(`/api/v1/documents/platform/${id}/acknowledgements`, { method: 'POST', body: { user_ids: userIds } }),
  acknowledge: (ackId: string) => api.request<unknown>(`/api/v1/document-acknowledgements/${ackId}/acknowledge`, { method: 'POST', body: {} }),
};

type Tab = 'dashboard' | 'documents' | 'acknowledgements';

interface Doc {
  id: string; document_code: string; title: string; category: string;
  document_type: string; status: string; version: number;
  created_at: string | null; department: string | null;
}

export function DocumentCenterPage() {
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<Tab>('dashboard');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [catFilter, setCatFilter] = useState('');

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  const dash = useFetch(() => docApi.dashboard().catch(() => ({})), []);
  const docs = useFetch(() => docApi.list(catFilter || undefined).then(r => r?.data ?? []).catch(() => []), [catFilter]);

  const d = useMemo(() => (dash.data ?? {}) as Record<string, unknown>, [dash.data]);
  const allDocs = useMemo(() => (docs.data ?? []) as Doc[], [docs.data]);
  const byCat = useMemo(() => (d.byCategory ?? {}) as Record<string, number>, [d]);

  const [form, setForm] = useState({ document_type: 'clinical_note', category: 'clinical', title: '', description: '', department: '' });

  const CATS = ['clinical','financial','hr','administrative','governance','procurement','patient_generated'] as const;
  const CAT_COLORS: Record<string, string> = { clinical: '#3b82f6', financial: '#f59e0b', hr: '#8b5cf6', administrative: '#6b7280', governance: '#10b981', procurement: '#ec4899', patient_generated: '#06b6d4' };
  const STATUS_COLORS: Record<string, string> = { draft: '#f59e0b', final: '#10b981', released: '#3b82f6', archived: '#6b7280', superseded: '#ef4444' };

  return (
    <div className="page orch-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Document Center</h1>
          <p className="page__subtitle">Documents, forms, acknowledgements, records</p>
        </div>
        <div className="orch-actions">
          <Button variant="ghost" onClick={() => { dash.refresh(); docs.refresh(); }}>Refresh</Button>
          <Button variant="primary" size="sm" onClick={() => setDlg('new-doc')}>+ New Document</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="orch-tabs">
        {(['dashboard', 'documents', 'acknowledgements'] as const).map(t => (
          <button key={t} className={`orch-tab ${activeTab === t ? 'orch-tab--active' : ''}`}
            onClick={() => setActiveTab(t)}>
            {t === 'dashboard' ? 'Overview' : t === 'documents' ? 'Documents' : 'Acknowledgements'}
          </button>
        ))}
      </div>

      {activeTab === 'dashboard' && (
        <div className="orch-dashboard">
          <div className="orch-census">
            <div className="orch-census-card"><span className="orch-census-value">{d.totalDocuments as number ?? 0}</span><span className="orch-census-label">Total Documents</span></div>
            <div className="orch-census-card"><span className="orch-census-value" style={{color:'#f59e0b'}}>{d.draftDocuments as number ?? 0}</span><span className="orch-census-label">Draft</span></div>
            <div className="orch-census-card"><span className="orch-census-value" style={{color:'#10b981'}}>{d.finalDocuments as number ?? 0}</span><span className="orch-census-label">Final</span></div>
            <div className="orch-census-card"><span className="orch-census-value" style={{color:'#ef4444'}}>{d.pendingAcknowledgements as number ?? 0}</span><span className="orch-census-label">Pending Acks</span></div>
          </div>
          <Card className="orch-section-card">
            <div className="orch-section-header"><h3>Documents by Category</h3></div>
            <div className="orch-flow-strip">
              {Object.entries(byCat).map(([cat, count]) => (
                <div key={cat} className="orch-flow-item" style={{borderLeftColor: CAT_COLORS[cat] ?? '#6b7280'}}>
                  <span className="orch-flow-value" style={{color: CAT_COLORS[cat] ?? '#6b7280'}}>{count as number}</span>
                  <span className="orch-flow-label">{cat}</span>
                </div>
              ))}
              {Object.keys(byCat).length === 0 && <EmptyState title="No documents" body="Create documents to see category breakdown." />}
            </div>
          </Card>
        </div>
      )}

      {activeTab === 'documents' && (
        <Card className="orch-section-card">
          <div className="orch-section-header">
            <h3>Documents</h3>
            <div style={{display:'flex',gap:'0.5rem',alignItems:'center'}}>
              <select className="q-input" value={catFilter} onChange={e => setCatFilter(e.target.value)}>
                <option value="">All Categories</option>
                {CATS.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
          </div>
          {allDocs.length === 0 ? <EmptyState title="No documents" body="Create documents to manage hospital records." /> : (
            <div className="orch-table">
              <div className="orch-table-header"><span>Code</span><span>Title</span><span>Category</span><span>Type</span><span>Status</span><span>Version</span><span>Actions</span></div>
              {allDocs.map(doc => (
                <div key={doc.id} className="orch-table-row">
                  <span className="orch-mono">{doc.document_code}</span>
                  <span>{doc.title}</span>
                  <span className="orch-badge" style={{background: CAT_COLORS[doc.category] + '20', color: CAT_COLORS[doc.category]}}>{doc.category}</span>
                  <span>{doc.document_type}</span>
                  <span className="orch-badge" style={{background: (STATUS_COLORS[doc.status] ?? '#6b7280') + '20', color: STATUS_COLORS[doc.status] ?? '#6b7280'}}>{doc.status}</span>
                  <span>v{doc.version}</span>
                  <span className="orch-table-actions">
                    {doc.status === 'draft' && <Button variant="ghost" size="sm" onClick={async () => { await go(() => docApi.finalize(doc.id)); docs.refresh(); dash.refresh(); }}>Finalize</Button>}
                    {doc.status === 'final' && <Button variant="ghost" size="sm" onClick={async () => { await go(() => docApi.archive(doc.id)); docs.refresh(); dash.refresh(); }}>Archive</Button>}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {activeTab === 'acknowledgements' && (
        <Card className="orch-section-card">
          <div className="orch-section-header"><h3>Pending Acknowledgements</h3></div>
          <EmptyState title="Acknowledgements" body="When documents require staff acknowledgement, they will appear here." />
        </Card>
      )}

      {dlg === 'new-doc' && (
        <Dialog open onClose={() => setDlg(null)} title="New Document" footer={<>
          <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
          <Button onClick={async () => {
            await go(() => docApi.create(form));
            setDlg(null); setForm({ document_type: 'clinical_note', category: 'clinical', title: '', description: '', department: '' }); docs.refresh(); dash.refresh();
          }} loading={busy} disabled={!form.title}>Create</Button>
        </>}>
          <div className="orch-form">
            <div className="orch-form-field">
              <label className="orch-label">Category</label>
              <select className="q-input" value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))}>
                {CATS.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <Input label="Document Type" value={form.document_type} onChange={e => setForm(f => ({ ...f, document_type: e.target.value }))} placeholder="e.g. discharge_summary" required />
            <Input label="Title" value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} placeholder="Document title" required />
            <Input label="Description" value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} placeholder="Optional description" />
            <Input label="Department" value={form.department} onChange={e => setForm(f => ({ ...f, department: e.target.value }))} placeholder="e.g. Emergency" />
          </div>
        </Dialog>
      )}
    </div>
  );
}
