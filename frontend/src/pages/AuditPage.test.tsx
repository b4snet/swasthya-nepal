import { render, screen } from '@testing-library/react';
import { useEffect } from 'react';
import { describe, expect, it } from 'vitest';
import { AuthProvider, useAuth } from '../auth/AuthProvider';
import { TenantProvider } from '../context/TenantContext';
import { AuditPage } from './AuditPage';
import { jsonOk, stubFetch } from '../test/helpers';

// The real session flow: login() issues the POST /auth/login and the backend
// returns the assignments payload. No local-storage-derived authorization.
function Harness() {
  const { login } = useAuth();
  useEffect(() => {
    void login('a@b.test', 'secret');
  }, [login]);
  return <AuditPage />;
}

function renderAudit(roles: string[], ...extra: Response[]) {
  stubFetch(
    jsonOk({
      accessToken: 'at',
      refreshToken: 'rt',
      tokenType: 'Bearer',
      expiresIn: 3600,
      refreshExpiresIn: 604800,
      user: { id: 'u1', email: 'x@y.test', status: 'active' },
      assignments: [{ organizationId: 'org-1', organizationCode: 'A', facilityId: 'fac-1', facilityName: 'Smoke Central', roles }],
    }),
    ...extra,
  );
  return render(
    <AuthProvider>
      <TenantProvider>
        <Harness />
      </TenantProvider>
    </AuthProvider>,
  );
}

describe('AuditPage authorization', () => {
  it('denies the view to a doctor', async () => {
    renderAudit(['doctor']);
    expect(await screen.findByText(/not authorized/i)).toBeInTheDocument();
  });

  it('allows a hospital admin and fetches audit events', async () => {
    renderAudit(
      ['hospital_admin'],
      jsonOk([
        { id: 'e1', action: 'patient.created', entityType: 'patient', entityId: 'p1', actor: { id: 'u1', email: 'x@y.test' }, facilityId: 'fac-1', occurredAt: '2026-08-11T10:00:00Z', metadata: {} },
      ]),
    );
    expect(await screen.findByText('patient.created')).toBeInTheDocument();
  });
});
