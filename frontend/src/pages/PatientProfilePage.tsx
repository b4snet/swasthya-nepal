import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Alert, Button, Card, Dialog, EmptyState, ErrorState, Input, Select, Spinner, StatusChip, Tabs, formatDate, formatDateTime } from '../components/ui';
import { encountersApi } from '../api/endpoints';
import { ApiError } from '../api/client';
import type { TimelineEntry, PatientIdentifier, PatientContact } from '../api/types';
import './patient.css';

/**
 * The backend stores structured summary metadata per timeline event (e.g.
 * `{ mrn: 'MRN-…' }`, `{ changed: [...] }`). Render it as plain text — never
 * as a React child directly.
 */
export function timelineSummary(summary: TimelineEntry['summary']): string {
  if (typeof summary === 'string') return summary;
  if (Array.isArray(summary)) return summary.map(String).join(', ');
  if (summary && typeof summary === 'object') {
    return Object.values(summary)
      .flatMap((v) => (Array.isArray(v) ? v.map(String) : [String(v)]))
      .join(' · ');
  }
  return '';
}

export function PatientProfilePage() {
  const { id } = useParams<{ id: string }>();
  const { selectedFacilityId, hasRole } = useTenant();
  const fac = selectedFacilityId;

  const profile = useFetch(() => patientsApi.show(id!, fac), [id, fac]);
  const timeline = useFetch(() => patientsApi.timeline(id!, fac), [id, fac]);
  const identifiers = useFetch(() => patientsApi.identifiers(id!, fac), [id, fac]);
  const contacts = useFetch(() => patientsApi.contacts(id!, fac), [id, fac]);
  const encounters = useFetch(
    () => encountersApi.forPatient(id!, fac),
    [id, fac],
  );

  const [tab, setTab] = useState<'demographics' | 'encounters' | 'lab' | 'identifiers' | 'contacts' | 'timeline'>('demographics');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const canBook = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');
  const canUpdate = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');

  if (profile.loading) return <Spinner />;
  if (profile.error) return <ErrorState error={profile.error} onRetry={() => void profile.refresh()} />;
  if (!profile.data) return <EmptyState title="Patient not found" body="This patient may have been removed or is outside your facility scope." />;
  const patient = profile.data;

  const tabs = [
    { id: 'demographics', label: 'Demographics' },
    { id: 'encounters', label: `Encounters${encounters.data ? ` (${(encounters.data as any[]).length})` : ''}` },
    { id: 'lab', label: 'Lab Orders' },
    { id: 'identifiers', label: `Identifiers${identifiers.data ? ` (${identifiers.data.length})` : ''}` },
    { id: 'contacts', label: `Contacts${contacts.data ? ` (${contacts.data.length})` : ''}` },
    { id: 'timeline', label: 'Timeline' },
  ];

  return (
    <div className="page">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="patient-hero">
        <div className="patient-hero__avatar">
          {patient.fullName?.charAt(0) ?? '?'}
        </div>
        <div className="patient-hero__info">
          <h1 className="patient-hero__name">{patient.fullName}</h1>
          <div className="patient-hero__meta">
            <span className="mono">{patient.mrn}</span>
            <span style={{ color: 'var(--line)' }}>·</span>
            <span>{formatDate(patient.dateOfBirth)}</span>
            <span style={{ color: 'var(--line)' }}>·</span>
            <span className="capitalize">{patient.sex}</span>
            {patient.status !== 'active' && (
              <StatusChip
                tone={patient.status === 'deceased' ? 'danger' : 'neutral'}
                label={patient.status}
                struck={patient.status === 'inactive'}
              />
            )}
          </div>
        </div>
        <div className="patient-hero__actions">
          {canUpdate && (
            <EditPatientDialog patient={patient} facilityId={fac} onSaved={() => { void profile.refresh(); setNotice({ tone: 'success', text: 'Patient updated.' }); }} />
          )}
          {canBook && (
            <Link className="btn btn--primary" to={`/appointments/new?patientId=${patient.id}`}>
              Book appointment
            </Link>
          )}
        </div>
      </div>

      <Tabs tabs={tabs} active={tab} onChange={(t) => setTab(t as typeof tab)} />

      {tab === 'demographics' && (
        <Card>
          <dl className="kv">
            <div><dt>MRN</dt><dd className="mono">{patient.mrn}</dd></div>
            <div><dt>Blood group</dt><dd>{patient.bloodGroup ?? '—'}</dd></div>
            <div><dt>Status</dt><dd>{patient.status}</dd></div>
            <div><dt>Registered</dt><dd>{formatDateTime(patient.createdAt)}</dd></div>
          </dl>
        </Card>
      )}

      {tab === 'encounters' && (
        <Card title="Encounters">
          {encounters.loading ? (
            <Spinner label="Loading encounters…" />
          ) : encounters.error ? (
            <ErrorState error={encounters.error} onRetry={() => void encounters.refresh()} />
          ) : (encounters.data ?? []).length === 0 ? (
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

      {tab === 'lab' && (
        <Card title="Laboratory orders">
          <EmptyState title="Lab orders" body="Laboratory orders for this patient will appear here." />
        </Card>
      )}

      {tab === 'identifiers' && (
        <IdentifiersTab patientId={id!} facilityId={fac} data={identifiers} onRefresh={() => void identifiers.refresh()} />
      )}

      {tab === 'contacts' && (
        <ContactsTab patientId={id!} facilityId={fac} data={contacts} onRefresh={() => void contacts.refresh()} />
      )}

      {tab === 'timeline' && (
        <Card title="Timeline">
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

      <Card>
        <Link to="/patients">← Back to patients</Link>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------------ Edit Patient Dialog */

function EditPatientDialog({ patient, facilityId, onSaved }: {
  patient: { id: string; fullName: string; dateOfBirth: string; sex: string; bloodGroup: string | null };
  facilityId: string | null;
  onSaved: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [fullName, setFullName] = useState(patient.fullName);
  const [dateOfBirth, setDateOfBirth] = useState(patient.dateOfBirth);
  const [sex, setSex] = useState(patient.sex);
  const [bloodGroup, setBloodGroup] = useState(patient.bloodGroup ?? '');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await patientsApi.update(patient.id, {
        fullName: fullName.trim(),
        dateOfBirth,
        sex,
        bloodGroup: bloodGroup || undefined,
      }, facilityId);
      onSaved();
      setOpen(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Update failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <Button variant="secondary" onClick={() => setOpen(true)}>Edit patient</Button>
      <Dialog open={open} onClose={() => setOpen(false)} title="Edit patient" footer={
        <>
          <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
          <Button onClick={() => void submit()} loading={submitting}>Save changes</Button>
        </>
      }>
        <div className="stack">
          {error && <Alert tone="danger">{error}</Alert>}
          <Input label="Full name" value={fullName} onChange={(e) => setFullName(e.target.value)} required />
          <Input label="Date of birth" type="date" value={dateOfBirth} onChange={(e) => setDateOfBirth(e.target.value)} required />
          <Select label="Sex" value={sex} onChange={(e) => setSex(e.target.value)} required>
            <option value="female">Female</option>
            <option value="male">Male</option>
            <option value="other">Other</option>
            <option value="unknown">Unknown</option>
          </Select>
          <Input label="Blood group" value={bloodGroup} onChange={(e) => setBloodGroup(e.target.value)} placeholder="e.g. O+" />
        </div>
      </Dialog>
    </>
  );
}

/* ------------------------------------------------------------------ Identifiers Tab */

function IdentifiersTab({ patientId, facilityId, data, onRefresh }: {
  patientId: string; facilityId: string | null;
  data: ReturnType<typeof useFetch<PatientIdentifier[]>>;
  onRefresh: () => void;
}) {
  const [addOpen, setAddOpen] = useState(false);

  if (data.loading) return <Spinner />;
  if (data.error) return <ErrorState error={data.error} onRetry={onRefresh} />;

  const items = Array.isArray(data.data) ? data.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>Identifiers</h2>
        <Button onClick={() => setAddOpen(true)}>Add identifier</Button>
      </div>

      {items.length === 0 ? (
        <EmptyState title="No identifiers" body="Add government IDs, passports, or other identifiers for this patient." />
      ) : (
        <Card>
          <table className="data-table" aria-label="Patient identifiers">
            <thead>
              <tr>
                <th>Type</th>
                <th>Value</th>
                <th>Country</th>
                <th>Verified</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((ident) => (
                <tr key={ident.id}>
                  <td data-label="Type" className="capitalize">{ident.type.replace(/_/g, ' ')}</td>
                  <td data-label="Value" className="mono">{ident.value}</td>
                  <td data-label="Country">{ident.issuingCountry ?? '—'}</td>
                  <td data-label="Verified">
                    <StatusChip tone={ident.isVerified ? 'success' : 'neutral'} label={ident.isVerified ? 'Yes' : 'No'} />
                  </td>
                  <td data-label="Status">
                    <StatusChip tone={ident.status === 'active' ? 'success' : 'neutral'} label={ident.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <AddIdentifierDialog open={addOpen} onClose={() => setAddOpen(false)} patientId={patientId} facilityId={facilityId} onCreated={() => { setAddOpen(false); onRefresh(); }} />
    </div>
  );
}

function AddIdentifierDialog({ open, onClose, patientId, facilityId, onCreated }: {
  open: boolean; onClose: () => void; patientId: string; facilityId: string | null; onCreated: () => void;
}) {
  const [type, setType] = useState('national_id');
  const [value, setValue] = useState('');
  const [country, setCountry] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await patientsApi.addIdentifier(patientId, {
        type,
        value: value.trim(),
        issuingCountry: country || undefined,
      }, facilityId);
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to add identifier.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Add identifier" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!value.trim()}>Add</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="national_id">National ID</option>
          <option value="passport">Passport</option>
          <option value="license">Driving license</option>
          <option value="other">Other</option>
        </Select>
        <Input label="Value" value={value} onChange={(e) => setValue(e.target.value)} required />
        <Input label="Issuing country" value={country} onChange={(e) => setCountry(e.target.value)} placeholder="e.g. NP" />
      </div>
    </Dialog>
  );
}

/* ------------------------------------------------------------------ Contacts Tab */

function ContactsTab({ patientId, facilityId, data, onRefresh }: {
  patientId: string; facilityId: string | null;
  data: ReturnType<typeof useFetch<PatientContact[]>>;
  onRefresh: () => void;
}) {
  const [addOpen, setAddOpen] = useState(false);

  if (data.loading) return <Spinner />;
  if (data.error) return <ErrorState error={data.error} onRetry={onRefresh} />;

  const items = Array.isArray(data.data) ? data.data : [];

  return (
    <div className="stack">
      <div className="page__head">
        <h2>Contacts</h2>
        <Button onClick={() => setAddOpen(true)}>Add contact</Button>
      </div>

      {items.length === 0 ? (
        <EmptyState title="No contacts" body="Add phone, email, or address contacts for this patient." />
      ) : (
        <Card>
          <table className="data-table" aria-label="Patient contacts">
            <thead>
              <tr>
                <th>Type</th>
                <th>Value</th>
                <th>Primary</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id}>
                  <td data-label="Type" className="capitalize">{c.type}</td>
                  <td data-label="Value">{c.value}</td>
                  <td data-label="Primary">
                    <StatusChip tone={c.isPrimary ? 'success' : 'neutral'} label={c.isPrimary ? 'Yes' : 'No'} />
                  </td>
                  <td data-label="Status">
                    <StatusChip tone={c.status === 'active' ? 'success' : 'neutral'} label={c.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <AddContactDialog open={addOpen} onClose={() => setAddOpen(false)} patientId={patientId} facilityId={facilityId} onCreated={() => { setAddOpen(false); onRefresh(); }} />
    </div>
  );
}

function AddContactDialog({ open, onClose, patientId, facilityId, onCreated }: {
  open: boolean; onClose: () => void; patientId: string; facilityId: string | null; onCreated: () => void;
}) {
  const [type, setType] = useState('phone');
  const [value, setValue] = useState('');
  const [isPrimary, setIsPrimary] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await patientsApi.addContact(patientId, {
        type,
        value: value.trim(),
        isPrimary,
      }, facilityId);
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to add contact.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} title="Add contact" footer={
      <>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={() => void submit()} loading={submitting} disabled={!value.trim()}>Add</Button>
      </>
    }>
      <div className="stack">
        {error && <Alert tone="danger">{error}</Alert>}
        <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="phone">Phone</option>
          <option value="email">Email</option>
          <option value="address">Address</option>
          <option value="emergency">Emergency</option>
        </Select>
        <Input label="Value" value={value} onChange={(e) => setValue(e.target.value)} required placeholder={type === 'email' ? 'email@example.com' : type === 'phone' ? '+977-…' : ''} />
        <label className="check">
          <input type="checkbox" checked={isPrimary} onChange={(e) => setIsPrimary(e.target.checked)} />
          Primary contact
        </label>
      </div>
    </Dialog>
  );
}
