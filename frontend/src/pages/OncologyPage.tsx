import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { oncologyApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input } from '../components/ui';
import '../pages/ncology.css';

/* ── Types ───────────────────────────────────────────────────────── */

interface OncologyProfile {
  id: string;
  patientId: string;
  primaryDiagnosis: string | null;
  cancerSite: string | null;
  histology: string | null;
  grade: string | null;
  tnmStaging: string | null;
  overallStage: string | null;
  performanceStatus: string | null;
  status: string;
  diagnosedAt: string | null;
  createdAt: string;
}

interface TreatmentPlan {
  id: string;
  oncologyProfileId: string;
  planType: string;
  protocolCode: string | null;
  protocolName: string | null;
  intent: string;
  status: string;
  lineOfTherapy: string;
  plannedCycles: number | null;
  completedCycles: number;
  medications: Array<{
    id: string;
    medicationName: string;
    dose: number;
    doseUnit: string;
    route: string;
    frequency: string;
  }>;
}

interface RtCourse {
  id: string;
  oncologyProfileId: string;
  intent: string;
  status: string;
  totalFractions: number;
  completedFractions: number;
  totalDoseCgy: number;
  rtPlans: RtPlan[];
}

interface RtPlan {
  id: string;
  planName: string;
  technique: string;
  energy: string | null;
  fractionDoseCgy: number;
  numFractions: number;
  totalDoseCgy: number;
  status: string;
  physicistApprovedAt: string | null;
  roApprovedAt: string | null;
  requiresSecondaryCheck: boolean;
  isFullyApproved: boolean;
  fractions: Fraction[];
}

interface Fraction {
  id: string;
  fractionNumber: number;
  doseCgy: number;
  status: string;
  sessions: Session[];
}

interface Session {
  id: string;
  machineId: string;
  status: string;
  deliveredDoseCgy: number | null;
}

interface Machine {
  id: string;
  code: string;
  name: string;
  machineType: string;
  status: string;
  dailyCapacity: number;
}

/* ── Constants ───────────────────────────────────────────────────── */

const PROFILE_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  in_remission: { label: 'In Remission', color: '#3b82f6', bg: '#dbeafe' },
  deceased: { label: 'Deceased', color: '#6b7280', bg: '#f3f4f6' },
  completed: { label: 'Completed', color: '#10b981', bg: '#ecfdf5' },
};

const PLAN_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  draft: { label: 'Draft', color: '#f59e0b', bg: '#fef3c7' },
  active: { label: 'Active', color: '#10b981', bg: '#ecfdf5' },
  completed: { label: 'Completed', color: '#3b82f6', bg: '#dbeafe' },
  discontinued: { label: 'Discontinued', color: '#ef4444', bg: '#fee2e2' },
};

const RT_STATUS: Record<string, { label: string; color: string; bg: string }> = {
  planned: { label: 'Planned', color: '#6b7280', bg: '#f3f4f6' },
  in_progress: { label: 'In Progress', color: '#3b82f6', bg: '#dbeafe' },
  completed: { label: 'Completed', color: '#10b981', bg: '#ecfdf5' },
  draft: { label: 'Draft', color: '#f59e0b', bg: '#fef3c7' },
  in_review: { label: 'In Review', color: '#8b5cf6', bg: '#f5f3ff' },
  approved: { label: 'Approved', color: '#10b981', bg: '#ecfdf5' },
  delivered: { label: 'Delivered', color: '#10b981', bg: '#ecfdf5' },
  missed: { label: 'Missed', color: '#ef4444', bg: '#fee2e2' },
};

const STAGE_OPTIONS = ['I', 'II', 'III', 'IV', 'IA', 'IB', 'IIA', 'IIB', 'IIIA', 'IIIB', 'IIIC', 'IVA', 'IVB'];
const INTENT_OPTIONS = ['curative', 'adjuvant', 'neoadjuvant', 'palliative'];
const LINE_OPTIONS = ['first', 'second', 'third', 'fourth', 'fifth'];
const TECHNIQUE_OPTIONS = ['VMAT', 'IMRT', '3DCRT', 'Electron', 'SRS', 'SBRT'];

function StatusBadge({ status, config }: { status: string; config: Record<string, { label: string; color: string; bg: string }> }) {
  const c = config[status] ?? { label: status.replace(/_/g, ' '), color: '#6b7280', bg: '#f3f4f6' };
  return (
    <span className="onc-badge" style={{ color: c.color, backgroundColor: c.bg }}>{c.label}</span>
  );
}

/* ── Main Component ──────────────────────────────────────────────── */

export function OncologyPage() {
  const { selectedFacilityId: fac } = useTenant();
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'profiles' | 'treatment' | 'rt' | 'mdt' | 'machines'>('profiles');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Profile state
  const [selectedProfileId, setSelectedProfileId] = useState<string | null>(null);

  // Treatment plan form
  const [planForm, setPlanForm] = useState({ planType: 'chemotherapy', protocolName: '', intent: 'curative', lineOfTherapy: 'first', plannedCycles: '' });

  // MDT form
  const [mdtForm, setMdtForm] = useState({ reviewDate: '', decision: '', recommendations: '', attendees: '' });

  // RT form
  const [rtForm, setRtForm] = useState({ intent: 'curative', totalFractions: '', totalDoseCgy: '' });

  // Treatment plan state
  const [planDetail, setPlanDetail] = useState<TreatmentPlan | null>(null);

  // RT course state
  const [selectedCourseId, setSelectedCourseId] = useState<string | null>(null);
  const [courseDetail, setCourseDetail] = useState<RtCourse | null>(null);

  // Data fetching
  const stats = useFetch(
    () => fac ? oncologyApi.stats(fac) : Promise.resolve(null),
    [fac],
  );

  const profiles = useFetch(
    () => fac ? oncologyApi.listProfiles(fac) : Promise.resolve({ data: [] }),
    [fac],
  );

  const machines = useFetch(
    () => fac ? oncologyApi.listMachines(fac) : Promise.resolve({ data: [] }),
    [fac],
  );

  const allProfiles = useMemo(() => {
    const d = profiles.data as unknown as { data: OncologyProfile[] } | null;
    return (d?.data ?? []) as OncologyProfile[];
  }, [profiles.data]);
  const allMachines = useMemo(() => {
    const d = machines.data as unknown as { data: Machine[] } | null;
    return (d?.data ?? []) as Machine[];
  }, [machines.data]);
  const statsData = useMemo(() => stats.data as unknown as { active_profiles: number; in_remission: number; active_treatment_plans: number; active_rt_courses: number } | null, [stats.data]);

  const go = useCallback(async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  }, []);

  const loadProfileDetail = useCallback(async (id: string) => {
    setSelectedProfileId(id);
    const detail = await go(() => oncologyApi.showProfile(id, fac));
    if (detail) {
      setPlanDetail(null);
    }
  }, [allProfiles, fac, go]);

  const loadPlanDetail = useCallback(async (planId: string) => {
    const detail = await go(() => oncologyApi.showTreatmentPlan(planId, fac));
    if (detail) {
      setPlanDetail(detail as unknown as TreatmentPlan);
    }
  }, [fac, go]);

  const loadCourseDetail = useCallback(async (courseId: string) => {
    setSelectedCourseId(courseId);
    const detail = await go(() => oncologyApi.showRtCourse(courseId, fac));
    if (detail) setCourseDetail(detail as unknown as RtCourse);
  }, [fac, go]);

  const handleCreateProfile = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    const fd = e.currentTarget as HTMLFormElement;
    const data = new FormData(fd);
    await go(() => oncologyApi.storeProfile({
      patientId: data.get('patientId') as string,
      primaryDiagnosis: data.get('primaryDiagnosis') as string || undefined,
      cancerSite: data.get('cancerSite') as string || undefined,
      overallStage: data.get('overallStage') as string || undefined,
      performanceStatus: data.get('performanceStatus') as string || undefined,
    }, fac));
    setDlg(null);
    profiles.refresh();
    stats.refresh();
  }, [fac, go, profiles, stats]);

  const handleCreatePlan = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedProfileId) return;
    await go(() => oncologyApi.storeTreatmentPlan(selectedProfileId, {
      planType: planForm.planType,
      protocolName: planForm.protocolName || undefined,
      intent: planForm.intent,
      lineOfTherapy: planForm.lineOfTherapy,
      plannedCycles: planForm.plannedCycles ? parseInt(planForm.plannedCycles) : undefined,
    }, fac));
    setDlg(null);
    loadProfileDetail(selectedProfileId);
    stats.refresh();
  }, [selectedProfileId, planForm, fac, go, loadProfileDetail, stats]);

  const handleCreateMdtReview = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedProfileId) return;
    await go(() => oncologyApi.storeMdtReview(selectedProfileId, {
      reviewDate: mdtForm.reviewDate,
      decision: mdtForm.decision || undefined,
      recommendations: mdtForm.recommendations || undefined,
      attendees: mdtForm.attendees ? mdtForm.attendees.split(',').map(s => s.trim()) : undefined,
    }, fac));
    setDlg(null);
    loadProfileDetail(selectedProfileId);
  }, [selectedProfileId, mdtForm, fac, go, loadProfileDetail]);

  const handleStartCycle = useCallback(async (planId: string) => {
    await go(() => oncologyApi.startCycle(planId, fac));
    loadPlanDetail(planId);
    stats.refresh();
  }, [fac, go, loadPlanDetail, stats]);

  const handleCreateRtCourse = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedProfileId) return;
    await go(() => oncologyApi.storeRtCourse(selectedProfileId, {
      intent: rtForm.intent,
      totalFractions: parseInt(rtForm.totalFractions),
      totalDoseCgy: parseInt(rtForm.totalDoseCgy),
    }, fac));
    setDlg(null);
    loadProfileDetail(selectedProfileId);
    stats.refresh();
  }, [selectedProfileId, rtForm, fac, go, loadProfileDetail, stats]);

  const handleCreateRtPlan = useCallback(async (courseId: string, e: React.FormEvent) => {
    e.preventDefault();
    const fd = e.currentTarget as HTMLFormElement;
    const data = new FormData(fd);
    await go(() => oncologyApi.storeRtPlan(courseId, {
      planName: data.get('planName') as string,
      technique: data.get('technique') as string,
      energy: data.get('energy') as string || undefined,
      fractionDoseCgy: Number(data.get('fractionDoseCgy')),
      numFractions: Number(data.get('numFractions')),
      totalDoseCgy: Number(data.get('totalDoseCgy')),
    }, fac));
    loadCourseDetail(courseId);
  }, [fac, go, loadCourseDetail]);

  const handleRtPlanAction = useCallback(async (planId: string, action: string) => {
    await go(() => {
      switch (action) {
        case 'submit': return oncologyApi.submitRtPlan(planId, fac);
        case 'physicist': return oncologyApi.physicistCheck(planId, { approved: true, checklist: {} }, fac);
        case 'secondary': return oncologyApi.secondaryCheck(planId, { passed: true, checklist: {} }, fac);
        case 'ro-approval': return oncologyApi.roApproval(planId, { approved: true }, fac);
        default: return Promise.resolve(null);
      }
    });
    if (selectedCourseId) loadCourseDetail(selectedCourseId);
  }, [fac, go, selectedCourseId, loadCourseDetail]);

  // Census computation
  const activeProfiles = allProfiles.filter(p => p.status === 'active').length;
  const inRemission = allProfiles.filter(p => p.status === 'in_remission').length;

  return (
    <div className="page onc-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Oncology</h1>
          <p className="page__subtitle">Cancer care, treatment planning, multidisciplinary review, radiotherapy</p>
        </div>
        <div className="onc-actions">
          <Button variant="ghost" onClick={() => { profiles.refresh(); stats.refresh(); machines.refresh(); }}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="onc-census">
        <div className="onc-census-card onc-census-card--profiles">
          <span className="onc-census-value">{statsData?.active_profiles ?? activeProfiles}</span>
          <span className="onc-census-label">Active Patients</span>
        </div>
        <div className="onc-census-card onc-census-card--remission">
          <span className="onc-census-value" style={{ color: '#3b82f6' }}>{statsData?.in_remission ?? inRemission}</span>
          <span className="onc-census-label">In Remission</span>
        </div>
        <div className="onc-census-card onc-census-card--plans">
          <span className="onc-census-value">{statsData?.active_treatment_plans ?? 0}</span>
          <span className="onc-census-label">Active Plans</span>
        </div>
        <div className="onc-census-card onc-census-card--rt">
          <span className="onc-census-value" style={{ color: '#8b5cf6' }}>{statsData?.active_rt_courses ?? 0}</span>
          <span className="onc-census-label">Active RT Courses</span>
        </div>
        <div className="onc-census-card onc-census-card--machines">
          <span className="onc-census-value">{allMachines.length}</span>
          <span className="onc-census-label">Treatment Machines</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="onc-tabs">
        {(['profiles', 'treatment', 'rt', 'mdt', 'machines'] as const).map(t => (
          <button key={t} className={`onc-tab ${activeTab === t ? 'onc-tab--active' : ''}`}
            onClick={() => { setActiveTab(t); setSelectedProfileId(null); setPlanDetail(null); setSelectedCourseId(null); setCourseDetail(null); }}>
            {t === 'profiles' ? 'Oncology Profiles' : t === 'treatment' ? 'Treatment Plans' : t === 'rt' ? 'Radiotherapy' : t === 'mdt' ? 'MDT Reviews' : 'Machines'}
          </button>
        ))}
      </div>

      {/* ── Profiles Tab ──────────────────────────────────── */}
      {activeTab === 'profiles' && (
        <Card className="onc-section-card">
          <div className="onc-section-header">
            <h3>Oncology Profiles</h3>
            <Button variant="primary" size="sm" onClick={() => setDlg('new-profile')}>+ New Profile</Button>
          </div>

          {allProfiles.length === 0 ? (
            <EmptyState title="No oncology profiles" body="Create an oncology profile to begin tracking a patient's cancer journey." />
          ) : (
            <div className="onc-profile-grid">
              {allProfiles.map(p => (
                <div key={p.id}
                  className={`onc-profile-card ${selectedProfileId === p.id ? 'onc-profile-card--selected' : ''}`}
                  onClick={() => loadProfileDetail(p.id)}>
                  <div className="onc-profile-card__header">
                    <span className="onc-profile-card__diagnosis">{p.primaryDiagnosis ?? 'No diagnosis'}</span>
                    <StatusBadge status={p.status} config={PROFILE_STATUS} />
                  </div>
                  <div className="onc-profile-card__meta">
                    <span>{p.cancerSite ?? 'Unknown site'}</span>
                    <span>Stage: {p.overallStage ?? '—'}</span>
                    <span>ECOG: {p.performanceStatus ?? '—'}</span>
                  </div>
                  <div className="onc-profile-card__footer">
                    <Link to={`/patients/${p.patientId}`} onClick={e => e.stopPropagation()}>
                      <Button variant="ghost" size="sm">Patient Record</Button>
                    </Link>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Treatment Plans Tab ────────────────────────────── */}
      {activeTab === 'treatment' && (
        <Card className="onc-section-card">
          <div className="onc-section-header">
            <h3>Treatment Plans</h3>
            <div className="onc-section-actions">
              {allProfiles.length > 0 && (
                <select className="onc-select" value={selectedProfileId ?? ''} onChange={e => loadProfileDetail(e.target.value)}>
                  <option value="">Select patient...</option>
                  {allProfiles.map(p => <option key={p.id} value={p.id}>{p.primaryDiagnosis ?? p.id} — {p.cancerSite ?? 'Unknown'}</option>)}
                </select>
              )}
              <Button variant="primary" size="sm" onClick={() => setDlg('new-plan')} disabled={!selectedProfileId}>+ New Plan</Button>
            </div>
          </div>

          {!selectedProfileId ? (
            <EmptyState title="Select a patient" body="Select an oncology profile to view treatment plans." />
          ) : !planDetail ? (
            <EmptyState title="No treatment plan" body="Create a new treatment plan for this patient." />
          ) : (
            <div className="onc-plan-detail">
              <div className="onc-plan-header">
                <div>
                  <span className="onc-plan-name">{planDetail.protocolName ?? planDetail.planType}</span>
                  <span className="onc-plan-meta">{planDetail.intent} · Line: {planDetail.lineOfTherapy}</span>
                </div>
                <StatusBadge status={planDetail.status} config={PLAN_STATUS} />
              </div>

              {planDetail.plannedCycles && (
                <div className="onc-cycle-progress">
                  <div className="onc-cycle-bar">
                    <div className="onc-cycle-fill" style={{ width: `${(planDetail.completedCycles / planDetail.plannedCycles) * 100}%` }} />
                  </div>
                  <span className="onc-cycle-text">Cycle {planDetail.completedCycles} of {planDetail.plannedCycles}</span>
                </div>
              )}

              {/* Medications */}
              {planDetail.medications.length > 0 && (
                <div className="onc-meds">
                  <h4>Medications</h4>
                  {planDetail.medications.map(m => (
                    <div key={m.id} className="onc-med">
                      <span className="onc-med-name">{m.medicationName}</span>
                      <span className="onc-med-rx">{m.dose} {m.doseUnit} · {m.route} · {m.frequency}</span>
                    </div>
                  ))}
                </div>
              )}

              {/* Actions */}
              <div className="onc-actions-row">
                {planDetail.status === 'draft' && (
                  <Button variant="primary" size="sm" onClick={() => handleStartCycle(planDetail.id)}>
                    Start First Cycle
                  </Button>
                )}
                {planDetail.status === 'active' && (
                  <Button variant="primary" size="sm" onClick={() => handleStartCycle(planDetail.id)}>
                    Start Next Cycle
                  </Button>
                )}
              </div>
            </div>
          )}
        </Card>
      )}

      {/* ── Radiotherapy Tab ───────────────────────────────── */}
      {activeTab === 'rt' && (
        <Card className="onc-section-card">
          <div className="onc-section-header">
            <h3>Radiotherapy</h3>
            <div className="onc-section-actions">
              {allProfiles.length > 0 && (
                <select className="onc-select" value={selectedProfileId ?? ''} onChange={e => loadProfileDetail(e.target.value)}>
                  <option value="">Select patient...</option>
                  {allProfiles.map(p => <option key={p.id} value={p.id}>{p.primaryDiagnosis ?? p.id} — {p.cancerSite ?? 'Unknown'}</option>)}
                </select>
              )}
              <Button variant="primary" size="sm" onClick={() => setDlg('new-rt-course')} disabled={!selectedProfileId}>+ New RT Course</Button>
            </div>
          </div>

          {selectedCourseId && courseDetail ? (
            <div className="onc-rt-detail">
              <button className="onc-back-link" onClick={() => { setSelectedCourseId(null); setCourseDetail(null); }}>
                ← Back to courses
              </button>
              <div className="onc-rt-header">
                <div>
                  <span className="onc-rt-name">RT Course — {courseDetail.intent}</span>
                  <span className="onc-rt-meta">
                    Fractions: {courseDetail.completedFractions}/{courseDetail.totalFractions} · Dose: {courseDetail.totalDoseCgy} cGy
                  </span>
                </div>
                <StatusBadge status={courseDetail.status} config={RT_STATUS} />
              </div>

              {/* RT Plans */}
              <div className="onc-rt-plans">
                <div className="onc-section-subheader">
                  <h4>Treatment Plans</h4>
                  <Button variant="ghost" size="sm" onClick={() => setDlg('new-rt-plan')}>+ New Plan</Button>
                </div>

                {(courseDetail.rtPlans ?? []).map(rp => (
                  <div key={rp.id} className="onc-rt-plan">
                    <div className="onc-rt-plan__header">
                      <div>
                        <span className="onc-rt-plan__name">{rp.planName} — {rp.technique}</span>
                        <span className="onc-rt-plan__dose">
                          {rp.fractionDoseCgy} cGy × {rp.numFractions} = {rp.totalDoseCgy} cGy
                          {rp.energy && ` · ${rp.energy}`}
                        </span>
                        <span className="onc-rt-plan__approvals">
                          Physicist: {rp.physicistApprovedAt ? '✓' : '○'}
                          {rp.requiresSecondaryCheck && ` · Secondary: ${rp.physicistApprovedAt ? '○' : '—'}`}
                          {' · RO: '}{rp.roApprovedAt ? '✓' : '○'}
                        </span>
                      </div>
                      <StatusBadge status={rp.status} config={RT_STATUS} />
                    </div>

                    <div className="onc-rt-plan__actions">
                      {rp.status === 'draft' && (
                        <Button variant="primary" size="sm" onClick={() => handleRtPlanAction(rp.id, 'submit')}>
                          Submit for Review
                        </Button>
                      )}
                      {rp.status === 'in_review' && (
                        <>
                          <Button variant="ghost" size="sm" onClick={() => handleRtPlanAction(rp.id, 'physicist')}>
                            Physicist Check
                          </Button>
                          {rp.requiresSecondaryCheck && rp.physicistApprovedAt && (
                            <Button variant="ghost" size="sm" onClick={() => handleRtPlanAction(rp.id, 'secondary')}>
                              Secondary Check
                            </Button>
                          )}
                          {rp.physicistApprovedAt && (!rp.requiresSecondaryCheck || rp.isFullyApproved) && (
                            <Button variant="primary" size="sm" onClick={() => handleRtPlanAction(rp.id, 'ro-approval')}>
                              RO Approve
                            </Button>
                          )}
                        </>
                      )}
                    </div>

                    {/* Fractions */}
                    {rp.fractions && rp.fractions.length > 0 && (
                      <div className="onc-fractions">
                        {rp.fractions.map(f => (
                          <div key={f.id} className="onc-fraction">
                            <span className="onc-fraction__num">#{f.fractionNumber}</span>
                            <span className="onc-fraction__dose">{f.doseCgy} cGy</span>
                            <StatusBadge status={f.status} config={RT_STATUS} />
                            <span className="onc-fraction__delivered">
                              {f.sessions.length > 0 ? `${f.sessions[0].deliveredDoseCgy} cGy delivered` : '—'}
                            </span>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ) : (
            <EmptyState title="Select an RT course" body="Select an oncology profile and create or view an RT course." />
          )}
        </Card>
      )}

      {/* ── MDT Tab ───────────────────────────────────────── */}
      {activeTab === 'mdt' && (
        <Card className="onc-section-card">
          <div className="onc-section-header">
            <h3>Multidisciplinary Team Reviews</h3>
            <div className="onc-section-actions">
              {allProfiles.length > 0 && (
                <select className="onc-select" value={selectedProfileId ?? ''} onChange={e => loadProfileDetail(e.target.value)}>
                  <option value="">Select patient...</option>
                  {allProfiles.map(p => <option key={p.id} value={p.id}>{p.primaryDiagnosis ?? p.id} — {p.cancerSite ?? 'Unknown'}</option>)}
                </select>
              )}
              <Button variant="primary" size="sm" onClick={() => setDlg('new-mdt')} disabled={!selectedProfileId}>+ New Review</Button>
            </div>
          </div>
          <EmptyState title="MDT Reviews" body={selectedProfileId ? "Create a multidisciplinary team review for this patient." : "Select a patient profile to view MDT reviews."} />
        </Card>
      )}

      {/* ── Machines Tab ──────────────────────────────────── */}
      {activeTab === 'machines' && (
        <Card className="onc-section-card">
          <div className="onc-section-header">
            <h3>Treatment Machines (Linacs)</h3>
          </div>
          {allMachines.length === 0 ? (
            <EmptyState title="No treatment machines" body="No treatment machines are configured for this facility." />
          ) : (
            <div className="onc-machine-grid">
              {allMachines.map(m => (
                <div key={m.id} className="onc-machine-card">
                  <div className="onc-machine-card__header">
                    <span className="onc-machine-card__name">{m.name}</span>
                    <StatusBadge status={m.status} config={RT_STATUS} />
                  </div>
                  <div className="onc-machine-card__meta">
                    <span>{m.code}</span>
                    <span>{m.machineType}</span>
                    <span>Capacity: {m.dailyCapacity}/day</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* New Profile Dialog */}
      {dlg === 'new-profile' && (
        <Dialog open onClose={() => setDlg(null)} title="New Oncology Profile" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateProfile} loading={busy}>Create Profile</Button>
          </>
        }>
          <form onSubmit={handleCreateProfile} className="onc-form">
            <Input label="Patient ID" name="patientId" required placeholder="Patient UUID" />
            <Input label="Primary Diagnosis" name="primaryDiagnosis" placeholder="e.g. Invasive Ductal Carcinoma" />
            <Input label="Cancer Site" name="cancerSite" placeholder="e.g. Left Breast" />
            <div className="onc-form-row">
              <div className="onc-form-field">
                <label className="onc-label">Overall Stage</label>
                <select name="overallStage" className="onc-input">
                  <option value="">Select...</option>
                  {STAGE_OPTIONS.map(s => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <Input label="ECOG Performance Status" name="performanceStatus" placeholder="0-4" />
            </div>
          </form>
        </Dialog>
      )}

      {/* New Treatment Plan Dialog */}
      {dlg === 'new-plan' && (
        <Dialog open onClose={() => setDlg(null)} title="New Treatment Plan" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreatePlan} loading={busy}>Create Plan</Button>
          </>
        }>
          <form onSubmit={handleCreatePlan} className="onc-form">
            <div className="onc-form-row">
              <div className="onc-form-field">
                <label className="onc-label">Plan Type</label>
                <select name="planType" className="onc-input" value={planForm.planType} onChange={e => setPlanForm(f => ({ ...f, planType: e.target.value }))}>
                  <option value="chemotherapy">Chemotherapy</option>
                  <option value="immunotherapy">Immunotherapy</option>
                  <option value="targeted">Targeted Therapy</option>
                  <option value="hormone">Hormone Therapy</option>
                  <option value="combined">Combined</option>
                </select>
              </div>
              <Input label="Protocol Name" value={planForm.protocolName} onChange={e => setPlanForm(f => ({ ...f, protocolName: e.target.value }))} placeholder="e.g. AC-T" />
            </div>
            <div className="onc-form-row">
              <div className="onc-form-field">
                <label className="onc-label">Intent</label>
                <select className="onc-input" value={planForm.intent} onChange={e => setPlanForm(f => ({ ...f, intent: e.target.value }))}>
                  {INTENT_OPTIONS.map(i => <option key={i} value={i}>{i.charAt(0).toUpperCase() + i.slice(1)}</option>)}
                </select>
              </div>
              <div className="onc-form-field">
                <label className="onc-label">Line of Therapy</label>
                <select className="onc-input" value={planForm.lineOfTherapy} onChange={e => setPlanForm(f => ({ ...f, lineOfTherapy: e.target.value }))}>
                  {LINE_OPTIONS.map(l => <option key={l} value={l}>{l.charAt(0).toUpperCase() + l.slice(1)}</option>)}
                </select>
              </div>
            </div>
            <Input label="Planned Cycles" type="number" value={planForm.plannedCycles} onChange={e => setPlanForm(f => ({ ...f, plannedCycles: e.target.value }))} placeholder="e.g. 6" />
          </form>
        </Dialog>
      )}

      {/* New MDT Review Dialog */}
      {dlg === 'new-mdt' && (
        <Dialog open onClose={() => setDlg(null)} title="New MDT Review" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateMdtReview} loading={busy}>Create Review</Button>
          </>
        }>
          <form onSubmit={handleCreateMdtReview} className="onc-form">
            <Input label="Review Date" type="date" name="reviewDate" required value={mdtForm.reviewDate} onChange={e => setMdtForm(f => ({ ...f, reviewDate: e.target.value }))} />
            <Input label="Decision" value={mdtForm.decision} onChange={e => setMdtForm(f => ({ ...f, decision: e.target.value }))} placeholder="e.g. Proceed with surgery then adjuvant chemo" />
            <div className="onc-form-field">
              <label className="onc-label">Recommendations</label>
              <textarea className="onc-textarea" value={mdtForm.recommendations} onChange={e => setMdtForm(f => ({ ...f, recommendations: e.target.value }))} placeholder="MDT recommendations..." rows={3} />
            </div>
            <Input label="Attendees (comma-separated)" value={mdtForm.attendees} onChange={e => setMdtForm(f => ({ ...f, attendees: e.target.value }))} placeholder="e.g. Dr. Smith, Dr. Jones, Dr. Lee" />
          </form>
        </Dialog>
      )}

      {/* New RT Course Dialog */}
      {dlg === 'new-rt-course' && (
        <Dialog open onClose={() => setDlg(null)} title="New RT Course" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={handleCreateRtCourse} loading={busy}>Create Course</Button>
          </>
        }>
          <form onSubmit={handleCreateRtCourse} className="onc-form">
            <div className="onc-form-field">
              <label className="onc-label">Intent</label>
              <select className="onc-input" value={rtForm.intent} onChange={e => setRtForm(f => ({ ...f, intent: e.target.value }))}>
                {INTENT_OPTIONS.map(i => <option key={i} value={i}>{i.charAt(0).toUpperCase() + i.slice(1)}</option>)}
              </select>
            </div>
            <div className="onc-form-row">
              <Input label="Total Fractions" type="number" required value={rtForm.totalFractions} onChange={e => setRtForm(f => ({ ...f, totalFractions: e.target.value }))} placeholder="e.g. 30" />
              <Input label="Total Dose (cGy)" type="number" required value={rtForm.totalDoseCgy} onChange={e => setRtForm(f => ({ ...f, totalDoseCgy: e.target.value }))} placeholder="e.g. 6000" />
            </div>
          </form>
        </Dialog>
      )}

      {/* New RT Plan Dialog */}
      {dlg === 'new-rt-plan' && (
        <Dialog open onClose={() => setDlg(null)} title="New RT Plan" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={(e) => { e.preventDefault(); if (selectedCourseId) { const form = document.querySelector('.onc-form') as HTMLFormElement; if (form) form.requestSubmit(); } }} loading={busy}>Create Plan</Button>
          </>
        }>
          <form onSubmit={(e) => { if (selectedCourseId) handleCreateRtPlan(selectedCourseId, e); }} className="onc-form">
            <Input label="Plan Name" name="planName" required placeholder="e.g. PTV Boost" />
            <div className="onc-form-field">
              <label className="onc-label">Technique</label>
              <select name="technique" className="onc-input">
                {TECHNIQUE_OPTIONS.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <Input label="Energy" name="energy" placeholder="e.g. 6 MV" />
            <div className="onc-form-row">
              <Input label="Fraction Dose (cGy)" type="number" name="fractionDoseCgy" required placeholder="e.g. 200" />
              <Input label="Number of Fractions" type="number" name="numFractions" required placeholder="e.g. 30" />
              <Input label="Total Dose (cGy)" type="number" name="totalDoseCgy" required placeholder="e.g. 6000" />
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
