import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthProvider';
import { TenantProvider, useTenant } from './context/TenantContext';
import { ToastProvider } from './context/ToastContext';
import { useI18n } from './i18n/I18nProvider';
import { AppShell } from './layout/AppShell';
import { ErrorBoundary } from './components/ErrorBoundary';
import { Button, Card, Spinner } from './components/ui';

// Core pages — eagerly loaded (high-traffic, small)
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { ForbiddenPage } from './pages/ForbiddenPage';

// Feature pages — lazily loaded (code-split per route)
const PatientsPage = lazy(() => import('./pages/PatientsPage').then(m => ({ default: m.PatientsPage })));
const PatientRegisterPage = lazy(() => import('./pages/PatientRegisterPage').then(m => ({ default: m.PatientRegisterPage })));
const PatientProfilePage = lazy(() => import('./pages/PatientProfilePage').then(m => ({ default: m.PatientProfilePage })));
const AppointmentsPage = lazy(() => import('./pages/AppointmentsPage').then(m => ({ default: m.AppointmentsPage })));
const AppointmentDetailPage = lazy(() => import('./pages/AppointmentDetailPage').then(m => ({ default: m.AppointmentDetailPage })));
const QueuePage = lazy(() => import('./pages/QueuePage').then(m => ({ default: m.QueuePage })));
const EncounterPage = lazy(() => import('./pages/EncounterPage').then(m => ({ default: m.EncounterPage })));
const BillingPage = lazy(() => import('./pages/BillingPage').then(m => ({ default: m.BillingPage })));
const PharmacyPage = lazy(() => import('./pages/PharmacyPage').then(m => ({ default: m.PharmacyPage })));
const InventoryPage = lazy(() => import('./pages/InventoryPage').then(m => ({ default: m.InventoryPage })));
const ProcurementPage = lazy(() => import('./pages/ProcurementPage').then(m => ({ default: m.ProcurementPage })));
const FinancePage = lazy(() => import('./pages/FinancePage').then(m => ({ default: m.FinancePage })));
const BudgetPage = lazy(() => import('./pages/BudgetPage').then(m => ({ default: m.BudgetPage })));
const ExpensePage = lazy(() => import('./pages/ExpensePage').then(m => ({ default: m.ExpensePage })));
const FinancialPeriodPage = lazy(() => import('./pages/FinancialPeriodPage').then(m => ({ default: m.FinancialPeriodPage })));
const AuditPage = lazy(() => import('./pages/AuditPage').then(m => ({ default: m.AuditPage })));
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage').then(m => ({ default: m.AnalyticsPage })));
const NotificationsPage = lazy(() => import('./pages/NotificationsPage').then(m => ({ default: m.NotificationsPage })));
const CommunicationsPage = lazy(() => import('./pages/CommunicationsPage').then(m => ({ default: m.CommunicationsPage })));
const RadiologyPage = lazy(() => import('./pages/RadiologyPage').then(m => ({ default: m.RadiologyPage })));
const OncologyPage = lazy(() => import('./pages/OncologyPage').then(m => ({ default: m.OncologyPage })));
const PatientPortalPage = lazy(() => import('./pages/PatientPortalPage').then(m => ({ default: m.PatientPortalPage })));

// Admin pages — lazily loaded as a group
const AdminLayout = lazy(() => import('./pages/admin/AdminLayout').then(m => ({ default: m.AdminLayout })));
const AdminUsersPage = lazy(() => import('./pages/admin/AdminUsersPage').then(m => ({ default: m.AdminUsersPage })));
const AdminRolesPage = lazy(() => import('./pages/admin/AdminRolesPage').then(m => ({ default: m.AdminRolesPage })));
const AdminStaffPage = lazy(() => import('./pages/admin/AdminStaffPage').then(m => ({ default: m.AdminStaffPage })));
const AdminDepartmentsPage = lazy(() => import('./pages/admin/AdminDepartmentsPage').then(m => ({ default: m.AdminDepartmentsPage })));
const AdminServicesPage = lazy(() => import('./pages/admin/AdminServicesPage').then(m => ({ default: m.AdminServicesPage })));
const AdminMedicationsPage = lazy(() => import('./pages/admin/AdminMedicationsPage').then(m => ({ default: m.AdminMedicationsPage })));
const AdminSettingsPage = lazy(() => import('./pages/admin/AdminSettingsPage').then(m => ({ default: m.AdminSettingsPage })));
const AdminBrandingPage = lazy(() => import('./pages/admin/AdminBrandingPage').then(m => ({ default: m.AdminBrandingPage })));
const PhysicianSchedulingPage = lazy(() => import('./pages/PhysicianSchedulingPage').then(m => ({ default: m.PhysicianSchedulingPage })));
const PatientImportPage = lazy(() => import('./pages/PatientImportPage').then(m => ({ default: m.PatientImportPage })));
const EmergencyPage = lazy(() => import('./pages/EmergencyPage').then(m => ({ default: m.EmergencyPage })));
const IcuPage = lazy(() => import('./pages/IcuPage').then(m => ({ default: m.IcuPage })));
const OperatingTheatrePage = lazy(() => import('./pages/OperatingTheatrePage').then(m => ({ default: m.OperatingTheatrePage })));
const BloodBankPage = lazy(() => import('./pages/BloodBankPage').then(m => ({ default: m.BloodBankPage })));
const NursingPage = lazy(() => import('./pages/NursingPage').then(m => ({ default: m.NursingPage })));
const FormsPage = lazy(() => import('./pages/FormsPage').then(m => ({ default: m.FormsPage })));
const BedOccupancyPage = lazy(() => import('./pages/BedOccupancyPage').then(m => ({ default: m.BedOccupancyPage })));

function FullScreenSpinner({ label }: { label: string }) {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <Spinner label={label} />
    </div>
  );
}

function LazySuspense({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<FullScreenSpinner label="Loading…" />}>{children}</Suspense>;
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
      <TenantGate />
    </TenantProvider>
  );
}

function TenantGate() {
  const { ready, facilities } = useTenant();
  const { t } = useI18n();
  if (!ready) {
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
    <ErrorBoundary>
      <AuthProvider>
        <ToastProvider>
          <Routes>
            <Route path="/login" element={<LoginRoute />} />
            <Route path="/forbidden" element={<ForbiddenPage />} />
            <Route element={<Gate />}>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/patients" element={<LazySuspense><PatientsPage /></LazySuspense>} />
              <Route path="/patients/new" element={<LazySuspense><PatientRegisterPage /></LazySuspense>} />               <Route path="/patients/:id" element={<LazySuspense><PatientProfilePage /></LazySuspense>} />
               <Route path="/patients/import" element={<LazySuspense><PatientImportPage /></LazySuspense>} />
              <Route path="/appointments" element={<LazySuspense><AppointmentsPage /></LazySuspense>} />
              <Route path="/appointments/:id" element={<LazySuspense><AppointmentDetailPage /></LazySuspense>} />
              <Route path="/queue" element={<LazySuspense><QueuePage /></LazySuspense>} />
              <Route path="/encounters/:id" element={<LazySuspense><EncounterPage /></LazySuspense>} />
              <Route path="/billing" element={<LazySuspense><BillingPage /></LazySuspense>} />
              <Route path="/billing/:invoiceId" element={<LazySuspense><BillingPage /></LazySuspense>} />
              <Route path="/pharmacy" element={<LazySuspense><PharmacyPage /></LazySuspense>} />
              <Route path="/inventory" element={<LazySuspense><InventoryPage /></LazySuspense>} />
              <Route path="/procurement" element={<LazySuspense><ProcurementPage /></LazySuspense>} />
              <Route path="/finance" element={<LazySuspense><FinancePage /></LazySuspense>} />
              <Route path="/budgets" element={<LazySuspense><BudgetPage /></LazySuspense>} />
              <Route path="/expenses" element={<LazySuspense><ExpensePage /></LazySuspense>} />
              <Route path="/financial-periods" element={<LazySuspense><FinancialPeriodPage /></LazySuspense>} />
              <Route path="/audit" element={<LazySuspense><AuditPage /></LazySuspense>} />               <Route path="/analytics" element={<LazySuspense><AnalyticsPage /></LazySuspense>} />
               <Route path="/notifications" element={<LazySuspense><NotificationsPage /></LazySuspense>} />
               <Route path="/communications" element={<LazySuspense><CommunicationsPage /></LazySuspense>} />
               <Route path="/communications" element={<LazySuspense><CommunicationsPage /></LazySuspense>} />
               <Route path="/forms" element={<LazySuspense><FormsPage /></LazySuspense>} />
               <Route path="/physician-scheduling" element={<LazySuspense><PhysicianSchedulingPage /></LazySuspense>} />
               <Route path="/beds" element={<LazySuspense><BedOccupancyPage /></LazySuspense>} />
               <Route path="/emergency" element={<LazySuspense><EmergencyPage /></LazySuspense>} />
               <Route path="/icu" element={<LazySuspense><IcuPage /></LazySuspense>} />
               <Route path="/ot" element={<LazySuspense><OperatingTheatrePage /></LazySuspense>} />
               <Route path="/blood-bank" element={<LazySuspense><BloodBankPage /></LazySuspense>} />
               <Route path="/nursing" element={<LazySuspense><NursingPage /></LazySuspense>} />
               <Route path="/radiology" element={<LazySuspense><RadiologyPage /></LazySuspense>} />
               <Route path="/oncology" element={<LazySuspense><OncologyPage /></LazySuspense>} />
               <Route path="/portal" element={<LazySuspense><PatientPortalPage /></LazySuspense>} />
              <Route path="/admin" element={<LazySuspense><AdminLayout /></LazySuspense>}>
                <Route index element={<Navigate to="/admin/users" replace />} />
                <Route path="users" element={<LazySuspense><AdminUsersPage /></LazySuspense>} />
                <Route path="roles" element={<LazySuspense><AdminRolesPage /></LazySuspense>} />
                <Route path="staff" element={<LazySuspense><AdminStaffPage /></LazySuspense>} />
                <Route path="departments" element={<LazySuspense><AdminDepartmentsPage /></LazySuspense>} />
                <Route path="services" element={<LazySuspense><AdminServicesPage /></LazySuspense>} />
                <Route path="medications" element={<LazySuspense><AdminMedicationsPage /></LazySuspense>} />
                <Route path="settings" element={<LazySuspense><AdminSettingsPage /></LazySuspense>} />
                <Route path="branding" element={<LazySuspense><AdminBrandingPage /></LazySuspense>} />
              </Route>
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </ToastProvider>
      </AuthProvider>
    </ErrorBoundary>
  );
}
