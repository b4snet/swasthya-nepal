/**
 * EncounterWorkspace — Encounter / Care-Episode Workspace (Phase 83)
 *
 * The encounter is the operational bridge between:
 *   PATIENT (longitudinal identity)
 *   and
 *   CURRENT WORK (orders, results, documentation, billing).
 *
 * This component establishes the three-level hierarchy:
 *   PATIENT = WHO
 *   ENCOUNTER = WHAT IS HAPPENING NOW
 *   WORKSPACE = WHAT THE USER IS DOING
 *
 * It integrates with the Patient Workspace (Phase 82) to provide
 * a seamless patient→encounter→task workflow.
 */

import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import {
  patientsApi,
  encountersApi,
  labOrdersApi,
  billingApi,
  catalogsApi,
  labTestsApi,
  radiologyApi,
  referralsApi,
  followUpsApi,
} from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Input,
  Select,
  Spinner,
  StatusChip,
  Textarea,
  formatDate,
} from '../components/ui';
import { money } from '../components/ui';

import { FollowUpList } from '../components/FollowUpList';
import { CreateFollowUpDialog } from '../components/CreateFollowUpDialog';
import { CdssWarning } from '../components/CdssWarning';
import '../components/cdss-warning.css';
import {
  UserRound,
  Pill,
  FlaskConical,
  ScanLine,
  GitPullRequestArrow,
  CalendarDays,
  ClipboardList,
  Activity,
  WalletCards,
  Stethoscope,
  ChevronRight,
  Clock,
  CheckCircle2,
  AlertTriangle,
  Pen,
} from 'lucide-react';
import './encounter-workspace.css';

// ─── Encounter status helpers ───
function encounterStatusInfo(status: string): { tone: 'success' | 'warning' | 'info' | 'danger' | 'neutral'; label: string } {
  switch (status) {
    case 'open': return { tone: 'info', label: 'Open' };
    case 'in_progress': return { tone: 'warning', label: 'In Progress' };
    case 'signed': return { tone: 'success', label: 'Signed' };
    case 'amended': return { tone: 'warning', label: 'Amended' };
    case 'closed': return { tone: 'neutral', label: 'Closed' };
    default: return { tone: 'neutral', label: status };
  }
}

// ─── Workspace definitions (role-aware, encounter-scoped) ───
interface EncounterWorkspaceDef {
  id: string;
  label: string;
  Icon: any;
  roles: string[];
  description?: string;
}

export const ENCOUNTER_WORKSPACES: EncounterWorkspaceDef[] = [
  { id: 'overview', label: 'Overview', Icon: Activity, roles: [], description: 'Episode summary and current status' },
  { id: 'clinical', label: 'Clinical Note', Icon: Pen, roles: ['doctor', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Documentation and notes' },
  { id: 'diagnoses', label: 'Diagnoses', Icon: ClipboardList, roles: ['doctor', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Diagnoses for this episode' },
  { id: 'medications', label: 'Prescriptions', Icon: Pill, roles: ['doctor', 'pharmacist', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Medications for this episode' },
  { id: 'lab', label: 'Lab Orders', Icon: FlaskConical, roles: ['doctor', 'nurse', 'lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Laboratory investigations' },
  { id: 'radiology', label: 'Radiology', Icon: ScanLine, roles: ['doctor', 'nurse', 'radiologist', 'radiographer', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Imaging studies' },
  { id: 'referrals', label: 'Referrals', Icon: GitPullRequestArrow, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Consultations and referrals' },
  { id: 'followup', label: 'Follow-up', Icon: CalendarDays, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], description: 'Follow-up planning' },
  { id: 'billing', label: 'Billing', Icon: WalletCards, roles: ['billing_clerk', 'hospital_admin', 'org_admin', 'org_finance', 'superadmin'], description: 'Episode charges and invoice' },
];

// ════════════════════════════════════════════════════════════════════════════
// PATIENT CONTEXT BAR — mini patient identity inside encounter workspace
// ════════════════════════════════════════════════════════════════════════════
function PatientContextBar({ patient }: { patient: any }) {
  if (!patient) return null;
  return (
    <Link to={`/patients/${patient.id}`} className="ew-patient-bar" data-testid="ew-patient-link">
      <UserRound size={16} />
      <span className="ew-patient-bar__name">{patient.fullName}</span>
      <span className="ew-patient-bar__mrn mono">{patient.mrn}</span>
      <ChevronRight size={14} className="ew-patient-bar__arrow" />
    </Link>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// ENCOUNTER HEADER — what care episode am I in?
// ════════════════════════════════════════════════════════════════════════════
function EncounterHeader({
  encounter,
  onSign,
  signingBusy,
}: {
  encounter: any;
  onSign: () => void;
  signingBusy: boolean;
}) {
  const statusInfo = encounterStatusInfo(encounter.status);
  const isSigned = encounter.status === 'signed' || encounter.status === 'closed';

  return (
    <div className="ew-header" role="banner" aria-label={`Encounter: ${encounter.type}`}>
      <div className="ew-header__content">
        <div className="ew-header__main">
          <div className="ew-header__type">
            <Stethoscope size={20} />
            <h1 className="ew-header__title">{encounter.type || 'Encounter'}</h1>
            <StatusChip tone={statusInfo.tone} label={statusInfo.label} />
          </div>
          <div className="ew-header__meta">
            <span className="ew-header__meta-item">
              <Clock size={13} />
              {formatDate(encounter.startedAt)}
            </span>
            {encounter.provider && (
              <>
                <span className="ew-header__meta-sep">·</span>
                <span className="ew-header__meta-item">{encounter.provider.fullName}</span>
              </>
            )}
            {encounter.serviceName && (
              <>
                <span className="ew-header__meta-sep">·</span>
                <span className="ew-header__meta-item">{encounter.serviceName}</span>
              </>
            )}
          </div>
        </div>

        {!isSigned && (
          <Button
            variant="secondary"
            size="sm"
            loading={signingBusy}
            onClick={onSign}
            data-testid="ew-sign-encounter"
          >
            Sign Encounter
          </Button>
        )}
      </div>

      {isSigned && (
        <div className="ew-header__signed">
          <CheckCircle2 size={14} />
          Signed — this encounter is now immutable history
        </div>
      )}
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// ENCOUNTER WORKSPACE NAV — contextual workspace launchers
// ════════════════════════════════════════════════════════════════════════════
function EncounterWorkspaceNav({
  activeWorkspace,
  onSelect,
  hasRole,
  counts,
}: {
  activeWorkspace: string;
  onSelect: (id: string) => void;
  hasRole: (role: string) => boolean;
  counts: Record<string, number | undefined>;
}) {
  const visible = ENCOUNTER_WORKSPACES.filter(
    (ws) => ws.roles.length === 0 || ws.roles.some((r) => hasRole(r as any)),
  );

  return (
    <nav className="ew-nav" role="navigation" aria-label="Encounter workspace navigation">
      <div className="ew-nav__grid" role="list">
        {visible.map((ws) => {
          const isActive = ws.id === activeWorkspace;
          const count = counts[ws.id];
          return (
            <button
              key={ws.id}
              type="button"
              className={`ew-nav__card ${isActive ? 'ew-nav__card--active' : ''}`}
              onClick={() => onSelect(ws.id)}
              aria-label={ws.label}
              aria-current={isActive ? 'page' : undefined}
              data-testid={`ew-nav-${ws.id}`}
              role="listitem"
            >
              <div className="ew-nav__icon">
                <ws.Icon size={18} strokeWidth={1.75} />
              </div>
              <div className="ew-nav__label">
                {ws.label}
                {count !== undefined && count > 0 && (
                  <span className="ew-nav__badge">{count}</span>
                )}
              </div>
            </button>
          );
        })}
      </div>
    </nav>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// ENCOUNTER WORKSPACE VIEWS
// ════════════════════════════════════════════════════════════════════════════

// ── Overview: What is happening in this episode? ──
function EncounterOverview({
  notes,
  diagnoses,
  prescriptions,
  labOrders,
  referrals,
  followUps,
}: {
  notes: any[];
  diagnoses: any[];
  prescriptions: any[];
  labOrders: any[];
  referrals: any[];
  followUps: any[];
}) {
  const pendingLabs = labOrders.filter((o: any) => !['reported', 'verified'].includes(o.status));
  const activePrescriptions = prescriptions.filter((p: any) => p.status === 'active');
  const activeReferrals = referrals.filter((r: any) => !['completed', 'cancelled'].includes(r.status));
  const pendingFollowUps = followUps.filter((f: any) => !['completed', 'cancelled'].includes(f.status));
  const activeDiagnoses = diagnoses.filter((d: any) => d.status === 'active');
  const hasNote = notes.length > 0;

  return (
    <div className="ew-overview">
      <section className="ew-overview__section" aria-label="Episode status">
        <h3 className="ew-overview__heading">Episode Status</h3>
        <div className="ew-overview__cards">
          {!hasNote && (
            <div className="ew-status-card ew-status-card--warning">
              <div className="ew-status-card__icon"><Pen size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">No Documentation</span>
                <span className="ew-status-card__detail">Clinical note not yet started</span>
              </div>
            </div>
          )}

          {hasNote && (
            <div className="ew-status-card ew-status-card--ok">
              <div className="ew-status-card__icon"><CheckCircle2 size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Documented</span>
                <span className="ew-status-card__detail">{notes.length} note{notes.length !== 1 ? 's' : ''}</span>
              </div>
            </div>
          )}

          {activeDiagnoses.length > 0 && (
            <div className="ew-status-card">
              <div className="ew-status-card__icon"><ClipboardList size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Diagnoses</span>
                <span className="ew-status-card__detail">
                  {activeDiagnoses.map((d: any) => d.description || d.code).filter(Boolean).join('; ').slice(0, 60)}
                </span>
              </div>
            </div>
          )}

          {pendingLabs.length > 0 && (
            <div className="ew-status-card ew-status-card--warning">
              <div className="ew-status-card__icon"><AlertTriangle size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Pending Labs</span>
                <span className="ew-status-card__detail">
                  {pendingLabs.length} order{pendingLabs.length !== 1 ? 's' : ''} awaiting results
                </span>
              </div>
            </div>
          )}

          {activePrescriptions.length > 0 && (
            <div className="ew-status-card">
              <div className="ew-status-card__icon"><Pill size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Prescriptions</span>
                <span className="ew-status-card__detail">
                  {activePrescriptions.length} active
                </span>
              </div>
            </div>
          )}

          {activeReferrals.length > 0 && (
            <div className="ew-status-card">
              <div className="ew-status-card__icon"><GitPullRequestArrow size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Referrals</span>
                <span className="ew-status-card__detail">
                  {activeReferrals.length} pending
                </span>
              </div>
            </div>
          )}

          {pendingFollowUps.length > 0 && (
            <div className="ew-status-card">
              <div className="ew-status-card__icon"><CalendarDays size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Follow-ups</span>
                <span className="ew-status-card__detail">
                  {pendingFollowUps.length} planned
                </span>
              </div>
            </div>
          )}

          {hasNote && activeDiagnoses.length === 0 && pendingLabs.length === 0 &&
           activePrescriptions.length === 0 && activeReferrals.length === 0 && pendingFollowUps.length === 0 && (
            <div className="ew-status-card ew-status-card--quiet">
              <div className="ew-status-card__icon"><CheckCircle2 size={18} /></div>
              <div className="ew-status-card__info">
                <span className="ew-status-card__label">Episode Active</span>
                <span className="ew-status-card__detail">Documentation in progress, no pending items</span>
              </div>
            </div>
          )}
        </div>
      </section>
    </div>
  );
}

// ── Clinical Note workspace ──
function ClinicalNoteWorkspace({
  encounterId,
  fac,
  signed,
  notes,
  onError,
  onSaved,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  notes: any[];
  onError: (e: unknown) => void;
  onSaved: () => void;
}) {
  const [complaint, setComplaint] = useState('');
  const [examination, setExamination] = useState('');
  const [assessment, setAssessment] = useState('');
  const [plan, setPlan] = useState('');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    try {
      const content: Record<string, string> = {};
      if (complaint) content.complaint = complaint;
      if (examination) content.examination = examination;
      if (assessment) content.assessment = assessment;
      if (plan) content.plan = plan;
      await encountersApi.storeNote(encounterId, 'consultation', content, fac);
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  const signNote = async (noteId: string) => {
    setBusy(true);
    try {
      await encountersApi.signNote(encounterId, noteId, fac);
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  if (notes.length === 0 && !signed) {
    return (
      <Card title="Clinical Note">
        <div className="ew-note-form">
          <Textarea label="Chief Complaint" value={complaint} onChange={(e) => setComplaint(e.target.value)} placeholder="Why has the patient come?" />
          <Textarea label="Examination" value={examination} onChange={(e) => setExamination(e.target.value)} placeholder="Clinical examination findings" />
          <Textarea label="Assessment" value={assessment} onChange={(e) => setAssessment(e.target.value)} placeholder="Clinical assessment and reasoning" />
          <Textarea label="Plan" value={plan} onChange={(e) => setPlan(e.target.value)} placeholder="Management plan" />
          <div className="ew-form-actions">
            <Button onClick={() => void save()} loading={busy} disabled={!complaint.trim() && !examination.trim() && !assessment.trim() && !plan.trim()}>
              Save Draft
            </Button>
          </div>
        </div>
      </Card>
    );
  }

  if (signed) {
    return notes.length === 0 ? (
      <EmptyState title="No documentation" body="This encounter was signed without a clinical note." />
    ) : (
      <div className="ew-notes-list">
        {notes.map((n: any) => (
          <Card key={n.id} title={`${n.noteType} — ${n.status}`} className="ew-note-card">
            <dl className="kv">
              {Object.entries(n.content).map(([k, v]) => (
                <div key={k}>
                  <dt className="capitalize">{k}</dt>
                  <dd>{String(v)}</dd>
                </div>
              ))}
            </dl>
          </Card>
        ))}
      </div>
    );
  }

  return (
    <div className="ew-notes-list">
      {notes.map((n: any) => (
        <Card key={n.id} title={`${n.noteType} — ${n.status}`} className="ew-note-card">
          <dl className="kv">
            {Object.entries(n.content).map(([k, v]) => (
              <div key={k}>
                <dt className="capitalize">{k}</dt>
                <dd>{String(v)}</dd>
              </div>
            ))}
          </dl>
          {n.status === 'draft' && (
            <div className="ew-form-actions">
              <Button variant="secondary" loading={busy} onClick={() => void signNote(n.id)}>
                Sign Note
              </Button>
            </div>
          )}
        </Card>
      ))}
    </div>
  );
}

// ── Diagnosis workspace ──
function DiagnosisWorkspace({
  encounterId,
  fac,
  signed,
  diagnoses,
  onError,
  onSaved,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  diagnoses: any[];
  onError: (e: unknown) => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState('');
  const [description, setDescription] = useState('');
  const [diagnosisType, setDiagnosisType] = useState('provisional');
  const [isPrimary, setIsPrimary] = useState(false);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      await encountersApi.storeDiagnosis(encounterId, {
        code: code || undefined,
        codingSystem: code ? 'icd10' : undefined,
        description: description.trim(),
        diagnosisType,
        isPrimary,
      }, fac);
      setDescription('');
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  return (
    <div className="ew-stack">
      {!signed && (
        <Card title="Record Diagnosis">
          <div className="ew-note-form">
            <div className="ew-form-row">
              <Input label="ICD-10 Code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. J11.1" />
              <Select label="Type" value={diagnosisType} onChange={(e) => setDiagnosisType(e.target.value)}>
                <option value="provisional">Provisional</option>
                <option value="differential">Differential</option>
                <option value="final">Final</option>
              </Select>
            </div>
            <Textarea label="Description" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Diagnosis in clinical language" />
            <label className="check">
              <input type="checkbox" checked={isPrimary} onChange={(e) => setIsPrimary(e.target.checked)} />
              Primary diagnosis
            </label>
            <div className="ew-form-actions">
              <Button onClick={() => void submit()} loading={busy} disabled={!description.trim()}>Add Diagnosis</Button>
            </div>
          </div>
        </Card>
      )}

      {diagnoses.length > 0 && (
        <Card title="Episode Diagnoses">
          <table className="data-table" aria-label="Encounter diagnoses">
            <thead>
              <tr><th>Date</th><th>Diagnosis</th><th>Type</th><th>Status</th></tr>
            </thead>
            <tbody>
              {diagnoses.map((d: any) => (
                <tr key={d.id}>
                  <td className="mono">{formatDate(d.createdAt)}</td>
                  <td>{d.description ?? d.code ?? '—'}</td>
                  <td className="capitalize">{d.type ?? '—'}</td>
                  <td><StatusChip tone={d.status === 'active' ? 'warning' : d.status === 'resolved' ? 'success' : 'neutral'} label={d.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {diagnoses.length === 0 && (
        <EmptyState title="No diagnoses recorded" body="Add a diagnosis for this episode." />
      )}
    </div>
  );
}

// ── Prescription workspace ──
function PrescriptionWorkspace({
  encounterId,
  fac,
  signed,
  prescriptions,
  medications,
  cdssMedicationIds,
  setCdssMedicationIds,
  onError,
  onSaved,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  prescriptions: any[];
  medications: any[];
  cdssMedicationIds: string[];
  setCdssMedicationIds: (ids: string[]) => void;
  onError: (e: unknown) => void;
  onSaved: () => void;
}) {
  const [medicationId, setMedicationId] = useState('');
  const [dose, setDose] = useState('');
  const [route, setRoute] = useState('oral');
  const [frequency, setFrequency] = useState('');
  const [duration, setDuration] = useState('');
  const [instructions, setInstructions] = useState('');
  const [busy, setBusy] = useState(false);

  const handleMedicationChange = (newId: string) => {
    setMedicationId(newId);
    if (newId) {
      const existing = cdssMedicationIds.filter(id => id !== newId);
      setCdssMedicationIds([...existing, newId]);
    }
  };

  const submit = async () => {
    setBusy(true);
    try {
      await encountersApi.storePrescription(encounterId, {
        notes: instructions || undefined,
        lines: [{ medicationId, dose: dose.trim(), route, frequency: frequency.trim(), duration: duration || undefined, quantityMinor: undefined, instructions: instructions || undefined }],
      }, fac);
      setMedicationId('');
      setDose('');
      setFrequency('');
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  const selected = medications.find((m: any) => m.id === medicationId);

  return (
    <div className="ew-stack">
      {!signed && (
        <Card title="New Prescription">
          <div className="ew-note-form">
            <Select label="Medication" value={medicationId} onChange={(e) => handleMedicationChange(e.target.value)}>
              <option value="">Select medication…</option>
              {medications.map((m: any) => (
                <option key={m.id} value={m.id}>{m.genericName} {m.strength} — {money(m.priceMinor, m.currency)}</option>
              ))}
            </Select>
            <div className="ew-form-row">
              <Input label="Dose" value={dose} onChange={(e) => setDose(e.target.value)} placeholder="e.g. 500mg" />
              <Select label="Route" value={route} onChange={(e) => setRoute(e.target.value)}>
                <option value="oral">Oral</option>
                <option value="topical">Topical</option>
                <option value="iv">IV</option>
                <option value="im">IM</option>
                <option value="inhalation">Inhalation</option>
              </Select>
            </div>
            <div className="ew-form-row">
              <Input label="Frequency" value={frequency} onChange={(e) => setFrequency(e.target.value)} placeholder="e.g. TDS" />
              <Input label="Duration" value={duration} onChange={(e) => setDuration(e.target.value)} placeholder="e.g. 3 days" />
            </div>
            <Textarea label="Instructions" value={instructions} onChange={(e) => setInstructions(e.target.value)} />
            {selected && (
              <p className="muted small">Unit price: {money(selected.priceMinor, selected.currency)}</p>
            )}
            {cdssMedicationIds.length >= 2 && (
              <CdssWarning medicationIds={cdssMedicationIds} facilityId={fac} />
            )}
            <div className="ew-form-actions">
              <Button onClick={() => void submit()} loading={busy} disabled={!medicationId || !dose.trim() || !frequency.trim()}>
                Draft Prescription
              </Button>
            </div>
          </div>
        </Card>
      )}

      {prescriptions.length > 0 && (
        <Card title="Episode Prescriptions">
          <table className="data-table" aria-label="Encounter prescriptions">
            <thead>
              <tr><th>Medication</th><th>Dose</th><th>Frequency</th><th>Status</th></tr>
            </thead>
            <tbody>
              {prescriptions.map((p: any) => (
                <tr key={p.id}>
                  <td>{p.medicationName ?? p.medication?.name ?? '—'}</td>
                  <td>{p.dosage ?? '—'}</td>
                  <td>{p.frequency ?? '—'}</td>
                  <td><StatusChip tone={p.status === 'active' ? 'success' : p.status === 'dispensed' ? 'info' : 'neutral'} label={p.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {prescriptions.length === 0 && signed && (
        <EmptyState title="No prescriptions" body="No medications were prescribed during this episode." />
      )}
    </div>
  );
}

// ── Lab Orders workspace ──
function LabWorkspace({
  encounterId,
  fac,
  signed,
  labOrders,
  labTests,
  onError,
  onSaved,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  labOrders: any[];
  labTests: any[];
  onError: (e: unknown) => void;
  onSaved: () => void;
}) {
  const [testIds, setTestIds] = useState<string[]>([]);
  const [priority, setPriority] = useState('routine');
  const [clinicalIndication, setClinicalIndication] = useState('');
  const [busy, setBusy] = useState(false);

  const toggleTest = (id: string) => {
    setTestIds((prev) => prev.includes(id) ? prev.filter((t) => t !== id) : [...prev, id]);
  };

  const submit = async () => {
    setBusy(true);
    try {
      await labOrdersApi.store(encounterId, { testIds, priority, clinicalIndication: clinicalIndication || undefined }, fac);
      setTestIds([]);
      setClinicalIndication('');
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  const transitionOrder = async (orderId: string, action: 'collect' | 'process' | 'verify' | 'report') => {
    try {
      if (action === 'collect') await labOrdersApi.collect(orderId, fac);
      else if (action === 'process') await labOrdersApi.process(orderId, fac);
      else if (action === 'verify') await labOrdersApi.verify(orderId, fac);
      else if (action === 'report') await labOrdersApi.report(orderId, fac);
      onSaved();
    } catch (err) { onError(err); }
  };

  const nextAction = (status: string): { label: string; action: 'collect' | 'process' | 'verify' | 'report' } | null => {
    if (status === 'ordered') return { label: 'Collect', action: 'collect' };
    if (status === 'collected') return { label: 'Process', action: 'process' };
    if (status === 'results_entered') return { label: 'Verify', action: 'verify' };
    if (status === 'verified') return { label: 'Release', action: 'report' };
    return null;
  };

  return (
    <div className="ew-stack">
      {!signed && (
        <Card title="Order Lab Tests">
          <div className="ew-note-form">
            <div className="ew-lab-grid">
              {labTests.map((t: any) => (
                <label key={t.id} className="check">
                  <input type="checkbox" checked={testIds.includes(t.id)} onChange={() => toggleTest(t.id)} />
                  <span>{t.name} <span className="muted small">({t.sampleType})</span></span>
                </label>
              ))}
            </div>
            <div className="ew-form-row">
              <Select label="Priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
                <option value="routine">Routine</option>
                <option value="urgent">Urgent</option>
                <option value="stat">STAT</option>
              </Select>
            </div>
            <Textarea label="Clinical Indication" value={clinicalIndication} onChange={(e) => setClinicalIndication(e.target.value)} placeholder="Why are these tests ordered?" />
            <div className="ew-form-actions">
              <Button onClick={() => void submit()} loading={busy} disabled={testIds.length === 0}>Order Tests</Button>
            </div>
          </div>
        </Card>
      )}

      {labOrders.length > 0 && (
        <Card title="Episode Lab Orders">
          {labOrders.map((o: any) => {
            const next = nextAction(o.status);
            return (
              <Card key={o.id} className="ew-order-card">
                <div className="ew-order-detail">
                  <div className="ew-order-row"><span className="ew-order-label">Status</span><StatusChip tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'} label={o.status} /></div>
                  <div className="ew-order-row"><span className="ew-order-label">Priority</span><span className="capitalize">{o.priority}</span></div>
                  {o.clinicalIndication && <div className="ew-order-row"><span className="ew-order-label">Indication</span><span>{o.clinicalIndication}</span></div>}
                  <div className="ew-order-row"><span className="ew-order-label">Tests</span><span>{o.items?.map((i: any) => i.testName ?? 'Unknown').join(', ')}</span></div>
                  {o.items?.some((i: any) => i.resultValue) && (
                    <div className="ew-order-row"><span className="ew-order-label">Results</span>
                      <span>{o.items.filter((i: any) => i.resultValue).map((i: any) => `${i.testName}: ${i.resultValue} ${i.resultUnit ?? ''}`).join(' · ')}</span>
                    </div>
                  )}
                  {next && !signed && (
                    <div className="ew-form-actions">
                      <Button size="sm" onClick={() => void transitionOrder(o.id, next.action)}>{next.label}</Button>
                    </div>
                  )}
                </div>
              </Card>
            );
          })}
        </Card>
      )}

      {labOrders.length === 0 && (
        <EmptyState title="No lab orders" body="Order tests from this encounter." />
      )}
    </div>
  );
}

// ── Radiology workspace ──
function RadiologyWorkspace({
  encounterId,
  fac,
  signed,
  studies,
  labTests,
  onError,
  onSaved,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  studies: any[];
  labTests: any[];
  onError: (e: unknown) => void;
  onSaved: () => void;
}) {
  const [testIds, setTestIds] = useState<string[]>([]);
  const [priority, setPriority] = useState('routine');
  const [clinicalIndication, setClinicalIndication] = useState('');
  const [busy, setBusy] = useState(false);

  const toggleTest = (id: string) => {
    setTestIds((prev) => prev.includes(id) ? prev.filter((t) => t !== id) : [...prev, id]);
  };

  const submit = async () => {
    setBusy(true);
    try {
      await radiologyApi.storeOrder(encounterId, { testIds, priority, clinicalIndication: clinicalIndication || undefined }, fac);
      setTestIds([]);
      setClinicalIndication('');
      onSaved();
    } catch (err) { onError(err); }
    finally { setBusy(false); }
  };

  return (
    <div className="ew-stack">
      {!signed && (
        <Card title="Order Imaging">
          <div className="ew-note-form">
            <div className="ew-lab-grid">
              {labTests.map((t: any) => (
                <label key={t.id} className="check">
                  <input type="checkbox" checked={testIds.includes(t.id)} onChange={() => toggleTest(t.id)} />
                  <span>{t.name}</span>
                </label>
              ))}
            </div>
            <div className="ew-form-row">
              <Select label="Priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
                <option value="routine">Routine</option>
                <option value="urgent">Urgent</option>
                <option value="stat">STAT</option>
              </Select>
            </div>
            <Textarea label="Clinical Indication" value={clinicalIndication} onChange={(e) => setClinicalIndication(e.target.value)} placeholder="Reason for imaging" />
            <div className="ew-form-actions">
              <Button onClick={() => void submit()} loading={busy} disabled={testIds.length === 0}>Order Imaging</Button>
            </div>
          </div>
        </Card>
      )}

      {studies.length > 0 && (
        <Card title="Radiology Worklist">
          <table className="data-table" aria-label="Radiology studies">
            <thead>
              <tr><th>Status</th><th>Modality</th><th>Priority</th><th>Indication</th></tr>
            </thead>
            <tbody>
              {studies.map((s: any) => (
                <tr key={s.id}>
                  <td><StatusChip tone={s.status === 'reported' ? 'success' : 'info'} label={s.status} /></td>
                  <td>{s.modality?.name ?? '—'}</td>
                  <td className="capitalize">{s.priority}</td>
                  <td>{s.clinicalIndication ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {studies.length === 0 && (
        <EmptyState title="No imaging studies" body="Order imaging from this encounter." />
      )}
    </div>
  );
}

// ── Follow-up workspace ──
function FollowUpWorkspace({
  encounterId,
  signed,
  providerStaffId,
  onRefresh,
}: {
  encounterId: string;
  signed: boolean;
  providerStaffId: string;
  onRefresh: () => void;
}) {
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <div className="ew-stack">
      <Card title="Follow-up Plans">
        <div className="ew-form-actions" style={{ marginBottom: 'var(--space-3)' }}>
          {!signed && (
            <Button size="sm" onClick={() => setCreateOpen(true)}>Plan Follow-up</Button>
          )}
        </div>
        <FollowUpList encounterId={encounterId} onRefresh={onRefresh} />
        <CreateFollowUpDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
          encounterId={encounterId}
          providerStaffId={providerStaffId}
          onCreated={() => onRefresh()}
        />
      </Card>
    </div>
  );
}

// ── Billing workspace ──
function BillingWorkspace({
  encounterId,
  fac,
  signed,
  onIssued,
}: {
  encounterId: string;
  fac: string | null;
  signed: boolean;
  onIssued: (invoice: any) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  return (
    <Card title="Billing" className="ew-billing-card">
      <p className="muted small">
        Issue the invoice from the signed encounter charges. The backend derives charges
        (consultation + prescription lines) and returns the invoice.
      </p>
      <div className="ew-form-actions" style={{ marginTop: 'var(--space-3)' }}>
        <Button
          loading={busy}
          disabled={!signed}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              const inv = await billingApi.invoice(encounterId, fac);
              onIssued(inv);
            } catch (err: any) {
              setError(err?.message || 'Failed to issue invoice');
            } finally {
              setBusy(false);
            }
          }}
        >
          Issue Invoice
        </Button>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN ENCOUNTER WORKSPACE PAGE
// ════════════════════════════════════════════════════════════════════════════
export function EncounterWorkspace() {
  const { encounterId } = useParams<{ encounterId: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const { selectedFacilityId, organizationId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const activeWorkspace = searchParams.get('ws') || 'overview';
  const setActiveWorkspace = useCallback((ws: string) => {
    setSearchParams({ ws }, { replace: true });
  }, [setSearchParams]);

  // ── Data fetching ──
  const encounter = useFetch(() => encountersApi.show(encounterId!, fac), [encounterId, fac]);
  const notes = useFetch(() => encountersApi.notes(encounterId!, fac), [encounterId, fac]);
  const diagnoses = useFetch(() => {
    if (!encounter.data?.patientId) return Promise.resolve([]);
    return patientsApi.diagnoses(encounter.data.patientId, fac);
  }, [encounter.data?.patientId, fac]);
  const prescriptions = useFetch(() => {
    if (!encounter.data?.patientId) return Promise.resolve([]);
    return patientsApi.prescriptions(encounter.data.patientId, fac);
  }, [encounter.data?.patientId, fac]);
  const labOrders = useFetch(() => labOrdersApi.forEncounter(encounterId!, fac), [encounterId, fac]);
  const labTests = useFetch(() => organizationId ? labTestsApi.list(organizationId, fac) : Promise.resolve([]), [organizationId, fac]);
  const studies = useFetch(() => radiologyApi.queue(fac), [fac]);
  const referrals = useFetch(async () => {
    if (!encounter.data?.patientId) return { data: [] };
    return referralsApi.list({ patientId: encounter.data.patientId, facilityId: fac });
  }, [encounter.data?.patientId, fac]);
  const followUps = useFetch(async () => {
    return followUpsApi.forEncounter(encounterId!, fac);
  }, [encounterId, fac]);
  const medications = useFetch(() => organizationId ? catalogsApi.medications(organizationId, fac) : Promise.resolve([]), [organizationId, fac]);

  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [signingBusy, setSigningBusy] = useState(false);
  const [cdssMedicationIds, setCdssMedicationIds] = useState<string[]>([]);

  // ── Workspace counts ──
  const counts = useMemo(() => ({
    diagnoses: (diagnoses.data as any[])?.filter((d: any) => d.status === 'active').length,
    medications: (prescriptions.data as any[])?.filter((p: any) => p.status === 'active').length,
    lab: (labOrders.data as any[])?.length,
    referrals: Array.isArray(referrals.data) ? ((referrals.data as any[])?.filter((r: any) => !['completed', 'cancelled'].includes(r.status)).length) : 0,
    followup: Array.isArray(followUps.data) ? ((followUps.data as any[])?.filter((f: any) => !['completed', 'cancelled'].includes(f.status)).length) : 0,
  }), [diagnoses.data, prescriptions.data, labOrders.data, referrals.data, followUps.data]);

  // ── Loading / error states ──
  if (encounter.loading) return <div className="ew-page"><Spinner /></div>;
  if (encounter.error) return <div className="ew-page"><ErrorState error={encounter.error} onRetry={() => void encounter.refresh()} /></div>;
  if (!encounter.data) return <div className="ew-page"><EmptyState title="Encounter not found" body="This encounter may have been removed or is outside your facility scope." /></div>;

  const enc = encounter.data;
  const isSigned = enc.status === 'signed' || enc.status === 'closed';
  const patient = enc.patient;

  // ── Sign encounter ──
  const handleSign = async () => {
    setSigningBusy(true);
    try {
      await encountersApi.sign(encounterId!, fac);
      setNotice({ tone: 'success', text: 'Encounter signed — now immutable history.' });
      await encounter.refresh();
    } catch (err: any) {
      setNotice({ tone: 'danger', text: err?.message || 'Failed to sign encounter.' });
    } finally {
      setSigningBusy(false);
    }
  };

  // ── Workspace content ──
  const renderWorkspace = () => {
    switch (activeWorkspace) {
      case 'overview':
        return (
          <EncounterOverview
            notes={(notes.data as any[]) || []}
            diagnoses={(diagnoses.data as any[]) || []}
            prescriptions={(prescriptions.data as any[]) || []}
            labOrders={(labOrders.data as any[]) || []}
            referrals={Array.isArray(referrals.data) ? (referrals.data as any[]) : []}
            followUps={Array.isArray(followUps.data) ? (followUps.data as any[]) : []}
          />
        );
      case 'clinical':
        return (
          <ClinicalNoteWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            notes={(notes.data as any[]) || []}
            onError={(e) => setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Action failed' })}
            onSaved={() => { setNotice({ tone: 'success', text: 'Note saved.' }); void notes.refresh(); }}
          />
        );
      case 'diagnoses':
        return (
          <DiagnosisWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            diagnoses={(diagnoses.data as any[]) || []}
            onError={(e) => setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Action failed' })}
            onSaved={() => { setNotice({ tone: 'success', text: 'Diagnosis recorded.' }); void diagnoses.refresh(); }}
          />
        );
      case 'medications':
        return (
          <PrescriptionWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            prescriptions={(prescriptions.data as any[]) || []}
            medications={(medications.data as any[]) || []}
            cdssMedicationIds={cdssMedicationIds}
            setCdssMedicationIds={setCdssMedicationIds}
            onError={(e) => setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Action failed' })}
            onSaved={() => { setNotice({ tone: 'success', text: 'Prescription drafted.' }); void prescriptions.refresh(); }}
          />
        );
      case 'lab':
        return (
          <LabWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            labOrders={(labOrders.data as any[]) || []}
            labTests={(labTests.data as any[]) || []}
            onError={(e) => setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Action failed' })}
            onSaved={() => { setNotice({ tone: 'success', text: 'Lab order placed.' }); void labOrders.refresh(); }}
          />
        );
      case 'radiology':
        return (
          <RadiologyWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            studies={(studies.data as any[]) || []}
            labTests={(labTests.data as any[]) || []}
            onError={(e) => setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Action failed' })}
            onSaved={() => { setNotice({ tone: 'success', text: 'Imaging ordered.' }); }}
          />
        );
      case 'referrals':
        return (
          <div className="ew-stack">
            {Array.isArray(referrals.data) && (referrals.data as any[]).length > 0 ? (
              <Card title="Episode Referrals">
                <table className="data-table" aria-label="Encounter referrals">
                  <thead>
                    <tr><th>Date</th><th>From</th><th>To</th><th>Reason</th><th>Status</th></tr>
                  </thead>
                  <tbody>
                    {(referrals.data as any[]).map((r: any) => (
                      <tr key={r.id}>
                        <td className="mono">{formatDate(r.createdAt)}</td>
                        <td>{r.fromDepartment ?? r.referringProvider ?? '—'}</td>
                        <td>{r.toDepartment ?? r.receivingProvider ?? '—'}</td>
                        <td>{r.reason ?? '—'}</td>
                        <td><StatusChip tone={r.status === 'completed' ? 'success' : r.status === 'accepted' ? 'info' : 'neutral'} label={r.status} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            ) : (
              <EmptyState title="No referrals" body="Referrals for this episode will appear here." />
            )}
          </div>
        );
      case 'followup':
        return (
          <FollowUpWorkspace
            encounterId={encounterId!}
            signed={isSigned}
            providerStaffId={enc.providerStaffId}
            onRefresh={() => { void encounter.refresh(); void followUps.refresh(); }}
          />
        );
      case 'billing':
        return (
          <BillingWorkspace
            encounterId={encounterId!}
            fac={fac}
            signed={isSigned}
            onIssued={(inv) => {
              setNotice({ tone: 'success', text: `Invoice ${inv.invoiceNumber} issued — ${money(inv.totalMinor)}.` });
              navigate(`/finance/billing/${inv.id}`);
            }}
          />
        );
      default:
        return <EmptyState title="Workspace not found" body="Select a workspace above." />;
    }
  };

  return (
    <div className="ew-page" data-testid="encounter-workspace">
      {/* Patient context link */}
      {patient && <PatientContextBar patient={patient} />}

      {/* Breadcrumb: Patient > Encounter Type */}
      <nav className="ew-breadcrumb" aria-label="Breadcrumb">
        {patient && (
          <>
            <Link to={`/patients/${patient.id}`} className="ew-breadcrumb__link">{patient.fullName}</Link>
            <ChevronRight size={12} />
          </>
        )}
        <span className="ew-breadcrumb__current">{enc.type || 'Encounter'}</span>
      </nav>

      {/* Encounter Header */}
      <EncounterHeader
        encounter={enc}
        onSign={handleSign}
        signingBusy={signingBusy}
      />

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {/* Encounter Workspace Navigation */}
      <EncounterWorkspaceNav
        activeWorkspace={activeWorkspace}
        onSelect={setActiveWorkspace}
        hasRole={hasRole as any}
        counts={counts}
      />

      {/* Workspace Content */}
      <div className="ew-content">
        {renderWorkspace()}
      </div>
    </div>
  );
}

export default EncounterWorkspace;
