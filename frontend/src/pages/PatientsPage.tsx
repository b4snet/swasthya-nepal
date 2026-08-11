import { useDeferredValue, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { patientsApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import { Button, Card, EmptyState, ErrorState, Spinner, formatDate } from '../components/ui';

export function PatientsPage() {
  const { selectedFacilityId, organizationId } = useTenant();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const deferred = useDeferredValue(search);

  const list = useFetch(() => patientsApi.list(organizationId ?? '', { search: deferred || undefined, facilityId: selectedFacilityId }), [organizationId, selectedFacilityId, deferred]);

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>Patients</h1>
          <span className="page__sub">Search the patient master in your facility</span>
        </div>
        <Button onClick={() => navigate('/patients/new')}>Register patient</Button>
      </div>

      <div className="searchbar">
        <label className="visually-hidden" htmlFor="patient-search">
          Search patients
        </label>
        <input
          id="patient-search"
          className="input"
          type="search"
          placeholder="Search by name or MRN"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {list.loading ? (
        <Spinner />
      ) : list.error ? (
        <ErrorState error={list.error} onRetry={() => void list.refresh()} />
      ) : (list.data ?? []).length === 0 ? (
        <EmptyState
          title="No patients found"
          body={deferred ? 'Nothing matches that search.' : 'Register the first patient to begin.'}
          action={<Button onClick={() => navigate('/patients/new')}>Register patient</Button>}
        />
      ) : (
        <Card>
          <table className="data-table">
            <thead>
              <tr>
                <th>MRN</th>
                <th>Name</th>
                <th>Date of birth</th>
                <th>Sex</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {(list.data ?? []).map((p) => (
                <tr key={p.id} onClick={() => navigate(`/patients/${p.id}`)} style={{ cursor: 'pointer' }}>
                  <td data-label="MRN" className="mono">{p.mrn}</td>
                  <td data-label="Name">{p.fullName}</td>
                  <td data-label="Date of birth">{formatDate(p.dateOfBirth)}</td>
                  <td data-label="Sex" className="capitalize">{p.sex}</td>
                  <td data-label="Status">{p.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
