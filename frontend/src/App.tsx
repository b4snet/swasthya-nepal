import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthProvider';
import { TenantProvider, useTenant } from './context/TenantContext';
import { ToastProvider } from './context/ToastContext';
import { useI18n } from './i18n/I18nProvider';
import { AppShell } from './layout/AppShell';
import { ErrorBoundary } from './components/ErrorBoundary';
import { Button, Card, Spinner } from './components/ui';

// Core pages — eagerly loaded
import { LoginPage } from './pages/LoginPage';
import { ForbiddenPage } from './pages/ForbiddenPage';
import { DashboardPage } from './pages/DashboardPage';
import { RoleDashboardRouter } from './auth/RoleDashboardRouter';

// Module dashboards
const HospitalDashboard = lazy(() => import('./pages/modules/HospitalDashboard').then(m => ({ default: m.HospitalDashboard })));
const ClinicalDashboard = lazy(() => import('./pages/modules/ClinicalDashboard').then(m => ({ default: m.ClinicalDashboard })));
const PharmacyDashboard = lazy(() => import('./pages/modules/PharmacyDashboard').then(m => ({ default: m.PharmacyDashboard })));
const LaboratoryDashboard = lazy(() => import('./pages/modules/LaboratoryDashboard').then(m => ({ default: m.LaboratoryDashboard })));
const FinanceDashboard = lazy(() => import('./pages/modules/FinanceDashboard').then(m => ({ default: m.FinanceDashboard })));
const ProcurementDashboard = lazy(() => import('./pages/modules/ProcurementDashboard').then(m => ({ default: m.ProcurementDashboard })));
const ReportsDashboard = lazy(() => import('./pages/modules/ReportsDashboard').then(m => ({ default: m.ReportsDashboard })));
const CommunicationsDashboard = lazy(() => import('./pages/modules/CommunicationsDashboard').then(m => ({ default: m.CommunicationsDashboard })));

// Feature pages — lazily loaded
const PatientsPage = lazy(() => import('./pages/PatientsPage').then(m => ({ default: m.PatientsPage })));
const PatientRegisterPage = lazy(() => import('./pages/PatientRegisterPage').then(m => ({ default: m.PatientRegisterPage })));
const PatientProfilePage = lazy(() => import('./pages/PatientProfilePage').then(m => ({ default: m.PatientProfilePage })));
const PatientWorkspace = lazy(() => import('./pages/PatientWorkspace').then(m => ({ default: m.PatientWorkspace })));
const AppointmentsPage = lazy(() => import('./pages/AppointmentsPage').then(m => ({ default: m.AppointmentsPage })));
const AppointmentDetailPage = lazy(() => import('./pages/AppointmentDetailPage').then(m => ({ default: m.AppointmentDetailPage })));
const QueuePage = lazy(() => import('./pages/QueuePage').then(m => ({ default: m.QueuePage })));
const EncounterPage = lazy(() => import('./pages/EncounterPage').then(m => ({ default: m.EncounterPage })));
const EncounterWorkspace = lazy(() => import('./pages/EncounterWorkspace').then(m => ({ default: m.EncounterWorkspace })));
const PatientFlowOrchestrator = lazy(() => import('./pages/PatientFlowOrchestrator').then(m => ({ default: m.PatientFlowOrchestrator })));
const BillingPage = lazy(() => import('./pages/BillingPage').then(m => ({ default: m.BillingPage })));
const PharmacyPage = lazy(() => import('./pages/PharmacyPage').then(m => ({ default: m.PharmacyPage })));
const InventoryPage = lazy(() => import('./pages/InventoryPage').then(m => ({ default: m.InventoryPage })));
const ProcurementPage = lazy(() => import('./pages/ProcurementPage').then(m => ({ default: m.ProcurementPage })));
const BudgetPage = lazy(() => import('./pages/BudgetPage').then(m => ({ default: m.BudgetPage })));
const ExpensePage = lazy(() => import('./pages/ExpensePage').then(m => ({ default: m.ExpensePage })));
const FinancialPeriodPage = lazy(() => import('./pages/FinancialPeriodPage').then(m => ({ default: m.FinancialPeriodPage })));
const NepalFinanceAdminPage = lazy(() => import('./pages/NepalFinanceAdminPage').then(m => ({ default: m.NepalFinanceAdminPage })));
const AuditPage = lazy(() => import('./pages/AuditPage').then(m => ({ default: m.AuditPage })));
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage').then(m => ({ default: m.AnalyticsPage })));
const NotificationsPage = lazy(() => import('./pages/NotificationsPage').then(m => ({ default: m.NotificationsPage })));
const CommunicationsPage = lazy(() => import('./pages/CommunicationsPage').then(m => ({ default: m.CommunicationsPage })));
const RadiologyPage = lazy(() => import('./pages/RadiologyPage').then(m => ({ default: m.RadiologyPage })));
const PacsViewer = lazy(() => import('./pages/PacsViewer').then(m => ({ default: m.PacsViewer })));

const OncologyPage = lazy(() => import('./pages/OncologyPage').then(m => ({ default: m.OncologyPage })));
const ReferralsPage = lazy(() => import('./pages/ReferralsPage').then(m => ({ default: m.ReferralsPage })));
const PatientPortalPage = lazy(() => import('./pages/PatientPortalPage').then(m => ({ default: m.PatientPortalPage })));
const PortalActivationPage = lazy(() => import('./pages/PortalActivationPage').then(m => ({ default: m.PortalActivationPage })));
const TelehealthPage = lazy(() => import('./pages/TelehealthPage').then(m => ({ default: m.TelehealthPage })));
const EmergencyPage = lazy(() => import('./pages/EmergencyPage').then(m => ({ default: m.EmergencyPage })));
const IcuPage = lazy(() => import('./pages/IcuPage').then(m => ({ default: m.IcuPage })));
const OperatingTheatrePage = lazy(() => import('./pages/OperatingTheatrePage').then(m => ({ default: m.OperatingTheatrePage })));
const BloodBankPage = lazy(() => import('./pages/BloodBankPage').then(m => ({ default: m.BloodBankPage })));
const NursingPage = lazy(() => import('./pages/NursingPage').then(m => ({ default: m.NursingPage })));
const FormsPage = lazy(() => import('./pages/FormsPage').then(m => ({ default: m.FormsPage })));
const BedOccupancyPage = lazy(() => import('./pages/BedOccupancyPage').then(m => ({ default: m.BedOccupancyPage })));
const IpdDashboard = lazy(() => import('./pages/IpdDashboard').then(m => ({ default: m.IpdDashboard })));
const DocumentCenterPage = lazy(() => import('./pages/DocumentCenterPage').then(m => ({ default: m.DocumentCenterPage })));
const RevenueCyclePage = lazy(() => import('./pages/RevenueCyclePage').then(m => ({ default: m.RevenueCyclePage })));
const OperationsCenterPage = lazy(() => import('./pages/OperationsCenterPage').then(m => ({ default: m.OperationsCenterPage })));
const PatientImportPage = lazy(() => import('./pages/PatientImportPage').then(m => ({ default: m.PatientImportPage })));
const PhysicianSchedulingPage = lazy(() => import('./pages/PhysicianSchedulingPage').then(m => ({ default: m.PhysicianSchedulingPage })));
const ClinicalWorkspace = lazy(() => import('./pages/ClinicalWorkspace').then(m => ({ default: m.ClinicalWorkspace })));
const HospitalOnboarding = lazy(() => import('./pages/HospitalOnboarding').then(m => ({ default: m.HospitalOnboarding })));
const LabOrdersPage = lazy(() => import('./pages/LabOrdersPage').then(m => ({ default: m.LabOrdersPage })));
const QualityPage = lazy(() => import('./pages/QualityPage').then(m => ({ default: m.QualityPage })));
const OrchestrationPage = lazy(() => import('./pages/OrchestrationPage').then(m => ({ default: m.OrchestrationPage })));
const HrPage = lazy(() => import('./pages/HrPage').then(m => ({ default: m.HrPage })));
const StaffWorkspace = lazy(() => import('./pages/StaffWorkspace').then(m => ({ default: m.StaffWorkspace })));
const AssetPage = lazy(() => import('./pages/AssetPage').then(m => ({ default: m.AssetPage })));
const ResearchPage = lazy(() => import('./pages/ResearchPage').then(m => ({ default: m.ResearchPage })));
const InteropPage = lazy(() => import('./pages/InteropPage').then(m => ({ default: m.InteropPage })));
const AccountingPage = lazy(() => import('./pages/AccountingPage').then(m => ({ default: m.AccountingPage })));
const AiAssistPage = lazy(() => import('./pages/AiAssistPage').then(m => ({ default: m.AiAssistPage })));
const NotificationCenterPage = lazy(() => import('./pages/NotificationCenterPage').then(m => ({ default: m.NotificationCenterPage })));

// Admin pages
const AdminLayout = lazy(() => import('./pages/admin/AdminLayout').then(m => ({ default: m.AdminLayout })));
const AdminUsersPage = lazy(() => import('./pages/admin/AdminUsersPage').then(m => ({ default: m.AdminUsersPage })));
const AdminRolesPage = lazy(() => import('./pages/admin/AdminRolesPage').then(m => ({ default: m.AdminRolesPage })));
const AdminStaffPage = lazy(() => import('./pages/admin/AdminStaffPage').then(m => ({ default: m.AdminStaffPage })));
const AdminDepartmentsPage = lazy(() => import('./pages/admin/AdminDepartmentsPage').then(m => ({ default: m.AdminDepartmentsPage })));
const AdminServicesPage = lazy(() => import('./pages/admin/AdminServicesPage').then(m => ({ default: m.AdminServicesPage })));
const AdminMedicationsPage = lazy(() => import('./pages/admin/AdminMedicationsPage').then(m => ({ default: m.AdminMedicationsPage })));
const AdminSettingsPage = lazy(() => import('./pages/admin/AdminSettingsPage').then(m => ({ default: m.AdminSettingsPage })));
const AdminBrandingPage = lazy(() => import('./pages/admin/AdminBrandingPage').then(m => ({ default: m.AdminBrandingPage })));
const AdminConsolePage = lazy(() => import('./pages/admin/AdminConsolePage').then(m => ({ default: m.AdminConsolePage })));

const CriticalValuesPage = lazy(() => import('./pages/CriticalValuesPage').then(m => ({ default: m.CriticalValuesPage })));
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

// RoleDashboardRouter replaces the old RoleRedirect — see auth/RoleDashboardRouter.tsx

export function App() {
  return (
    <ErrorBoundary>
      <AuthProvider>
        <ToastProvider>
          <Routes>
            <Route path="/login" element={<LoginRoute />} />
            <Route path="/forbidden" element={<ForbiddenPage />} />
            <Route path="/portal/activate/:token" element={<LazySuspense><PortalActivationPage /></LazySuspense>} />
            <Route path="/portal" element={<LazySuspense><PatientPortalPage /></LazySuspense>} />
            <Route element={<Gate />}>
              {/* ── Root: role-based redirect ── */}
              <Route path="/" element={<RoleDashboardRouter />} />
              <Route path="/dashboard" element={<LazySuspense><DashboardPage /></LazySuspense>} />
              <Route path="/onboarding" element={<LazySuspense><HospitalOnboarding /></LazySuspense>} />

              {/* ═══ HOSPITAL MODULE ═══ */}
              <Route path="/hospital" element={<LazySuspense><HospitalDashboard /></LazySuspense>} />
              <Route path="/hospital/opd" element={<LazySuspense><QueuePage /></LazySuspense>} />
              <Route path="/hospital/ipd" element={<LazySuspense><IpdDashboard /></LazySuspense>} />
              <Route path="/ipd" element={<LazySuspense><IpdDashboard /></LazySuspense>} />
              <Route path="/emergency" element={<LazySuspense><EmergencyPage /></LazySuspense>} />
              <Route path="/icu" element={<LazySuspense><IcuPage /></LazySuspense>} />
              <Route path="/ot" element={<LazySuspense><OperatingTheatrePage /></LazySuspense>} />
              <Route path="/beds" element={<LazySuspense><BedOccupancyPage /></LazySuspense>} />
              <Route path="/nursing" element={<LazySuspense><NursingPage /></LazySuspense>} />

              {/* ═══ CLINICAL MODULE ═══ */}
              <Route path="/clinical" element={<LazySuspense><ClinicalDashboard /></LazySuspense>} />
              <Route path="/clinical/workspace" element={<LazySuspense><ClinicalWorkspace /></LazySuspense>} />
              <Route path="/clinical/patients" element={<LazySuspense><PatientsPage /></LazySuspense>} />
              <Route path="/clinical/patients/new" element={<LazySuspense><PatientRegisterPage /></LazySuspense>} />
              <Route path="/clinical/patients/:id" element={<LazySuspense><PatientWorkspace /></LazySuspense>} />
              <Route path="/clinical/patients/:id/profile" element={<LazySuspense><PatientProfilePage /></LazySuspense>} />
              <Route path="/clinical/patients/import" element={<LazySuspense><PatientImportPage /></LazySuspense>} />
              <Route path="/clinical/appointments" element={<LazySuspense><AppointmentsPage /></LazySuspense>} />
              <Route path="/clinical/appointments/:id" element={<LazySuspense><AppointmentDetailPage /></LazySuspense>} />
              <Route path="/clinical/flow" element={<LazySuspense><PatientFlowOrchestrator /></LazySuspense>} />
              <Route path="/clinical/queue" element={<LazySuspense><QueuePage /></LazySuspense>} />
              <Route path="/clinical/encounters" element={<LazySuspense><EncounterPage /></LazySuspense>} />
              <Route path="/clinical/encounters/:encounterId" element={<LazySuspense><EncounterWorkspace /></LazySuspense>} />
              <Route path="/clinical/encounters/:encounterId/edit" element={<LazySuspense><EncounterPage /></LazySuspense>} />
              <Route path="/clinical/forms" element={<LazySuspense><FormsPage /></LazySuspense>} />
              <Route path="/clinical/referrals" element={<LazySuspense><ReferralsPage /></LazySuspense>} />
              <Route path="/clinical/scheduling" element={<LazySuspense><PhysicianSchedulingPage /></LazySuspense>} />
              <Route path="/clinical/telehealth" element={<LazySuspense><TelehealthPage /></LazySuspense>} />
              <Route path="/clinical/oncology" element={<LazySuspense><OncologyPage /></LazySuspense>} />

              {/* ═══ PHARMACY MODULE ═══ */}
              <Route path="/pharmacy" element={<LazySuspense><PharmacyDashboard /></LazySuspense>} />
              <Route path="/pharmacy/prescriptions" element={<LazySuspense><PharmacyPage /></LazySuspense>} />
              <Route path="/pharmacy/dispensing" element={<LazySuspense><PharmacyPage /></LazySuspense>} />
              <Route path="/pharmacy/inventory" element={<LazySuspense><InventoryPage /></LazySuspense>} />

              {/* ═══ LABORATORY MODULE ═══ */}
              <Route path="/laboratory" element={<LazySuspense><LaboratoryDashboard /></LazySuspense>} />
              <Route path="/laboratory/orders" element={<LazySuspense><LabOrdersPage /></LazySuspense>} />
              <Route path="/laboratory/reports" element={<LazySuspense><LabOrdersPage /></LazySuspense>} />
              <Route path="/laboratory/critical-values" element={<LazySuspense><CriticalValuesPage /></LazySuspense>} />

              {/* ═══ RADIOLOGY MODULE ═══ */}
              <Route path="/radiology" element={<LazySuspense><RadiologyPage /></LazySuspense>} />
              <Route path="/radiology/imaging/:studyId" element={<LazySuspense><PacsViewer /></LazySuspense>} />

              {/* ═══ BLOOD BANK ═══ */}
              <Route path="/blood-bank" element={<LazySuspense><BloodBankPage /></LazySuspense>} />

              {/* ═══ FINANCE MODULE ═══ */}
              <Route path="/finance" element={<LazySuspense><FinanceDashboard /></LazySuspense>} />
              <Route path="/finance/billing" element={<LazySuspense><BillingPage /></LazySuspense>} />
              <Route path="/finance/billing/:invoiceId" element={<LazySuspense><BillingPage /></LazySuspense>} />
              <Route path="/finance/revenue" element={<LazySuspense><RevenueCyclePage /></LazySuspense>} />
              <Route path="/finance/budgets" element={<LazySuspense><BudgetPage /></LazySuspense>} />
              <Route path="/finance/expenses" element={<LazySuspense><ExpensePage /></LazySuspense>} />
              <Route path="/finance/accounting" element={<LazySuspense><AccountingPage /></LazySuspense>} />
              <Route path="/finance/periods" element={<LazySuspense><FinancialPeriodPage /></LazySuspense>} />
              <Route path="/finance/nepal-admin" element={<LazySuspense><NepalFinanceAdminPage /></LazySuspense>} />

              {/* ═══ PROCUREMENT MODULE ═══ */}
              <Route path="/procurement" element={<LazySuspense><ProcurementDashboard /></LazySuspense>} />
              <Route path="/procurement/inventory" element={<LazySuspense><InventoryPage /></LazySuspense>} />
              <Route path="/procurement/orders" element={<LazySuspense><ProcurementPage /></LazySuspense>} />

              {/* ═══ REPORTS MODULE ═══ */}
              <Route path="/reports" element={<LazySuspense><ReportsDashboard /></LazySuspense>} />
              <Route path="/reports/analytics" element={<LazySuspense><AnalyticsPage /></LazySuspense>} />
              <Route path="/reports/operations" element={<LazySuspense><OperationsCenterPage /></LazySuspense>} />
              {/* ═══ QUALITY & SAFETY ═══ */}
              <Route path="/quality" element={<LazySuspense><QualityPage /></LazySuspense>} />
              <Route path="/orchestration" element={<LazySuspense><OrchestrationPage /></LazySuspense>} />
              <Route path="/quality/audit" element={<LazySuspense><AuditPage /></LazySuspense>} />
              {/* ═══ STAFF WORKSPACE ═══ */}
              <Route path="/my-work" element={<LazySuspense><StaffWorkspace /></LazySuspense>} />
              <Route path="/hr" element={<LazySuspense><HrPage /></LazySuspense>} />
              <Route path="/assets" element={<LazySuspense><AssetPage /></LazySuspense>} />

              {/* ═══ AI ASSIST ═══ */}
              <Route path="/reports/ai" element={<LazySuspense><AiAssistPage /></LazySuspense>} />
              <Route path="/reports/research" element={<LazySuspense><ResearchPage /></LazySuspense>} />

              {/* ═══ INTEROPERABILITY ═══ */}
              <Route path="/admin/integrations" element={<LazySuspense><InteropPage /></LazySuspense>} />

              {/* ═══ NOTIFICATION CENTER ═══ */}
              <Route path="/communications/center" element={<LazySuspense><NotificationCenterPage /></LazySuspense>} />
              <Route path="/reports/documents" element={<LazySuspense><DocumentCenterPage /></LazySuspense>} />

              {/* ═══ COMMUNICATIONS MODULE ═══ */}
              <Route path="/communications" element={<LazySuspense><CommunicationsDashboard /></LazySuspense>} />
              <Route path="/communications/notifications" element={<LazySuspense><NotificationsPage /></LazySuspense>} />
              <Route path="/communications/messages" element={<LazySuspense><CommunicationsPage /></LazySuspense>} />

              {/* ═══ ADMINISTRATION MODULE ═══ */}
              <Route path="/admin" element={<LazySuspense><AdminLayout /></LazySuspense>}>
                <Route index element={<LazySuspense><AdminConsolePage /></LazySuspense>} />
                <Route path="users" element={<LazySuspense><AdminUsersPage /></LazySuspense>} />
                <Route path="roles" element={<LazySuspense><AdminRolesPage /></LazySuspense>} />
                <Route path="staff" element={<LazySuspense><AdminStaffPage /></LazySuspense>} />
                <Route path="departments" element={<LazySuspense><AdminDepartmentsPage /></LazySuspense>} />
                <Route path="services" element={<LazySuspense><AdminServicesPage /></LazySuspense>} />
                <Route path="medications" element={<LazySuspense><AdminMedicationsPage /></LazySuspense>} />
                <Route path="settings" element={<LazySuspense><AdminSettingsPage /></LazySuspense>} />
                <Route path="branding" element={<LazySuspense><AdminBrandingPage /></LazySuspense>} />
                <Route path="audit" element={<LazySuspense><AuditPage /></LazySuspense>} />
              </Route>

              {/* ── Legacy redirects (old URLs → new module hierarchy) ── */}
              <Route path="/patients" element={<Navigate to="/clinical/patients" replace />} />
              <Route path="/patients/new" element={<Navigate to="/clinical/patients/new" replace />} />
              <Route path="/patients/:id" element={<Navigate to="/clinical/patients/:id" replace />} />
              <Route path="/patients/:id/profile" element={<Navigate to="/clinical/patients/:id/profile" replace />} />
              <Route path="/patients/import" element={<Navigate to="/clinical/patients/import" replace />} />
              <Route path="/appointments" element={<Navigate to="/clinical/appointments" replace />} />
              <Route path="/appointments/:id" element={<Navigate to="/clinical/appointments/:id" replace />} />
              <Route path="/queue" element={<Navigate to="/clinical/queue" replace />} />
              <Route path="/encounters/:id" element={<Navigate to="/clinical/encounters/:id" replace />} />
              <Route path="/billing" element={<Navigate to="/finance/billing" replace />} />
              <Route path="/billing/:invoiceId" element={<Navigate to="/finance/billing/:invoiceId" replace />} />
              <Route path="/inventory" element={<Navigate to="/procurement/inventory" replace />} />
              <Route path="/procurement" element={<Navigate to="/procurement/orders" replace />} />
              <Route path="/finance" element={<Navigate to="/finance/billing" replace />} />
              <Route path="/budgets" element={<Navigate to="/finance/budgets" replace />} />
              <Route path="/expenses" element={<Navigate to="/finance/expenses" replace />} />
              <Route path="/financial-periods" element={<Navigate to="/finance/periods" replace />} />
              <Route path="/audit" element={<Navigate to="/admin/audit" replace />} />
              <Route path="/analytics" element={<Navigate to="/reports/analytics" replace />} />
              <Route path="/operations" element={<Navigate to="/reports/operations" replace />} />
              <Route path="/notifications" element={<Navigate to="/communications/notifications" replace />} />
              <Route path="/communications" element={<Navigate to="/communications/messages" replace />} />
              <Route path="/forms" element={<Navigate to="/clinical/forms" replace />} />
              <Route path="/physician-scheduling" element={<Navigate to="/clinical/scheduling" replace />} />
              <Route path="/documents" element={<Navigate to="/reports/documents" replace />} />
              <Route path="/revenue" element={<Navigate to="/finance/revenue" replace />} />
              <Route path="/referrals" element={<Navigate to="/clinical/referrals" replace />} />
              <Route path="/telehealth" element={<Navigate to="/clinical/telehealth" replace />} />
              <Route path="/oncology" element={<Navigate to="/clinical/oncology" replace />} />

              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </ToastProvider>
      </AuthProvider>
    </ErrorBoundary>
  );
}
