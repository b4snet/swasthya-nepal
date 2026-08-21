import { useMemo, useState } from 'react';
import { api } from '../api/client';
import { useI18n } from '../i18n/I18nProvider';
import { useFetch } from '../hooks/useFetch';
import { Button, Card, EmptyState, Spinner } from '../components/ui';

/* ── Types ── */
interface FormTemplate {
  id: string;
  code: string;
  name: string;
  slug: string;
  description: string | null;
  category: string;
  subcategory: string | null;
  module: string | null;
  department: string | null;
  specialty: string | null;
  workflow: string | null;
  version: number;
  is_active: boolean;
  is_published: boolean;
  printable: boolean;
  pdf_capable: boolean;
  linked_to_patient: boolean;
  linked_to_encounter: boolean;
  linked_to_admission: boolean;
  generates_document_number: boolean;
  document_number_prefix: string | null;
  allowed_roles: string[] | null;
  schema: {
    sections?: Array<{
      title: string;
      fields: Array<{
        key: string;
        label: string;
        type: string;
        required?: boolean;
        options?: string[];
      }>;
    }>;
  };
}

interface FormSubmission {
  id: string;
  template_id: string;
  document_number: string | null;
  status: string;
  submitted_at: string | null;
  created_at: string;
  template?: { name: string; code: string; category: string };
}

/* ── Category config ── */
const CATEGORIES: Record<string, { label: string; color: string; bg: string }> = {
  registration: { label: 'Registration', color: '#2e90fa', bg: '#eff8ff' },
  clinical: { label: 'Clinical', color: '#0f766e', bg: '#f0fdfa' },
  consent: { label: 'Consent', color: '#7c3aed', bg: '#f5f3ff' },
  specialty: { label: 'Specialty', color: '#d946ef', bg: '#fdf4ff' },
  pediatric: { label: 'Pediatric', color: '#f59e0b', bg: '#fffbeb' },
  mental_health: { label: 'Mental Health', color: '#6366f1', bg: '#eef2ff' },
  nutrition: { label: 'Nutrition', color: '#10b981', bg: '#ecfdf5' },
  dental: { label: 'Dental', color: '#f97316', bg: '#fff7ed' },
  imaging: { label: 'Imaging', color: '#0891b2', bg: '#ecfeff' },
  laboratory: { label: 'Laboratory', color: '#059669', bg: '#ecfdf5' },
  admission: { label: 'Admission', color: '#dc2626', bg: '#fef2f2' },
  icu: { label: 'ICU', color: '#be123c', bg: '#fff1f2' },
  pharmacy: { label: 'Pharmacy', color: '#16a34a', bg: '#f0fdf4' },
  referral: { label: 'Referral', color: '#2563eb', bg: '#eff6ff' },
  insurance: { label: 'Insurance', color: '#7c3aed', bg: '#f5f3ff' },
  telemedicine: { label: 'Telemedicine', color: '#0d9488', bg: '#f0fdfa' },
  nursing: { label: 'Nursing', color: '#e11d48', bg: '#fff1f2' },
  wellness: { label: 'Wellness', color: '#a3e635', bg: '#f7fee7' },
};

const STATUS_STYLES: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#667085', bg: '#f9fafb' },
  submitted: { label: 'Submitted', color: '#2e90fa', bg: '#eff8ff' },
  verified: { label: 'Verified', color: '#0f766e', bg: '#f0fdfa' },
  approved: { label: 'Approved', color: '#12b76a', bg: '#ecfdf3' },
  rejected: { label: 'Rejected', color: '#f04438', bg: '#fef3f2' },
  cancelled: { label: 'Cancelled', color: '#667085', bg: '#f9fafb' },
  signed: { label: 'Signed', color: '#0f766e', bg: '#f0fdfa' },
};

/* ── Helper: chip ── */
function Chip({ color, bg, children }: { color: string; bg: string; children: React.ReactNode }) {
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        padding: '2px 8px',
        borderRadius: 9999,
        fontSize: 11,
        fontWeight: 600,
        color,
        background: bg,
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </span>
  );
}

/* ══════════════════════════════════════════════════════════════════
   MAIN PAGE
   ══════════════════════════════════════════════════════════════════ */

export function FormsPage() {
  const { t } = useI18n();

  /* ── State ── */
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<string>('');
  const [view, setView] = useState<'library' | 'submissions'>('library');
  const [selectedTemplate, setSelectedTemplate] = useState<FormTemplate | null>(null);
  const [formData, setFormData] = useState<Record<string, unknown>>({});
  const [submitting, setSubmitting] = useState(false);

  /* ── Data ── */
  const templatesUrl = useMemo(
    () => `/api/v1/forms/templates?active_only=true${categoryFilter ? `&category=${categoryFilter}` : ''}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    [categoryFilter, search],
  );
  const { data: templatesData, loading } = useFetch<{ data: FormTemplate[] }>(
    () => api.request<{ data: FormTemplate[] }>(templatesUrl),
    [templatesUrl],
  );

  const { data: submissionsData, loading: subsLoading } = useFetch<{ data: FormSubmission[] }>(
    () => view === 'submissions'
      ? api.request<{ data: FormSubmission[] }>('/api/v1/forms/submissions?per_page=50')
      : Promise.resolve({ data: [] }),
    [view],
  );

  const templates = useMemo(() => templatesData?.data ?? [], [templatesData]);
  const submissions = useMemo(() => submissionsData?.data ?? [], [submissionsData]);

  /* ── Submit form ── */
  const handleSubmit = async () => {
    if (!selectedTemplate) return;
    setSubmitting(true);
    try {
      await api.request('/api/v1/forms/submissions', {
        method: 'POST',
        body: { template_id: selectedTemplate.id, data: formData },
      });
      setSelectedTemplate(null);
      setFormData({});
    } catch {
      // error handled by toast
    } finally {
      setSubmitting(false);
    }
  };

  /* ════════════════════════════════════════════════════════════════
     RENDER
     ════════════════════════════════════════════════════════════════ */

  return (
    <div className="page page-transition" style={{ maxWidth: 1200, margin: '0 auto' }}>
      {/* ── Header ── */}
      <div className="page__head">
        <div className="page__title">
          <h1>{t('nav.forms') ?? 'Form Library'}</h1>
          <span className="page__sub">
            {templates.length} templates · {submissions.length} submissions
          </span>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Button
            variant={view === 'library' ? 'primary' : 'secondary'}
            onClick={() => setView('library')}
          >
            Library
          </Button>
          <Button
            variant={view === 'submissions' ? 'primary' : 'secondary'}
            onClick={() => setView('submissions')}
          >
            Submissions
          </Button>
        </div>
      </div>

      {/* ── Library View ── */}
      {view === 'library' && (
        <>
          {/* ── Search & Filters ── */}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            <input
              type="text"
              placeholder="Search forms..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="input"
              style={{ flex: 1, minWidth: 200 }}
            />
            <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
              className="input"
              style={{ width: 180 }}
            >
              <option value="">All Categories</option>
              {Object.entries(CATEGORIES).map(([key, cat]) => (
                <option key={key} value={key}>{cat.label}</option>
              ))}
            </select>
          </div>

          {/* ── Category Pills ── */}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {Object.entries(CATEGORIES).map(([key, cat]) => (
              <button
                key={key}
                onClick={() => setCategoryFilter(categoryFilter === key ? '' : key)}
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 4,
                  padding: '4px 12px',
                  border: `1px solid ${categoryFilter === key ? cat.color : 'var(--border-subtle)'}`,
                  borderRadius: 9999,
                  background: categoryFilter === key ? cat.bg : 'transparent',
                  color: categoryFilter === key ? cat.color : 'var(--text-secondary)',
                  fontSize: 12,
                  fontWeight: 600,
                  cursor: 'pointer',
                  transition: 'all 120ms',
                }}
              >
                {cat.label}
              </button>
            ))}
          </div>

          {/* ── Loading ── */}
          {loading && (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}>
              <Spinner />
            </div>
          )}

          {/* ── Empty ── */}
          {!loading && templates.length === 0 && (
            <EmptyState title="No forms found" body="No form templates match your search criteria." />
          )}

          {/* ── Template Grid ── */}
          {!loading && templates.length > 0 && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 16 }}>
              {templates.map((tpl) => {
                const cat = CATEGORIES[tpl.category] ?? { label: tpl.category, color: '#667085', bg: '#f9fafb' };
                return (
                  <Card
                    key={tpl.id}
                    className="card--clickable"
                    onClick={() => { setSelectedTemplate(tpl); setFormData({}); }}
                  >
                    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 8 }}>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-primary)' }}>
                          {tpl.name}
                        </span>
                        <span style={{ fontSize: 11, color: 'var(--text-tertiary)' }}>
                          {tpl.code} · v{tpl.version}
                        </span>
                      </div>
                      <Chip color={cat.color} bg={cat.bg}>{cat.label}</Chip>
                    </div>

                    {tpl.description && (
                      <p style={{ fontSize: 13, color: 'var(--text-secondary)', lineHeight: 1.5, margin: '0 0 8px' }}>
                        {tpl.description.length > 100 ? tpl.description.slice(0, 100) + '…' : tpl.description}
                      </p>
                    )}

                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginTop: 'auto' }}>
                      {tpl.module && <Chip color="#667085" bg="#f9fafb">{tpl.module}</Chip>}
                      {tpl.specialty && <Chip color="#667085" bg="#f9fafb">{tpl.specialty}</Chip>}
                      {tpl.generates_document_number && <Chip color="#0f766e" bg="#f0fdfa">#{tpl.document_number_prefix}</Chip>}
                      {tpl.printable && <Chip color="#667085" bg="#f9fafb">Print</Chip>}
                      {tpl.linked_to_encounter && <Chip color="#2e90fa" bg="#eff8ff">Encounter</Chip>}
                    </div>
                  </Card>
                );
              })}
            </div>
          )}
        </>
      )}

      {/* ── Submissions View ── */}
      {view === 'submissions' && (
        <>
          {subsLoading && (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}>
              <Spinner />
            </div>
          )}

          {!subsLoading && submissions.length === 0 && (
            <EmptyState title="No submissions yet" body="Submit a form from the library to see it here." />
          )}

          {!subsLoading && submissions.length > 0 && (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Document #</th>
                  <th>Form</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {submissions.map((sub) => {
                  const statusStyle = STATUS_STYLES[sub.status] ?? STATUS_STYLES.draft;
                  return (
                    <tr key={sub.id}>
                      <td style={{ fontWeight: 600, fontFamily: 'var(--font-mono)', fontSize: 13 }}>
                        {sub.document_number ?? '—'}
                      </td>
                      <td>{sub.template?.name ?? '—'}</td>
                      <td>
                        {sub.template?.category && (
                          <Chip
                            color={CATEGORIES[sub.template.category]?.color ?? '#667085'}
                            bg={CATEGORIES[sub.template.category]?.bg ?? '#f9fafb'}
                          >
                            {CATEGORIES[sub.template.category]?.label ?? sub.template.category}
                          </Chip>
                        )}
                      </td>
                      <td>
                        <Chip color={statusStyle.color} bg={statusStyle.bg}>
                          {statusStyle.label}
                        </Chip>
                      </td>
                      <td style={{ fontSize: 13, color: 'var(--text-tertiary)' }}>
                        {sub.created_at ? new Date(sub.created_at).toLocaleDateString() : '—'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </>
      )}

      {/* ═══════════════════════════════════════════════════════════
          FORM FILL DIALOG
          ═══════════════════════════════════════════════════════════ */}
      {selectedTemplate && (
        <div
          className="dialog-backdrop"
          onClick={(e) => { if (e.target === e.currentTarget) setSelectedTemplate(null); }}
        >
          <div className="dialog" style={{ maxWidth: 640 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h3 className="dialog__title">{selectedTemplate.name}</h3>
                <p style={{ fontSize: 13, color: 'var(--text-tertiary)', margin: '4px 0 0' }}>
                  {selectedTemplate.code} · v{selectedTemplate.version}
                </p>
              </div>
              <Chip
                color={CATEGORIES[selectedTemplate.category]?.color ?? '#667085'}
                bg={CATEGORIES[selectedTemplate.category]?.bg ?? '#f9fafb'}
              >
                {CATEGORIES[selectedTemplate.category]?.label ?? selectedTemplate.category}
              </Chip>
            </div>

            {selectedTemplate.description && (
              <p style={{ fontSize: 14, color: 'var(--text-secondary)', lineHeight: 1.5 }}>
                {selectedTemplate.description}
              </p>
            )}

            {/* ── Form Fields ── */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxHeight: '50vh', overflowY: 'auto', padding: '4px 0' }}>
              {selectedTemplate.schema?.sections?.map((section, si) => (
                <div key={si}>
                  <h4 style={{ fontSize: 14, fontWeight: 600, color: 'var(--text-primary)', marginBottom: 8 }}>
                    {section.title}
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {section.fields.map((field) => (
                      <div key={field.key} className="field">
                        <label className="field__label">
                          {field.label}
                          {field.required && <span className="field__required">*</span>}
                        </label>
                        {field.type === 'textarea' ? (
                          <textarea
                            className="input input--area"
                            value={String(formData[field.key] ?? '')}
                            onChange={(e) => setFormData({ ...formData, [field.key]: e.target.value })}
                            rows={3}
                          />
                        ) : field.type === 'select' ? (
                          <select
                            className="input"
                            value={String(formData[field.key] ?? '')}
                            onChange={(e) => setFormData({ ...formData, [field.key]: e.target.value })}
                          >
                            <option value="">Select...</option>
                            {field.options?.map((opt) => (
                              <option key={opt} value={opt}>{opt}</option>
                            ))}
                          </select>
                        ) : field.type === 'signature' ? (
                          <div style={{
                            height: 80,
                            border: '2px dashed var(--border-default)',
                            borderRadius: 'var(--radius-md)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: 'var(--text-tertiary)',
                            fontSize: 13,
                          }}>
                            Signature area
                          </div>
                        ) : (
                          <input
                            className="input"
                            type={field.type === 'date' ? 'date' : field.type === 'number' ? 'number' : field.type === 'datetime' ? 'datetime-local' : 'text'}
                            value={String(formData[field.key] ?? '')}
                            onChange={(e) => setFormData({ ...formData, [field.key]: e.target.value })}
                            placeholder={field.label}
                          />
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            <div className="dialog__footer">
              <Button variant="secondary" onClick={() => setSelectedTemplate(null)}>
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={handleSubmit}
                disabled={submitting}
              >
                {submitting ? 'Submitting...' : 'Submit Form'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default FormsPage;
