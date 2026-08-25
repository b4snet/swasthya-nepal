import { useState, useEffect, useCallback } from 'react';
import { drugInteractionApi, type InteractionCheckResponse } from '../api/clinical-cdss';
import { AlertTriangle, ShieldCheck, ShieldAlert, ShieldX, Info } from 'lucide-react';

interface CdssWarningProps {
  medicationIds: string[];
  facilityId?: string | null;
  onAcknowledge?: () => void;
  autoCheck?: boolean;
}

const SEVERITY_CONFIG = {
  critical: { icon: ShieldX, color: '#dc2626', bg: '#fef2f2', label: 'Critical', desc: 'Must not coexist - contraindicated combination' },
  major: { icon: ShieldAlert, color: '#d97706', bg: '#fffbeb', label: 'Major', desc: 'Requires clinical review before co-prescribing' },
  moderate: { icon: Info, color: '#2563eb', bg: '#eff6ff', label: 'Moderate', desc: 'Informational - clinician awareness' },
} as const;

export function CdssWarning({ medicationIds, facilityId, onAcknowledge, autoCheck = true, }: CdssWarningProps) {
  const [result, setResult] = useState<InteractionCheckResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [acknowledged, setAcknowledged] = useState(false);

  const checkInteractions = useCallback(async () => {
    if (medicationIds.length < 2) { setResult({ interactions: [], hasCritical: false, hasMajor: false, count: 0 }); return; }
    setLoading(true); setError(null); setResult(null);
    try { setResult(await drugInteractionApi.check(medicationIds, facilityId)); }
    catch (err) { setError(err instanceof Error ? err.message : 'Medication safety check unavailable'); }
    finally { setLoading(false); }
  }, [medicationIds, facilityId]);

  useEffect(() => { if (autoCheck && medicationIds.length >= 2) void checkInteractions(); }, [autoCheck, checkInteractions, medicationIds.length]);

  if (loading) return <div className="cdss-warning cdss-warning--loading"><div className="cdss-warning__icon"><ShieldCheck size={16} className="cdss-spin" /></div><span>Checking medication safety...</span></div>;

  if (error) return <div className="cdss-warning cdss-warning--error"><div className="cdss-warning__icon"><AlertTriangle size={16} /></div><div><strong>Safety check unavailable</strong><span>{error}</span><span>Do not assume no interactions exist. Follow hospital safety policy.</span></div><button onClick={() => void checkInteractions()}>Retry</button></div>;

  if (!result) {
    if (!autoCheck && medicationIds.length >= 2) return <button className="cdss-warning__check-btn" onClick={() => void checkInteractions()}><ShieldCheck size={16} /> Check medication safety</button>;
    return null;
  }

  if (result.count === 0) return <div className="cdss-warning cdss-warning--clean"><div className="cdss-warning__icon cdss-warning__icon--green"><ShieldCheck size={16} /></div><span>No interaction detected</span><span className="cdss-warning__detail--muted">The configured CDSS found no supported interaction for these medications.</span></div>;

  const sorted = [...result.interactions].sort((a, b) => { const o = { critical: 0, major: 1, moderate: 2 }; return (o[a.severity] ?? 3) - (o[b.severity] ?? 3); });

  return (
    <div className="cdss-warning cdss-warning--found">
      <div className="cdss-warning__header"><AlertTriangle size={16} /><span>Medication Safety {result.count === 1 ? 'Warning' : 'Warnings'}</span><span>{result.count} interaction{result.count !== 1 ? 's' : ''} detected</span></div>
      <div className="cdss-warning__list">
        {sorted.map((ix) => { const cfg = SEVERITY_CONFIG[ix.severity] ?? SEVERITY_CONFIG.moderate; const Icon = cfg.icon; return (
          <div key={ix.id} className="cdss-warning__item" style={{ borderLeftColor: cfg.color, backgroundColor: cfg.bg }}>
            <div className="cdss-warning__item-header"><Icon size={14} style={{ color: cfg.color }} /><span style={{ color: cfg.color }}>{cfg.label}</span><span>{ix.medicationA.name} + {ix.medicationB.name}</span></div>
            <p className="cdss-warning__description">{ix.description}</p>
            {ix.clinicalEffect && <p className="cdss-warning__detail"><strong>Clinical effect:</strong> {ix.clinicalEffect}</p>}
            {ix.recommendation && <p className="cdss-warning__detail"><strong>Recommendation:</strong> {ix.recommendation}</p>}
          </div>); })}
      </div>
      {!acknowledged && <div className="cdss-warning__footer"><button className="cdss-warning__acknowledge-btn" onClick={() => { setAcknowledged(true); onAcknowledge?.(); }}>I have reviewed the interaction{result.count !== 1 ? 's' : ''}</button><span className="cdss-warning__detail--muted">The clinician/pharmacist remains responsible for the final clinical decision.</span></div>}
      {acknowledged && <div className="cdss-warning__acknowledged"><ShieldCheck size={14} /> Acknowledged - you may proceed with clinical review.</div>}
    </div>
  );
}

export default CdssWarning;
