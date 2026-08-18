import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { billingApi, catalogsApi, encountersApi, labOrdersApi, labTestsApi, radiologyApi } from '../api/endpoints';
import { FollowUpList } from '../components/FollowUpList';
import { CreateFollowUpDialog } from '../components/CreateFollowUpDialog';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, EmptyState, ErrorState, Input, Select, Spinner, Textarea } from '../components/ui';
import { ApiError } from '../api/client';
import { money } from '../components/ui';
import { BILLING_ROLES } from '../auth/roles';

export function EncounterPage() {
  const { id } = useParams<{ id: string }>();
  const { selectedFacilityId, organizationId, hasRole } = useTenant();
  const fac = selectedFacilityId;
  const navigate = useNavigate();
  const canBill = hasRole(...BILLING_ROLES);

  const encounter = useFetch(() => encountersApi.show(id!, fac), [id, fac]);
  const notes = useFetch(() => encountersApi.notes(id!, fac), [id, fac]);
  const medications = useFetch(() => catalogsApi.medications(organizationId ?? '', fac), [organizationId, fac]);

  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [tab, setTab] = useState<'note' | 'diagnosis' | 'prescription' | 'lab' | 'radiology' | 'followup'>('note');
  const [busy, setBusy] = useState(false);

  if (encounter.loading) return <Spinner />;
  if (encounter.error) return <ErrorState error={encounter.error} onRetry={() => void encounter.refresh()} />;
  const enc = encounter.data!;
  const signed = enc.status === 'signed' || enc.status === 'closed';

  const showError = (err: unknown) => {
    setNotice({ tone: 'danger', text: err instanceof ApiError ? err.message : 'Action failed.' });
  };

  const refreshAll = async () => {
    await Promise.all([encounter.refresh(), notes.refresh()]);
  };

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Encounter</h1>
          <span className="page__sub">
            {enc.patient?.fullName ?? 'Patient'} <span className="mono">{enc.patient?.mrn ?? ''}</span> · {enc.provider?.fullName ?? '—'} · {enc.status}
          </span>
        </div>
        {!signed && (
          <Button variant="danger-outline" loading={busy} onClick={async () => { setBusy(true); try { await encountersApi.sign(id!, fac); setNotice({ tone: 'success', text: 'Encounter signed — it is now immutable history.' }); await refreshAll(); } catch (err) { showError(err); } finally { setBusy(false); } }}>
            Sign encounter
          </Button>
        )}
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {signed && <Alert tone="info">This encounter is signed and cannot be edited.</Alert>}

      {canBill && (
        <Card title="Billing">
          <p className="muted small">
            Issue the invoice from the signed encounter charges. The backend derives charges
            (consultation + prescription lines) and returns the invoice.
          </p>
          <div className="row mt-4">
            <Button
              loading={busy}
              disabled={!signed}
              onClick={async () => {
                setBusy(true);
                try {
                  const inv = await billingApi.invoice(id!, fac);
                  setNotice({ tone: 'success', text: `Invoice ${inv.invoiceNumber} issued — ${money(inv.totalMinor)}.` });
                  navigate(`/billing/${inv.id}`);
                } catch (err) {
                  showError(err);
                } finally {
                  setBusy(false);
                }
              }}
            >
              Issue invoice
            </Button>
          </div>
        </Card>
      )}

      <div className="tabs" role="tablist">
        {(['note', 'diagnosis', 'prescription', 'lab', 'radiology', 'followup'] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} className={`tabs__tab ${tab === t ? 'tabs__tab--active' : ''}`} onClick={() => setTab(t)}>
            {t === 'note' ? 'Clinical note' : t === 'diagnosis' ? 'Diagnosis' : t === 'prescription' ? 'Prescription' : t === 'lab' ? 'Lab orders' : t === 'radiology' ? 'Radiology' : 'Follow-ups'}
          </button>
        ))}
      </div>

      {tab === 'note' && <NoteTab encounterId={id!} fac={fac} signed={signed} notes={notes} onError={showError} onSaved={() => { setNotice({ tone: 'success', text: 'Note saved.' }); void notes.refresh(); }} />}
      {tab === 'diagnosis' && <DiagnosisTab encounterId={id!} fac={fac} signed={signed} onError={showError} onSaved={() => setNotice({ tone: 'success', text: 'Diagnosis recorded.' })} />}
      {tab === 'prescription' && <PrescriptionTab encounterId={id!} fac={fac} signed={signed} medications={medications} onError={showError} onSaved={() => setNotice({ tone: 'success', text: 'Prescription drafted.' })} />}
      {tab === 'lab' && <LabOrdersTab encounterId={id!} fac={fac} signed={signed} onError={showError} onSaved={() => { setNotice({ tone: 'success', text: 'Lab order placed.' }); }} />}
      {tab === 'radiology' && <RadiologyOrdersTab encounterId={id!} fac={fac} signed={signed} onError={showError} onSaved={() => { setNotice({ tone: 'success', text: 'Radiology order placed.' }); }} />}
      {tab === 'followup' && (
        <FollowUpTab
          encounterId={id!}
          signed={signed}
          providerStaffId={enc.providerStaffId}
          onRefresh={() => void encounter.refresh()}
        />
      )}

      <Card title="Patient">
        <Link to={`/patients/${enc.patientId}`}>Open patient profile →</Link>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------------ Note */

function NoteTab({ encounterId, fac, signed, notes, onError, onSaved }: { encounterId: string; fac: string | null; signed: boolean; notes: ReturnType<typeof useFetch<import('../api/types').ClinicalNote[]>>; onError: (e: unknown) => void; onSaved: () => void }) {
  const [complaint, setComplaint] = useState('');
  const [examination, setExamination] = useState('');
  const [assessment, setAssessment] = useState('');
  const [plan, setPlan] = useState('');
  const [busy, setBusy] = useState(false);

  if (notes.loading) return <Spinner label="Loading notes…" />;

  const draft = (notes.data ?? []).find((n) => n.status === 'draft');

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
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  const signNote = async (noteId: string) => {
    setBusy(true);
    try {
      await encountersApi.signNote(encounterId, noteId, fac);
      onSaved();
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="stack">
      {signed ? (
        (notes.data ?? []).length === 0 ? (
          <EmptyState title="No note" body="This encounter has no clinical note." />
        ) : (
          (notes.data ?? []).map((n) => (
            <Card key={n.id} title={`${n.noteType} — ${n.status}`}>
              <dl className="kv">
                {Object.entries(n.content).map(([k, v]) => (
                  <div key={k}>
                    <dt className="capitalize">{k}</dt>
                    <dd>{v}</dd>
                  </div>
                ))}
              </dl>
            </Card>
          ))
        )
      ) : (
        <>
          <Textarea label="Chief complaint" value={complaint} onChange={(e) => setComplaint(e.target.value)} />
          <Textarea label="Examination" value={examination} onChange={(e) => setExamination(e.target.value)} />
          <Textarea label="Assessment" value={assessment} onChange={(e) => setAssessment(e.target.value)} />
          <Textarea label="Plan" value={plan} onChange={(e) => setPlan(e.target.value)} />
          <div className="row">
            <Button onClick={() => void save()} loading={busy}>
              Save draft
            </Button>
            {draft && (
              <Button variant="secondary" loading={busy} onClick={() => void signNote(draft.id)}>
                Sign note
              </Button>
            )}
          </div>
        </>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ Diagnosis */

function DiagnosisTab({ encounterId, fac, signed, onError, onSaved }: { encounterId: string; fac: string | null; signed: boolean; onError: (e: unknown) => void; onSaved: () => void }) {
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
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Record diagnosis">
      {signed && <p className="muted">Encounter is signed — diagnoses are immutable history.</p>}
      <div className="stack">
        <div className="grid grid--2">
          <Input label="ICD-10 code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. J11.1" />
          <Select label="Type" value={diagnosisType} onChange={(e) => setDiagnosisType(e.target.value)}>
            <option value="provisional">Provisional</option>
            <option value="differential">Differential</option>
            <option value="final">Final</option>
          </Select>
        </div>
        <Textarea label="Description" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Diagnosis in plain clinical language" />
        <label className="check">
          <input type="checkbox" checked={isPrimary} onChange={(e) => setIsPrimary(e.target.checked)} />
          Primary diagnosis
        </label>
        {!signed && (
          <div className="row">
            <Button onClick={() => void submit()} loading={busy} disabled={!description.trim()}>
              Add diagnosis
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ Prescription */

function PrescriptionTab({ encounterId, fac, signed, medications, onError, onSaved }: { encounterId: string; fac: string | null; signed: boolean; medications: ReturnType<typeof useFetch<import('../api/types').Medication[]>>; onError: (e: unknown) => void; onSaved: () => void }) {
  const [medicationId, setMedicationId] = useState('');
  const [dose, setDose] = useState('');
  const [route, setRoute] = useState('oral');
  const [frequency, setFrequency] = useState('');
  const [duration, setDuration] = useState('');
  const [instructions, setInstructions] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      await encountersApi.storePrescription(
        encounterId,
        {
          notes: instructions || undefined,
          lines: [{ medicationId, dose: dose.trim(), route, frequency: frequency.trim(), duration: duration || undefined, quantityMinor: undefined, instructions: instructions || undefined }],
        },
        fac,
      );
      setMedicationId('');
      setDose('');
      setFrequency('');
      onSaved();
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  const selected = medications.data?.find((m) => m.id === medicationId);

  return (
    <Card title="New prescription">
      {signed && <p className="muted">Encounter is signed — prescriptions are immutable history.</p>}
      <div className="stack">
        <Select label="Medication" value={medicationId} onChange={(e) => setMedicationId(e.target.value)}>
          <option value="">Select medication…</option>
          {(medications.data ?? []).map((m) => (
            <option key={m.id} value={m.id}>
              {m.genericName} {m.strength} — {money(m.priceMinor, m.currency)}
            </option>
          ))}
        </Select>
        <div className="grid grid--2">
          <Input label="Dose" value={dose} onChange={(e) => setDose(e.target.value)} placeholder="e.g. 500mg" />
          <Select label="Route" value={route} onChange={(e) => setRoute(e.target.value)}>
            <option value="oral">Oral</option>
            <option value="topical">Topical</option>
            <option value="iv">IV</option>
            <option value="im">IM</option>
            <option value="inhalation">Inhalation</option>
          </Select>
        </div>
        <div className="grid grid--2">
          <Input label="Frequency" value={frequency} onChange={(e) => setFrequency(e.target.value)} placeholder="e.g. TDS" />
          <Input label="Duration" value={duration} onChange={(e) => setDuration(e.target.value)} placeholder="e.g. 3 days" />
        </div>
        <Textarea label="Instructions" value={instructions} onChange={(e) => setInstructions(e.target.value)} />
        {selected && (
          <p className="muted small">
            Unit price: {money(selected.priceMinor, selected.currency)} — quantity pricing is applied at billing.
          </p>
        )}
        {!signed && (
          <div className="row">
            <Button onClick={() => void submit()} loading={busy} disabled={!medicationId || !dose.trim() || !frequency.trim()}>
              Draft prescription
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ Follow-up */

function FollowUpTab({ encounterId, signed, providerStaffId, onRefresh }: { encounterId: string; signed: boolean; providerStaffId: string; onRefresh: () => void }) {
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--sp-3)' }}>
        <h3 style={{ margin: 0 }}>Follow-up plans</h3>
        {!signed && (
          <Button size="sm" onClick={() => setCreateOpen(true)}>Plan follow-up</Button>
        )}
      </div>
      <FollowUpList encounterId={encounterId} onRefresh={onRefresh} />
      <CreateFollowUpDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        encounterId={encounterId}
        providerStaffId={providerStaffId}
        onCreated={() => { onRefresh(); }}
      />
    </Card>
  );
}

/* ------------------------------------------------------------------ Lab Orders */

function LabOrdersTab({ encounterId, fac, signed, onError, onSaved }: { encounterId: string; fac: string | null; signed: boolean; onError: (e: unknown) => void; onSaved: () => void }) {
  const { organizationId } = useTenant();
  const [testIds, setTestIds] = useState<string[]>([]);
  const [priority, setPriority] = useState('routine');
  const [clinicalIndication, setClinicalIndication] = useState('');
  const [busy, setBusy] = useState(false);

  const labTests = useFetch(() => labTestsApi.list(organizationId ?? '', fac), [organizationId, fac]);
  const orders = useFetch(() => labOrdersApi.forEncounter(encounterId, fac), [encounterId, fac]);

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
      void orders.refresh();
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  const transitionOrder = async (orderId: string, action: 'collect' | 'process' | 'verify' | 'report') => {
    try {
      if (action === 'collect') await labOrdersApi.collect(orderId, fac);
      else if (action === 'process') await labOrdersApi.process(orderId, fac);
      else if (action === 'verify') await labOrdersApi.verify(orderId, fac);
      else if (action === 'report') await labOrdersApi.report(orderId, fac);
      onSaved();
      void orders.refresh();
    } catch (err) {
      onError(err);
    }
  };

  const nextAction = (status: string): { label: string; action: 'collect' | 'process' | 'verify' | 'report' } | null => {
    if (status === 'ordered') return { label: 'Collect specimen', action: 'collect' };
    if (status === 'collected') return { label: 'Start processing', action: 'process' };
    if (status === 'results_entered') return { label: 'Verify results', action: 'verify' };
    if (status === 'verified') return { label: 'Release report', action: 'report' };
    return null;
  };

  return (
    <div className="stack">
      {!signed && (
        <Card title="Order lab tests">
          <div className="stack">
            {labTests.loading ? <Spinner /> : (
              <div className="check-grid">
                {(labTests.data ?? []).map((t) => (
                  <label key={t.id} className="check">
                    <input type="checkbox" checked={testIds.includes(t.id)} onChange={() => toggleTest(t.id)} />
                    <span>{t.name} <span className="muted small">({t.sampleType})</span></span>
                  </label>
                ))}
              </div>
            )}
            <Select label="Priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
              <option value="routine">Routine</option>
              <option value="urgent">Urgent</option>
              <option value="stat">STAT</option>
            </Select>
            <Textarea label="Clinical indication" value={clinicalIndication} onChange={(e) => setClinicalIndication(e.target.value)} placeholder="Why are these tests ordered?" />
            <Button onClick={() => void submit()} loading={busy} disabled={testIds.length === 0}>Order tests</Button>
          </div>
        </Card>
      )}

      <Card title="Orders">
        {(orders.data ?? []).length === 0 ? (
          <EmptyState title="No lab orders" body="Order tests from the encounter." />
        ) : (
          (orders.data ?? []).map((o) => {
            const next = nextAction(o.status);
            return (
              <Card key={o.id} className="lab-order-card">
                <div className="detail-grid">
                  <div className="detail-row"><span className="detail-label">Status</span><span className="status-chip status-chip--info">{o.status}</span></div>
                  <div className="detail-row"><span className="detail-label">Priority</span><span>{o.priority}</span></div>
                  {o.clinicalIndication && <div className="detail-row"><span className="detail-label">Indication</span><span>{o.clinicalIndication}</span></div>}
                  <div className="detail-row"><span className="detail-label">Tests</span><span>{o.items.map((i) => i.testName ?? 'Unknown').join(', ')}</span></div>
                  {o.items.some((i) => i.resultValue) && (
                    <div className="detail-row"><span className="detail-label">Results</span>
                      <span>{o.items.map((i) => i.resultValue ? `${i.testName}: ${i.resultValue} ${i.resultUnit ?? ''}` : null).filter(Boolean).join(' · ')}</span>
                    </div>
                  )}
                  {next && !signed && (
                    <Button size="sm" onClick={() => void transitionOrder(o.id, next.action)}>{next.label}</Button>
                  )}
                </div>
              </Card>
            );
          })
        )}
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------------ Radiology Orders */

function RadiologyOrdersTab({ encounterId, fac, signed, onError, onSaved }: { encounterId: string; fac: string | null; signed: boolean; onError: (e: unknown) => void; onSaved: () => void }) {
  const { organizationId } = useTenant();
  const [testIds, setTestIds] = useState<string[]>([]);
  const [priority, setPriority] = useState('routine');
  const [clinicalIndication, setClinicalIndication] = useState('');
  const [busy, setBusy] = useState(false);

  const labTests = useFetch(() => labTestsApi.list(organizationId ?? '', fac), [organizationId, fac]);
  const studies = useFetch(() => radiologyApi.queue(fac), [fac]);

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
      void studies.refresh();
    } catch (err) {
      onError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="stack">
      {!signed && (
        <Card title="Order imaging">
          <div className="stack">
            {labTests.loading ? <Spinner /> : (
              <div className="check-grid">
                {(labTests.data ?? []).map((t) => (
                  <label key={t.id} className="check">
                    <input type="checkbox" checked={testIds.includes(t.id)} onChange={() => toggleTest(t.id)} />
                    <span>{t.name}</span>
                  </label>
                ))}
              </div>
            )}
            <Select label="Priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
              <option value="routine">Routine</option>
              <option value="urgent">Urgent</option>
              <option value="stat">STAT</option>
            </Select>
            <Textarea label="Clinical indication" value={clinicalIndication} onChange={(e) => setClinicalIndication(e.target.value)} placeholder="Clinical reason for imaging" />
            <Button onClick={() => void submit()} loading={busy} disabled={testIds.length === 0}>Order imaging</Button>
          </div>
        </Card>
      )}

      <Card title="Radiology worklist">
        {(studies.data ?? []).length === 0 ? (
          <EmptyState title="No studies" body="Imaging studies will appear here." />
        ) : (
          <Card>
            <table className="data-table">
              <thead>
                <tr><th>Status</th><th>Modality</th><th>Priority</th><th>Indication</th></tr>
              </thead>
              <tbody>
                {(studies.data ?? []).map((s) => (
                  <tr key={s.id}>
                    <td data-label="Status"><span className="status-chip status-chip--info">{s.status}</span></td>
                    <td data-label="Modality">{s.modality?.name ?? '—'}</td>
                    <td data-label="Priority">{s.priority}</td>
                    <td data-label="Indication">{s.clinicalIndication ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        )}
      </Card>
    </div>
  );
}
