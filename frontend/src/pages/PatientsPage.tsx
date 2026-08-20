import { useDeferredValue, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Button, Card, EmptyState, ErrorState, Spinner, StatusChip, formatDate } from '../components/ui';
import './patients.css';

export function PatientsPage() {
  const { selectedFacilityId, organizationId, hasRole } = useTenant();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const deferred = useDeferredValue(search);

  const canRegister = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse');
  const canView = hasRole('hospital_admin', 'receptionist', 'doctor', 'nurse', 'org_admin', 'pharmacist', 'lab_technician');

  const list = useFetch(
    () => patientsApi.list(organizationId ?? '', { search: deferred || undefined, facilityId: selectedFacilityId }),
    [organizationId, selectedFacilityId, deferred],
  );

  if (!canView) {
    return (
      <div className="page">
        <div className="state state--empty" style={{ minHeight: '60vh' }}>
          <h2>Access denied</h2>
          <p className="muted">You do not have permission to view patients.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Patients</h1>
          <span className="page__sub">Search the patient master in your facility</span>
        </div>
        {canRegister && (
          <Button onClick={() => navigate('/patients/new')}>Register patient</Button>
        )}
      </div>

      <div className="patients__search">
        <label className="visually-hidden" htmlFor="patient-search">
          Search patients
        </label>
        <input
          id="patient-search"
          className="input"
          type="search"
          placeholder="Search by name or MRN..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          aria-describedby="patient-search-hint"
        />
        <p className="field__hint" id="patient-search-hint">
          Results are scoped to your current facility context.
        </p>
      </div>

      {list.loading ? (
        <Spinner />
      ) : list.error ? (
        <ErrorState error={list.error} onRetry={() => void list.refresh()} />
      ) : (list.data ?? []).length === 0 ? (
        <EmptyState
          title="No patients found"
          body={deferred ? 'Nothing matches that search.' : 'Register the first patient to begin.'}
          action={canRegister ? <Button onClick={() => navigate('/patients/new')}>Register patient</Button> : undefined}
        />
      ) : (
        <Card>
          <table className="data-table patients__table" aria-label="Patient list">
            <thead>
              <tr>
                <th scope="col">MRN</th>
                <th scope="col">Name</th>
                <th scope="col">Date of birth</th>
                <th scope="col">Sex</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {(list.data ?? []).map((p) => (                  <tr
                  key={p.id}
                  onClick={() => navigate(`/patients/${p.id}`)}
                  tabIndex={0}
                  onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/patients/${p.id}`); }}
                  role="link"
                  aria-label={`View patient ${p.fullName}`}
                >
                  <td data-label="MRN" className="mono">{p.mrn}</td>
                  <td data-label="Name">{p.fullName}</td>
                  <td data-label="Date of birth">{formatDate(p.dateOfBirth)}</td>
                  <td data-label="Sex" className="capitalize">{p.sex}</td>
                  <td data-label="Status">
                    <StatusChip
                      tone={p.status === 'active' ? 'success' : p.status === 'deceased' ? 'danger' : 'neutral'}
                      label={p.status}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
