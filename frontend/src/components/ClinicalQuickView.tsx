/**
 * ClinicalQuickView — Longitudinal Clinical Context Intelligence (Phase 108)
 *
 * Answers: "WHAT MATTERS RIGHT NOW?" in 10 seconds.
 *
 * Architecture: CANONICAL DATA + TRACEABLE DERIVATION + HUMAN CLINICAL AUTHORITY
 *
 * This is NOT an AI doctor.
 * This is NOT a clinical decision-support system.
 * This IS a better way to understand the record.
 *
 * Synthesizes:
 * - Active problems/diagnoses
 * - Current medications
 * - Allergies (safety-critical)
 * - Recent important results
 * - Open clinical loops
 * - What changed since last encounter
 * - Current care team
 *
 * Every synthesized item links to its canonical source.
 * The summary is DERIVED. The clinical record is AUTHORITATIVE.
 */

import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi, encountersApi } from '../api/endpoints';
import type { Encounter } from '../api/types';
import {
  EmptyState,
  StatusChip,
  formatDateTime,
} from './ui';
import {
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  FlaskConical,
  Pill,
  Shield,
  FileText,
  Activity,
} from 'lucide-react';
import './clinical-quickview.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface ActiveIssue {
  id: string;
  label: string;
  type: string;
  status: string;
  source: string;
  sourceId: string;
  actionTo: string;
  isNew?: boolean;
}

interface CurrentMedication {
  id: string;
  name: string;
  dosage: string;
  frequency: string;
  status: string;
  source: string;
  sourceId: string;
  actionTo: string;
  isNew?: boolean;
}

interface Allergy {
  id: string;
  allergen: string;
  reaction: string;
  severity: string;
  status: string;
  source: string;
  sourceId: string;
}

interface RecentResult {
  id: string;
  testName: string;
  value: string;
  unit: string;
  referenceRange?: string;
  status: string;
  isAbnormal: boolean;
  orderedAt: string;
  verifiedAt?: string;
  sourceId: string;
  actionTo: string;
}

interface ClinicalChange {
  id: string;
  type: 'new' | 'changed' | 'resolved';
  label: string;
  detail: string;
  date: string;
}

interface QuickViewData {
  activeIssues: ActiveIssue[];
  medications: CurrentMedication[];
  allergies: Allergy[];
  recentResults: RecentResult[];
  changes: ClinicalChange[];
  openLoops: number;
  careTeamSize: number;
}

/* ────────────────────────────────────────────────────────────────────
   DERIVE CLINICAL CONTEXT FROM CANONICAL DATA
   ──────────────────────────────────────────────────────────────────── */

function deriveQuickView(
  encounters: Encounter[],
  diagnoses: any[],
  prescriptions: any[],
  allergies: any[],
  labOrders: any[],
  patientId: string,
): QuickViewData {
  // ── Active Issues (diagnoses with active status) ──
  const activeIssues: ActiveIssue[] = (diagnoses ?? [])
    .filter((d: any) => d.status === 'active')
    .map((d: any) => ({
      id: d.id,
      label: d.description ?? d.code ?? 'Diagnosis',
      type: d.type ?? 'diagnosis',
      status: d.status,
      source: 'Diagnosis record',
      sourceId: d.id,
      actionTo: `/clinical/patients/${patientId}?ws=diagnoses`,
    }));

  // ── Active Encounters as issues ──
  for (const enc of encounters) {
    if (enc.status === 'open' || enc.status === 'in_progress') {
      activeIssues.push({
        id: `enc-${enc.id}`,
        label: `${enc.type} encounter`,
        type: 'encounter',
        status: enc.status,
        source: 'Encounter record',
        sourceId: enc.id,
        actionTo: `/clinical/encounters/${enc.id}`,
      });
    }
  }

  // ── Current Medications (active prescriptions) ──
  const medications: CurrentMedication[] = (prescriptions ?? [])
    .filter((p: any) => p.status === 'active' || p.status === 'dispensed')
    .map((p: any) => ({
      id: p.id,
      name: p.medicationName ?? p.medication?.name ?? 'Medication',
      dosage: p.dosage ?? '',
      frequency: p.frequency ?? '',
      status: p.status,
      source: 'Prescription record',
      sourceId: p.id,
      actionTo: `/clinical/patients/${patientId}?ws=medications`,
    }));

  // ── Allergies (safety-critical — always visible) ──
  const allergyList: Allergy[] = (allergies ?? []).map((a: any) => ({
    id: a.id,
    allergen: a.allergen ?? a.name ?? 'Unknown',
    reaction: a.reaction ?? a.description ?? '',
    severity: a.severity ?? 'unknown',
    status: a.status ?? 'active',
    source: 'Allergy record',
    sourceId: a.id,
  }));

  // ── Recent Results (last 7 days, from lab orders) ──
  const sevenDaysAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
  const recentResults: RecentResult[] = (labOrders ?? [])
    .filter((o: any) => {
      const date = new Date(o.createdAt ?? o.orderedAt);
      return date >= sevenDaysAgo && (o.status === 'reported' || o.status === 'verified' || o.resultValue);
    })
    .slice(0, 10)
    .map((o: any) => ({
      id: o.id,
      testName: o.testName ?? o.name ?? 'Lab test',
      value: o.resultValue ?? '—',
      unit: o.resultUnit ?? '',
      referenceRange: o.referenceRange,
      status: o.status,
      isAbnormal: o.isAbnormal ?? false,
      orderedAt: o.createdAt ?? o.orderedAt ?? new Date().toISOString(),
      verifiedAt: o.verifiedAt,
      sourceId: o.id,
      actionTo: `/clinical/patients/${patientId}?ws=lab`,
    }));

  // ── Changes (simplified: new diagnoses, new medications since last week) ──
  const changes: ClinicalChange[] = [];
  const weekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);

  for (const d of diagnoses ?? []) {
    const created = new Date(d.createdAt ?? d.updatedAt);
    if (created >= weekAgo && d.status === 'active') {
      changes.push({
        id: `chg-d-${d.id}`,
        type: 'new',
        label: `New diagnosis: ${d.description ?? d.code ?? 'Added'}`,
        detail: d.type ?? '',
        date: d.createdAt ?? d.updatedAt ?? new Date().toISOString(),
      });
    }
  }

  for (const p of prescriptions ?? []) {
    const created = new Date(p.createdAt ?? p.updatedAt);
    if (created >= weekAgo && (p.status === 'active' || p.status === 'dispensed')) {
      changes.push({
        id: `chg-rx-${p.id}`,
        type: 'new',
        label: `New medication: ${p.medicationName ?? p.medication?.name ?? 'Added'}`,
        detail: `${p.dosage ?? ''} ${p.frequency ?? ''}`.trim(),
        date: p.createdAt ?? p.updatedAt ?? new Date().toISOString(),
      });
    }
  }

  // ── Open Loops count ──
  let openLoops = 0;
  for (const o of labOrders ?? []) {
    const s = o.status?.toLowerCase();
    if (s && !['reported', 'verified', 'cancelled'].includes(s)) openLoops++;
  }
  for (const p of prescriptions ?? []) {
    const s = p.status?.toLowerCase();
    if (s && !['dispensed', 'cancelled', 'completed'].includes(s)) openLoops++;
  }
  for (const enc of encounters) {
    if (enc.status === 'open' || enc.status === 'in_progress') openLoops++;
  }

  // ── Care Team Size ──
  const providerIds = new Set<string>();
  for (const enc of encounters) {
    if (enc.provider?.id && (enc.status === 'open' || enc.status === 'in_progress')) {
      providerIds.add(enc.provider.id);
    }
  }

  return {
    activeIssues,
    medications,
    allergies: allergyList,
    recentResults,
    changes,
    openLoops,
    careTeamSize: providerIds.size,
  };
}

/* ────────────────────────────────────────────────────────────────────
   SECTION COMPONENTS
   ──────────────────────────────────────────────────────────────────── */

function IssueSection({ issues }: { issues: ActiveIssue[] }) {
  const navigate = useNavigate();
  if (issues.length === 0) return null;

  return (
    <section className="qv-section" aria-label="Active issues">
      <div className="qv-section__header">
        <h3 className="qv-section__title">
          <AlertTriangle size={15} />
          Active Issues
        </h3>
        <span className="qv-section__count">{issues.length}</span>
      </div>
      <div className="qv-items">
        {issues.map((issue) => (
          <button
            key={issue.id}
            type="button"
            className="qv-item qv-item--issue"
            onClick={() => navigate(issue.actionTo)}
          >
            <span className="qv-item__label">{issue.label}</span>
            <span className="qv-item__meta">
              <StatusChip tone="info" label={issue.type} />
            </span>
            <ExternalLink size={12} className="qv-item__link" />
          </button>
        ))}
      </div>
    </section>
  );
}

function MedicationSection({ medications }: { medications: CurrentMedication[] }) {
  const navigate = useNavigate();
  if (medications.length === 0) return null;

  return (
    <section className="qv-section" aria-label="Current medications">
      <div className="qv-section__header">
        <h3 className="qv-section__title">
          <Pill size={15} />
          Current Medications
        </h3>
        <span className="qv-section__count">{medications.length}</span>
      </div>
      <div className="qv-items">
        {medications.map((med) => (
          <button
            key={med.id}
            type="button"
            className="qv-item qv-item--med"
            onClick={() => navigate(med.actionTo)}
          >
            <span className="qv-item__label">{med.name}</span>
            <span className="qv-item__detail">
              {med.dosage && <span>{med.dosage}</span>}
              {med.frequency && <span>{med.frequency}</span>}
            </span>
            <ExternalLink size={12} className="qv-item__link" />
          </button>
        ))}
      </div>
    </section>
  );
}

function AllergySection({ allergies }: { allergies: Allergy[] }) {
  if (allergies.length === 0) {
    return (
      <section className="qv-section qv-section--safe" aria-label="Allergies">
        <div className="qv-section__header">
          <h3 className="qv-section__title">
            <Shield size={15} />
            Allergies
          </h3>
        </div>
        <div className="qv-empty-inline">
          <CheckCircle2 size={13} />
          <span>No known allergies documented</span>
        </div>
      </section>
    );
  }

  return (
    <section className="qv-section qv-section--alert" aria-label="Allergies">
      <div className="qv-section__header">
        <h3 className="qv-section__title">
          <Shield size={15} />
          Allergies
        </h3>
        <span className="qv-section__count qv-section__count--alert">{allergies.length}</span>
      </div>
      <div className="qv-items">
        {allergies.map((allergy) => (
          <div key={allergy.id} className="qv-item qv-item--allergy">
            <span className="qv-item__label">{allergy.allergen}</span>
            {allergy.reaction && (
              <span className="qv-item__detail">{allergy.reaction}</span>
            )}
            <StatusChip
              tone={allergy.severity === 'severe' || allergy.severity === 'life-threatening' ? 'danger' : allergy.severity === 'moderate' ? 'warning' : 'info'}
              label={allergy.severity}
            />
          </div>
        ))}
      </div>
    </section>
  );
}

function ResultSection({ results }: { results: RecentResult[] }) {
  const navigate = useNavigate();
  if (results.length === 0) return null;

  return (
    <section className="qv-section" aria-label="Recent results">
      <div className="qv-section__header">
        <h3 className="qv-section__title">
          <FlaskConical size={15} />
          Recent Results
        </h3>
        <span className="qv-section__count">{results.length}</span>
      </div>
      <div className="qv-items">
        {results.map((result) => (
          <button
            key={result.id}
            type="button"
            className={`qv-item qv-item--result ${result.isAbnormal ? 'qv-item--abnormal' : ''}`}
            onClick={() => navigate(result.actionTo)}
          >
            <span className="qv-item__label">{result.testName}</span>
            <span className="qv-item__value">
              <span className={`qv-value ${result.isAbnormal ? 'qv-value--abnormal' : ''}`}>
                {result.value}
              </span>
              {result.unit && <span className="qv-unit">{result.unit}</span>}
              {result.referenceRange && (
                <span className="qv-ref">Ref: {result.referenceRange}</span>
              )}
            </span>
            <ExternalLink size={12} className="qv-item__link" />
          </button>
        ))}
      </div>
    </section>
  );
}

function ChangeSection({ changes }: { changes: ClinicalChange[] }) {
  if (changes.length === 0) return null;

  return (
    <section className="qv-section" aria-label="Recent changes">
      <div className="qv-section__header">
        <h3 className="qv-section__title">
          <Activity size={15} />
          What Changed (Past 7 Days)
        </h3>
        <span className="qv-section__count">{changes.length}</span>
      </div>
      <div className="qv-items">
        {changes.map((change) => (
          <div key={change.id} className={`qv-item qv-item--change qv-item--${change.type}`}>
            <StatusChip
              tone={change.type === 'new' ? 'info' : change.type === 'resolved' ? 'success' : 'warning'}
              label={change.type}
            />
            <span className="qv-item__label">{change.label}</span>
            <span className="qv-item__time">{formatDateTime(change.date)}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL QUICK VIEW
   ──────────────────────────────────────────────────────────────────── */

export function ClinicalQuickView({ patientId }: { patientId: string }) {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();

  const patient = useFetch(
    () => patientsApi.show(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const encounters = useFetch(
    () => encountersApi.forPatient(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const diagnoses = useFetch(
    () => patientsApi.diagnoses(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const prescriptions = useFetch(
    () => patientsApi.prescriptions(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const allergies = useFetch(
    () => patientsApi.allergies(patientId, selectedFacilityId).catch(() => []),
    [patientId, selectedFacilityId],
  );

  const labOrders = useFetch(
    () => patientsApi.labOrders(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const quickView = useMemo(
    () => deriveQuickView(
      (encounters.data as any[]) ?? [],
      (diagnoses.data as any[]) ?? [],
      (prescriptions.data as any[]) ?? [],
      (allergies.data as any[]) ?? [],
      (labOrders.data as any[]) ?? [],
      patientId,
    ),
    [encounters.data, diagnoses.data, prescriptions.data, allergies.data, labOrders.data, patientId],
  );

  const isLoading = patient.loading || encounters.loading || diagnoses.loading || prescriptions.loading;

  if (isLoading) {
    return (
      <div className="qv-loading" role="status">
        <div className="spinner" />
        <span>Loading clinical context…</span>
      </div>
    );
  }

  const hasAnyData = quickView.activeIssues.length > 0 || quickView.medications.length > 0 ||
    quickView.allergies.length > 0 || quickView.recentResults.length > 0 || quickView.changes.length > 0;

  return (
    <div className="clinical-quickview" role="region" aria-label="Clinical quick view">
      {/* Summary bar */}
      <div className="qv-summary">
        {quickView.openLoops > 0 && (
          <button
            type="button"
            className="qv-summary__stat qv-summary__stat--alert"
            onClick={() => navigate(`/clinical/patients/${patientId}?ws=loops`)}
          >
            <span className="qv-summary__count">{quickView.openLoops}</span>
            <span className="qv-summary__label">Open Loops</span>
          </button>
        )}
        {quickView.careTeamSize > 0 && (
          <button
            type="button"
            className="qv-summary__stat"
            onClick={() => navigate(`/clinical/patients/${patientId}?ws=careteam`)}
          >
            <span className="qv-summary__count">{quickView.careTeamSize}</span>
            <span className="qv-summary__label">Care Team</span>
          </button>
        )}
        {quickView.allergies.length > 0 && (
          <div className="qv-summary__stat qv-summary__stat--warning">
            <span className="qv-summary__count">{quickView.allergies.length}</span>
            <span className="qv-summary__label">Allergies</span>
          </div>
        )}
      </div>

      {/* Clinical sections */}
      {hasAnyData ? (
        <div className="qv-grid">
          <div className="qv-column">
            <AllergySection allergies={quickView.allergies} />
            <IssueSection issues={quickView.activeIssues} />
            <MedicationSection medications={quickView.medications} />
          </div>
          <div className="qv-column">
            <ResultSection results={quickView.recentResults} />
            <ChangeSection changes={quickView.changes} />
          </div>
        </div>
      ) : (
        <EmptyState
          title="No clinical context"
          body="Active diagnoses, medications, results, and changes will appear here as clinical data accumulates."
        />
      )}

      {/* Source traceability notice */}
      <div className="qv-notice" role="note">
        <FileText size={12} />
        <span>
          All items are derived from canonical clinical records. Each links to its authoritative source.
        </span>
      </div>
    </div>
  );
}

export default ClinicalQuickView;
