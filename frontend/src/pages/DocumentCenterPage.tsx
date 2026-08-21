import { useState, useEffect, useCallback } from 'react';
import { useTenant } from '../context/TenantContext';
import { documentCenterApi } from '../api/endpoints';
import type { GeneratedDocument } from '../api/types';
import { Button, EmptyState, ErrorState, StatusChip } from '../components/ui';
import { useI18n } from '../i18n/I18nProvider';
import { DocumentWizard } from '../components/DocumentWizard';
import { PrintPreviewModal } from '../components/PrintPreviewModal';
import {
  FileText,
  CheckCircle,
  Pen,
  Share2,
  Search,
  BarChart3,
  Clock,
  Shield,
  Eye,
  RefreshCw,
  X,
  Plus,
} from 'lucide-react';
import './document-center.css';

type CategoryFilter = 'all' | 'clinical' | 'financial' | 'administrative' | 'operational' | 'compliance';

const CATEGORY_ICONS: Record<string, typeof FileText> = {
  clinical: FileText,
  financial: BarChart3,
  administrative: Shield,
  operational: Clock,
  compliance: Shield,
};

const TYPE_LABELS: Record<string, string> = {
  lab_report: 'Laboratory Report',
  radiology_report: 'Radiology Report',
  discharge_summary: 'Discharge Summary',
  invoice: 'Invoice',
  receipt: 'Receipt',
  prescription: 'Prescription',
  referral: 'Referral',
  consent: 'Consent Form',
  form: 'Form',
  clinical_note: 'Clinical Note',
  other: 'Other',
};

export function DocumentCenterPage() {
  const { organizationId } = useTenant();
  const { t } = useI18n();

  const [documents, setDocuments] = useState<GeneratedDocument[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedDoc, setSelectedDoc] = useState<GeneratedDocument | null>(null);
  const [stats, setStats] = useState<Record<string, unknown> | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [wizardOpen, setWizardOpen] = useState(false);

  const fetchDocuments = useCallback(async () => {
    if (!organizationId) return;
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = {};
      if (categoryFilter !== 'all') params.category = categoryFilter;
      if (searchQuery.trim()) params.search = searchQuery.trim();
      const res = await documentCenterApi.list(organizationId, params);
      const data = res as unknown as { data: GeneratedDocument[]; total: number; page: number; lastPage: number };
      setDocuments(data.data ?? []);
      setTotal(data.total ?? 0);
      setPage(data.page ?? 1);
      setLastPage(data.lastPage ?? 1);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load documents');
    } finally {
      setLoading(false);
    }
  }, [organizationId, categoryFilter, searchQuery]);

  const fetchStats = useCallback(async () => {
    if (!organizationId) return;
    try {
      const res = await documentCenterApi.stats(organizationId);
      setStats(res as unknown as Record<string, unknown>);
    } catch {
      // Stats are optional
    }
  }, [organizationId]);

  useEffect(() => {
    fetchDocuments();
    fetchStats();
  }, [fetchDocuments, fetchStats]);

  const handlePreview = async (doc: GeneratedDocument) => {
    try {
      const res = await documentCenterApi.show(doc.id);
      setSelectedDoc(res as unknown as GeneratedDocument);
      setPreviewOpen(true);
    } catch {
      setSelectedDoc(doc);
      setPreviewOpen(true);
    }
  };

  const handleVerify = async (doc: GeneratedDocument) => {
    try {
      await documentCenterApi.verify(doc.id);
      fetchDocuments();
      fetchStats();
    } catch {
      // handled
    }
  };

  const handleSign = async (doc: GeneratedDocument) => {
    try {
      await documentCenterApi.sign(doc.id);
      fetchDocuments();
      fetchStats();
    } catch {
      // handled
    }
  };

  const handleShare = async (doc: GeneratedDocument) => {
    try {
      await documentCenterApi.share(doc.id);
      fetchDocuments();
    } catch {
      // handled
    }
  };



  const categoryCounts = stats && typeof stats === 'object' && stats.byCategory
    ? stats.byCategory as Record<string, number>
    : {};

  const categories: CategoryFilter[] = ['all', 'clinical', 'financial', 'administrative', 'operational', 'compliance'];

  if (error && documents.length === 0) {
    return (
      <div className="dc-page">
        <ErrorState error={error} onRetry={fetchDocuments} />
      </div>
    );
  }

  return (
    <div className="dc-page">
      {/* Header */}
      <header className="dc-header">
        <div className="dc-header__title">
          <FileText size={24} />
          <div>
            <h1>{t('nav.documentCenter') || 'Document Center'}</h1>
            <p className="dc-header__subtitle">
              Browse, generate, verify, and manage all hospital documents
            </p>
          </div>
        </div>
        <div className="dc-header__actions">
          <Button onClick={() => setWizardOpen(true)} variant="primary" size="sm">
            <Plus size={16} /> Generate Document
          </Button>
          <Button onClick={fetchDocuments} variant="ghost" size="sm">
            <RefreshCw size={16} />
            Refresh
          </Button>
        </div>
      </header>

      {/* Stats row */}
      {stats && (
        <div className="dc-stats">
          <div className="dc-stat-card">
            <div className="dc-stat-card__value">{(stats.total as number) ?? 0}</div>
            <div className="dc-stat-card__label">Total Documents</div>
          </div>
          <div className="dc-stat-card dc-stat-card--verified">
            <div className="dc-stat-card__value">{(stats.verified as number) ?? 0}</div>
            <div className="dc-stat-card__label">Verified</div>
          </div>
          <div className="dc-stat-card dc-stat-card--signed">
            <div className="dc-stat-card__value">{(stats.signed as number) ?? 0}</div>
            <div className="dc-stat-card__label">Signed</div>
          </div>
          <div className="dc-stat-card dc-stat-card--shared">
            <div className="dc-stat-card__value">{(stats.sharedWithPatient as number) ?? 0}</div>
            <div className="dc-stat-card__label">Shared with Patient</div>
          </div>
          <div className="dc-stat-card">
            <div className="dc-stat-card__value">{(stats.recent7Days as number) ?? 0}</div>
            <div className="dc-stat-card__label">Last 7 Days</div>
          </div>
        </div>
      )}

      {/* Category tabs */}
      <div className="dc-categories">
        {categories.map((cat) => {
          const Icon = cat === 'all' ? FileText : CATEGORY_ICONS[cat] ?? FileText;
          const count = cat === 'all' ? total : (categoryCounts[cat] ?? 0);
          return (
            <button
              key={cat}
              className={`dc-category-tab ${categoryFilter === cat ? 'dc-category-tab--active' : ''}`}
              onClick={() => { setCategoryFilter(cat); setPage(1); }}
            >
              <Icon size={16} />
              <span>{cat === 'all' ? 'All' : cat.charAt(0).toUpperCase() + cat.slice(1)}</span>
              {count > 0 && <span className="dc-category-tab__count">{count}</span>}
            </button>
          );
        })}
      </div>

      {/* Search */}
      <div className="dc-search">
        <Search size={18} />
        <input
          type="text"
          placeholder="Search by title, document number, or content..."
          value={searchQuery}
          onChange={(e) => { setSearchQuery(e.target.value); setPage(1); }}
          onKeyDown={(e) => { if (e.key === 'Enter') fetchDocuments(); }}
        />
        {searchQuery && (
          <button className="dc-search__clear" onClick={() => { setSearchQuery(''); setPage(1); }}>
            <X size={14} />
          </button>
        )}
      </div>

      {/* Document list */}
      <div className="dc-content">
        {loading ? (
          <div className="dc-loading">
            <div className="dc-loading__spinner" />
            <p>Loading documents...</p>
          </div>
        ) : documents.length === 0 ? (
          <EmptyState
            title="No Documents Found"
            body={searchQuery ? 'Try adjusting your search or filter criteria.' : 'Documents generated from clinical, financial, and administrative workflows will appear here.'}
          />
        ) : (
          <div className="dc-doc-list">
            {documents.map((doc) => {
              const TypeIcon = CATEGORY_ICONS[doc.category] ?? FileText;
              const toneMap: Record<string, 'info' | 'success' | 'neutral' | 'danger' | 'warning'> = {
                generated: 'info',
                verified: 'success',
                final: 'success',
                archived: 'neutral',
                cancelled: 'danger',
              };
              return (
                <div key={doc.id} className="dc-doc-row">
                  <div className="dc-doc-row__icon">
                    <TypeIcon size={20} />
                  </div>
                  <div className="dc-doc-row__info">
                    <div className="dc-doc-row__title">{doc.title}</div>
                    <div className="dc-doc-row__meta">
                      <span className="dc-doc-row__number">{doc.documentNumber}</span>
                      <span className="dc-doc-row__type">{TYPE_LABELS[doc.documentType] ?? doc.documentType}</span>
                      {doc.patientName && (
                        <span className="dc-doc-row__patient">{doc.patientName}{doc.patientMrn ? ` (${doc.patientMrn})` : ''}</span>
                      )}
                      {doc.providerName && (
                        <span className="dc-doc-row__provider">Dr. {doc.providerName}</span>
                      )}
                    </div>
                  </div>
                  <div className="dc-doc-row__badges">
                    <StatusChip tone={toneMap[doc.status] ?? 'neutral'} label={doc.status} />
                    {doc.verified && (
                      <span className="dc-badge dc-badge--verified">
                        <CheckCircle size={12} /> Verified
                      </span>
                    )}
                    {doc.signed && (
                      <span className="dc-badge dc-badge--signed">
                        <Pen size={12} /> Signed
                      </span>
                    )}
                    {doc.sharedWithPatient && (
                      <span className="dc-badge dc-badge--shared">
                        <Share2 size={12} /> Shared
                      </span>
                    )}
                  </div>
                  <div className="dc-doc-row__actions">
                    <Button onClick={() => handlePreview(doc)} variant="ghost" size="sm" title="Preview">
                      <Eye size={16} />
                    </Button>
                    {!doc.verified && (
                      <Button onClick={() => handleVerify(doc)} variant="ghost" size="sm" title="Verify">
                        <CheckCircle size={16} />
                      </Button>
                    )}
                    {!doc.signed && doc.verified && (
                      <Button onClick={() => handleSign(doc)} variant="ghost" size="sm" title="Sign">
                        <Pen size={16} />
                      </Button>
                    )}
                    {!doc.sharedWithPatient && doc.patientId && (
                      <Button onClick={() => handleShare(doc)} variant="ghost" size="sm" title="Share with Patient">
                        <Share2 size={16} />
                      </Button>
                    )}
                  </div>
                  <div className="dc-doc-row__date">
                    {new Date(doc.createdAt).toLocaleDateString()}
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="dc-pagination">
            <Button onClick={() => setPage(Math.max(1, page - 1))} disabled={page <= 1} variant="ghost" size="sm">
              Previous
            </Button>
            <span className="dc-pagination__info">
              Page {page} of {lastPage} ({total} documents)
            </span>
            <Button onClick={() => setPage(Math.min(lastPage, page + 1))} disabled={page >= lastPage} variant="ghost" size="sm">
              Next
            </Button>
          </div>
        )}
      </div>

      {/* Print Preview Modal */}
      <PrintPreviewModal
        open={previewOpen}
        html={selectedDoc?.contentHtml ?? ''}
        title={selectedDoc?.title}
        documentNumber={selectedDoc?.documentNumber}
        status={selectedDoc?.status}
        onClose={() => { setPreviewOpen(false); setSelectedDoc(null); }}
        showVerify={!!selectedDoc && !selectedDoc.verified}
        onVerify={selectedDoc ? () => handleVerify(selectedDoc) : undefined}
        showSign={!!selectedDoc && !selectedDoc.signed && !!selectedDoc.verified}
        onSign={selectedDoc ? () => handleSign(selectedDoc) : undefined}
      />

      {/* Document Generation Wizard */}
      <DocumentWizard
        open={wizardOpen}
        onClose={() => setWizardOpen(false)}
        onGenerated={() => {
          setWizardOpen(false);
          fetchDocuments();
          fetchStats();
        }}
      />
    </div>
  );
}
