import { useState, useRef } from 'react';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { Alert, Button, Card, StatusChip } from '../components/ui';
import { ApiError } from '../api/client';
import './patient-import.css';

type Step = 'upload' | 'mapping' | 'preview' | 'result';

const EXPECTED_FIELDS: Record<string, { label: string; required: boolean }> = {
  full_name: { label: 'Full Name', required: true },
  date_of_birth: { label: 'Date of Birth', required: true },
  sex: { label: 'Sex', required: true },
  blood_group: { label: 'Blood Group', required: false },
  phone: { label: 'Phone', required: false },
  email: { label: 'Email', required: false },
  national_id: { label: 'National ID', required: false },
  passport: { label: 'Passport', required: false },
  address_line1: { label: 'Address', required: false },
  city: { label: 'City', required: false },
  state: { label: 'State', required: false },
  emergency_contact_name: { label: 'Emergency Contact Name', required: false },
  emergency_contact_phone: { label: 'Emergency Contact Phone', required: false },
  emergency_contact_relation: { label: 'Emergency Contact Relation', required: false },
};

export function PatientImportPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const [step, setStep] = useState<Step>('upload');
  const [importId, setImportId] = useState<string | null>(null);
  const [csvHeaders, setCsvHeaders] = useState<string[]>([]);
  const [totalRows, setTotalRows] = useState(0);
  const [fieldMapping, setFieldMapping] = useState<Record<string, string>>({});
  const [previewData, setPreviewData] = useState<{ totalRows: number; validRows: number; errorRows: number; preview: Array<Record<string, unknown>>; errorSummary: Array<Record<string, unknown>> } | null>(null);
  const [importResult, setImportResult] = useState<{ success: number; errors: number; errorDetails: Array<Record<string, unknown>> } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // Auto-download template
  const downloadTemplate = async () => {
    if (!organizationId) return;
    try {
      const res = await patientsApi.importTemplate(organizationId);
      const data = res as unknown as { csv: string; fileName: string };
      const blob = new Blob([data.csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = data.fileName || 'patient-import-template.csv';
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to download template');
    }
  };

  // Upload CSV
  const handleUpload = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file || !organizationId) return;
    setUploading(true);
    setError(null);
    try {
      const res = await patientsApi.importUpload(organizationId, file, selectedFacilityId);
      const data = res as unknown as { importId: string; headers: string[]; totalRows: number };
      setImportId(data.importId);
      setCsvHeaders(data.headers);
      setTotalRows(data.totalRows);
      // Auto-map: match CSV headers to known fields
      const autoMap: Record<string, string> = {};
      for (const h of data.headers) {
        const normalized = h.toLowerCase().trim().replace(/[\s-]+/g, '_');
        if (EXPECTED_FIELDS[normalized]) {
          autoMap[h] = normalized;
        }
      }
      setFieldMapping(autoMap);
      setStep('mapping');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to upload file');
    } finally {
      setUploading(false);
    }
  };

  // Save mapping and preview
  const handlePreview = async () => {
    if (!importId) return;
    setLoading(true);
    setError(null);
    try {
      await patientsApi.importMapping(importId, fieldMapping);
      const res = await patientsApi.importPreview(importId);
      setPreviewData(res as unknown as typeof previewData);
      setStep('preview');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to preview import');
    } finally {
      setLoading(false);
    }
  };

  // Execute import
  const handleImport = async () => {
    if (!importId) return;
    setLoading(true);
    setError(null);
    try {
      const res = await patientsApi.importExecute(importId);
      setImportResult(res as unknown as typeof importResult);
      setStep('result');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to import patients');
    } finally {
      setLoading(false);
    }
  };

  const steps: { key: Step; label: string; num: number }[] = [
    { key: 'upload', label: 'Upload', num: 1 },
    { key: 'mapping', label: 'Map Fields', num: 2 },
    { key: 'preview', label: 'Preview', num: 3 },
    { key: 'result', label: 'Result', num: 4 },
  ];

  const currentStepIdx = steps.findIndex(s => s.key === step);

  return (
    <div className="import-page">
      <div className="import-page__header">
        <h2>Patient Data Import</h2>
        <p className="import-page__subtitle">Import patients from a CSV file with automatic validation and duplicate detection.</p>
      </div>

      {/* Progress bar */}
      <div className="import-progress">
        {steps.map((s, i) => (
          <div key={s.key} className={`import-progress__step ${i <= currentStepIdx ? 'import-progress__step--active' : ''} ${i < currentStepIdx ? 'import-progress__step--done' : ''}`}>
            <div className="import-progress__circle">{i < currentStepIdx ? '✓' : s.num}</div>
            <span className="import-progress__label">{s.label}</span>
          </div>
        ))}
      </div>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* Step 1: Upload */}
      {step === 'upload' && (
        <Card className="import-card">
          <h3>Upload CSV File</h3>
          <p className="import-card__desc">Upload a CSV file containing patient data. Download the template first to ensure correct column formatting.</p>

          <div className="import-card__actions">
            <Button variant="secondary" onClick={() => void downloadTemplate()}>
              Download Template
            </Button>
          </div>

          <div className="import-upload-zone" onClick={() => fileRef.current?.click()}>
            <input ref={fileRef} type="file" accept=".csv,.txt" onChange={() => {}} className="import-upload-input" />
            <div className="import-upload-icon">CSV</div>
            <p className="import-upload-text">{fileRef.current?.files?.[0]?.name || 'Click to select a CSV file'}</p>
            <p className="import-upload-hint">Supports .csv files up to 10MB</p>
          </div>

          <Button onClick={() => void handleUpload()} loading={uploading} disabled={!fileRef.current?.files?.[0]}>
            Upload &amp; Parse
          </Button>
        </Card>
      )}

      {/* Step 2: Mapping */}
      {step === 'mapping' && (
        <Card className="import-card">
          <h3>Map CSV Columns to Patient Fields</h3>
          <p className="import-card__desc">Match each CSV column header to the corresponding patient field. Required fields are marked with *.</p>
          <p className="import-card__info">{totalRows} rows found in CSV. {csvHeaders.length} columns detected.</p>

          <div className="import-mapping-grid">
            <div className="import-mapping-header">
              <span>CSV Column</span>
              <span>→</span>
              <span>Patient Field</span>
            </div>
            {csvHeaders.map(h => (
              <div key={h} className="import-mapping-row">
                <span className="import-mapping-csv">{h}</span>
                <span className="import-mapping-arrow">→</span>
                <select
                  className="import-mapping-select"
                  value={fieldMapping[h] ?? ''}
                  onChange={e => setFieldMapping(prev => ({ ...prev, [h]: e.target.value }))}
                >
                  <option value="">— Skip —</option>
                  {Object.entries(EXPECTED_FIELDS).map(([key, { label, required }]) => (
                    <option key={key} value={key}>{label}{required ? ' *' : ''}</option>
                  ))}
                </select>
              </div>
            ))}
          </div>

          <div className="import-card__actions">
            <Button variant="ghost" onClick={() => setStep('upload')}>Back</Button>
            <Button onClick={() => void handlePreview()} loading={loading}>
              Preview Import
            </Button>
          </div>
        </Card>
      )}

      {/* Step 3: Preview */}
      {step === 'preview' && previewData && (
        <Card className="import-card">
          <h3>Import Preview</h3>
          <div className="import-stats">
            <div className="import-stat import-stat--total">
              <span className="import-stat__num">{previewData.totalRows}</span>
              <span className="import-stat__label">Total Rows</span>
            </div>
            <div className="import-stat import-stat--valid">
              <span className="import-stat__num">{previewData.validRows}</span>
              <span className="import-stat__label">Valid</span>
            </div>
            <div className="import-stat import-stat--error">
              <span className="import-stat__num">{previewData.errorRows}</span>
              <span className="import-stat__label">Errors</span>
            </div>
          </div>

          {previewData.errorSummary.length > 0 && (
            <div className="import-errors">
              <h4>Errors ({previewData.errorSummary.length})</h4>
              <div className="import-errors__list">
                {previewData.errorSummary.slice(0, 20).map((e, i) => (
                  <div key={i} className="import-errors__item">
                    <span className="import-errors__row">Row {String(e.row)}</span>
                    <span className="import-errors__msg">{Array.isArray(e.errors) ? e.errors.join('; ') : ''}</span>
                  </div>
                ))}
                {previewData.errorSummary.length > 20 && (
                  <p className="import-errors__more">…and {previewData.errorSummary.length - 20} more errors</p>
                )}
              </div>
            </div>
          )}

          {/* Preview table */}
          <div className="import-preview-table">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Row</th>
                  <th>Name</th>
                  <th>Sex</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {previewData.preview.slice(0, 50).map((row, i) => (
                  <tr key={i}>
                    <td>{String(row.row)}</td>
                    <td>{String(row.fullName ?? '—')}</td>
                    <td>{String(row.sex ?? '—')}</td>
                    <td>
                      <StatusChip
                        tone={row.valid ? 'success' : 'danger'}
                        label={row.valid ? 'Valid' : (row.duplicateCandidate ? 'Duplicate' : 'Error')}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="import-card__actions">
            <Button variant="ghost" onClick={() => setStep('mapping')}>Back</Button>
            <Button onClick={() => void handleImport()} loading={loading} disabled={previewData.validRows === 0}>
              Import {previewData.validRows} Patients
            </Button>
          </div>
        </Card>
      )}

      {/* Step 4: Result */}
      {step === 'result' && importResult && (
        <Card className="import-card">
          <h3>Import Complete</h3>
          <div className="import-stats">
            <div className="import-stat import-stat--valid">
              <span className="import-stat__num">{importResult.success}</span>
              <span className="import-stat__label">Imported</span>
            </div>
            <div className="import-stat import-stat--error">
              <span className="import-stat__num">{importResult.errors}</span>
              <span className="import-stat__label">Failed</span>
            </div>
          </div>

          {importResult.errorDetails.length > 0 && (
            <div className="import-errors">
              <h4>Failure Report ({importResult.errorDetails.length})</h4>
              <div className="import-errors__list">
                {importResult.errorDetails.map((e, i) => (
                  <div key={i} className="import-errors__item">
                    <span className="import-errors__row">Row {String(e.row)}</span>
                    <span className="import-errors__msg">{Array.isArray(e.errors) ? e.errors.join('; ') : ''}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="import-card__actions">
            <Button onClick={() => { setStep('upload'); setImportId(null); setPreviewData(null); setImportResult(null); }}>
              Import Another File
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
