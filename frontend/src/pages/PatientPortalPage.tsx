import { useState, useEffect } from 'react';
import { portalApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Card, EmptyState, SkeletonCard, SkeletonStats, StatusChip, Button, Input } from '../components/ui';
import {
  Home,
  ClipboardList,
  TestTube,
  Pill,
  FileText,
  MessageSquare,
  CalendarDays,
  WalletCards,
  Lock,
  Settings,
  AlertTriangle,
} from 'lucide-react';
import './portal.css';

type PatientProfile = {
  id: string;
  fullName: string;
  dateOfBirth: string;
  sex: string;
  mrn: string;
  status: string;
};

type MedicalHistory = {
  allergies: Array<{ id: string; allergen: string; reaction: string | null; severity: string | null; status: string }>;
  diagnoses: Array<{ id: string; code: string | null; description: string; status: string }>;
};

type Medication = { id: string; medicationName: string; dosage: string | null; frequency: string | null; status: string };
type LabResult = { id: string; testName: string | null; resultValue: string | null; resultUnit: string | null; referenceRange: string | null; status: string; resultedAt: string | null };
type RadiologyReport = { id: string; reportType: string; status: string; impression: string | null; criticalFindings: string | null; verifiedAt: string | null };
type Document = { id: string; documentType: string; title: string; description: string | null; mimeType: string | null; createdAt: string | null };
type Referral = { id: string; reason: string | null; status: string; createdAt: string | null };
type Immunization = { id: string; code: string | null; description: string | null; observedAt: string | null };
type Appointment = { id: string; status: string; scheduledAt: string | null };
type Bill = { id: string; status: string; totalMinor: number; paidMinor: number; issuedAt: string | null };
type Grant = { id: string; scope: string; status: string; grantedAt: string; revokedAt: string | null };
type Message = { id: string; subject: string; body: string; senderIsPatient: boolean; status: string; category: string; createdAt: string | null };
type Consent = { id: string; dataCategory: string; consentStatus: string; purpose: string | null; grantedAt: string | null; revokedAt: string | null };
type NotifPrefs = {
  emailEnabled: boolean; smsEnabled: boolean; pushEnabled: boolean;
  appointmentReminders: boolean; resultNotifications: boolean;
  billingNotifications: boolean; messagingNotifications: boolean;
  marketingOptOut: boolean; preferredLanguage: string; timezone: string;
};

type Tab = 'overview' | 'medical' | 'results' | 'prescriptions' | 'documents' | 'messaging' | 'appointments' | 'billing' | 'consent' | 'preferences';

const SEVERITY_TONE: Record<string, 'warning' | 'danger' | 'neutral'> = {
  mild: 'neutral',
  moderate: 'warning',
  severe: 'danger',
  life_threatening: 'danger',
};

export function PatientPortalPage() {
  const [tab, setTab] = useState<Tab>('overview');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [profile, setProfile] = useState<PatientProfile | null>(null);
  const [medicalHistory, setMedicalHistory] = useState<MedicalHistory | null>(null);
  const [medications, setMedications] = useState<Medication[]>([]);
  const [labResults, setLabResults] = useState<LabResult[]>([]);
  const [radiologyReports, setRadiologyReports] = useState<RadiologyReport[]>([]);
  const [documents, setDocuments] = useState<Document[]>([]);
  const [referrals, setReferrals] = useState<Referral[]>([]);
  const [immunizations, setImmunizations] = useState<Immunization[]>([]);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [bills, setBills] = useState<Bill[]>([]);
  const [grants, setGrants] = useState<Grant[]>([]);
  const [messages, setMessages] = useState<Message[]>([]);
  const [consents, setConsents] = useState<Consent[]>([]);
  const [notifPrefs, setNotifPrefs] = useState<NotifPrefs | null>(null);

  const [msgSubject, setMsgSubject] = useState('');
  const [msgBody, setMsgBody] = useState('');
  const [msgRecipient, setMsgRecipient] = useState('');
  const [sending, setSending] = useState(false);

  useEffect(() => { loadOverview(); }, []);

  async function loadOverview() {
    setLoading(true);
    setError(null);
    try {
      const meRes = await portalApi.me() as unknown as { account: { patientId: string }; grants: Grant[] };
      setGrants(meRes.grants ?? []);
      const profileRes = await portalApi.profile() as unknown as { patient: PatientProfile };
      setProfile(profileRes.patient);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load portal data');
    } finally {
      setLoading(false);
    }
  }

  async function loadTab(t: Tab) {
    setTab(t);
    setError(null);
    try {
      switch (t) {
        case 'medical': {
          const [hist, meds] = await Promise.all([portalApi.medicalHistory(), portalApi.medications()]);
          setMedicalHistory(hist as unknown as MedicalHistory);
          setMedications((meds as unknown as { medications: Medication[] }).medications ?? []);
          break;
        }
        case 'results': {
          const [lr, rr] = await Promise.all([portalApi.labResults(), portalApi.radiologyReports()]);
          setLabResults((lr as unknown as { results: LabResult[] }).results ?? []);
          setRadiologyReports((rr as unknown as { reports: RadiologyReport[] }).reports ?? []);
          break;
        }
        case 'prescriptions': {
          const res = await portalApi.prescriptions() as unknown as { prescriptions: unknown[] };
          setMedications(res.prescriptions as Medication[] ?? []);
          break;
        }
        case 'documents': {
          const [docs, refs, imm] = await Promise.all([portalApi.documents(), portalApi.referrals(), portalApi.immunizations()]);
          setDocuments((docs as unknown as { documents: Document[] }).documents ?? []);
          setReferrals((refs as unknown as { referrals: Referral[] }).referrals ?? []);
          setImmunizations((imm as unknown as { immunizations: Immunization[] }).immunizations ?? []);
          break;
        }
        case 'messaging': {
          const res = await portalApi.messages() as unknown as { messages: Message[] };
          setMessages(res.messages ?? []);
          break;
        }
        case 'appointments': {
          const res = await portalApi.appointments() as unknown as { appointments: Appointment[] };
          setAppointments(res.appointments ?? []);
          break;
        }
        case 'billing': {
          const res = await portalApi.bills() as unknown as { bills: Bill[] };
          setBills(res.bills ?? []);
          break;
        }
        case 'consent': {
          const res = await portalApi.consentRecords() as unknown as { consents: Consent[] };
          setConsents(res.consents ?? []);
          break;
        }
        case 'preferences': {
          const res = await portalApi.notificationPreferences() as unknown as { preferences: NotifPrefs };
          setNotifPrefs(res.preferences);
          break;
        }
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load data');
    }
  }

  async function handleSendMessage(e: React.FormEvent) {
    e.preventDefault();
    if (!msgSubject || !msgBody || !msgRecipient) return;
    setSending(true);
    try {
      await portalApi.sendMessage({ recipientStaffId: msgRecipient, subject: msgSubject, body: msgBody });
      setMsgSubject(''); setMsgBody(''); setMsgRecipient('');
      await loadTab('messaging');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to send message');
    } finally {
      setSending(false);
    }
  }

  async function handleRevokeGrant(grantId: string) {
    try {
      await portalApi.revokeGrant(grantId);
      setGrants(prev => prev.map(g => g.id === grantId ? { ...g, status: 'revoked' } : g));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to revoke grant');
    }
  }

  async function handleRevokeConsent(consentId: string) {
    try {
      await portalApi.revokeConsent(consentId);
      setConsents(prev => prev.map(c => c.id === consentId ? { ...c, consentStatus: 'revoked' } : c));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to revoke consent');
    }
  }

  async function handleUpdatePrefs(updates: Partial<NotifPrefs>) {
    try {
      const res = await portalApi.updateNotificationPreferences(updates) as unknown as { preferences: NotifPrefs };
      setNotifPrefs(res.preferences);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update preferences');
    }
  }

  if (loading) return (
    <div className="portal">
      <div className="portal__header" style={{ minHeight: 88 }}>
        <div className="skeleton skeleton--circle" style={{ width: 56, height: 56 }} />
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div className="skeleton skeleton--heading" style={{ width: 200, height: 24 }} />
          <div className="skeleton skeleton--text-sm" style={{ width: 280, height: 10 }} />
        </div>
      </div>
      <SkeletonStats />
      <SkeletonCard rows={3} />
    </div>
  );
  if (error) return <div className="state state--error"><p>{error}</p></div>;

  const tabs: { key: Tab; label: string; icon: React.ReactNode }[] = [
    { key: 'overview', label: 'Overview', icon: <Home size={14} /> },
    { key: 'medical', label: 'Medical', icon: <ClipboardList size={14} /> },
    { key: 'results', label: 'Results', icon: <TestTube size={14} /> },
    { key: 'prescriptions', label: 'Rx', icon: <Pill size={14} /> },
    { key: 'documents', label: 'Docs', icon: <FileText size={14} /> },
    { key: 'messaging', label: 'Messages', icon: <MessageSquare size={14} /> },
    { key: 'appointments', label: 'Appts', icon: <CalendarDays size={14} /> },
    { key: 'billing', label: 'Billing', icon: <WalletCards size={14} /> },
    { key: 'consent', label: 'Consent', icon: <Lock size={14} /> },
    { key: 'preferences', label: 'Settings', icon: <Settings size={14} /> },
  ];

  return (
    <div className="portal">
      {/* Patient header */}
      {profile && (
        <div className="portal__header">
          <div className="portal__avatar">
            {profile.fullName?.charAt(0) ?? '?'}
          </div>
          <div className="portal__info">
            <h1>{profile.fullName}</h1>
            <div className="portal__meta">
              <span className="portal__meta-item mono">{profile.mrn}</span>
              <span className="portal__meta-sep">·</span>
              <span className="portal__meta-item">{profile.dateOfBirth}</span>
              <span className="portal__meta-sep">·</span>
              <span className="portal__meta-item capitalize">{profile.sex}</span>
            </div>
          </div>
        </div>
      )}

      {/* Tabs */}
      <div className="portal__tabs" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            onClick={() => loadTab(t.key)}
            className={`portal__tab ${tab === t.key ? 'portal__tab--active' : ''}`}
          >
            <span className="portal__tab-icon" aria-hidden="true">{t.icon}</span>
            {t.label}
          </button>
        ))}
      </div>

      {/* Error banner */}
      {error && (
        <div className="alert alert--danger portal__error" role="alert">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="portal__error-dismiss">Dismiss</button>
        </div>
      )}

      {/* Overview Tab */}
      {tab === 'overview' && (
        <>
          <Card title="Access Grants">
            {grants.length === 0 ? (
              <EmptyState title="No grants" body="No data access has been granted yet." />
            ) : (
              <div className="portal__list">
                {grants.map((g) => (
                  <div key={g.id} className="portal__list-item">
                    <div>
                      <span className="portal__list-label">{g.scope}</span>
                      <span className="portal__list-detail" style={{ marginLeft: 8 }}>{g.status}</span>
                    </div>
                    {g.status === 'granted' && (
                      <button onClick={() => handleRevokeGrant(g.id)} className="portal__revoke-btn">Revoke</button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Quick Access">
            <div className="portal__quick-grid">
              <button onClick={() => loadTab('medical')} className="portal__quick-card">
                <ClipboardList size={20} className="portal__quick-icon" aria-hidden="true" />
                <span className="portal__quick-label">Medical History</span>
              </button>
              <button onClick={() => loadTab('results')} className="portal__quick-card">
                <TestTube size={20} className="portal__quick-icon" aria-hidden="true" />
                <span className="portal__quick-label">Lab Results</span>
              </button>
              <button onClick={() => loadTab('prescriptions')} className="portal__quick-card">
                <Pill size={20} className="portal__quick-icon" aria-hidden="true" />
                <span className="portal__quick-label">Prescriptions</span>
              </button>
              <button onClick={() => loadTab('messaging')} className="portal__quick-card">
                <MessageSquare size={20} className="portal__quick-icon" aria-hidden="true" />
                <span className="portal__quick-label">Messages</span>
              </button>
            </div>
          </Card>
        </>
      )}

      {/* Medical History Tab */}
      {tab === 'medical' && (
        <>
          <Card title="Allergies">
            {medicalHistory?.allergies?.length === 0 ? (
              <EmptyState title="No allergies recorded" body="No allergy information on file." />
            ) : (
              <div className="portal__list">
                {medicalHistory?.allergies?.map((a) => (
                  <div key={a.id} className="portal__list-item">
                    <div>
                      <span className="portal__list-label">{a.allergen}</span>
                      {a.reaction && <span className="portal__list-detail"> — {a.reaction}</span>}
                    </div>
                    {a.severity && (
                      <StatusChip tone={SEVERITY_TONE[a.severity] ?? 'neutral'} label={a.severity} />
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Diagnoses">
            {medicalHistory?.diagnoses?.length === 0 ? (
              <EmptyState title="No diagnoses" body="No diagnosis information on file." />
            ) : (
              <div className="portal__list">
                {medicalHistory?.diagnoses?.map((d) => (
                  <div key={d.id} className="portal__list-item">
                    <div>
                      <span className="portal__list-label">{d.description}</span>
                      {d.code && <span className="portal__list-detail mono" style={{ marginLeft: 8 }}>{d.code}</span>}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Current Medications">
            {medications.length === 0 ? (
              <EmptyState title="No medications" body="No medication information on file." />
            ) : (
              <div className="portal__list">
                {medications.map((m) => (
                  <div key={m.id} className="portal__list-item">
                    <div>
                      <span className="portal__list-label">{m.medicationName}</span>
                      <span className="portal__list-detail" style={{ marginLeft: 8 }}>{m.dosage} · {m.frequency}</span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </>
      )}

      {/* Results Tab */}
      {tab === 'results' && (
        <>
          <Card title="Laboratory Results">
            {labResults.length === 0 ? (
              <EmptyState title="No lab results" body="No laboratory results available." />
            ) : (
              <div className="overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Test</th>
                      <th>Result</th>
                      <th>Range</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    {labResults.map((r) => (
                      <tr key={r.id}>
                        <td data-label="Test" className="mono">{r.testName ?? '—'}</td>
                        <td data-label="Result">{r.resultValue ?? '—'} {r.resultUnit ?? ''}</td>
                        <td data-label="Range" className="muted">{r.referenceRange ?? '—'}</td>
                        <td data-label="Date" className="muted">{r.resultedAt ? new Date(r.resultedAt).toLocaleDateString() : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card title="Radiology Reports">
            {radiologyReports.length === 0 ? (
              <EmptyState title="No radiology reports" body="No imaging reports available." />
            ) : (
              <div className="portal__list">
                {radiologyReports.map((r) => (
                  <div key={r.id} className="portal__list-item" style={{ flexDirection: 'column', alignItems: 'stretch' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span className="portal__list-label">{r.reportType} Report</span>
                      <StatusChip tone={r.status === 'verified' ? 'success' : 'neutral'} label={r.status} />
                    </div>
                    {r.impression && <p className="muted small" style={{ marginTop: 'var(--sp-2)' }}>{r.impression}</p>}
                    {r.criticalFindings && (
                      <div className="alert alert--danger" style={{ marginTop: 'var(--sp-2)', padding: 'var(--sp-2) var(--sp-3)' }}>
                        <AlertTriangle size={14} /> {r.criticalFindings}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>
        </>
      )}

      {/* Prescriptions Tab */}
      {tab === 'prescriptions' && (
        <Card title="Prescriptions">
          {medications.length === 0 ? (
            <EmptyState title="No prescriptions" body="No prescription records available." />
          ) : (
            <div className="portal__list">
              {medications.map((p) => (
                <div key={p.id} className="portal__list-item">
                  <div>
                    <span className="portal__list-label">{p.medicationName ?? 'Prescription'}</span>
                    <span className="portal__list-detail" style={{ marginLeft: 8 }}>{p.dosage} · {p.frequency}</span>
                  </div>
                  <StatusChip tone={p.status === 'active' ? 'success' : 'neutral'} label={p.status} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Documents Tab */}
      {tab === 'documents' && (
        <>
          <Card title="Clinical Documents">
            {documents.length === 0 ? (
              <EmptyState title="No documents" body="No clinical documents available." />
            ) : (
              <div className="portal__list">
                {documents.map((d) => (
                  <div key={d.id} className="portal__list-item">
                    <div>
                      <span className="portal__list-label">{d.title}</span>
                      <span className="portal__list-detail" style={{ marginLeft: 8 }}>{d.documentType} · {d.mimeType ?? ''}</span>
                    </div>
                    {d.createdAt && <span className="caption">{new Date(d.createdAt).toLocaleDateString()}</span>}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Referrals">
            {referrals.length === 0 ? (
              <EmptyState title="No referrals" body="No referral records available." />
            ) : (
              <div className="portal__list">
                {referrals.map((r) => (
                  <div key={r.id} className="portal__list-item">
                    <span className="portal__list-label">{r.reason ?? 'Referral'}</span>
                    <StatusChip tone={r.status === 'completed' ? 'success' : 'info'} label={r.status} />
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Immunization History">
            {immunizations.length === 0 ? (
              <EmptyState title="No immunizations" body="No immunization records available." />
            ) : (
              <div className="portal__list">
                {immunizations.map((i) => (
                  <div key={i.id} className="portal__list-item">
                    <span className="portal__list-label">{i.description ?? i.code ?? 'Immunization'}</span>
                    {i.observedAt && <span className="caption">{new Date(i.observedAt).toLocaleDateString()}</span>}
                  </div>
                ))}
              </div>
            )}
          </Card>
        </>
      )}

      {/* Messaging Tab */}
      {tab === 'messaging' && (
        <>
          <Card title="Send Message">
            <form onSubmit={handleSendMessage} className="portal__compose">
              <Input label="Recipient Staff ID" value={msgRecipient} onChange={(e) => setMsgRecipient(e.target.value)} required />
              <Input label="Subject" value={msgSubject} onChange={(e) => setMsgSubject(e.target.value)} required />
              <div className="field">
                <label className="field__label" htmlFor="portal-msg-body">Message</label>
                <textarea
                  id="portal-msg-body"
                  className="input input--area"
                  value={msgBody}
                  onChange={(e) => setMsgBody(e.target.value)}
                  rows={4}
                  required
                />
              </div>
              <Button type="submit" loading={sending}>Send Message</Button>
            </form>
          </Card>

          <Card title="Messages">
            {messages.length === 0 ? (
              <EmptyState title="No messages" body="No secure messages yet." />
            ) : (
              <div className="stack">
                {messages.map((m) => (
                  <div key={m.id} className={`portal__message ${m.senderIsPatient ? 'portal__message--sent' : 'portal__message--received'}`}>
                    <div className="portal__message-header">
                      <span className="portal__list-label">{m.subject}</span>
                      <StatusChip tone={m.senderIsPatient ? 'info' : 'neutral'} label={m.senderIsPatient ? 'Sent' : 'Received'} />
                    </div>
                    <div className="portal__message-body">{m.body}</div>
                    {m.createdAt && <div className="portal__message-time">{new Date(m.createdAt).toLocaleString()}</div>}
                  </div>
                ))}
              </div>
            )}
          </Card>
        </>
      )}

      {/* Appointments Tab */}
      {tab === 'appointments' && (
        <Card title="Appointments">
          {appointments.length === 0 ? (
            <EmptyState title="No appointments" body="No appointment records available." />
          ) : (
            <div className="portal__list">
              {appointments.map((a) => (
                <div key={a.id} className="portal__list-item">
                  <div>
                    <span className="portal__list-label">Appointment</span>
                    {a.scheduledAt && <span className="portal__list-detail" style={{ marginLeft: 8 }}>{new Date(a.scheduledAt).toLocaleString()}</span>}
                  </div>
                  <StatusChip tone={a.status === 'completed' ? 'success' : 'info'} label={a.status} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Billing Tab */}
      {tab === 'billing' && (
        <Card title="Bills">
          {bills.length === 0 ? (
            <EmptyState title="No bills" body="No billing records available." />
          ) : (
            <div className="portal__list">
              {bills.map((b) => (
                <div key={b.id} className="portal__list-item">
                  <div>
                    <span className="portal__list-label">Bill</span>
                    <span className="portal__list-detail" style={{ marginLeft: 8 }}>
                      Total: Rs {(b.totalMinor / 100).toFixed(2)} · Paid: Rs {(b.paidMinor / 100).toFixed(2)}
                    </span>
                  </div>
                  <StatusChip
                    tone={b.status === 'paid' ? 'success' : b.status === 'partially_paid' ? 'warning' : 'neutral'}
                    label={b.status}
                  />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Consent Tab */}
      {tab === 'consent' && (
        <Card title="Consent Records">
          {consents.length === 0 ? (
            <EmptyState title="No consents" body="No consent records on file." />
          ) : (
            <div className="portal__list">
              {consents.map((c) => (
                <div key={c.id} className="portal__consent-row">
                  <div>
                    <span className="portal__list-label">{c.dataCategory}</span>
                    <span className="portal__list-detail" style={{ marginLeft: 8 }}>
                      {c.purpose ?? 'General'} · Granted: {c.grantedAt ? new Date(c.grantedAt).toLocaleDateString() : '—'}
                    </span>
                  </div>
                  <div className="portal__consent-actions">
                    <StatusChip tone={c.consentStatus === 'granted' ? 'success' : 'neutral'} label={c.consentStatus} />
                    {c.consentStatus === 'granted' && (
                      <button onClick={() => handleRevokeConsent(c.id)} className="portal__revoke-btn">Revoke</button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Preferences Tab */}
      {tab === 'preferences' && notifPrefs && (
        <Card title="Notification Preferences">
          <div className="portal__prefs">
            {[
              { key: 'emailEnabled', label: 'Email Notifications', value: notifPrefs.emailEnabled },
              { key: 'smsEnabled', label: 'SMS Notifications', value: notifPrefs.smsEnabled },
              { key: 'pushEnabled', label: 'Push Notifications', value: notifPrefs.pushEnabled },
              { key: 'appointmentReminders', label: 'Appointment Reminders', value: notifPrefs.appointmentReminders },
              { key: 'resultNotifications', label: 'Result Notifications', value: notifPrefs.resultNotifications },
              { key: 'billingNotifications', label: 'Billing Notifications', value: notifPrefs.billingNotifications },
              { key: 'messagingNotifications', label: 'Messaging Notifications', value: notifPrefs.messagingNotifications },
              { key: 'marketingOptOut', label: 'Marketing Opt-out', value: notifPrefs.marketingOptOut },
            ].map((pref) => (
              <label key={pref.key} className="portal__pref-row">
                <span className="portal__pref-label">{pref.label}</span>
                <input
                  type="checkbox"
                  checked={pref.value}
                  onChange={(e) => handleUpdatePrefs({ [pref.key]: e.target.checked })}
                  className="input"
                  style={{ width: 'auto', minHeight: 'auto' }}
                />
              </label>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
