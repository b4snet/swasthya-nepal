import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { Alert, Button, Card, Input, Select, Textarea, formatDate } from '../components/ui';
import { ApiError } from '../api/client';

export function PatientRegisterPage() {
  const { organizationId, selectedFacilityId, facilities } = useTenant();
  const navigate = useNavigate();

  const [facilityId, setFacilityId] = useState(selectedFacilityId ?? facilities[0]?.id ?? '');
  const [fullName, setFullName] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const [sex, setSex] = useState('female');
  const [bloodGroup, setBloodGroup] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [address, setAddress] = useState('');
  const [ecName, setEcName] = useState('');
  const [ecRelation, setEcRelation] = useState('');
  const [ecPhone, setEcPhone] = useState('');
  const [idType, setIdType] = useState('national_id');
  const [idValue, setIdValue] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [duplicates, setDuplicates] = useState<Array<{ id: string; mrn: string; fullName: string; dateOfBirth: string; sex: string; matchReason: string }> | null>(null);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setServerError(null);
    setFieldErrors({});
    setDuplicates(null);
    try {
      if (!organizationId) throw new Error('Organization context is required');
      const patient = await patientsApi.create(organizationId, {
        fullName: fullName.trim(),
        dateOfBirth,
        sex,
        bloodGroup: bloodGroup || undefined,
        facilityId,
        phone: phone || undefined,
        email: email || undefined,
        address: address ? { address } : undefined,
        emergencyContact: ecName && ecRelation && ecPhone ? { name: ecName, relation: ecRelation, phone: ecPhone } : undefined,
        identifiers: idValue ? [{ type: idType, value: idValue.trim() }] : undefined,
      });
      navigate(`/clinical/patients/${patient.id}`);
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr.code === 'VALIDATION' && apiErr.details) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(apiErr.details)) {
          const arr = Array.isArray(v) ? v : [v];
          mapped[k] = arr.join(', ');
        }
        setFieldErrors(mapped);
        setServerError('Some fields need attention.');
      } else if (apiErr.code === 'CONFLICT' && apiErr.details?.candidates) {
        // Duplicate candidates returned by the backend.
        setDuplicates(apiErr.details.candidates as Array<{ id: string; mrn: string; fullName: string; dateOfBirth: string; sex: string; matchReason: string }>);
        setServerError('Possible duplicate patients found. Review the candidates below.');
      } else {
        setServerError(apiErr.message);
      }
    } finally {
      setSubmitting(false);
    }
  };

  const facilityChoices = facilities.length > 1;

  return (
    <div className="page page--narrow">
      <div className="page__head">
        <div className="page__title">
          <h1>Register patient</h1>
          <span className="page__sub">New patient registration is audited and tenant-scoped</span>
        </div>
      </div>

      {serverError && (
        <Alert tone={duplicates ? 'warning' : 'danger'}>{serverError}</Alert>
      )}

      {duplicates && duplicates.length > 0 && (
        <Card title="Possible duplicates" className="mb-4">
          <p className="muted small">These patients may match the one you are registering. Review before continuing.</p>
          <table className="data-table" aria-label="Duplicate candidates">
            <thead>
              <tr>
                <th>MRN</th>
                <th>Name</th>
                <th>Date of birth</th>
                <th>Sex</th>
                <th>Match reason</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {duplicates.map((d) => (
                <tr key={d.id}>
                  <td data-label="MRN" className="mono">{d.mrn}</td>
                  <td data-label="Name">{d.fullName}</td>
                  <td data-label="Date of birth">{formatDate(d.dateOfBirth)}</td>
                  <td data-label="Sex" className="capitalize">{d.sex}</td>
                  <td data-label="Match reason">{d.matchReason}</td>
                  <td>
                    <Link className="btn btn--ghost" to={`/patients/${d.id}`}>View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="row mt-4">
            <Button variant="ghost" onClick={() => setDuplicates(null)}>Register anyway</Button>
            <Button variant="ghost" onClick={() => navigate('/patients')}>Cancel</Button>
          </div>
        </Card>
      )}

      {!duplicates && (
        <form onSubmit={onSubmit} className="stack" noValidate>
          {facilityChoices && (
            <Select label="Facility" value={facilityId} onChange={(e) => setFacilityId(e.target.value)} required error={fieldErrors.facilityId}>
              {facilities.map((f) => (
                <option key={f.id} value={f.id}>{f.name}</option>
              ))}
            </Select>
          )}
          <Input label="Full name" value={fullName} onChange={(e) => setFullName(e.target.value)} required error={fieldErrors.fullName} />
          <div className="grid grid--2">
            <Input label="Date of birth" type="date" value={dateOfBirth} onChange={(e) => setDateOfBirth(e.target.value)} required error={fieldErrors.dateOfBirth} />
            <Select label="Sex" value={sex} onChange={(e) => setSex(e.target.value)} required error={fieldErrors.sex}>
              <option value="female">Female</option>
              <option value="male">Male</option>
              <option value="other">Other</option>
              <option value="unknown">Unknown</option>
            </Select>
          </div>
          <div className="grid grid--2">
            <Input label="Blood group" value={bloodGroup} onChange={(e) => setBloodGroup(e.target.value)} placeholder="e.g. O+" error={fieldErrors.bloodGroup} />
            <Input label="Phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={fieldErrors.phone} />
          </div>
          <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fieldErrors.email} />
          <Textarea label="Address" value={address} onChange={(e) => setAddress(e.target.value)} error={fieldErrors.address} />

          <h3 className="mt-4">Emergency contact</h3>
          <div className="grid grid--2">
            <Input label="Name" value={ecName} onChange={(e) => setEcName(e.target.value)} />
            <Input label="Relation" value={ecRelation} onChange={(e) => setEcRelation(e.target.value)} />
          </div>
          <Input label="Emergency phone" type="tel" value={ecPhone} onChange={(e) => setEcPhone(e.target.value)} />

          <h3 className="mt-4">Government identifier (optional)</h3>
          <div className="grid grid--2">
            <Select label="Type" value={idType} onChange={(e) => setIdType(e.target.value)}>
              <option value="national_id">National ID</option>
              <option value="passport">Passport</option>
              <option value="license">Driving license</option>
              <option value="other">Other</option>
            </Select>
            <Input label="Value" value={idValue} onChange={(e) => setIdValue(e.target.value)} />
          </div>

          <div className="row mt-4">
            <Button type="submit" loading={submitting}>
              Register patient
            </Button>
            <Button type="button" variant="ghost" onClick={() => navigate('/patients')}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
