import { useState } from 'react';
import { bbApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './blood-bank.css';

/* ── Constants ───────────────────────────────────────────────────── */

const BLOOD_GROUPS = ['A', 'B', 'AB', 'O'];
const RH_FACTORS = ['positive', 'negative'];

const COMPONENTS = [
  { value: 'packed_red_cells', label: 'Packed Red Cells', color: '#dc2626' },
  { value: 'platelets', label: 'Platelets', color: '#f59e0b' },
  { value: 'fresh_frozen_plasma', label: 'FFP', color: '#3b82f6' },
  { value: 'cryoprecipitate', label: 'Cryoprecipitate', color: '#8b5cf6' },
];

const DISCARD_REASONS = [
  { value: 'expired', label: 'Expired' },
  { value: 'failed_screening', label: 'Failed Screening' },
  { value: 'contaminated', label: 'Contaminated' },
  { value: 'damaged_bag', label: 'Damaged Bag' },
];

const REACTION_SEVERITIES = [
  { value: 'mild', label: 'Mild', color: '#10b981' },
  { value: 'moderate', label: 'Moderate', color: '#f59e0b' },
  { value: 'severe', label: 'Severe', color: '#ea580c' },
  { value: 'life_threatening', label: 'Life-Threatening', color: '#dc2626' },
];

/* ── Main Component ──────────────────────────────────────────────── */

export function BloodBankPage() {
  const donors = useFetch(() => bbApi.donors(), ['bb-donors']);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'inventory' | 'donors' | 'requests' | 'transfusions'>('inventory');
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Donor form
  const [dNum, setDNum] = useState('');
  const [dGrp, setDGrp] = useState('O');
  const [dRh, setDRh] = useState('positive');

  // Donation form
  const [donorId, setDonorId] = useState('');
  const [donStaff, setDonStaff] = useState('');

  // Test form
  const [testId, setTestId] = useState('');
  const [testOk, setTestOk] = useState(true);

  // Crossmatch form
  const [xUnit, setXUnit] = useState('');
  const [xPat, setXPat] = useState('');
  const [xMatchId, setXMatchId] = useState('');
  const [xCompat, setXCompat] = useState(true);

  // Issue form
  const [issUnit, setIssUnit] = useState('');
  const [issPat, setIssPat] = useState('');
  const [issStaff, setIssStaff] = useState('');

  // Transfusion form
  const [txId, setTxId] = useState('');
  const [txVol, setTxVol] = useState('');
  const [txStaff, setTxStaff] = useState('');
  const [txReason, setTxReason] = useState('');

  // Reaction form
  const [rxSev, setRxSev] = useState('mild');
  const [rxDesc, setRxDesc] = useState('');

  // Discard form
  const [discUnit, setDiscUnit] = useState('');
  const [discReason, setDiscReason] = useState('expired');

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };

  const allDonors = donors.data ?? [];
  const refresh = () => { void donors.refresh(); };

  return (
    <div className="page bb-page">
      <header className="page__head">
        <div>
          <h1 className="page__title">Blood Bank & Transfusion Medicine</h1>
          <p className="page__subtitle">Blood products, compatibility, issue, and transfusion monitoring</p>
        </div>
        <div className="bb-actions">
          <Button variant="primary" onClick={() => { setDNum(''); setDlg('donor'); }}>Register Donor</Button>
          <Button variant="ghost" onClick={() => refresh()}>Refresh</Button>
        </div>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* ── Census Dashboard ──────────────────────────────── */}
      <div className="bb-census">
        <div className="bb-census-card bb-census-card--donors">
          <span className="bb-census-value">{allDonors.length}</span>
          <span className="bb-census-label">Donors</span>
        </div>
        <div className="bb-census-card bb-census-card--units">
          <span className="bb-census-value">—</span>
          <span className="bb-census-label">Blood Units</span>
        </div>
        <div className="bb-census-card bb-census-card--available">
          <span className="bb-census-value">—</span>
          <span className="bb-census-label">Available</span>
        </div>
        <div className="bb-census-card bb-census-card--reserved">
          <span className="bb-census-value">—</span>
          <span className="bb-census-label">Reserved</span>
        </div>
        <div className="bb-census-card bb-census-card--issued">
          <span className="bb-census-value">—</span>
          <span className="bb-census-label">Issued</span>
        </div>
        <div className="bb-census-card bb-census-card--expiring">
          <span className="bb-census-value">—</span>
          <span className="bb-census-label">Expiring Soon</span>
        </div>
      </div>

      {/* ── Tabs ──────────────────────────────────────────── */}
      <div className="bb-tabs">
        <button className={`bb-tab ${activeTab === 'inventory' ? 'bb-tab--active' : ''}`} onClick={() => setActiveTab('inventory')}>
          Inventory
        </button>
        <button className={`bb-tab ${activeTab === 'donors' ? 'bb-tab--active' : ''}`} onClick={() => setActiveTab('donors')}>
          Donors
        </button>
        <button className={`bb-tab ${activeTab === 'requests' ? 'bb-tab--active' : ''}`} onClick={() => setActiveTab('requests')}>
          Requests
        </button>
        <button className={`bb-tab ${activeTab === 'transfusions' ? 'bb-tab--active' : ''}`} onClick={() => setActiveTab('transfusions')}>
          Transfusions
        </button>
      </div>

      {/* ── Inventory Tab ─────────────────────────────────── */}
      {activeTab === 'inventory' && (
        <Card className="bb-section-card">
          <div className="bb-section-header">
            <h3>Blood Product Inventory</h3>
            <div className="bb-section-actions">
              <Button variant="ghost" size="sm" onClick={() => { setTestId(''); setDlg('test'); }}>Test Unit</Button>
              <Button variant="ghost" size="sm" onClick={() => { setXUnit(''); setXPat(''); setDlg('xmatch'); }}>Crossmatch</Button>
              <Button variant="ghost" size="sm" onClick={() => { setIssUnit(''); setIssPat(''); setIssStaff(''); setDlg('issue'); }}>Issue</Button>
              <Button variant="ghost" size="sm" onClick={() => { setDiscUnit(''); setDlg('discard'); }}>Discard</Button>
            </div>
          </div>

          {/* Component Groups */}
          <div className="bb-component-grid">
            {COMPONENTS.map(comp => (
              <div key={comp.value} className="bb-component-card">
                <div className="bb-component-header" style={{ borderLeftColor: comp.color }}>
                  <span className="bb-component-name">{comp.label}</span>
                  <span className="bb-component-count" style={{ color: comp.color }}>—</span>
                </div>
                <p className="bb-component-desc">Units tracked through collection → testing → issue → transfusion</p>
              </div>
            ))}
          </div>

          <div className="bb-lifecycle">
            <h4>Product Lifecycle</h4>
            <div className="bb-lifecycle-flow">
              <span className="bb-lifecycle-step bb-lifecycle-step--collection">Collection</span>
              <span className="bb-lifecycle-arrow">→</span>
              <span className="bb-lifecycle-step bb-lifecycle-step--testing">Testing</span>
              <span className="bb-lifecycle-arrow">→</span>
              <span className="bb-lifecycle-step bb-lifecycle-step--available">Available</span>
              <span className="bb-lifecycle-arrow">→</span>
              <span className="bb-lifecycle-step bb-lifecycle-step--reserved">Reserved</span>
              <span className="bb-lifecycle-arrow">→</span>
              <span className="bb-lifecycle-step bb-lifecycle-step--issued">Issued</span>
              <span className="bb-lifecycle-arrow">→</span>
              <span className="bb-lifecycle-step bb-lifecycle-step--transfused">Transfused</span>
            </div>
          </div>
        </Card>
      )}

      {/* ── Donors Tab ────────────────────────────────────── */}
      {activeTab === 'donors' && (
        <Card className="bb-section-card">
          <div className="bb-section-header">
            <h3>Donor Registry</h3>
            <Button variant="ghost" size="sm" onClick={() => { setDNum(''); setDlg('donor'); }}>Register Donor</Button>
          </div>
          {donors.loading ? <SkeletonTable rows={5} cols={4} /> : allDonors.length === 0 ? (
            <EmptyState title="No donors registered" body="Register a blood donor to begin collecting donations." />
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Donor #</th>
                  <th>Blood Group</th>
                  <th>Rh</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {allDonors.map(d => (
                  <tr key={d.id}>
                    <td className="font-medium">{d.donorNumber}</td>
                    <td>{d.bloodGroup}</td>
                    <td>{d.rhFactor}</td>
                    <td>
                      <span className="bb-status-badge" style={{ color: '#10b981', backgroundColor: '#ecfdf5' }}>
                        {d.status}
                      </span>
                    </td>
                    <td>
                      <Button size="sm" variant="ghost" onClick={() => { setDonorId(d.id); setDonStaff(''); setDlg('donate'); }}>
                        Record Donation
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Requests Tab ──────────────────────────────────── */}
      {activeTab === 'requests' && (
        <Card className="bb-section-card">
          <div className="bb-section-header">
            <h3>Blood Requests</h3>
            <Button variant="ghost" size="sm" onClick={() => { setXUnit(''); setXPat(''); setDlg('xmatch'); }}>
              New Request
            </Button>
          </div>
          <EmptyState title="Blood requests" body="Requests from Emergency, ICU, OT, and clinical wards appear here." />
        </Card>
      )}

      {/* ── Transfusions Tab ──────────────────────────────── */}
      {activeTab === 'transfusions' && (
        <Card className="bb-section-card">
          <div className="bb-section-header">
            <h3>Active Transfusions</h3>
          </div>
          <EmptyState title="No active transfusions" body="Start a transfusion from the Issue workflow." />
        </Card>
      )}

      {/* ── Dialogs ────────────────────────────────────────── */}

      {/* Register Donor */}
      {dlg === 'donor' && (
        <Dialog open onClose={() => setDlg(null)} title="Register Blood Donor" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!dNum.trim()) return;
              await go(async () => { await bbApi.createDonor({ donorNumber: dNum.trim(), bloodGroup: dGrp, rhFactor: dRh }); setDlg(null); refresh(); });
            }} loading={busy}>Register Donor</Button>
          </>
        }>
          <Input label="Donor Number" value={dNum} onChange={e => setDNum(e.target.value)} placeholder="e.g. DN-001" />
          <Select label="Blood Group" value={dGrp} onChange={e => setDGrp(e.target.value)}>
            {BLOOD_GROUPS.map(g => <option key={g}>{g}</option>)}
          </Select>
          <Select label="Rh Factor" value={dRh} onChange={e => setDRh(e.target.value)}>
            {RH_FACTORS.map(r => <option key={r}>{r}</option>)}
          </Select>
        </Dialog>
      )}

      {/* Record Donation */}
      {dlg === 'donate' && (
        <Dialog open onClose={() => setDlg(null)} title="Record Blood Donation" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!donorId.trim() || !donStaff.trim()) return;
              await go(async () => {
                await bbApi.recordDonation(donorId.trim(), {
                  phlebotomistStaffId: donStaff.trim(),
                  volumeMl: 450,
                  components: COMPONENTS.map(c => ({ componentType: c.value })),
                });
                setDlg(null); refresh();
              });
            }} loading={busy}>Record Donation</Button>
          </>
        }>
          <Input label="Donor ID" value={donorId} onChange={e => setDonorId(e.target.value)} placeholder="Donor identifier" />
          <Input label="Phlebotomist Staff ID" value={donStaff} onChange={e => setDonStaff(e.target.value)} placeholder="Staff identifier" />
          <Alert tone="info">This will create 4 component units: PRBC, Platelets, FFP, Cryoprecipitate.</Alert>
        </Dialog>
      )}

      {/* Test Unit */}
      {dlg === 'test' && (
        <Dialog open onClose={() => setDlg(null)} title="Test Blood Unit" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!testId.trim()) return;
              await go(async () => {
                await bbApi.testUnit(testId.trim(), { testResults: { screening: testOk ? 'pass' : 'fail' }, suitable: testOk });
                setDlg(null);
              });
            }} loading={busy}>Submit Test</Button>
          </>
        }>
          <Input label="Unit ID" value={testId} onChange={e => setTestId(e.target.value)} placeholder="Blood unit identifier" />
          <Select label="Screening Result" value={testOk ? 'pass' : 'fail'} onChange={e => setTestOk(e.target.value === 'pass')}>
            <option value="pass">Pass — Suitable for use</option>
            <option value="fail">Fail — Must be discarded</option>
          </Select>
        </Dialog>
      )}

      {/* Crossmatch Request */}
      {dlg === 'xmatch' && (
        <Dialog open onClose={() => setDlg(null)} title="Request Blood Compatibility" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!xUnit.trim() || !xPat.trim()) return;
              await go(async () => {
                const r = await bbApi.requestCrossmatch(xUnit.trim(), { patientId: xPat.trim() });
                setXMatchId(r.id); setDlg('perform-xm');
              });
            }} loading={busy}>Request Crossmatch</Button>
          </>
        }>
          <Input label="Blood Unit ID" value={xUnit} onChange={e => setXUnit(e.target.value)} placeholder="Unit to crossmatch" />
          <Input label="Patient ID" value={xPat} onChange={e => setXPat(e.target.value)} placeholder="Patient for compatibility" />
        </Dialog>
      )}

      {/* Perform Crossmatch */}
      {dlg === 'perform-xm' && (
        <Dialog open onClose={() => setDlg(null)} title="Perform Crossmatch" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              await go(async () => {
                await bbApi.performCrossmatch(xMatchId, { compatible: xCompat });
                setDlg(null);
              });
            }} loading={busy}>Record Result</Button>
          </>
        }>
          <Select label="Compatibility Result" value={xCompat ? 'compatible' : 'incompatible'} onChange={e => setXCompat(e.target.value === 'compatible')}>
            <option value="compatible">Compatible — Safe to issue</option>
            <option value="incompatible">Incompatible — Do NOT issue</option>
          </Select>
          {!xCompat && <Alert tone="danger">Incompatible units must never be issued to this patient.</Alert>}
        </Dialog>
      )}

      {/* Issue Unit */}
      {dlg === 'issue' && (
        <Dialog open onClose={() => setDlg(null)} title="Issue Blood Unit" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => {
              if (!issUnit.trim() || !issPat.trim() || !issStaff.trim()) return;
              await go(async () => {
                await bbApi.issueUnit(issUnit.trim(), { patientId: issPat.trim(), issuedToStaffId: issStaff.trim() });
                setDlg(null);
              });
            }} loading={busy}>Issue Unit</Button>
          </>
        }>
          <Input label="Blood Unit ID" value={issUnit} onChange={e => setIssUnit(e.target.value)} placeholder="Unit to issue" />
          <Input label="Patient ID" value={issPat} onChange={e => setIssPat(e.target.value)} placeholder="Patient receiving blood" />
          <Input label="Issued By (Staff ID)" value={issStaff} onChange={e => setIssStaff(e.target.value)} placeholder="Issuing staff identifier" />
          <Alert tone="warning">Verify patient identity, blood group, and crossmatch compatibility before issuing.</Alert>
        </Dialog>
      )}

      {/* Transfusion Actions */}
      {dlg === 'tx' && (
        <Dialog open onClose={() => setDlg(null)} title="Transfusion Management" footer={
          <Button variant="ghost" onClick={() => setDlg(null)}>Close</Button>
        }>
          <Input label="Transfusion ID" value={txId} onChange={e => setTxId(e.target.value)} placeholder="Transfusion identifier" />
          <div className="bb-tx-actions">
            <Button variant="ghost" size="sm" disabled={!txId.trim() || busy}
              onClick={async () => { await go(() => bbApi.verifyTransfusion(txId, { verifiedByStaffId: 'system' })); }}>Verify</Button>
            <Button variant="ghost" size="sm" disabled={!txId.trim()}
              onClick={() => { setTxVol(''); setTxStaff(''); setDlg('complete-tx'); }}>Complete</Button>
            <Button variant="ghost" size="sm" disabled={!txId.trim()}
              onClick={() => { setTxReason(''); setTxStaff(''); setDlg('stop-tx'); }}>Stop</Button>
            <Button variant="ghost" size="sm" disabled={!txId.trim()}
              onClick={() => { setRxSev('mild'); setRxDesc(''); setDlg('reaction'); }}>Report Reaction</Button>
          </div>
        </Dialog>
      )}

      {/* Complete Transfusion */}
      {dlg === 'complete-tx' && (
        <Dialog open onClose={() => setDlg('tx')} title="Complete Transfusion" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => {
              await go(async () => {
                await bbApi.completeTransfusion(txId, { volumeTransfusedMl: parseInt(txVol) || 0, completedByStaffId: txStaff.trim() });
                setDlg(null);
              });
            }} loading={busy}>Complete</Button>
          </>
        }>
          <Input label="Volume Transfused (mL)" type="number" value={txVol} onChange={e => setTxVol(e.target.value)} placeholder="450" />
          <Input label="Completed By (Staff ID)" value={txStaff} onChange={e => setTxStaff(e.target.value)} placeholder="Staff identifier" />
        </Dialog>
      )}

      {/* Stop Transfusion */}
      {dlg === 'stop-tx' && (
        <Dialog open onClose={() => setDlg('tx')} title="Stop Transfusion" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => {
              if (!txReason.trim() || !txStaff.trim()) return;
              await go(async () => {
                await bbApi.stopTransfusion(txId, { reason: txReason.trim(), stoppedByStaffId: txStaff.trim() });
                setDlg(null);
              });
            }} loading={busy}>Stop Transfusion</Button>
          </>
        }>
          <Input label="Reason for Stopping" value={txReason} onChange={e => setTxReason(e.target.value)} placeholder="Clinical reason" />
          <Input label="Stopped By (Staff ID)" value={txStaff} onChange={e => setTxStaff(e.target.value)} placeholder="Staff identifier" />
        </Dialog>
      )}

      {/* Report Reaction */}
      {dlg === 'reaction' && (
        <Dialog open onClose={() => setDlg('tx')} title="Report Transfusion Reaction" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => {
              if (!rxDesc.trim()) return;
              await go(async () => {
                await bbApi.reportReaction(txId, { severity: rxSev, description: rxDesc.trim(), reportedByStaffId: 'system' });
                setDlg(null);
              });
            }} loading={busy}>Report Reaction</Button>
          </>
        }>
          <Select label="Severity" value={rxSev} onChange={e => setRxSev(e.target.value)}>
            {REACTION_SEVERITIES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
          <Input label="Description" value={rxDesc} onChange={e => setRxDesc(e.target.value)} placeholder="Describe the reaction" />
          {rxSev === 'life_threatening' && <Alert tone="danger">Life-threatening reaction — immediate clinical escalation required.</Alert>}
        </Dialog>
      )}

      {/* Discard Unit */}
      {dlg === 'discard' && (
        <Dialog open onClose={() => setDlg(null)} title="Discard Blood Unit" footer={
          <>
            <Button variant="ghost" onClick={() => setDlg(null)}>Cancel</Button>
            <Button variant="danger" onClick={async () => {
              if (!discUnit.trim()) return;
              await go(async () => {
                await bbApi.discardUnit(discUnit.trim(), { reason: discReason });
                setDlg(null);
              });
            }} loading={busy}>Discard Unit</Button>
          </>
        }>
          <Input label="Unit ID" value={discUnit} onChange={e => setDiscUnit(e.target.value)} placeholder="Blood unit to discard" />
          <Select label="Reason" value={discReason} onChange={e => setDiscReason(e.target.value)}>
            {DISCARD_REASONS.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
          </Select>
          <Alert tone="warning">This action cannot be undone. The unit will be permanently marked as discarded.</Alert>
        </Dialog>
      )}
    </div>
  );
}
