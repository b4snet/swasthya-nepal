import { useState, useEffect } from 'react';
import { portalApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import { Card, EmptyState, Spinner } from '../components/ui';

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

const severityColors: Record<string, string> = {
  mild: 'bg-yellow-100 text-yellow-800',
  moderate: 'bg-orange-100 text-orange-800',
  severe: 'bg-red-100 text-red-800',
  life_threatening: 'bg-red-200 text-red-900',
};

function Badge({ text, className }: { text: string; className?: string }) {
  return <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${className ?? 'bg-gray-100 text-gray-700'}`}>{text}</span>;
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-2">
      <h4 className="font-semibold text-sm text-gray-700 uppercase tracking-wide">{title}</h4>
      {children}
    </div>
  );
}

export function PatientPortalPage() {
  const [tab, setTab] = useState<Tab>('overview');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Data
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

  // Messaging form
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

  if (loading) return <div className="flex justify-center p-8"><Spinner /></div>;
  if (error) return <div className="p-8 text-center text-red-600">{error}</div>;

  const tabs: { key: Tab; label: string; icon: string }[] = [
    { key: 'overview', label: 'Overview', icon: '🏠' },
    { key: 'medical', label: 'Medical', icon: '📋' },
    { key: 'results', label: 'Results', icon: '🔬' },
    { key: 'prescriptions', label: 'Rx', icon: '💊' },
    { key: 'documents', label: 'Docs', icon: '📄' },
    { key: 'messaging', label: 'Messages', icon: '💬' },
    { key: 'appointments', label: 'Appts', icon: '📅' },
    { key: 'billing', label: 'Billing', icon: '💰' },
    { key: 'consent', label: 'Consent', icon: '🔒' },
    { key: 'preferences', label: 'Settings', icon: '⚙️' },
  ];

  return (
    <div className="max-w-4xl mx-auto space-y-6 p-4">
      {/* Header */}
      {profile && (
        <Card className="p-6">
          <div className="flex items-center gap-4">
            <div className="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center text-2xl font-bold text-blue-600">
              {profile.fullName?.charAt(0) ?? '?'}
            </div>
            <div>
              <h1 className="text-xl font-bold">{profile.fullName}</h1>
              <div className="text-sm text-gray-500">MRN: {profile.mrn} · DOB: {profile.dateOfBirth} · {profile.sex}</div>
            </div>
          </div>
        </Card>
      )}

      {/* Tabs */}
      <div className="flex gap-1 overflow-x-auto pb-2 -mx-4 px-4">
        {tabs.map((t) => (
          <button
            key={t.key}
            onClick={() => loadTab(t.key)}
            className={`flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-lg whitespace-nowrap transition-colors ${tab === t.key ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}
          >
            <span>{t.icon}</span> {t.label}
          </button>
        ))}
      </div>

      {/* Error Banner */}
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
          {error}
          <button onClick={() => setError(null)} className="ml-2 underline">Dismiss</button>
        </div>
      )}

      {/* Overview Tab */}
      {tab === 'overview' && (
        <div className="space-y-4">
          <Section title="Access Grants">
            {grants.length === 0 ? (
              <EmptyState title="No grants" body="No data access has been granted yet." />
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                {grants.map((g) => (
                  <div key={g.id} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                      <span className="font-medium text-sm">{g.scope}</span>
                      <span className="text-xs text-gray-500 ml-2">{g.status}</span>
                    </div>
                    {g.status === 'granted' && (
                      <button onClick={() => handleRevokeGrant(g.id)} className="text-xs text-red-600 hover:underline">Revoke</button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Section>

          <Section title="Quick Access">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
              <button onClick={() => loadTab('medical')} className="p-4 bg-blue-50 rounded-lg text-left hover:bg-blue-100">
                <div className="text-lg">📋</div><div className="text-sm font-medium">Medical History</div>
              </button>
              <button onClick={() => loadTab('results')} className="p-4 bg-green-50 rounded-lg text-left hover:bg-green-100">
                <div className="text-lg">🔬</div><div className="text-sm font-medium">Lab Results</div>
              </button>
              <button onClick={() => loadTab('prescriptions')} className="p-4 bg-purple-50 rounded-lg text-left hover:bg-purple-100">
                <div className="text-lg">💊</div><div className="text-sm font-medium">Prescriptions</div>
              </button>
              <button onClick={() => loadTab('messaging')} className="p-4 bg-orange-50 rounded-lg text-left hover:bg-orange-100">
                <div className="text-lg">💬</div><div className="text-sm font-medium">Messages</div>
              </button>
            </div>
          </Section>
        </div>
      )}

      {/* Medical History Tab */}
      {tab === 'medical' && (
        <div className="space-y-6">
          <Section title="Allergies">
            {medicalHistory?.allergies?.length === 0 ? (
              <EmptyState title="No allergies recorded" body="No allergy information on file." />
            ) : (
              <div className="space-y-2">
                {medicalHistory?.allergies?.map((a) => (
                  <div key={a.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                    <div>
                      <span className="font-medium">{a.allergen}</span>
                      {a.reaction && <span className="text-sm text-gray-500 ml-2">— {a.reaction}</span>}
                    </div>
                    {a.severity && <Badge text={a.severity} className={severityColors[a.severity] ?? ''} />}
                  </div>
                ))}
              </div>
            )}
          </Section>

          <Section title="Diagnoses">
            {medicalHistory?.diagnoses?.length === 0 ? (
              <EmptyState title="No diagnoses" body="No diagnosis information on file." />
            ) : (
              <div className="space-y-2">
                {medicalHistory?.diagnoses?.map((d) => (
                  <div key={d.id} className="p-3 bg-gray-50 rounded-lg">
                    <div className="font-medium">{d.description}</div>
                    {d.code && <div className="text-xs text-gray-500">{d.code}</div>}
                  </div>
                ))}
              </div>
            )}
          </Section>

          <Section title="Current Medications">
            {medications.length === 0 ? (
              <EmptyState title="No medications" body="No medication information on file." />
            ) : (
              <div className="space-y-2">
                {medications.map((m) => (
                  <div key={m.id} className="p-3 bg-gray-50 rounded-lg">
                    <div className="font-medium">{m.medicationName}</div>
                    <div className="text-sm text-gray-500">{m.dosage} · {m.frequency}</div>
                  </div>
                ))}
              </div>
            )}
          </Section>
        </div>
      )}

      {/* Results Tab */}
      {tab === 'results' && (
        <div className="space-y-6">
          <Section title="Laboratory Results">
            {labResults.length === 0 ? (
              <EmptyState title="No lab results" body="No laboratory results available." />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead><tr className="text-left text-gray-500 border-b"><th className="pb-2">Test</th><th className="pb-2">Result</th><th className="pb-2">Range</th><th className="pb-2">Date</th></tr></thead>
                  <tbody>
                    {labResults.map((r) => (
                      <tr key={r.id} className="border-b border-gray-100">
                        <td className="py-2 font-medium">{r.testName ?? '—'}</td>
                        <td className="py-2">{r.resultValue ?? '—'} {r.resultUnit ?? ''}</td>
                        <td className="py-2 text-gray-500">{r.referenceRange ?? '—'}</td>
                        <td className="py-2 text-gray-500">{r.resultedAt ? new Date(r.resultedAt).toLocaleDateString() : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Section>

          <Section title="Radiology Reports">
            {radiologyReports.length === 0 ? (
              <EmptyState title="No radiology reports" body="No imaging reports available." />
            ) : (
              <div className="space-y-2">
                {radiologyReports.map((r) => (
                  <div key={r.id} className="p-3 bg-gray-50 rounded-lg">
                    <div className="flex justify-between">
                      <span className="font-medium">{r.reportType} Report</span>
                      <Badge text={r.status} />
                    </div>
                    {r.impression && <div className="text-sm text-gray-600 mt-1">{r.impression}</div>}
                    {r.criticalFindings && <div className="text-sm text-red-600 mt-1">⚠️ {r.criticalFindings}</div>}
                  </div>
                ))}
              </div>
            )}
          </Section>
        </div>
      )}

      {/* Prescriptions Tab */}
      {tab === 'prescriptions' && (
        <Section title="Prescriptions">
          {medications.length === 0 ? (
            <EmptyState title="No prescriptions" body="No prescription records available." />
          ) : (
            <div className="space-y-2">
              {medications.map((p) => (
                <div key={p.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                  <div>
                    <div className="font-medium">{p.medicationName ?? 'Prescription'}</div>
                    <div className="text-sm text-gray-500">{p.dosage} · {p.frequency}</div>
                  </div>
                  <Badge text={p.status} />
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Documents Tab */}
      {tab === 'documents' && (
        <div className="space-y-6">
          <Section title="Clinical Documents">
            {documents.length === 0 ? (
              <EmptyState title="No documents" body="No clinical documents available." />
            ) : (
              <div className="space-y-2">
                {documents.map((d) => (
                  <div key={d.id} className="p-3 bg-gray-50 rounded-lg flex justify-between">
                    <div>
                      <div className="font-medium">{d.title}</div>
                      <div className="text-xs text-gray-500">{d.documentType} · {d.mimeType ?? ''}</div>
                    </div>
                    {d.createdAt && <div className="text-xs text-gray-400">{new Date(d.createdAt).toLocaleDateString()}</div>}
                  </div>
                ))}
              </div>
            )}
          </Section>

          <Section title="Referrals">
            {referrals.length === 0 ? (
              <EmptyState title="No referrals" body="No referral records available." />
            ) : (
              <div className="space-y-2">
                {referrals.map((r) => (
                  <div key={r.id} className="p-3 bg-gray-50 rounded-lg flex justify-between">
                    <div className="font-medium">{r.reason ?? 'Referral'}</div>
                    <Badge text={r.status} />
                  </div>
                ))}
              </div>
            )}
          </Section>

          <Section title="Immunization History">
            {immunizations.length === 0 ? (
              <EmptyState title="No immunizations" body="No immunization records available." />
            ) : (
              <div className="space-y-2">
                {immunizations.map((i) => (
                  <div key={i.id} className="p-3 bg-gray-50 rounded-lg flex justify-between">
                    <div>
                      <div className="font-medium">{i.description ?? i.code ?? 'Immunization'}</div>
                    </div>
                    {i.observedAt && <div className="text-xs text-gray-400">{new Date(i.observedAt).toLocaleDateString()}</div>}
                  </div>
                ))}
              </div>
            )}
          </Section>
        </div>
      )}

      {/* Messaging Tab */}
      {tab === 'messaging' && (
        <div className="space-y-6">
          <Section title="Send Message">
            <form onSubmit={handleSendMessage} className="space-y-3 p-4 bg-gray-50 rounded-lg">
              <input value={msgRecipient} onChange={(e) => setMsgRecipient(e.target.value)} placeholder="Recipient Staff ID" className="w-full border rounded px-3 py-2 text-sm" required />
              <input value={msgSubject} onChange={(e) => setMsgSubject(e.target.value)} placeholder="Subject" className="w-full border rounded px-3 py-2 text-sm" required />
              <textarea value={msgBody} onChange={(e) => setMsgBody(e.target.value)} placeholder="Message..." rows={4} className="w-full border rounded px-3 py-2 text-sm" required />
              <button type="submit" disabled={sending} className="px-4 py-2 bg-blue-600 text-white rounded text-sm disabled:opacity-50">
                {sending ? 'Sending...' : 'Send Message'}
              </button>
            </form>
          </Section>

          <Section title="Messages">
            {messages.length === 0 ? (
              <EmptyState title="No messages" body="No secure messages yet." />
            ) : (
              <div className="space-y-2">
                {messages.map((m) => (
                  <div key={m.id} className={`p-3 rounded-lg ${m.senderIsPatient ? 'bg-blue-50 ml-8' : 'bg-gray-50 mr-8'}`}>
                    <div className="flex justify-between">
                      <span className="font-medium text-sm">{m.subject}</span>
                      <Badge text={m.senderIsPatient ? 'Sent' : 'Received'} className={m.senderIsPatient ? 'bg-blue-100 text-blue-800' : ''} />
                    </div>
                    <div className="text-sm text-gray-600 mt-1">{m.body}</div>
                    {m.createdAt && <div className="text-xs text-gray-400 mt-1">{new Date(m.createdAt).toLocaleString()}</div>}
                  </div>
                ))}
              </div>
            )}
          </Section>
        </div>
      )}

      {/* Appointments Tab */}
      {tab === 'appointments' && (
        <Section title="Appointments">
          {appointments.length === 0 ? (
            <EmptyState title="No appointments" body="No appointment records available." />
          ) : (
            <div className="space-y-2">
              {appointments.map((a) => (
                <div key={a.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                  <div>
                    <div className="font-medium">Appointment</div>
                    {a.scheduledAt && <div className="text-sm text-gray-500">{new Date(a.scheduledAt).toLocaleString()}</div>}
                  </div>
                  <Badge text={a.status} />
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Billing Tab */}
      {tab === 'billing' && (
        <Section title="Bills">
          {bills.length === 0 ? (
            <EmptyState title="No bills" body="No billing records available." />
          ) : (
            <div className="space-y-2">
              {bills.map((b) => (
                <div key={b.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                  <div>
                    <div className="font-medium">Bill</div>
                    <div className="text-sm text-gray-500">
                      Total: Rs {(b.totalMinor / 100).toFixed(2)} · Paid: Rs {(b.paidMinor / 100).toFixed(2)}
                    </div>
                  </div>
                  <Badge text={b.status} />
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Consent Tab */}
      {tab === 'consent' && (
        <Section title="Consent Records">
          {consents.length === 0 ? (
            <EmptyState title="No consents" body="No consent records on file." />
          ) : (
            <div className="space-y-2">
              {consents.map((c) => (
                <div key={c.id} className="p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                  <div>
                    <div className="font-medium text-sm">{c.dataCategory}</div>
                    <div className="text-xs text-gray-500">{c.purpose ?? 'General'} · Granted: {c.grantedAt ? new Date(c.grantedAt).toLocaleDateString() : '—'}</div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge text={c.consentStatus} className={c.consentStatus === 'granted' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'} />
                    {c.consentStatus === 'granted' && (
                      <button onClick={() => handleRevokeConsent(c.id)} className="text-xs text-red-600 hover:underline">Revoke</button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Preferences Tab */}
      {tab === 'preferences' && notifPrefs && (
        <Section title="Notification Preferences">
          <div className="space-y-3 p-4 bg-gray-50 rounded-lg">
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
              <label key={pref.key} className="flex items-center justify-between">
                <span className="text-sm">{pref.label}</span>
                <input
                  type="checkbox"
                  checked={pref.value}
                  onChange={(e) => handleUpdatePrefs({ [pref.key]: e.target.checked })}
                  className="h-4 w-4 text-blue-600 rounded"
                />
              </label>
            ))}
          </div>
        </Section>
      )}
    </div>
  );
}
