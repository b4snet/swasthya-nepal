import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthProvider';
import { TenantProvider, useTenant } from './context/TenantContext';
import { ToastProvider } from './context/ToastContext';
import { useI18n } from './i18n/I18nProvider';
import { AppShell } from './layout/AppShell';
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { PatientsPage } from './pages/PatientsPage';
import { PatientRegisterPage } from './pages/PatientRegisterPage';
import { PatientProfilePage } from './pages/PatientProfilePage';
import { AppointmentsPage } from './pages/AppointmentsPage';
import { QueuePage } from './pages/QueuePage';
import { EncounterPage } from './pages/EncounterPage';
import { BillingPage } from './pages/BillingPage';
import { AuditPage } from './pages/AuditPage';
import { ForbiddenPage } from './pages/ForbiddenPage';
import { Button, Card, Spinner } from './components/ui';

function FullScreenSpinner({ label }: { label: string }) {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <Spinner label={label} />
    </div>
  );
}

function FacilityChooser() {
  const { facilities, selectFacility } = useTenant();
  const { t } = useI18n();
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem' }}>
      <Card className="facility-chooser" style={{ maxWidth: '26rem', width: '100%' }}>
        <h2>{t('facilityChooser.title')}</h2>
        <p className="muted">{t('facilityChooser.hint')}</p>
        <div className="stack">
          {facilities.map((f) => (
            <Button key={f.id} variant="secondary" full onClick={() => selectFacility(f.id)}>
              {f.name}
            </Button>
          ))}
        </div>
      </Card>
    </div>
  );
}

function Gate() {
  const { status } = useAuth();
  const { t } = useI18n();
  if (status === 'loading') {
    return <FullScreenSpinner label={t('shell.restoringSession')} />;
  }
  if (status === 'unauthenticated') return <Navigate to="/login" replace />;
  return (
    <TenantProvider>
      {/* Tenant context must be resolved before any tenant-scoped page mounts:
          the facility auto-selection happens after the first render, so a raw
          AppShell would fire requests with an empty organization/facility
          context (a malformed-URL bug the E2E caught). */}
      <TenantGate />
    </TenantProvider>
  );
}

function TenantGate() {
  const { ready, facilities } = useTenant();
  const { t } = useI18n();
  if (!ready) {
    // A principal with several facilities must choose before any fetch.
    if (facilities.length > 1) return <FacilityChooser />;
    return <FullScreenSpinner label={t('shell.resolvingFacility')} />;
  }
  return <AppShell />;
}

function LoginRoute() {
  const { status } = useAuth();
  if (status === 'authenticated') return <Navigate to="/" replace />;
  if (status === 'loading') return null;
  return <LoginPage />;
}

export function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <Routes>
          <Route path="/login" element={<LoginRoute />} />
          <Route path="/forbidden" element={<ForbiddenPage />} />
          <Route element={<Gate />}>
            <Route path="/" element={<DashboardPage />} />
            <Route path="/patients" element={<PatientsPage />} />
            <Route path="/patients/new" element={<PatientRegisterPage />} />
            <Route path="/patients/:id" element={<PatientProfilePage />} />
            <Route path="/appointments" element={<AppointmentsPage />} />
            <Route path="/queue" element={<QueuePage />} />
            <Route path="/encounters/:id" element={<EncounterPage />} />
            <Route path="/billing" element={<BillingPage />} />
            <Route path="/billing/:invoiceId" element={<BillingPage />} />
            <Route path="/audit" element={<AuditPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </ToastProvider>
    </AuthProvider>
  );
}
