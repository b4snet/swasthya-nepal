import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { Alert, Button, Input, Select, Textarea } from '../components/ui';
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

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setServerError(null);
    setFieldErrors({});
    try {
      const patient = await patientsApi.create(organizationId ?? '', {
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
      // Duplicate candidates ride in meta.duplicates; the response's data is
      // the created patient (server-side duplicate detection, never auto-merge).
      navigate(`/patients/${patient.id}`);
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
        <Alert tone="danger">{serverError}</Alert>
      )}

      <form onSubmit={onSubmit} className="stack" noValidate>
        {facilityChoices && (
          <Select label="Facility" value={facilityId} onChange={(e) => setFacilityId(e.target.value)} required>
            {facilities.map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
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
        <Textarea label="Address" value={address} onChange={(e) => setAddress(e.target.value)} />

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
    </div>
  );
}
