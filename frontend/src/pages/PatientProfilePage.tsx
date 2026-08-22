/**
 * Patient Longitudinal Record — Clinical Workspace
 *
 * The central clinical object in SWASTHYA. Provides a role-aware,
 * contextual view of the patient across all encounters, departments,
 * and care settings.
 *
 * Tabs are filtered by the logged-in user's role.
 * Backend authorization remains authoritative.
 */

import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Spinner,
  StatusChip,
  Tabs,
  formatDate,
  formatDateTime,
} from '../components/ui';
import { encountersApi } from '../api/endpoints';
import {
  UserRound,
  FileText,
  Pill,
  FlaskConical,
  ScanLine,
  Bed,
  GitPullRequestArrow,
  CalendarDays,
  ClipboardList,
  Activity,
} from 'lucide-react';
import './patient.css';

/* ── Timeline helper ── */
export function timelineSummary(summary: any): string {
  if (typeof summary === 'string') return summary;
  if (Array.isArray(summary)) return summary.map(String).join(', ');
  if (summary && typeof summary === 'object') {
    return Object.values(summary)
      .flatMap((v) => (Array.isArray(v) ? v.map(String) : [String(v)]))
      .join(' · ');
  }
  return '';
}

/* ── Tab definitions ── */
const ALL_TABS = [
  { id: 'overview', label: 'Overview', Icon: Activity, roles: [] as string[] },
  { id: 'encounters', label: 'Encounters', Icon: FileText, roles: [] as string[] },
  { id: 'diagnoses', label: 'Diagnoses', Icon: ClipboardList, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'medications', label: 'Medications', Icon: Pill, roles: ['doctor', 'nurse', 'pharmacist', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'lab', label: 'Laboratory', Icon: FlaskConical, roles: ['doctor', 'nurse', 'lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'radiology', label: 'Radiology', Icon: ScanLine, roles: ['doctor', 'nurse', 'radiologist', 'radiographer', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'admissions', label: 'Admissions', Icon: Bed, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'referrals', label: 'Referrals', Icon: GitPullRequestArrow, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'] },
  { id: 'appointments', label: 'Appointments', Icon: CalendarDays, roles: [] as string[] },
  { id: 'documents', label: 'Documents', Icon: FileText, roles: [] as string[] },
  { id: 'timeline', label: 'Timeline', Icon: Activity, roles: [] as string[] },
];

/* ════════════════════════════════════════════════════════════════════════════
   MAIN PAGE
   ════════════════════════════════════════════════════════════════════════════ */

export function PatientProfilePage() {
  const { id } = useParams<{ id: string }>();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  /* ── Data fetching ── */
  const profile = useFetch(() => patientsApi.show(id!, fac), [id, fac]);
  const timeline = useFetch(() => patientsApi.timeline(id!, fac), [id, fac]);
  const encounters = useFetch(() => encountersApi.forPatient(id!, fac), [id, fac]);
  const diagnoses = useFetch(() => patientsApi.diagnoses(id!, fac), [id, fac]);
  const prescriptions = useFetch(() => patientsApi.prescriptions(id!, fac), [id, fac]);
  const labOrders = useFetch(() => patientsApi.labOrders(id!, fac), [id, fac]);
  const radiologyOrders = useFetch(() => patientsApi.radiologyOrders(id!, fac), [id, fac]);
  const admissions = useFetch(() => patientsApi.admissions(id!, fac), [id, fac]);
  const referrals = useFetch(() => patientsApi.referrals(id!, fac), [id, fac]);
  const documents = useFetch(() => patientsApi.documents(id!, fac), [id, fac]);
  const appointments = useFetch(
    () => patientsApi.followUps(id!, fac),
    [id, fac],
  );

  const [tab, setTab] = useState('overview');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  /* ── Loading / error states ── */
  if (profile.loading) return <Spinner />;
  if (profile.error) return <ErrorState error={profile.error} onRetry={() => void profile.refresh()} />;
  if (!profile.data) return <EmptyState title="Patient not found" body="This patient may have been removed or is outside your facility scope." />;

  const patient = profile.data;
  const canEdit = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');
  const canBook = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');

  /* ── Filter tabs by role ── */
  const visibleTabs = ALL_TABS.filter(
    (t) => t.roles.length === 0 || t.roles.some((r) => hasRole(r as any)),
  );

  /* ── Tab badge counts ── */
  const tabCounts: Record<string, number | undefined> = {
    encounters: (encounters.data as any[])?.length,
    diagnoses: (diagnoses.data as any[])?.length,
    medications: (prescriptions.data as any[])?.length,
    lab: (labOrders.data as any[])?.length,
    radiology: (radiologyOrders.data as any[])?.length,
    admissions: (admissions.data as any[])?.length,
    referrals: (referrals.data as any[])?.length,
    documents: (documents.data as any[])?.length,
  };

  return (
    <div className="page">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {/* ═══ PATIENT HEADER ═══ */}
      <div className="patient-hero">
        <div className="patient-hero__avatar">
          <UserRound size={24} />
        </div>
        <div className="patient-hero__info">
          <h1 className="patient-hero__name">{patient.fullName}</h1>
          <div className="patient-hero__meta">
            <span className="mono">{patient.mrn}</span>
            <span className="patient-hero__sep">·</span>
            <span>{patient.dateOfBirth ? formatDate(patient.dateOfBirth) : '—'}</span>
            <span className="patient-hero__sep">·</span>
            <span className="capitalize">{patient.sex}</span>
            {patient.bloodGroup && (
              <>
                <span className="patient-hero__sep">·</span>
                <span>{patient.bloodGroup}</span>
              </>
            )}
            {patient.status !== 'active' && (
              <StatusChip
                tone={patient.status === 'deceased' ? 'danger' : 'neutral'}
                label={patient.status}
              />
            )}
          </div>
        </div>
        <div className="patient-hero__actions">
          {canEdit && (
            <Button
              variant="secondary"
              onClick={() => setNotice({ tone: 'success', text: 'Edit dialog coming soon.' })}
            >
              Edit
            </Button>
          )}
          {canBook && (
            <Link className="btn btn--primary" to={`/clinical/appointments?patientId=${patient.id}`}>
              Book appointment
            </Link>
          )}
        </div>
      </div>

      {/* ═══ TABS ═══ */}
      <Tabs
        tabs={visibleTabs.map((t) => ({
          id: t.id,
          label: tabCounts[t.id] !== undefined && tabCounts[t.id] !== undefined
            ? `${t.label} (${tabCounts[t.id]})`
            : t.label,
        }))}
        active={tab}
        onChange={(t) => setTab(t)}
      />

      {/* ═══ TAB CONTENT ═══ */}

      {/* ── Overview ── */}
      {tab === 'overview' && (
        <div className="patient-overview">
          <div className="patient-overview__grid">
            <Card title="Demographics">
              <dl className="kv">
                <div><dt>MRN</dt><dd className="mono">{patient.mrn}</dd></div>
                <div><dt>Blood group</dt><dd>{patient.bloodGroup ?? '—'}</dd></div>
                <div><dt>Status</dt><dd><StatusChip tone={patient.status === 'active' ? 'success' : 'neutral'} label={patient.status} /></dd></div>
                <div><dt>Registered</dt><dd>{formatDateTime(patient.createdAt)}</dd></div>
              </dl>
            </Card>

            <Card title="Recent Encounters">
              {(encounters.data as any[])?.length === 0 ? (
                <p className="muted">No encounters yet</p>
              ) : (
                <ul className="patient-list">
                  {(encounters.data as any[])?.slice(0, 5).map((e: any) => (
                    <li key={e.id} className="patient-list__item">
                      <span className="patient-list__primary">{e.type} — {e.providerName ?? 'Unknown'}</span>
                      <span className="patient-list__secondary mono">{formatDateTime(e.startedAt)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card title="Active Diagnoses">
              {(diagnoses.data as any[])?.filter((d: any) => d.status === 'active').length === 0 ? (
                <p className="muted">No active diagnoses</p>
              ) : (
                <ul className="patient-list">
                  {(diagnoses.data as any[])?.filter((d: any) => d.status === 'active').slice(0, 5).map((d: any) => (
                    <li key={d.id} className="patient-list__item">
                      <span className="patient-list__primary">{d.description ?? d.code ?? 'Diagnosis'}</span>
                      <StatusChip tone={d.type === 'final' ? 'success' : 'info'} label={d.type} />
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card title="Recent Lab Orders">
              {(labOrders.data as any[])?.length === 0 ? (
                <p className="muted">No lab orders</p>
              ) : (
                <ul className="patient-list">
                  {(labOrders.data as any[])?.slice(0, 5).map((o: any) => (
                    <li key={o.id} className="patient-list__item">
                      <span className="patient-list__primary">{o.testName ?? o.name ?? 'Lab order'}</span>
                      <StatusChip
                        tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'}
                        label={o.status}
                      />
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      )}

      {/* ── Encounters ── */}
      {tab === 'encounters' && (
        <Card title="Encounters">
          {encounters.loading ? (
            <Spinner label="Loading encounters…" />
          ) : encounters.error ? (
            <ErrorState error={encounters.error} onRetry={() => void encounters.refresh()} />
          ) : (encounters.data as any[])?.length === 0 ? (
            <EmptyState title="No encounters yet" body="Consultations and visits will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient encounters">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Provider</th>
                  <th>Service</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {(encounters.data as any[]).map((e: any) => (
                  <tr key={e.id}>
                    <td data-label="Date" className="mono">{formatDateTime(e.startedAt)}</td>
                    <td data-label="Type" className="capitalize">{e.type}</td>
                    <td data-label="Provider">{e.providerName ?? '—'}</td>
                    <td data-label="Service">{e.serviceName ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={e.status === 'signed' ? 'success' : e.status === 'open' ? 'info' : 'neutral'}
                        label={e.status}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Diagnoses ── */}
      {tab === 'diagnoses' && (
        <Card title="Diagnoses & Problems">
          {diagnoses.loading ? (
            <Spinner label="Loading diagnoses…" />
          ) : diagnoses.error ? (
            <ErrorState error={diagnoses.error} onRetry={() => void diagnoses.refresh()} />
          ) : (diagnoses.data as any[])?.length === 0 ? (
            <EmptyState title="No diagnoses recorded" body="Diagnoses from encounters will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient diagnoses">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Diagnosis</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Provider</th>
                </tr>
              </thead>
              <tbody>
                {(diagnoses.data as any[]).map((d: any) => (
                  <tr key={d.id}>
                    <td data-label="Date" className="mono">{formatDateTime(d.createdAt)}</td>
                    <td data-label="Diagnosis">{d.description ?? d.code ?? '—'}</td>
                    <td data-label="Type" className="capitalize">{d.type ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={d.status === 'active' ? 'warning' : d.status === 'resolved' ? 'success' : 'neutral'}
                        label={d.status}
                      />
                    </td>
                    <td data-label="Provider">{d.providerName ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Medications ── */}
      {tab === 'medications' && (
        <Card title="Medications & Prescriptions">
          {prescriptions.loading ? (
            <Spinner label="Loading prescriptions…" />
          ) : prescriptions.error ? (
            <ErrorState error={prescriptions.error} onRetry={() => void prescriptions.refresh()} />
          ) : (prescriptions.data as any[])?.length === 0 ? (
            <EmptyState title="No prescriptions" body="Prescriptions from encounters will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient prescriptions">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Medication</th>
                  <th>Dosage</th>
                  <th>Frequency</th>
                  <th>Status</th>
                  <th>Prescriber</th>
                </tr>
              </thead>
              <tbody>
                {(prescriptions.data as any[]).map((p: any) => (
                  <tr key={p.id}>
                    <td data-label="Date" className="mono">{formatDateTime(p.createdAt)}</td>
                    <td data-label="Medication">{p.medicationName ?? p.medication?.name ?? '—'}</td>
                    <td data-label="Dosage">{p.dosage ?? '—'}</td>
                    <td data-label="Frequency">{p.frequency ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={p.status === 'active' ? 'success' : p.status === 'dispensed' ? 'info' : 'neutral'}
                        label={p.status}
                      />
                    </td>
                    <td data-label="Prescriber">{p.prescriberName ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Laboratory ── */}
      {tab === 'lab' && (
        <Card title="Laboratory Orders">
          {labOrders.loading ? (
            <Spinner label="Loading lab orders…" />
          ) : labOrders.error ? (
            <ErrorState error={labOrders.error} onRetry={() => void labOrders.refresh()} />
          ) : (labOrders.data as any[])?.length === 0 ? (
            <EmptyState title="No lab orders" body="Laboratory orders for this patient will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient lab orders">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Test</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Ordered By</th>
                </tr>
              </thead>
              <tbody>
                {(labOrders.data as any[]).map((o: any) => (
                  <tr key={o.id}>
                    <td data-label="Date" className="mono">{formatDateTime(o.createdAt)}</td>
                    <td data-label="Test">{o.testName ?? o.name ?? '—'}</td>
                    <td data-label="Priority" className="capitalize">{o.priority ?? 'routine'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'}
                        label={o.status}
                      />
                    </td>
                    <td data-label="Ordered By">{o.orderedByName ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Radiology ── */}
      {tab === 'radiology' && (
        <Card title="Radiology Orders">
          {radiologyOrders.loading ? (
            <Spinner label="Loading radiology orders…" />
          ) : radiologyOrders.error ? (
            <ErrorState error={radiologyOrders.error} onRetry={() => void radiologyOrders.refresh()} />
          ) : (radiologyOrders.data as any[])?.length === 0 ? (
            <EmptyState title="No radiology orders" body="Imaging orders for this patient will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient radiology orders">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Study</th>
                  <th>Modality</th>
                  <th>Status</th>
                  <th>Ordered By</th>
                </tr>
              </thead>
              <tbody>
                {(radiologyOrders.data as any[]).map((o: any) => (
                  <tr key={o.id}>
                    <td data-label="Date" className="mono">{formatDateTime(o.createdAt)}</td>
                    <td data-label="Study">{o.studyName ?? o.name ?? '—'}</td>
                    <td data-label="Modality">{o.modality ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={o.status === 'reported' ? 'success' : o.status === 'verified' ? 'info' : 'neutral'}
                        label={o.status}
                      />
                    </td>
                    <td data-label="Ordered By">{o.orderedByName ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Admissions ── */}
      {tab === 'admissions' && (
        <Card title="Admissions">
          {admissions.loading ? (
            <Spinner label="Loading admissions…" />
          ) : admissions.error ? (
            <ErrorState error={admissions.error} onRetry={() => void admissions.refresh()} />
          ) : (admissions.data as any[])?.length === 0 ? (
            <EmptyState title="No admissions" body="Inpatient admissions will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient admissions">
              <thead>
                <tr>
                  <th>Admitted</th>
                  <th>Ward</th>
                  <th>Bed</th>
                  <th>Attending</th>
                  <th>Status</th>
                  <th>Discharged</th>
                </tr>
              </thead>
              <tbody>
                {(admissions.data as any[]).map((a: any) => (
                  <tr key={a.id}>
                    <td data-label="Admitted" className="mono">{formatDateTime(a.admittedAt)}</td>
                    <td data-label="Ward">{a.wardName ?? '—'}</td>
                    <td data-label="Bed">{a.bedNumber ?? '—'}</td>
                    <td data-label="Attending">{a.attendingName ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={a.dischargedAt ? 'success' : 'info'}
                        label={a.dischargedAt ? 'discharged' : 'active'}
                      />
                    </td>
                    <td data-label="Discharged">{a.dischargedAt ? formatDateTime(a.dischargedAt) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Referrals ── */}
      {tab === 'referrals' && (
        <Card title="Referrals">
          {referrals.loading ? (
            <Spinner label="Loading referrals…" />
          ) : referrals.error ? (
            <ErrorState error={referrals.error} onRetry={() => void referrals.refresh()} />
          ) : (referrals.data as any[])?.length === 0 ? (
            <EmptyState title="No referrals" body="Internal and external referrals will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient referrals">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Reason</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {(referrals.data as any[]).map((r: any) => (
                  <tr key={r.id}>
                    <td data-label="Date" className="mono">{formatDateTime(r.createdAt)}</td>
                    <td data-label="From">{r.fromDepartment ?? r.referringProvider ?? '—'}</td>
                    <td data-label="To">{r.toDepartment ?? r.receivingProvider ?? '—'}</td>
                    <td data-label="Reason">{r.reason ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={r.status === 'completed' ? 'success' : r.status === 'accepted' ? 'info' : 'neutral'}
                        label={r.status}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Appointments ── */}
      {tab === 'appointments' && (
        <Card title="Appointments & Follow-ups">
          {appointments.loading ? (
            <Spinner label="Loading appointments…" />
          ) : appointments.error ? (
            <ErrorState error={appointments.error} onRetry={() => void appointments.refresh()} />
          ) : (appointments.data as any[])?.length === 0 ? (
            <EmptyState title="No appointments" body="Scheduled appointments and follow-ups will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient appointments">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Provider</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {(appointments.data as any[]).map((a: any) => (
                  <tr key={a.id}>
                    <td data-label="Date" className="mono">{formatDateTime(a.scheduledAt ?? a.date)}</td>
                    <td data-label="Type" className="capitalize">{a.type ?? 'consultation'}</td>
                    <td data-label="Provider">{a.providerName ?? '—'}</td>
                    <td data-label="Status">
                      <StatusChip
                        tone={a.status === 'completed' ? 'success' : a.status === 'cancelled' ? 'danger' : 'info'}
                        label={a.status}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Documents ── */}
      {tab === 'documents' && (
        <Card title="Documents">
          {documents.loading ? (
            <Spinner label="Loading documents…" />
          ) : documents.error ? (
            <ErrorState error={documents.error} onRetry={() => void documents.refresh()} />
          ) : (documents.data as any[])?.length === 0 ? (
            <EmptyState title="No documents" body="Patient documents, consents, and forms will appear here." />
          ) : (
            <table className="data-table" aria-label="Patient documents">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Document</th>
                  <th>Type</th>
                  <th>Author</th>
                </tr>
              </thead>
              <tbody>
                {(documents.data as any[]).map((d: any) => (
                  <tr key={d.id}>
                    <td data-label="Date" className="mono">{formatDateTime(d.createdAt)}</td>
                    <td data-label="Document">{d.name ?? d.title ?? '—'}</td>
                    <td data-label="Type" className="capitalize">{d.type ?? '—'}</td>
                    <td data-label="Author">{d.authorName ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}

      {/* ── Timeline ── */}
      {tab === 'timeline' && (
        <Card title="Clinical Timeline">
          {timeline.loading ? (
            <Spinner label="Loading timeline…" />
          ) : timeline.error ? (
            <ErrorState error={timeline.error} onRetry={() => void timeline.refresh()} />
          ) : (timeline.data ?? []).length === 0 ? (
            <EmptyState title="No activity yet" body="Visits and events will appear here." />
          ) : (
            <ol className="timeline">
              {(timeline.data ?? []).map((t) => (
                <li key={t.id} className="timeline__item">
                  <span className="timeline__time num">{formatDateTime(t.occurredAt)}</span>
                  <span className="timeline__type mono">{t.eventType}</span>
                  <span className="timeline__summary">{timelineSummary(t.summary)}</span>
                </li>
              ))}
            </ol>
          )}
        </Card>
      )}

      {/* ── Back link ── */}
      <div style={{ marginTop: 'var(--space-4)' }}>
        <Link to="/clinical/patients" className="btn btn--secondary">
          ← Back to patients
        </Link>
      </div>
    </div>
  );
}
