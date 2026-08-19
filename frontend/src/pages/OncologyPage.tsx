import { useState, useEffect } from 'react';
import { useTenant } from '../context/TenantContext';
import { oncologyApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Card, EmptyState, Spinner } from '../components/ui';

type OncologyProfile = {
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
};

type TreatmentPlan = {
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
};

type RtCourse = {
  id: string;
  oncologyProfileId: string;
  intent: string;
  status: string;
  totalFractions: number;
  completedFractions: number;
  totalDoseCgy: number;
  rtPlans: RtPlan[];
};

type RtPlan = {
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
};

type Fraction = {
  id: string;
  fractionNumber: number;
  doseCgy: number;
  status: string;
  sessions: Session[];
};

type Session = {
  id: string;
  machineId: string;
  status: string;
  deliveredDoseCgy: number | null;
};

type Machine = {
  id: string;
  code: string;
  name: string;
  machineType: string;
  status: string;
  dailyCapacity: number;
};

type Stats = {
  active_profiles: number;
  in_remission: number;
  active_treatment_plans: number;
  active_rt_courses: number;
};

const statusColors: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  in_remission: 'bg-blue-100 text-blue-800',
  deceased: 'bg-gray-100 text-gray-800',
  draft: 'bg-yellow-100 text-yellow-800',
  completed: 'bg-green-100 text-green-800',
  discontinued: 'bg-red-100 text-red-800',
  in_progress: 'bg-blue-100 text-blue-800',
  planned: 'bg-gray-100 text-gray-800',
  in_review: 'bg-orange-100 text-orange-800',
  approved: 'bg-green-100 text-green-800',
  delivered: 'bg-green-100 text-green-800',
  missed: 'bg-red-100 text-red-800',
};

function StatusBadge({ status }: { status: string }) {
  const cls = statusColors[status] ?? 'bg-gray-100 text-gray-700';
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${cls}`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

export function OncologyPage() {
  const { selectedFacilityId: facilityId } = useTenant();
  const [tab, setTab] = useState<'profiles' | 'rt' | 'machines'>('profiles');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stats, setStats] = useState<Stats | null>(null);
  const [profiles, setProfiles] = useState<OncologyProfile[]>([]);
  const [selectedProfile, setSelectedProfile] = useState<OncologyProfile | null>(null);
  const [profileDetail, setProfileDetail] = useState<Record<string, unknown> | null>(null);
  const [selectedCourse, setSelectedCourse] = useState<RtCourse | null>(null);
  const [machines, setMachines] = useState<Machine[]>([]);
  const [showNewProfile, setShowNewProfile] = useState(false);
  const [showNewRtPlan, setShowNewRtPlan] = useState(false);

  useEffect(() => {
    loadData();
  }, [facilityId]);

  async function loadData() {
    setLoading(true);
    setError(null);
    try {
      const [statsRes, profilesRes, machinesRes] = await Promise.all([
        oncologyApi.stats(facilityId),
        oncologyApi.listProfiles(facilityId),
        oncologyApi.listMachines(facilityId),
      ]);
      setStats(statsRes as unknown as Stats);
      setProfiles((profilesRes as unknown as { data: OncologyProfile[] }).data ?? []);
      setMachines(machinesRes as unknown as Machine[]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load oncology data');
    } finally {
      setLoading(false);
    }
  }

  async function loadProfileDetail(id: string) {
    try {
      const detail = await oncologyApi.showProfile(id, facilityId);
      setProfileDetail(detail as Record<string, unknown>);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load profile');
    }
  }

  async function loadRtCourse(courseId: string) {
    try {
      const course = await oncologyApi.showRtCourse(courseId, facilityId) as unknown as RtCourse;
      setSelectedCourse(course);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load RT course');
    }
  }

  async function handleCreateProfile(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    try {
      await oncologyApi.storeProfile({
        patientId: fd.get('patientId') as string,
        primaryDiagnosis: fd.get('primaryDiagnosis') || undefined,
        cancerSite: fd.get('cancerSite') || undefined,
        overallStage: fd.get('overallStage') || undefined,
        performanceStatus: fd.get('performanceStatus') || undefined,
      }, facilityId);
      setShowNewProfile(false);
      await loadData();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create profile');
    }
  }

  async function handleCreateRtPlan(courseId: string, e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    try {
      await oncologyApi.storeRtPlan(courseId, {
        planName: fd.get('planName') as string,
        technique: fd.get('technique') as string,
        fractionDoseCgy: Number(fd.get('fractionDoseCgy')),
        numFractions: Number(fd.get('numFractions')),
        totalDoseCgy: Number(fd.get('totalDoseCgy')),
        energy: fd.get('energy') || undefined,
      }, facilityId);
      setShowNewRtPlan(false);
      await loadRtCourse(courseId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create RT plan');
    }
  }

  async function handleRtPlanAction(planId: string, action: string) {
    try {
      switch (action) {
        case 'submit':
          await oncologyApi.submitRtPlan(planId, facilityId);
          break;
        case 'physicist':
          await oncologyApi.physicistCheck(planId, { approved: true, checklist: {} }, facilityId);
          break;
        case 'secondary':
          await oncologyApi.secondaryCheck(planId, { passed: true, checklist: {} }, facilityId);
          break;
        case 'ro-approval':
          await oncologyApi.roApproval(planId, { approved: true }, facilityId);
          break;
      }
      if (selectedCourse) {
        await loadRtCourse(selectedCourse.id);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Action failed');
    }
  }

  if (loading)    return <div className="flex justify-center p-8"><Spinner /></div>;
  if (error) return <div className="p-8 text-center text-red-600">{error}</div>;

  return (
    <div className="space-y-6">
      {/* Stats Bar */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card className="p-4">
            <div className="text-sm text-gray-500">Active Profiles</div>
            <div className="text-2xl font-bold">{stats.active_profiles}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">In Remission</div>
            <div className="text-2xl font-bold text-blue-600">{stats.in_remission}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">Active Treatment Plans</div>
            <div className="text-2xl font-bold">{stats.active_treatment_plans}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">Active RT Courses</div>
            <div className="text-2xl font-bold text-purple-600">{stats.active_rt_courses}</div>
          </Card>
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-4 border-b pb-2">
        {(['profiles', 'rt', 'machines'] as const).map((t) => (
          <button
            key={t}
            onClick={() => { setTab(t); setSelectedProfile(null); setProfileDetail(null); setSelectedCourse(null); }}
            className={`px-4 py-2 text-sm font-medium rounded-t ${tab === t ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500 hover:text-gray-700'}`}
          >
            {t === 'profiles' ? 'Oncology Profiles' : t === 'rt' ? 'Radiotherapy' : 'Treatment Machines'}
          </button>
        ))}
      </div>

      {/* Oncology Profiles Tab */}
      {tab === 'profiles' && (
        <div className="space-y-4">
          <div className="flex justify-between items-center">
            <h3 className="text-lg font-semibold">Oncology Profiles</h3>
            <button onClick={() => setShowNewProfile(true)} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">
              + New Profile
            </button>
          </div>

          {showNewProfile && (
            <Card className="p-6">
              <h4 className="font-semibold mb-4">New Oncology Profile</h4>
              <form onSubmit={handleCreateProfile} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input name="patientId" placeholder="Patient ID" required className="border rounded px-3 py-2" />
                <input name="primaryDiagnosis" placeholder="Primary Diagnosis" className="border rounded px-3 py-2" />
                <input name="cancerSite" placeholder="Cancer Site" className="border rounded px-3 py-2" />
                <input name="overallStage" placeholder="Overall Stage (e.g. IIIA)" className="border rounded px-3 py-2" />
                <input name="performanceStatus" placeholder="ECOG Status" className="border rounded px-3 py-2" />
                <div className="flex gap-2">
                  <button type="submit" className="px-4 py-2 bg-green-600 text-white rounded text-sm">Create</button>
                  <button type="button" onClick={() => setShowNewProfile(false)} className="px-4 py-2 bg-gray-200 rounded text-sm">Cancel</button>
                </div>
              </form>
            </Card>
          )}

          {profiles.length === 0 ? (
            <EmptyState title="No oncology profiles" body="Create an oncology profile to get started." />
          ) : (
            <div className="grid gap-4">
              {profiles.map((p) => (
                <Card
                  key={p.id}
                  className={`p-4 cursor-pointer hover:shadow ${selectedProfile?.id === p.id ? 'ring-2 ring-blue-500' : ''}`}
                  onClick={() => { setSelectedProfile(p); loadProfileDetail(p.id); }}
                >
                  <div className="flex justify-between items-start">
                    <div>
                      <div className="font-medium">{p.primaryDiagnosis ?? 'No diagnosis'} — {p.cancerSite ?? 'Unknown site'}</div>
                      <div className="text-sm text-gray-500 mt-1">
                        Stage: {p.overallStage ?? '—'} | TNM: {p.tnmStaging ?? '—'} | ECOG: {p.performanceStatus ?? '—'}
                      </div>
                    </div>
                    <StatusBadge status={p.status} />
                  </div>
                </Card>
              ))}
            </div>
          )}

          {/* Profile Detail */}
          {selectedProfile && profileDetail && (
            <Card className="p-6">
              <h4 className="font-semibold mb-4">Profile Detail — {selectedProfile.primaryDiagnosis}</h4>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                <div><span className="text-gray-500">Cancer Site:</span> {selectedProfile.cancerSite ?? '—'}</div>
                <div><span className="text-gray-500">Histology:</span> {selectedProfile.histology ?? '—'}</div>
                <div><span className="text-gray-500">Grade:</span> {selectedProfile.grade ?? '—'}</div>
                <div><span className="text-gray-500">Diagnosed:</span> {selectedProfile.diagnosedAt ?? '—'}</div>
              </div>
              {/* Treatment Plans */}
              {Array.isArray((profileDetail as Record<string, unknown>).treatmentPlans) && (
                <div className="mt-4">
                  <h5 className="font-medium mb-2">Treatment Plans</h5>
                  {((profileDetail as Record<string, unknown>).treatmentPlans as TreatmentPlan[]).map((tp) => (
                    <div key={tp.id} className="p-3 bg-gray-50 rounded mb-2">
                      <div className="flex justify-between">
                        <span className="font-medium">{tp.protocolName ?? tp.planType}</span>
                        <StatusBadge status={tp.status} />
                      </div>
                      <div className="text-sm text-gray-500 mt-1">
                        {tp.protocolCode && `${tp.protocolCode} · `}{tp.intent} · Line: {tp.lineOfTherapy}
                        {tp.plannedCycles && ` · Cycles: ${tp.completedCycles}/${tp.plannedCycles}`}
                      </div>
                      {tp.medications.length > 0 && (
                        <div className="mt-2 text-sm">
                          {tp.medications.map((m) => (
                            <div key={m.id}>{m.medicationName} — {m.dose} {m.doseUnit} {m.route} {m.frequency}</div>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </Card>
          )}
        </div>
      )}

      {/* Radiotherapy Tab */}
      {tab === 'rt' && (
        <div className="space-y-4">
          <h3 className="text-lg font-semibold">Radiotherapy Courses</h3>
          {profiles.length === 0 ? (
            <EmptyState title="No oncology profiles" body="Create an oncology profile first to start radiotherapy." />
          ) : (
            <div className="space-y-4">
              {selectedCourse ? (
                <div className="space-y-4">
                  <button onClick={() => setSelectedCourse(null)} className="text-blue-600 text-sm">&larr; Back to courses</button>
                  <Card className="p-6">
                    <div className="flex justify-between items-start mb-4">
                      <div>
                        <div className="font-semibold text-lg">RT Course — {selectedCourse.intent}</div>
                        <div className="text-sm text-gray-500">
                          Fractions: {selectedCourse.completedFractions}/{selectedCourse.totalFractions} · Dose: {selectedCourse.totalDoseCgy} cGy
                        </div>
                      </div>
                      <StatusBadge status={selectedCourse.status} />
                    </div>

                    {/* RT Plans */}
                    <div className="space-y-4">
                      <div className="flex justify-between items-center">
                        <h5 className="font-medium">Treatment Plans</h5>
                        <button onClick={() => setShowNewRtPlan(true)} className="px-3 py-1 bg-blue-600 text-white rounded text-sm">
                          + New RT Plan
                        </button>
                      </div>

                      {showNewRtPlan && (
                        <div className="p-4 bg-gray-50 rounded">
                          <form onSubmit={(e) => handleCreateRtPlan(selectedCourse.id, e)} className="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <input name="planName" placeholder="Plan Name" required className="border rounded px-3 py-2 text-sm" />
                            <select name="technique" required className="border rounded px-3 py-2 text-sm">
                              <option value="">Technique...</option>
                              <option value="VMAT">VMAT</option>
                              <option value="IMRT">IMRT</option>
                              <option value="3DCRT">3D-CRT</option>
                              <option value="electron">Electron</option>
                              <option value="SRS">SRS</option>
                              <option value="SBRT">SBRT</option>
                            </select>
                            <input name="energy" placeholder="Energy (e.g. 6 MV)" className="border rounded px-3 py-2 text-sm" />
                            <input name="fractionDoseCgy" type="number" placeholder="Fraction Dose (cGy)" required className="border rounded px-3 py-2 text-sm" />
                            <input name="numFractions" type="number" placeholder="Number of Fractions" required className="border rounded px-3 py-2 text-sm" />
                            <input name="totalDoseCgy" type="number" placeholder="Total Dose (cGy)" required className="border rounded px-3 py-2 text-sm" />
                            <div className="flex gap-2">
                              <button type="submit" className="px-3 py-1 bg-green-600 text-white rounded text-sm">Create</button>
                              <button type="button" onClick={() => setShowNewRtPlan(false)} className="px-3 py-1 bg-gray-200 rounded text-sm">Cancel</button>
                            </div>
                          </form>
                        </div>
                      )}

                      {(selectedCourse.rtPlans ?? []).map((rp) => (
                        <Card key={rp.id} className="p-4">
                          <div className="flex justify-between items-start mb-3">
                            <div>
                              <div className="font-medium">{rp.planName} — {rp.technique}</div>
                              <div className="text-sm text-gray-500">
                                {rp.fractionDoseCgy} cGy × {rp.numFractions} = {rp.totalDoseCgy} cGy
                                {rp.energy && ` · ${rp.energy}`}
                              </div>
                              <div className="text-xs text-gray-400 mt-1">
                                Physicist: {rp.physicistApprovedAt ? '✓' : '○'}
                                {rp.requiresSecondaryCheck && ` · Secondary: ${rp.physicistApprovedAt ? '○' : '—'}`}
                                {' · RO: '}{rp.roApprovedAt ? '✓' : '○'}
                              </div>
                            </div>
                            <StatusBadge status={rp.status} />
                          </div>

                          {/* Action Buttons */}
                          <div className="flex gap-2 flex-wrap">
                            {rp.status === 'draft' && (
                              <button onClick={() => handleRtPlanAction(rp.id, 'submit')} className="px-3 py-1 bg-yellow-500 text-white rounded text-xs">
                                Submit for Review
                              </button>
                            )}
                            {rp.status === 'in_review' && (
                              <>
                                <button onClick={() => handleRtPlanAction(rp.id, 'physicist')} className="px-3 py-1 bg-blue-500 text-white rounded text-xs">
                                  Physicist Check ✓
                                </button>
                                {rp.requiresSecondaryCheck && rp.physicistApprovedAt && (
                                  <button onClick={() => handleRtPlanAction(rp.id, 'secondary')} className="px-3 py-1 bg-purple-500 text-white rounded text-xs">
                                    Secondary Check ✓
                                  </button>
                                )}
                                {rp.physicistApprovedAt && (!rp.requiresSecondaryCheck || rp.isFullyApproved) && (
                                  <button onClick={() => handleRtPlanAction(rp.id, 'ro-approval')} className="px-3 py-1 bg-green-600 text-white rounded text-xs">
                                    RO Approve ✓
                                  </button>
                                )}
                              </>
                            )}
                          </div>

                          {/* Fractions */}
                          {rp.fractions && rp.fractions.length > 0 && (
                            <div className="mt-3 overflow-x-auto">
                              <table className="min-w-full text-xs">
                                <thead>
                                  <tr className="text-left text-gray-500 border-b">
                                    <th className="pb-1 pr-4">#</th>
                                    <th className="pb-1 pr-4">Dose</th>
                                    <th className="pb-1 pr-4">Status</th>
                                    <th className="pb-1">Delivered</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {rp.fractions.map((f) => (
                                    <tr key={f.id} className="border-b border-gray-100">
                                      <td className="py-1 pr-4">{f.fractionNumber}</td>
                                      <td className="py-1 pr-4">{f.doseCgy} cGy</td>
                                      <td className="py-1 pr-4"><StatusBadge status={f.status} /></td>
                                      <td className="py-1">{f.sessions.length > 0 ? `${f.sessions[0].deliveredDoseCgy} cGy` : '—'}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          )}
                        </Card>
                      ))}
                    </div>
                  </Card>
                </div>
              ) : (
                <div className="space-y-4">
                  {profiles.length > 0 && (
                    <Card className="p-4">
                      <h5 className="font-medium mb-2">Create RT Course for a Profile</h5>
                      <CreateRtCourseForm profiles={profiles} facilityId={facilityId} onCreated={loadData} />
                    </Card>
                  )}
                  {!selectedProfile && (
                    <EmptyState title="Select a profile" body="Select an oncology profile to view its RT courses." />
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Treatment Machines Tab */}
      {tab === 'machines' && (
        <div className="space-y-4">
          <h3 className="text-lg font-semibold">Treatment Machines (Linacs)</h3>
          {machines.length === 0 ? (
            <EmptyState title="No treatment machines" body="No treatment machines are configured for this facility." />
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {machines.map((m) => (
                <Card key={m.id} className="p-4">
                  <div className="flex justify-between items-start">
                    <div>
                      <div className="font-medium">{m.name}</div>
                      <div className="text-sm text-gray-500">
                        {m.code} · {m.machineType} · Capacity: {m.dailyCapacity}/day
                      </div>
                    </div>
                    <StatusBadge status={m.status} />
                  </div>
                </Card>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function CreateRtCourseForm({
  profiles,
  facilityId,
  onCreated,
}: {
  profiles: OncologyProfile[];
  facilityId: string | null;
  onCreated: () => void;
}) {
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    const fd = new FormData(e.currentTarget);
    try {
      await oncologyApi.storeRtCourse(fd.get('profileId') as string, {
        intent: fd.get('intent') || 'curative',
        totalFractions: Number(fd.get('totalFractions')),
        totalDoseCgy: Number(fd.get('totalDoseCgy')),
      }, facilityId);
      onCreated();
    } catch {
      // parent handles errors
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-4 gap-3">
      <select name="profileId" required className="border rounded px-3 py-2 text-sm">
        <option value="">Select profile...</option>
        {profiles.map((p) => (
          <option key={p.id} value={p.id}>{p.primaryDiagnosis ?? p.id} — {p.cancerSite ?? 'Unknown'}</option>
        ))}
      </select>
      <select name="intent" className="border rounded px-3 py-2 text-sm">
        <option value="curative">Curative</option>
        <option value="adjuvant">Adjuvant</option>
        <option value="neoadjuvant">Neoadjuvant</option>
        <option value="palliative">Palliative</option>
      </select>
      <input name="totalFractions" type="number" placeholder="Total Fractions" required className="border rounded px-3 py-2 text-sm" />
      <input name="totalDoseCgy" type="number" placeholder="Total Dose (cGy)" required className="border rounded px-3 py-2 text-sm" />
      <div className="md:col-span-4">
        <button type="submit" disabled={loading} className="px-4 py-2 bg-green-600 text-white rounded text-sm disabled:opacity-50">
          {loading ? 'Creating...' : 'Create RT Course'}
        </button>
      </div>
    </form>
  );
}
