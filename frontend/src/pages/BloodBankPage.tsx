import { useState } from 'react';
import { bbApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Alert, Button, Card, Dialog, EmptyState, Input, Select, SkeletonTable } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import './pages.css';

const BG = ['A', 'B', 'AB', 'O'];
const RH = ['positive', 'negative'];
const COMP = [
  { value: 'packed_red_cells', label: 'Packed Red Cells' },
  { value: 'platelets', label: 'Platelets' },
  { value: 'fresh_frozen_plasma', label: 'FFP' },
  { value: 'cryoprecipitate', label: 'Cryoprecipitate' },
];
const DISC_REASONS = [
  { value: 'expired', label: 'Expired' }, { value: 'failed_screening', label: 'Failed Screening' },
  { value: 'contaminated', label: 'Contaminated' }, { value: 'damaged_bag', label: 'Damaged Bag' },
];
const RX_SEV = [
  { value: 'mild', label: 'Mild' }, { value: 'moderate', label: 'Moderate' },
  { value: 'severe', label: 'Severe' }, { value: 'life_threatening', label: 'Life-Threatening' },
];

export function BloodBankPage() {
  const donors = useFetch(() => bbApi.donors(), ['bb-donors']);
  const [error, setError] = useState<string | null>(null);
  const [dlg, setDlg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [dNum, setDNum] = useState('');
  const [dGrp, setDGrp] = useState('O');
  const [dRh, setDRh] = useState('positive');
  const [donorId, setDonorId] = useState('');
  const [donStaff, setDonStaff] = useState('');
  const [testId, setTestId] = useState('');
  const [testOk, setTestOk] = useState(true);
  const [xUnit, setXUnit] = useState('');
  const [xPat, setXPat] = useState('');
  const [xMatchId, setXMatchId] = useState('');
  const [xCompat, setXCompat] = useState(true);
  const [issUnit, setIssUnit] = useState('');
  const [issPat, setIssPat] = useState('');
  const [issStaff, setIssStaff] = useState('');
  const [txId, setTxId] = useState('');
  const [txVol, setTxVol] = useState('');
  const [txStaff, setTxStaff] = useState('');
  const [txReason, setTxReason] = useState('');
  const [rxSev, setRxSev] = useState('mild');
  const [rxDesc, setRxDesc] = useState('');
  const [discUnit, setDiscUnit] = useState('');
  const [discReason, setDiscReason] = useState('expired');

  const go = async <T,>(fn: () => Promise<T>): Promise<T | null> => {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : 'Failed'); return null; } finally { setBusy(false); }
  };

  return (
    <div className="page-container">
      <div className="page-header">
        <div><h1 className="page-title">Blood Bank</h1><p className="page-subtitle">Donors, donations, testing, crossmatch, issue, transfusion</p></div>
        <div className="page-actions">
          <Button variant="secondary" onClick={() => { setDNum(''); setDlg('donor'); }}>Register Donor</Button>
          <Button variant="secondary" onClick={() => { setDonorId(''); setDonStaff(''); setDlg('donate'); }}>Record Donation</Button>
          <Button variant="secondary" onClick={() => { setTestId(''); setDlg('test'); }}>Test Unit</Button>
          <Button variant="secondary" onClick={() => { setXUnit(''); setXPat(''); setDlg('xmatch'); }}>Crossmatch</Button>
          <Button variant="secondary" onClick={() => { setIssUnit(''); setIssPat(''); setIssStaff(''); setDlg('issue'); }}>Issue</Button>
          <Button variant="secondary" onClick={() => { setTxId(''); setDlg('tx'); }}>Transfusion</Button>
          <Button variant="secondary" onClick={() => { setDiscUnit(''); setDlg('discard'); }}>Discard</Button>
        </div>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="stats-grid">
        <Card className="stat-card"><div className="stat-label">Donors</div><div className="stat-value">{donors.loading ? '—' : (donors.data ?? []).length}</div></Card>
      </div>
      {donors.loading ? <SkeletonTable rows={5} cols={4} /> : (donors.data ?? []).length === 0 ? (
        <EmptyState title="No donors" body="Register a blood donor to begin." />
      ) : (
        <Card><h3 className="card-header">Donor Registry</h3>
          <table className="data-table"><thead><tr><th>Donor #</th><th>Blood Group</th><th>Rh</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            {(donors.data ?? []).map((d) => <tr key={d.id}>
              <td className="font-medium">{d.donorNumber}</td><td>{d.bloodGroup}</td><td>{d.rhFactor}</td>
              <td><span className="badge" style={{ color: '#10b981' }}>{d.status}</span></td>
              <td><Button size="sm" variant="secondary" onClick={() => { setDonorId(d.id); setDonStaff(''); setDlg('donate'); }}>Donate</Button></td>
            </tr>)}
          </tbody></table>
        </Card>
      )}

      <Dialog open={dlg === 'donor'} onClose={() => setDlg(null)} title="Register Donor">
        <div className="dialog-form">
          <Input label="Donor Number" value={dNum} onChange={(e) => setDNum(e.target.value)} placeholder="DN-001" />
          <Select label="Blood Group" value={dGrp} onChange={(e) => setDGrp(e.target.value)}>{BG.map((g) => <option key={g}>{g}</option>)}</Select>
          <Select label="Rh" value={dRh} onChange={(e) => setDRh(e.target.value)}>{RH.map((r) => <option key={r}>{r}</option>)}</Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.createDonor({ donorNumber: dNum.trim(), bloodGroup: dGrp, rhFactor: dRh }); setDlg(null); donors.refresh(); }); }} disabled={busy || !dNum.trim()}>{busy ? '…' : 'Register'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'donate'} onClose={() => setDlg(null)} title="Record Donation">
        <div className="dialog-form">
          <Input label="Donor ID" value={donorId} onChange={(e) => setDonorId(e.target.value)} placeholder="Donor UUID" />
          <Input label="Phlebotomist Staff ID" value={donStaff} onChange={(e) => setDonStaff(e.target.value)} placeholder="Staff UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.recordDonation(donorId.trim(), { phlebotomistStaffId: donStaff.trim(), volumeMl: 450, components: COMP.map((c) => ({ componentType: c.value })) }); setDlg(null); }); }} disabled={busy || !donorId.trim() || !donStaff.trim()}>{busy ? '…' : 'Record'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'test'} onClose={() => setDlg(null)} title="Test Blood Unit">
        <div className="dialog-form">
          <Input label="Unit ID" value={testId} onChange={(e) => setTestId(e.target.value)} placeholder="Blood unit UUID" />
          <Select label="Result" value={testOk ? 'pass' : 'fail'} onChange={(e) => setTestOk(e.target.value === 'pass')}>
            <option value="pass">Pass — Suitable</option><option value="fail">Fail — Discard</option>
          </Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.testUnit(testId.trim(), { testResults: { screening: testOk ? 'pass' : 'fail' }, suitable: testOk }); setDlg(null); }); }} disabled={busy || !testId.trim()}>{busy ? '…' : 'Submit'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'xmatch'} onClose={() => setDlg(null)} title="Request Crossmatch">
        <div className="dialog-form">
          <Input label="Unit ID" value={xUnit} onChange={(e) => setXUnit(e.target.value)} placeholder="Blood unit UUID" />
          <Input label="Patient ID" value={xPat} onChange={(e) => setXPat(e.target.value)} placeholder="Patient UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { const r = await bbApi.requestCrossmatch(xUnit.trim(), { patientId: xPat.trim() }); setXMatchId(r.id); setDlg('perform-xm'); }); }} disabled={busy || !xUnit.trim() || !xPat.trim()}>{busy ? '…' : 'Request'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'perform-xm'} onClose={() => setDlg(null)} title="Perform Crossmatch">
        <div className="dialog-form">
          <Select label="Result" value={xCompat ? 'compatible' : 'incompatible'} onChange={(e) => setXCompat(e.target.value === 'compatible')}>
            <option value="compatible">Compatible</option><option value="incompatible">Incompatible</option>
          </Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.performCrossmatch(xMatchId, { compatible: xCompat }); setDlg(null); }); }} disabled={busy}>{busy ? '…' : 'Record'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'issue'} onClose={() => setDlg(null)} title="Issue Blood Unit">
        <div className="dialog-form">
          <Input label="Unit ID" value={issUnit} onChange={(e) => setIssUnit(e.target.value)} placeholder="Blood unit UUID" />
          <Input label="Patient ID" value={issPat} onChange={(e) => setIssPat(e.target.value)} placeholder="Patient UUID" />
          <Input label="Issued By Staff ID" value={issStaff} onChange={(e) => setIssStaff(e.target.value)} placeholder="Staff UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.issueUnit(issUnit.trim(), { patientId: issPat.trim(), issuedToStaffId: issStaff.trim() }); setDlg(null); }); }} disabled={busy || !issUnit.trim() || !issPat.trim() || !issStaff.trim()}>{busy ? '…' : 'Issue'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'tx'} onClose={() => setDlg(null)} title="Transfusion">
        <div className="dialog-form">
          <Input label="Transfusion ID" value={txId} onChange={(e) => setTxId(e.target.value)} placeholder="Transfusion UUID" />
          <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
            <Button size="sm" variant="secondary" disabled={!txId.trim() || busy} onClick={async () => { await go(() => bbApi.verifyTransfusion(txId, { verifiedByStaffId: 'system' })); }}>Verify</Button>
            <Button size="sm" variant="secondary" disabled={!txId.trim()} onClick={() => { setTxVol(''); setTxStaff(''); setDlg('complete-tx'); }}>Complete</Button>
            <Button size="sm" variant="secondary" disabled={!txId.trim()} onClick={() => { setTxReason(''); setTxStaff(''); setDlg('stop-tx'); }}>Stop</Button>
            <Button size="sm" variant="secondary" disabled={!txId.trim()} onClick={() => { setRxSev('mild'); setRxDesc(''); setDlg('reaction'); }}>Report Reaction</Button>
          </div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'complete-tx'} onClose={() => setDlg('tx')} title="Complete Transfusion">
        <div className="dialog-form">
          <Input label="Volume (mL)" type="number" value={txVol} onChange={(e) => setTxVol(e.target.value)} />
          <Input label="Staff ID" value={txStaff} onChange={(e) => setTxStaff(e.target.value)} placeholder="Staff UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.completeTransfusion(txId, { volumeTransfusedMl: parseInt(txVol) || 0, completedByStaffId: txStaff.trim() }); setDlg(null); }); }} disabled={busy || !txStaff.trim()}>{busy ? '…' : 'Complete'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'stop-tx'} onClose={() => setDlg('tx')} title="Stop Transfusion">
        <div className="dialog-form">
          <Input label="Reason" value={txReason} onChange={(e) => setTxReason(e.target.value)} placeholder="Reason" />
          <Input label="Staff ID" value={txStaff} onChange={(e) => setTxStaff(e.target.value)} placeholder="Staff UUID" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.stopTransfusion(txId, { reason: txReason.trim(), stoppedByStaffId: txStaff.trim() }); setDlg(null); }); }} disabled={busy || !txReason.trim() || !txStaff.trim()}>{busy ? '…' : 'Stop'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'reaction'} onClose={() => setDlg('tx')} title="Report Reaction">
        <div className="dialog-form">
          <Select label="Severity" value={rxSev} onChange={(e) => setRxSev(e.target.value)}>{RX_SEV.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</Select>
          <Input label="Description" value={rxDesc} onChange={(e) => setRxDesc(e.target.value)} placeholder="Description" />
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg('tx')}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.reportReaction(txId, { severity: rxSev, description: rxDesc.trim(), reportedByStaffId: 'system' }); setDlg(null); }); }} disabled={busy || !rxDesc.trim()}>{busy ? '…' : 'Report'}</Button></div>
        </div>
      </Dialog>

      <Dialog open={dlg === 'discard'} onClose={() => setDlg(null)} title="Discard Unit">
        <div className="dialog-form">
          <Input label="Unit ID" value={discUnit} onChange={(e) => setDiscUnit(e.target.value)} placeholder="Blood unit UUID" />
          <Select label="Reason" value={discReason} onChange={(e) => setDiscReason(e.target.value)}>{DISC_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}</Select>
          <div className="dialog-actions"><Button variant="secondary" onClick={() => setDlg(null)}>Cancel</Button>
            <Button onClick={async () => { await go(async () => { await bbApi.discardUnit(discUnit.trim(), { reason: discReason }); setDlg(null); }); }} disabled={busy || !discUnit.trim()}>{busy ? '…' : 'Discard'}</Button></div>
        </div>
      </Dialog>
    </div>
  );
}
