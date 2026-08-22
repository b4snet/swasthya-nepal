import type { LucideIcon } from 'lucide-react';
import type { MessageKey } from '../i18n/locales/en';
import {
  Building2,
  Stethoscope,
  Pill,
  FlaskConical,
  ScanLine,
  Landmark,
  Boxes,
  Droplets,
  BarChart3,
  Settings,
  MessageSquare,
  LayoutDashboard,
  Users,
  Upload,
  CalendarDays,
  ListOrdered,
  FileText,
  GitPullRequestArrow,
  CalendarClock,
  Video,
  HeartPulse,
  Siren,
  Scissors,
  Bed,
  ClipboardList,
  Activity,
  WalletCards,
  DollarSign,
  Receipt,
  ChartNoAxesCombined,
  Bell,
  ShieldCheck,
  Crosshair,
  ShoppingCart,
  PanelsTopLeft,
  type LucideIcon as _LI,
} from 'lucide-react';

// ── Role constants (mirror backend seeded catalog) ──
// Empty [] = visible to all authenticated users.
// Non-empty = ONLY visible to listed roles.
//
// Backend roles: superadmin, support_agent, org_admin, org_finance,
// hospital_admin, receptionist, billing_clerk, doctor, nurse,
// pharmacist, lab_technician, lab_supervisor, radiographer, radiologist

const ALL = [] as string[]; // visible to all authenticated users

// Module-level role groups
const HOSPITAL_ADMIN = ['superadmin', 'org_admin', 'hospital_admin', 'receptionist'];
const CLINICAL = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin', 'receptionist'];
const CLINICAL_DOCTOR = ['superadmin', 'doctor', 'hospital_admin', 'org_admin'];
const PHARMACY = ['superadmin', 'pharmacist', 'hospital_admin', 'org_admin'];
const LAB = ['superadmin', 'lab_technician', 'lab_supervisor', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const RADIOLOGY = ['superadmin', 'radiologist', 'radiographer', 'doctor', 'hospital_admin', 'org_admin'];
const FINANCE = ['superadmin', 'billing_clerk', 'hospital_admin', 'org_admin', 'org_finance'];
const PROCUREMENT = ['superadmin', 'hospital_admin', 'org_admin'];
const REPORTS = ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'];
const COMMS = ALL; // notifications are universal
const ADMIN = ['superadmin', 'org_admin', 'hospital_admin'];
const AUDIT = ['hospital_admin', 'org_admin', 'org_finance', 'superadmin'];
const PATIENT_ACCESS = ALL; // patient portal visible to all (backend gates data)

// Item-level role groups
const QUEUE = ['hospital_admin', 'doctor', 'nurse', 'receptionist'];
const BILLING = ['hospital_admin', 'org_admin', 'billing_clerk'];

export interface NavItem {
  key: string;
  labelKey: MessageKey;
  to: string;
  Icon: LucideIcon;
  roles: string[];
}

export interface NavModule {
  key: string;
  labelKey: MessageKey;
  Icon: LucideIcon;
  roles: string[];
  /** Sub-sections inside this module workspace */
  children: NavItem[];
  /** Default route when module is clicked */
  defaultTo: string;
  /** Route prefix for active-state matching */
  routePrefix: string;
}

/**
 * Module-first navigation hierarchy.
 *
 * Top-level modules shown in the icon rail.
 * Sub-sections shown in the contextual panel when a module is active.
 *
 * Client-side gating only — backend `authorize:` and RLS remain authoritative.
 */
export const MODULES: NavModule[] = [
  // ── HOSPITAL (operations / facility management) ──
  {
    key: 'hospital',
    labelKey: 'module.hospital',
    Icon: Building2,
    roles: HOSPITAL_ADMIN,
    defaultTo: '/hospital',
    routePrefix: '/hospital',
    children: [
      { key: 'hosp-dashboard', labelKey: 'nav.hospitalDashboard', to: '/hospital', Icon: LayoutDashboard, roles: HOSPITAL_ADMIN },
      { key: 'hosp-opd', labelKey: 'nav.opd', to: '/hospital/opd', Icon: Stethoscope, roles: HOSPITAL_ADMIN },
      { key: 'hosp-ipd', labelKey: 'nav.ipd', to: '/hospital/ipd', Icon: Bed, roles: HOSPITAL_ADMIN },
      { key: 'hosp-emergency', labelKey: 'nav.emergency', to: '/emergency', Icon: Siren, roles: HOSPITAL_ADMIN },
      { key: 'hosp-icu', labelKey: 'nav.icu', to: '/icu', Icon: HeartPulse, roles: HOSPITAL_ADMIN },
      { key: 'hosp-ot', labelKey: 'nav.ot', to: '/ot', Icon: Scissors, roles: HOSPITAL_ADMIN },
      { key: 'hosp-beds', labelKey: 'nav.beds', to: '/beds', Icon: Bed, roles: HOSPITAL_ADMIN },
      { key: 'hosp-nursing', labelKey: 'nav.nursing', to: '/nursing', Icon: ClipboardList, roles: HOSPITAL_ADMIN },
    ],
  },

  // ── CLINICAL (patient care workflow) ──
  {
    key: 'clinical',
    labelKey: 'module.clinical',
    Icon: Stethoscope,
    roles: CLINICAL,
    defaultTo: '/clinical',
    routePrefix: '/clinical',
    children: [
      { key: 'clin-workspace', labelKey: 'nav.clinicalWorkspace', to: '/clinical/workspace', Icon: Stethoscope, roles: CLINICAL_DOCTOR },
      { key: 'clin-dashboard', labelKey: 'nav.clinicalDashboard', to: '/clinical', Icon: LayoutDashboard, roles: CLINICAL },
      { key: 'clin-patients', labelKey: 'nav.patients', to: '/clinical/patients', Icon: Users, roles: CLINICAL },
      { key: 'clin-import', labelKey: 'nav.patientImport', to: '/clinical/patients/import', Icon: Upload, roles: CLINICAL },
      { key: 'clin-appointments', labelKey: 'nav.appointments', to: '/clinical/appointments', Icon: CalendarDays, roles: CLINICAL },
      { key: 'clin-queue', labelKey: 'nav.queue', to: '/clinical/queue', Icon: ListOrdered, roles: QUEUE },
      { key: 'clin-encounters', labelKey: 'nav.encounters', to: '/clinical/encounters', Icon: FileText, roles: CLINICAL_DOCTOR },
      { key: 'clin-forms', labelKey: 'nav.forms', to: '/clinical/forms', Icon: FileText, roles: CLINICAL },
      { key: 'clin-referrals', labelKey: 'nav.referrals', to: '/clinical/referrals', Icon: GitPullRequestArrow, roles: CLINICAL_DOCTOR },
      { key: 'clin-scheduling', labelKey: 'nav.physicianScheduling', to: '/clinical/scheduling', Icon: CalendarClock, roles: CLINICAL_DOCTOR },
      { key: 'clin-telehealth', labelKey: 'nav.telehealth', to: '/clinical/telehealth', Icon: Video, roles: CLINICAL_DOCTOR },
      { key: 'clin-oncology', labelKey: 'nav.oncology', to: '/clinical/oncology', Icon: Crosshair, roles: CLINICAL_DOCTOR },
    ],
  },

  // ── PHARMACY ──
  {
    key: 'pharmacy',
    labelKey: 'module.pharmacy',
    Icon: Pill,
    roles: PHARMACY,
    defaultTo: '/pharmacy',
    routePrefix: '/pharmacy',
    children: [
      { key: 'pharm-dashboard', labelKey: 'nav.pharmacyDashboard', to: '/pharmacy', Icon: LayoutDashboard, roles: PHARMACY },
      { key: 'pharm-prescriptions', labelKey: 'nav.prescriptions', to: '/pharmacy/prescriptions', Icon: FileText, roles: PHARMACY },
      { key: 'pharm-dispensing', labelKey: 'nav.dispensing', to: '/pharmacy/dispensing', Icon: Pill, roles: PHARMACY },
      { key: 'pharm-inventory', labelKey: 'nav.pharmacyInventory', to: '/pharmacy/inventory', Icon: Boxes, roles: PHARMACY },
    ],
  },

  // ── LABORATORY ──
  {
    key: 'laboratory',
    labelKey: 'module.laboratory',
    Icon: FlaskConical,
    roles: LAB,
    defaultTo: '/laboratory',
    routePrefix: '/laboratory',
    children: [
      { key: 'lab-dashboard', labelKey: 'nav.labDashboard', to: '/laboratory', Icon: LayoutDashboard, roles: LAB },
      { key: 'lab-orders', labelKey: 'nav.labOrders', to: '/laboratory/orders', Icon: ClipboardList, roles: LAB },
      { key: 'lab-reports', labelKey: 'nav.labReports', to: '/laboratory/reports', Icon: FileText, roles: LAB },
    ],
  },

  // ── NURSING ──
  {
    key: 'nursing',
    labelKey: 'module.nursing',
    Icon: ClipboardList,
    roles: ['superadmin', 'nurse', 'hospital_admin'],
    defaultTo: '/nursing',
    routePrefix: '/nursing',
    children: [
      { key: 'nurse-dashboard', labelKey: 'nav.nursing', to: '/nursing', Icon: LayoutDashboard, roles: ['superadmin', 'nurse', 'hospital_admin'] },
    ],
  },

  // ── RADIOLOGY ──
  {
    key: 'radiology',
    labelKey: 'module.radiology',
    Icon: ScanLine,
    roles: RADIOLOGY,
    defaultTo: '/radiology',
    routePrefix: '/radiology',
    children: [
      { key: 'rad-worklist', labelKey: 'nav.radiologyWorklist', to: '/radiology', Icon: ScanLine, roles: RADIOLOGY },
    ],
  },

  // ── BLOOD BANK ──
  {
    key: 'bloodbank',
    labelKey: 'module.bloodBank',
    Icon: Droplets,
    roles: HOSPITAL_ADMIN,
    defaultTo: '/blood-bank',
    routePrefix: '/blood-bank',
    children: [
      { key: 'bb-main', labelKey: 'nav.bloodBank', to: '/blood-bank', Icon: Droplets, roles: HOSPITAL_ADMIN },
    ],
  },

  // ── FINANCE ──
  {
    key: 'finance',
    labelKey: 'module.finance',
    Icon: Landmark,
    roles: FINANCE,
    defaultTo: '/finance',
    routePrefix: '/finance',
    children: [
      { key: 'fin-dashboard', labelKey: 'nav.financeDashboard', to: '/finance', Icon: LayoutDashboard, roles: FINANCE },
      { key: 'fin-billing', labelKey: 'nav.billing', to: '/finance/billing', Icon: WalletCards, roles: BILLING },
      { key: 'fin-revenue', labelKey: 'nav.revenueCycle', to: '/finance/revenue', Icon: DollarSign, roles: BILLING },
      { key: 'fin-budgets', labelKey: 'nav.budgets', to: '/finance/budgets', Icon: ChartNoAxesCombined, roles: FINANCE },
      { key: 'fin-expenses', labelKey: 'nav.expenses', to: '/finance/expenses', Icon: Receipt, roles: FINANCE },
      { key: 'fin-periods', labelKey: 'nav.financialPeriods', to: '/finance/periods', Icon: CalendarClock, roles: FINANCE },
    ],
  },

  // ── PROCUREMENT & INVENTORY ──
  {
    key: 'procurement',
    labelKey: 'module.procurement',
    Icon: Boxes,
    roles: PROCUREMENT,
    defaultTo: '/procurement',
    routePrefix: '/procurement',
    children: [
      { key: 'proc-dashboard', labelKey: 'nav.procurementDashboard', to: '/procurement', Icon: LayoutDashboard, roles: PROCUREMENT },
      { key: 'proc-inventory', labelKey: 'nav.inventory', to: '/procurement/inventory', Icon: Boxes, roles: PROCUREMENT },
      { key: 'proc-orders', labelKey: 'nav.procurement', to: '/procurement/orders', Icon: ShoppingCart, roles: PROCUREMENT },
    ],
  },

  // ── REPORTS & ANALYTICS ──
  {
    key: 'reports',
    labelKey: 'module.reports',
    Icon: BarChart3,
    roles: REPORTS,
    defaultTo: '/reports',
    routePrefix: '/reports',
    children: [
      { key: 'rpt-analytics', labelKey: 'nav.analytics', to: '/reports/analytics', Icon: ChartNoAxesCombined, roles: REPORTS },
      { key: 'rpt-operations', labelKey: 'nav.operationsCenter', to: '/reports/operations', Icon: Activity, roles: REPORTS },
      { key: 'rpt-documents', labelKey: 'nav.documentCenter', to: '/reports/documents', Icon: FileText, roles: REPORTS },
    ],
  },

  // ── COMMUNICATIONS ──
  {
    key: 'communications',
    labelKey: 'module.communications',
    Icon: MessageSquare,
    roles: COMMS,
    defaultTo: '/communications',
    routePrefix: '/communications',
    children: [
      { key: 'com-notifications', labelKey: 'nav.notifications', to: '/communications/notifications', Icon: Bell, roles: COMMS },
      { key: 'com-messages', labelKey: 'nav.communications', to: '/communications/messages', Icon: MessageSquare, roles: COMMS },
    ],
  },

  // ── ADMINISTRATION ──
  {
    key: 'admin',
    labelKey: 'module.administration',
    Icon: Settings,
    roles: ADMIN,
    defaultTo: '/admin',
    routePrefix: '/admin',
    children: [
      { key: 'adm-console', labelKey: 'admin.nav.console', to: '/admin', Icon: Settings, roles: ADMIN },
      { key: 'adm-users', labelKey: 'admin.nav.users', to: '/admin/users', Icon: Users, roles: ADMIN },
      { key: 'adm-roles', labelKey: 'admin.nav.roles', to: '/admin/roles', Icon: ShieldCheck, roles: ADMIN },
      { key: 'adm-staff', labelKey: 'admin.nav.staff', to: '/admin/staff', Icon: ClipboardList, roles: ADMIN },
      { key: 'adm-departments', labelKey: 'admin.nav.departments', to: '/admin/departments', Icon: Building2, roles: ADMIN },
      { key: 'adm-services', labelKey: 'admin.nav.services', to: '/admin/services', Icon: Stethoscope, roles: ADMIN },
      { key: 'adm-medications', labelKey: 'admin.nav.medications', to: '/admin/medications', Icon: Pill, roles: ADMIN },
      { key: 'adm-settings', labelKey: 'admin.nav.settings', to: '/admin/settings', Icon: Settings, roles: ADMIN },
      { key: 'adm-branding', labelKey: 'admin.nav.branding', to: '/admin/branding', Icon: LayoutDashboard, roles: ADMIN },
      { key: 'adm-audit', labelKey: 'nav.audit', to: '/admin/audit', Icon: ShieldCheck, roles: AUDIT },
    ],
  },

  // ── PATIENT PORTAL ──
  {
    key: 'portal',
    labelKey: 'module.patientPortal',
    Icon: PanelsTopLeft,
    roles: PATIENT_ACCESS,
    defaultTo: '/portal',
    routePrefix: '/portal',
    children: [
      { key: 'port-home', labelKey: 'nav.portal', to: '/portal', Icon: PanelsTopLeft, roles: PATIENT_ACCESS },
    ],
  },
];

/**
 * Given a pathname, return the active module (if any).
 */
export function getActiveModule(pathname: string): NavModule | undefined {
  return MODULES.find((m) =>
    pathname === m.routePrefix || pathname.startsWith(m.routePrefix + '/')
  );
}

/**
 * Filter modules and children by role.
 */
export function filterModulesByRole(
  modules: NavModule[],
  hasRole: (r: string) => boolean,
): NavModule[] {
  return modules
    .filter((m) => m.roles.length === 0 || m.roles.some((r) => hasRole(r)))
    .map((m) => ({
      ...m,
      children: m.children.filter(
        (c) => c.roles.length === 0 || c.roles.some((r) => hasRole(r)),
      ),
    }))
    .filter((m) => m.children.length > 0);
}

/**
 * Determine default module for a user role.
 */
export function getDefaultModuleKey(hasRole: (r: string) => boolean): string {
  if (hasRole('pharmacist')) return 'pharmacy';
  if (hasRole('lab_technician')) return 'laboratory';
  if (hasRole('billing_clerk')) return 'finance';
  if (hasRole('doctor') || hasRole('nurse')) return 'clinical';
  if (hasRole('receptionist')) return 'hospital';
  return 'hospital';
}
