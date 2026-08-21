import { useCallback, useEffect, useRef, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import {
  documentCenterApi,
  patientsApi,
  encountersApi,
} from '../api/endpoints';
import type { GeneratedDocument } from '../api/types';
import { Button, Dialog, EmptyState, Spinner, StatusChip } from '../components/ui';
import { PrintPreviewModal } from './PrintPreviewModal';
import {
  FileText,
  Stethoscope,
  Pill,
  ClipboardList,
  Receipt,
  ArrowRight,
  ArrowLeft,
  Check,
  Eye,
  X,
  Search,
} from 'lucide-react';
import './document-wizard.css';

/* ── template definitions ── */
interface DocTemplate {
  type: string;
  label: string;
  category: string;
  description: string;
  icon: typeof FileText;
}

const TEMPLATES: DocTemplate[] = [
  { type: 'discharge_summary', label: 'Discharge Summary', category: 'clinical', description: 'Complete patient discharge documentation with diagnoses, medications, and follow-up instructions', icon: ClipboardList },
  { type: 'prescription', label: 'Prescription', category: 'clinical', description: 'Generate prescription with patient history, allergies, and current medications', icon: Pill },
  { type: 'lab_report', label: 'Laboratory Report', category: 'clinical', description: 'Compile laboratory results with reference ranges and clinical interpretation', icon: Stethoscope },
  { type: 'radiology_report', label: 'Radiology Report', category: 'clinical', description: 'Document imaging study findings with modality and impression', icon: FileText },
  { type: 'consent', label: 'Informed Consent', category: 'compliance', description: 'Patient consent form with procedure description, risks, and signature fields', icon: Receipt },
  { type: 'referral', label: 'Referral Letter', category: 'clinical', description: 'Clinical referral with patient summary and receiving provider details', icon: ArrowRight },
  { type: 'clinical_note', label: 'Clinical Note (SOAP)', category: 'clinical', description: 'Subjective, Objective, Assessment, and Plan documentation', icon: ClipboardList },
];

const CATEGORY_COLORS: Record<string, string> = {
  clinical: 'info',
  compliance: 'warning',
  financial: 'success',
  administrative: 'neutral',
};

/* ── types ── */
interface PrefillField {
  label: string;
  value: string;
}

interface PrefillSection {
  heading: string;
  fields: PrefillField[];
}

interface PrefillData {
  patient: { id: string; fullName: string; mrn: string; dateOfBirth?: string; sex?: string; bloodGroup?: string; phone?: string; email?: string };
  encounter?: { id: string; type?: string; status?: string; providerName?: string; serviceName?: string; chiefComplaint?: string; startedAt?: string } | null;
  prefill: {
    title: string;
    category: string;
    sections: PrefillSection[];
    availableFields: string[];
  };
}

interface DocumentWizardProps {
  open: boolean;
  onClose: () => void;
  onGenerated: (doc: GeneratedDocument) => void;
}

/* ── main wizard ── */
export function DocumentWizard({ open, onClose, onGenerated }: DocumentWizardProps) {
  const { organizationId, selectedFacilityId: facilityId } = useTenant();

  const [step, setStep] = useState(0); // 0=template, 1=patient, 2=encounter, 3=content, 4=preview
  const [selectedTemplate, setSelectedTemplate] = useState<DocTemplate | null>(null);

  // Patient search
  const [patientSearch, setPatientSearch] = useState('');
  const [patientResults, setPatientResults] = useState<Array<{ id: string; fullName: string; mrn: string }>>([]);
  const [selectedPatient, setSelectedPatient] = useState<PrefillData['patient'] | null>(null);
  const [searchingPatient, setSearchingPatient] = useState(false);

  // Encounter search
  const [encounters, setEncounters] = useState<Array<{ id: string; type: string; status: string; providerName: string; startedAt: string }>>([]);
  const [selectedEncounter, setSelectedEncounter] = useState<PrefillData['encounter'] | null>(null);
  const [loadingEncounters, setLoadingEncounters] = useState(false);

  // Prefill content
  const [prefillData, setPrefillData] = useState<PrefillData | null>(null);
  const [title, setTitle] = useState('');
  const [contentHtml, setContentHtml] = useState('');
  const [loadingPrefill, setLoadingPrefill] = useState(false);

  // Generation
  const [generating, setGenerating] = useState(false);
  const [generatedDoc, setGeneratedDoc] = useState<GeneratedDocument | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const searchTimerRef = useRef<ReturnType<typeof setTimeout>>();

  /* ── patient search with debounce ── */
  useEffect(() => {
    if (!patientSearch || patientSearch.length < 2) {
      setPatientResults([]);
      return;
    }
    clearTimeout(searchTimerRef.current);
    searchTimerRef.current = setTimeout(async () => {
      setSearchingPatient(true);
      try {
        const results = await patientsApi.search(patientSearch, facilityId);
        setPatientResults(results.map((r) => ({ id: r.id, fullName: r.fullName, mrn: r.mrn })));
      } catch {
        setPatientResults([]);
      } finally {
        setSearchingPatient(false);
      }
    }, 300);
    return () => clearTimeout(searchTimerRef.current);
  }, [patientSearch, facilityId]);

  /* ── load encounters when patient selected ── */
  useEffect(() => {
    if (!selectedPatient) return;
    setLoadingEncounters(true);
    (async () => {
      try {
        const result = await encountersApi.forPatient(selectedPatient.id, facilityId);
        setEncounters(
          result.map((e: { id: string; type: string; status: string; provider?: { fullName: string }; startedAt?: string }) => ({
            id: e.id,
            type: e.type,
            status: e.status,
            providerName: e.provider?.fullName ?? '',
            startedAt: e.startedAt ?? '',
          })),
        );
      } catch {
        setEncounters([]);
      } finally {
        setLoadingEncounters(false);
      }
    })();
  }, [selectedPatient, facilityId]);

  /* ── reset wizard ── */
  const reset = useCallback(() => {
    setStep(0);
    setSelectedTemplate(null);
    setPatientSearch('');
    setPatientResults([]);
    setSelectedPatient(null);
    setSelectedEncounter(null);
    setPrefillData(null);
    setTitle('');
    setContentHtml('');
    setGeneratedDoc(null);
    setError(null);
  }, []);

  const handleClose = () => {
    reset();
    onClose();
  };

  /* ── step navigation ── */
  const goNext = () => setStep((s) => Math.min(s + 1, 4));
  const goBack = () => setStep((s) => Math.max(s - 1, 0));

  /* ── load prefill data ── */
  const loadPrefill = useCallback(async () => {
    if (!selectedTemplate || !selectedPatient) return;
    setLoadingPrefill(true);
    setError(null);
    try {
      const params = new URLSearchParams({
        documentType: selectedTemplate.type,
        patientId: selectedPatient.id,
      });
      if (selectedEncounter?.id) {
        params.set('encounterId', selectedEncounter.id);
      }
      const result = await fetch(`/api/v1/documents/prefill?${params}`, {
        headers: {
          Authorization: `Bearer ${sessionStorage.getItem('swasthya.accessToken') || ''}`,
          'X-Swasthya-Facility': facilityId || '',
        },
      });
      const json = await result.json();
      const data = (json.data ?? json) as PrefillData;
      setPrefillData(data);
      setTitle(data.prefill?.title ?? `${selectedTemplate.label} — ${selectedPatient.fullName}`);
      // Build initial HTML from sections
      const html = buildInitialHtml(data);
      setContentHtml(html);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load patient data');
    } finally {
      setLoadingPrefill(false);
    }
  }, [selectedTemplate, selectedPatient, selectedEncounter, facilityId]);

  useEffect(() => {
    if (step === 3 && !prefillData && selectedTemplate && selectedPatient) {
      void loadPrefill();
    }
  }, [step, prefillData, selectedTemplate, selectedPatient, loadPrefill]);

  /* ── generate document ── */
  const handleGenerate = async () => {
    if (!organizationId || !selectedTemplate || !selectedPatient) return;
    setGenerating(true);
    setError(null);
    try {
      const doc = await documentCenterApi.generate(organizationId, {
        documentType: selectedTemplate.type,
        category: selectedTemplate.category,
        title,
        contentHtml,
        patientId: selectedPatient.id,
        encounterId: selectedEncounter?.id ?? undefined,
        providerName: selectedEncounter?.providerName ?? '',
        sourceType: 'wizard',
      });
      setGeneratedDoc(doc as unknown as GeneratedDocument);
      goNext();
      onGenerated(doc as unknown as GeneratedDocument);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate document');
    } finally {
      setGenerating(false);
    }
  };



  const canGoNext = (() => {
    if (step === 0) return selectedTemplate !== null;
    if (step === 1) return selectedPatient !== null;
    if (step === 2) return true; // encounter is optional
    if (step === 3) return title.trim().length > 0 && contentHtml.trim().length > 0;
    return false;
  })();

  return (
    <Dialog open={open} onClose={handleClose} title={step === 4 && generatedDoc ? 'Document Generated' : `Generate Document — Step ${step + 1} of 4`}>
      <div className="dw-wizard">
        {/* Step indicator */}
        <div className="dw-steps">
          {['Template', 'Patient', 'Encounter', 'Content', 'Done'].map((label, i) => (
            <div key={label} className={`dw-step ${i === step ? 'dw-step--active' : ''} ${i < step ? 'dw-step--done' : ''}`}>
              <div className="dw-step__dot">{i < step ? <Check size={12} /> : i + 1}</div>
              <span className="dw-step__label">{label}</span>
            </div>
          ))}
        </div>

        {error && <div className="dw-error">{error}</div>}

        {/* Step 0: Template picker */}
        {step === 0 && (
          <div className="dw-template-grid">
            {TEMPLATES.map((tmpl) => (
              <button
                key={tmpl.type}
                className={`dw-template-card ${selectedTemplate?.type === tmpl.type ? 'dw-template-card--selected' : ''}`}
                onClick={() => setSelectedTemplate(tmpl)}
                type="button"
              >
                <div className="dw-template-card__icon">
                  <tmpl.icon size={24} />
                </div>
                <div className="dw-template-card__content">
                  <div className="dw-template-card__header">
                    <strong>{tmpl.label}</strong>
                    <StatusChip tone={CATEGORY_COLORS[tmpl.category] as 'info' | 'warning' | 'success' | 'neutral' | 'danger'} label={tmpl.category} />
                  </div>
                  <p className="dw-template-card__desc">{tmpl.description}</p>
                </div>
              </button>
            ))}
          </div>
        )}

        {/* Step 1: Patient search */}
        {step === 1 && (
          <div className="dw-patient-search">
            <div className="dw-search-input-wrap">
              <Search size={16} />
              <input
                className="dw-search-input"
                placeholder="Search patient by name or MRN…"
                value={patientSearch}
                onChange={(e) => setPatientSearch(e.target.value)}
                autoFocus
              />
              {searchingPatient && <Spinner label="" />}
            </div>
            {selectedPatient ? (
              <div className="dw-selected-patient">
                <div>
                  <strong>{selectedPatient.fullName}</strong>
                  <span className="text-muted"> {selectedPatient.mrn}</span>
                </div>
                <Button size="sm" variant="ghost" onClick={() => { setSelectedPatient(null); setPatientSearch(''); }}>Change</Button>
              </div>
            ) : patientResults.length > 0 ? (
              <div className="dw-patient-results">
                {patientResults.map((p) => (
                  <button
                    key={p.id}
                    className="dw-patient-result"
                    onClick={() => { setSelectedPatient(p); setPatientResults([]); setPatientSearch(''); }}
                    type="button"
                  >
                    <strong>{p.fullName}</strong>
                    <span className="text-muted">{p.mrn}</span>
                  </button>
                ))}
              </div>
            ) : patientSearch.length >= 2 ? (
              <EmptyState title="No patients found" body="Try a different search term" />
            ) : (
              <p className="dw-hint">Type at least 2 characters to search</p>
            )}
          </div>
        )}

        {/* Step 2: Encounter (optional) */}
        {step === 2 && (
          <div className="dw-encounter-select">
            <p className="dw-hint">Select an encounter to pre-fill clinical data, or skip to create a blank document.</p>
            {loadingEncounters ? (
              <Spinner label="Loading encounters…" />
            ) : encounters.length === 0 ? (
              <EmptyState title="No encounters found" body="This patient has no recorded encounters. Proceeding with blank document." />
            ) : (
              <div className="dw-encounter-list">
                {encounters.map((enc) => (
                  <button
                    key={enc.id}
                    className={`dw-encounter-card ${selectedEncounter?.id === enc.id ? 'dw-encounter-card--selected' : ''}`}
                    onClick={() => setSelectedEncounter(selectedEncounter?.id === enc.id ? null : enc)}
                    type="button"
                  >
                    <div className="dw-encounter-card__info">
                      <strong>{enc.type}</strong>
                      <span className="text-muted">{enc.providerName}</span>
                    </div>
                    <StatusChip tone={enc.status === 'completed' ? 'success' : 'info'} label={enc.status} />
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Step 3: Content editor */}
        {step === 3 && (
          <div className="dw-content-editor">
            {loadingPrefill ? (
              <Spinner label="Loading pre-fill data…" />
            ) : (
              <>
                <div className="dw-field">
                  <label className="dw-field__label">Document Title</label>
                  <input
                    className="dw-input"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Document title…"
                  />
                </div>
                <div className="dw-field">
                  <label className="dw-field__label">Content (HTML)</label>
                  <textarea
                    className="dw-textarea"
                    value={contentHtml}
                    onChange={(e) => setContentHtml(e.target.value)}
                    rows={16}
                    placeholder="<p>Patient clinical content…</p>"
                  />
                </div>
                {prefillData?.prefill?.sections && (
                  <details className="dw-prefill-sections">
                    <summary>Pre-filled data ({prefillData.prefill.sections.length} sections)</summary>
                    {prefillData.prefill.sections.map((section) => (
                      <div key={section.heading} className="dw-prefill-section">
                        <h4>{section.heading}</h4>
                        <table className="dw-prefill-table">
                          <tbody>
                            {section.fields.map((field, i) => (
                              <tr key={i}>
                                <td className="dw-prefill-table__label">{field.label}</td>
                                <td className="dw-prefill-table__value">{field.value || '—'}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    ))}
                  </details>
                )}
              </>
            )}
          </div>
        )}

        {/* Step 4: Success / preview */}
        {step === 4 && generatedDoc && (
          <div className="dw-success">
            <div className="dw-success__header">
              <Check size={32} className="dw-success__icon" />
              <div>
                <h3>Document Generated</h3>
                <p className="text-muted">{generatedDoc.documentNumber} — {generatedDoc.title}</p>
              </div>
            </div>
            <div className="dw-success__actions">
              <Button variant="primary" onClick={() => setPreviewOpen(true)}>
                <Eye size={16} /> Preview Document
              </Button>
              <Button variant="ghost" onClick={handleClose}>
                <X size={16} /> Close
              </Button>
            </div>
          </div>
        )}

        {/* Print preview modal for generated document */}
        <PrintPreviewModal
          open={previewOpen}
          html={generatedDoc?.contentHtml ?? ''}
          title={generatedDoc?.title}
          documentNumber={generatedDoc?.documentNumber}
          documentId={generatedDoc?.id}
          hasPdf={!!generatedDoc?.hasPdf}
          status={generatedDoc?.status}
          onClose={() => setPreviewOpen(false)}
        />

        {/* Navigation */}
        {step < 4 && (
          <div className="dw-nav">
            {step > 0 && (
              <Button variant="ghost" onClick={goBack}>
                <ArrowLeft size={16} /> Back
              </Button>
            )}
            <div className="dw-nav__spacer" />
            <Button variant="ghost" onClick={handleClose}>Cancel</Button>
            {step < 3 ? (
              <Button variant="primary" disabled={!canGoNext} onClick={goNext}>
                Next <ArrowRight size={16} />
              </Button>
            ) : (
              <Button variant="primary" disabled={!canGoNext || generating} onClick={handleGenerate}>
                {generating ? 'Generating…' : 'Generate Document'}
              </Button>
            )}
          </div>
        )}
      </div>
    </Dialog>
  );
}

/* ── helpers ── */
function buildInitialHtml(data: PrefillData): string {
  if (!data.prefill?.sections) return '';
  return data.prefill.sections
    .map((section) => {
      const rows = section.fields
        .map((f) => `  <tr><td style="padding:4px 8px;font-weight:600;white-space:nowrap;color:#475569;">${f.label}</td><td style="padding:4px 8px;">${f.value || ''}</td></tr>`)
        .join('\n');
      return `<h3 style="margin:16px 0 8px;font-size:14px;color:#0f172a;">${section.heading}</h3>\n<table style="width:100%;border-collapse:collapse;font-size:13px;">\n${rows}\n</table>`;
    })
    .join('\n\n');
}

export default DocumentWizard;
