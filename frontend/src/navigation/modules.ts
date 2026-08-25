import type { LucideIcon } from 'lucide-react';
import type { MessageKey } from '../i18n/locales/en';
import {
  LayoutDashboard,
  Stethoscope,
  Siren,
  Bed,
  Pill,
  FlaskConical,
  ScanLine,
  Scissors,
  HeartPulse,
  Droplets,
  Crosshair,
  Boxes,
  Landmark,
  Users,
  ShieldCheck,
  MessageSquare,
  BarChart3,
  Settings,
  Bell,
  GitPullRequestArrow,
  CalendarDays,
  ListOrdered,
  FileText,
  ClipboardList,
  Activity,
  WalletCards,
  DollarSign,
  Receipt,
  ChartNoAxesCombined,
  ShoppingCart,
  PillBottle,
  Bot,
  TestTube,
  ShieldAlert,
  Link,
  type LucideIcon as _LI,
} from 'lucide-react';

// ── Role constants ──
const ALL = [] as string[];

const ADMIN_ROLES = ['superadmin', 'org_admin', 'hospital_admin'];
const CLINICAL_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin', 'receptionist'];
const DOCTOR_ROLES = ['superadmin', 'doctor', 'hospital_admin', 'org_admin'];
const NURSE_ROLES = ['superadmin', 'nurse', 'hospital_admin', 'org_admin'];
const ER_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const IPD_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const PHARMACY_ROLES = ['superadmin', 'pharmacist', 'hospital_admin', 'org_admin'];
const LAB_ROLES = ['superadmin', 'lab_technician', 'lab_supervisor', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const RADIOLOGY_ROLES = ['superadmin', 'radiologist', 'radiographer', 'doctor', 'hospital_admin', 'org_admin'];
const OT_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const ICU_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const BLOOD_BANK_ROLES = ['superadmin', 'lab_technician', 'lab_supervisor', 'hospital_admin', 'org_admin'];
const ONCOLOGY_ROLES = ['superadmin', 'doctor', 'nurse', 'hospital_admin', 'org_admin'];
const FINANCE_ROLES = ['superadmin', 'billing_clerk', 'hospital_admin', 'org_admin', 'org_finance'];
const HR_ROLES = ['superadmin', 'hospital_admin', 'org_admin'];
const QUALITY_ROLES = ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'];
const INTEROP_ROLES = ['superadmin', 'hospital_admin', 'org_admin'];
const ANALYTICS_ROLES = ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'];

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
  children: NavItem[];
  defaultTo: string;
  routePrefix: string;
  persistent?: boolean;
}

/**
 * SWASTHYA product navigation hierarchy.
 *
 * One sidebar. Icon + name. Children below parent.
 * Dashboard always first. Only authorized modules visible.
 */
export const MODULES: NavModule[] = [
  // ── DASHBOARD (always first) ──
  {
    key: 'dashboard',
    labelKey: 'nav.dashboard',
    Icon: LayoutDashboard,
    roles: ALL,
    defaultTo: '/dashboard',
    routePrefix: '/dashboard',
    persistent: true,
    children: [],
  },

  // ── CLINICAL ──
  {
    key: 'clinical',
    labelKey: 'module.clinical',
    Icon: Stethoscope,
    roles: CLINICAL_ROLES,
    defaultTo: '/clinical',
    routePrefix: '/clinical',
    children: [
      { key: 'clin-patients', labelKey: 'nav.patients', to: '/clinical/patients', Icon: Users, roles: CLINICAL_ROLES },
      { key: 'clin-appointments', labelKey: 'nav.appointments', to: '/clinical/appointments', Icon: CalendarDays, roles: CLINICAL_ROLES },
      { key: 'clin-queue', labelKey: 'nav.queue', to: '/clinical/queue', Icon: ListOrdered, roles: ['hospital_admin', 'doctor', 'nurse', 'receptionist'] },
      { key: 'clin-encounters', labelKey: 'nav.encounters', to: '/clinical/encounters', Icon: FileText, roles: DOCTOR_ROLES },
      { key: 'clin-forms', labelKey: 'nav.orders', to: '/clinical/forms', Icon: ClipboardList, roles: CLINICAL_ROLES },
      { key: 'clin-referrals', labelKey: 'nav.referrals', to: '/clinical/referrals', Icon: GitPullRequestArrow, roles: DOCTOR_ROLES },
    ],
  },

  // ── EMERGENCY ──
  {
    key: 'emergency',
    labelKey: 'module.emergency',
    Icon: Siren,
    roles: ER_ROLES,
    defaultTo: '/emergency',
    routePrefix: '/emergency',
    children: [
      { key: 'er-triage', labelKey: 'nav.triage', to: '/emergency', Icon: Siren, roles: ER_ROLES },
      { key: 'er-queue', labelKey: 'nav.queue', to: '/clinical/queue', Icon: ListOrdered, roles: ER_ROLES },
      { key: 'er-cases', labelKey: 'nav.cases', to: '/emergency', Icon: Activity, roles: ER_ROLES },
    ],
  },

  // ── INPATIENT ──
  {
    key: 'inpatient',
    labelKey: 'module.inpatient',
    Icon: Bed,
    roles: IPD_ROLES,
    defaultTo: '/ipd',
    routePrefix: '/ipd',
    children: [
      { key: 'ipd-admissions', labelKey: 'nav.admissions', to: '/ipd', Icon: Bed, roles: IPD_ROLES },
      { key: 'ipd-wards', labelKey: 'nav.wards', to: '/beds', Icon: Bed, roles: IPD_ROLES },
      { key: 'ipd-beds', labelKey: 'nav.beds', to: '/beds', Icon: Bed, roles: IPD_ROLES },
      { key: 'ipd-nursing', labelKey: 'nav.nursing', to: '/nursing', Icon: ClipboardList, roles: NURSE_ROLES },
      { key: 'ipd-discharge', labelKey: 'nav.discharge', to: '/ipd', Icon: Activity, roles: DOCTOR_ROLES },
    ],
  },

  // ── PHARMACY ──
  {
    key: 'pharmacy',
    labelKey: 'module.pharmacy',
    Icon: Pill,
    roles: PHARMACY_ROLES,
    defaultTo: '/pharmacy',
    routePrefix: '/pharmacy',
    children: [
      { key: 'pharm-prescriptions', labelKey: 'nav.prescriptions', to: '/pharmacy/prescriptions', Icon: FileText, roles: PHARMACY_ROLES },
      { key: 'pharm-dispensing', labelKey: 'nav.dispensing', to: '/pharmacy/dispensing', Icon: Pill, roles: PHARMACY_ROLES },
      { key: 'pharm-inventory', labelKey: 'nav.pharmacyInventory', to: '/pharmacy/inventory', Icon: Boxes, roles: PHARMACY_ROLES },
      { key: 'pharm-returns', labelKey: 'nav.returns', to: '/pharmacy/dispensing', Icon: PillBottle, roles: PHARMACY_ROLES },
    ],
  },

  // ── LABORATORY ──
  {
    key: 'laboratory',
    labelKey: 'module.laboratory',
    Icon: FlaskConical,
    roles: LAB_ROLES,
    defaultTo: '/laboratory',
    routePrefix: '/laboratory',
    children: [
      { key: 'lab-orders', labelKey: 'nav.labOrders', to: '/laboratory/orders', Icon: ClipboardList, roles: LAB_ROLES },
      { key: 'lab-specimens', labelKey: 'nav.specimens', to: '/laboratory/orders', Icon: TestTube, roles: LAB_ROLES },
      { key: 'lab-worklists', labelKey: 'nav.worklists', to: '/laboratory/orders', Icon: ListOrdered, roles: LAB_ROLES },
      { key: 'lab-results', labelKey: 'nav.results', to: '/laboratory/reports', Icon: FileText, roles: LAB_ROLES },
      { key: 'lab-reports', labelKey: 'nav.labReports', to: '/laboratory/reports', Icon: BarChart3, roles: LAB_ROLES },
    ],
  },

  // ── RADIOLOGY ──
  {
    key: 'radiology',
    labelKey: 'module.radiology',
    Icon: ScanLine,
    roles: RADIOLOGY_ROLES,
    defaultTo: '/radiology',
    routePrefix: '/radiology',
    children: [
      { key: 'rad-worklist', labelKey: 'nav.worklist', to: '/radiology', Icon: ListOrdered, roles: RADIOLOGY_ROLES },
      { key: 'rad-studies', labelKey: 'nav.studies', to: '/radiology', Icon: ScanLine, roles: RADIOLOGY_ROLES },
      { key: 'rad-reporting', labelKey: 'nav.reporting', to: '/radiology', Icon: FileText, roles: ['superadmin', 'radiologist', 'hospital_admin', 'org_admin'] },
    ],
  },

  // ── OPERATING THEATRE ──
  {
    key: 'ot',
    labelKey: 'module.ot',
    Icon: Scissors,
    roles: OT_ROLES,
    defaultTo: '/ot',
    routePrefix: '/ot',
    children: [
      { key: 'ot-schedule', labelKey: 'nav.schedule', to: '/ot', Icon: CalendarDays, roles: OT_ROLES },
      { key: 'ot-procedures', labelKey: 'nav.procedures', to: '/ot', Icon: Scissors, roles: OT_ROLES },
    ],
  },

  // ── ICU ──
  {
    key: 'icu',
    labelKey: 'module.icu',
    Icon: HeartPulse,
    roles: ICU_ROLES,
    defaultTo: '/icu',
    routePrefix: '/icu',
    children: [
      { key: 'icu-census', labelKey: 'nav.census', to: '/icu', Icon: HeartPulse, roles: ICU_ROLES },
      { key: 'icu-patients', labelKey: 'nav.patients', to: '/icu', Icon: Users, roles: ICU_ROLES },
      { key: 'icu-tasks', labelKey: 'nav.tasks', to: '/nursing', Icon: ClipboardList, roles: NURSE_ROLES },
    ],
  },

  // ── BLOOD BANK ──
  {
    key: 'bloodbank',
    labelKey: 'module.bloodBank',
    Icon: Droplets,
    roles: BLOOD_BANK_ROLES,
    defaultTo: '/blood-bank',
    routePrefix: '/blood-bank',
    children: [
      { key: 'bb-inventory', labelKey: 'nav.inventory', to: '/blood-bank', Icon: Boxes, roles: BLOOD_BANK_ROLES },
      { key: 'bb-requests', labelKey: 'nav.requests', to: '/blood-bank', Icon: Droplets, roles: BLOOD_BANK_ROLES },
    ],
  },

  // ── ONCOLOGY ──
  {
    key: 'oncology',
    labelKey: 'module.oncology',
    Icon: Crosshair,
    roles: ONCOLOGY_ROLES,
    defaultTo: '/clinical/oncology',
    routePrefix: '/clinical/oncology',
    children: [
      { key: 'onc-patients', labelKey: 'nav.patients', to: '/clinical/patients', Icon: Users, roles: ONCOLOGY_ROLES },
      { key: 'onc-treatment', labelKey: 'nav.treatment', to: '/clinical/oncology', Icon: Crosshair, roles: ONCOLOGY_ROLES },
    ],
  },

  // ── PROCUREMENT & INVENTORY ──
  {
    key: 'procurement',
    labelKey: 'module.procurement',
    Icon: Boxes,
    roles: ADMIN_ROLES,
    defaultTo: '/procurement',
    routePrefix: '/procurement',
    children: [
      { key: 'proc-orders', labelKey: 'nav.procurement', to: '/procurement/orders', Icon: ShoppingCart, roles: ADMIN_ROLES },
      { key: 'proc-inventory', labelKey: 'nav.inventory', to: '/procurement/inventory', Icon: Boxes, roles: ADMIN_ROLES },
    ],
  },

  // ── FINANCE ──
  {
    key: 'finance',
    labelKey: 'module.finance',
    Icon: Landmark,
    roles: FINANCE_ROLES,
    defaultTo: '/finance',
    routePrefix: '/finance',
    children: [
      { key: 'fin-billing', labelKey: 'nav.billing', to: '/finance/billing', Icon: WalletCards, roles: FINANCE_ROLES },
      { key: 'fin-revenue', labelKey: 'nav.revenueCycle', to: '/finance/revenue', Icon: DollarSign, roles: ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'] },
      { key: 'fin-budgets', labelKey: 'nav.budgets', to: '/finance/budgets', Icon: ChartNoAxesCombined, roles: ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'] },
      { key: 'fin-expenses', labelKey: 'nav.expenses', to: '/finance/expenses', Icon: Receipt, roles: ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'] },
      { key: 'fin-periods', labelKey: 'nav.financialPeriods', to: '/finance/periods', Icon: CalendarDays, roles: ['superadmin', 'hospital_admin', 'org_admin', 'org_finance'] },
      { key: 'fin-settings', labelKey: 'nav.financeSettings', to: '/finance/nepal-admin', Icon: Settings, roles: ['superadmin', 'org_admin', 'hospital_admin', 'org_finance'] },
    ],
  },

  // ── STAFF & HR ──
  {
    key: 'staff',
    labelKey: 'module.staff',
    Icon: Users,
    roles: HR_ROLES,
    defaultTo: '/admin/staff',
    routePrefix: '/admin/staff',
    children: [
      { key: 'hr-staff', labelKey: 'nav.staff', to: '/admin/staff', Icon: Users, roles: HR_ROLES },
      { key: 'hr-roles', labelKey: 'nav.rolesPermissions', to: '/admin/roles', Icon: ShieldCheck, roles: HR_ROLES },
      { key: 'hr-departments', labelKey: 'nav.departments', to: '/admin/departments', Icon: Settings, roles: HR_ROLES },
      { key: 'hr-services', labelKey: 'nav.services', to: '/admin/services', Icon: Stethoscope, roles: HR_ROLES },
    ],
  },

  // ── QUALITY & SAFETY ──
  {
    key: 'quality',
    labelKey: 'module.quality',
    Icon: ShieldAlert,
    roles: QUALITY_ROLES,
    defaultTo: '/admin/audit',
    routePrefix: '/admin/audit',
    children: [
      { key: 'qual-audit', labelKey: 'nav.audit', to: '/admin/audit', Icon: ShieldCheck, roles: QUALITY_ROLES },
    ],
  },

  // ── COMMUNICATIONS ──
  {
    key: 'communications',
    labelKey: 'module.communications',
    Icon: MessageSquare,
    roles: ALL,
    defaultTo: '/communications',
    routePrefix: '/communications',
    children: [
      { key: 'com-notifications', labelKey: 'nav.notifications', to: '/communications/notifications', Icon: Bell, roles: ALL },
      { key: 'com-messages', labelKey: 'nav.communications', to: '/communications/messages', Icon: MessageSquare, roles: ALL },
    ],
  },

  // ── ANALYTICS ──
  {
    key: 'analytics',
    labelKey: 'module.analytics',
    Icon: BarChart3,
    roles: ANALYTICS_ROLES,
    defaultTo: '/reports',
    routePrefix: '/reports',
    children: [
      { key: 'rpt-analytics', labelKey: 'nav.analytics', to: '/reports/analytics', Icon: ChartNoAxesCombined, roles: ANALYTICS_ROLES },
      { key: 'rpt-operations', labelKey: 'nav.operationsCenter', to: '/reports/operations', Icon: Activity, roles: ANALYTICS_ROLES },
      { key: 'rpt-ai', labelKey: 'nav.aiAssist', to: '/reports/ai', Icon: Bot, roles: ANALYTICS_ROLES },
    ],
  },

  // ── ADMINISTRATION ──
  {
    key: 'admin',
    labelKey: 'module.administration',
    Icon: Settings,
    roles: ADMIN_ROLES,
    defaultTo: '/admin',
    routePrefix: '/admin',
    children: [
      { key: 'adm-users', labelKey: 'admin.nav.users', to: '/admin/users', Icon: Users, roles: ADMIN_ROLES },
      { key: 'adm-medications', labelKey: 'admin.nav.medications', to: '/admin/medications', Icon: Pill, roles: ADMIN_ROLES },
      { key: 'adm-settings', labelKey: 'admin.nav.settings', to: '/admin/settings', Icon: Settings, roles: ADMIN_ROLES },
      { key: 'adm-branding', labelKey: 'admin.nav.branding', to: '/admin/branding', Icon: LayoutDashboard, roles: ADMIN_ROLES },
      { key: 'adm-forms', labelKey: 'nav.forms', to: '/clinical/forms', Icon: FileText, roles: ADMIN_ROLES },
      { key: 'adm-integrations', labelKey: 'nav.integrations', to: '/admin/integrations', Icon: Link, roles: INTEROP_ROLES },
    ],
  },
];

/**
 * Given a pathname, return the active module (if any).
 */
export function getActiveModule(pathname: string): NavModule | undefined {
  // Check more-specific prefixes first
  const sorted = [...MODULES].sort(
    (a, b) => b.routePrefix.length - a.routePrefix.length,
  );
  return sorted.find(
    (m) => pathname === m.routePrefix || pathname.startsWith(m.routePrefix + '/'),
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
    .filter((m) => m.persistent || m.children.length > 0);
}

/**
 * Determine default module for a user role.
 */
export function getDefaultModuleKey(hasRole: (r: string) => boolean): string {
  if (hasRole('pharmacist')) return 'pharmacy';
  if (hasRole('lab_technician') || hasRole('lab_supervisor')) return 'laboratory';
  if (hasRole('billing_clerk')) return 'finance';
  if (hasRole('doctor')) return 'clinical';
  if (hasRole('nurse')) return 'clinical';
  if (hasRole('receptionist')) return 'clinical';
  return 'clinical';
}
