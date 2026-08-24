import { api } from './client';

/**
 * Dashboard data layer.
 *
 * All metrics come from the backend via authorized, tenant-scoped endpoints.
 * No client-side aggregation from raw datasets.
 */

function opt(facilityId?: string | null) {
  return facilityId ? { facilityId } : {};
}

/* ── Domain Summary (from AnalyticsService) ── */

export interface DomainSummary {
  domain: string;
  total: number;
  timeSeries: Array<{ date: string; count: number }>;
  filters: Record<string, number>;
}

/* ── Direct aggregate queries for dashboard ── */

export interface DashboardMetrics {
  // Patients
  totalPatients: number;
  newPatientsToday: number;
  newPatientsThisWeek: number;

  // Appointments
  appointmentsToday: number;
  completedToday: number;
  cancelledToday: number;
  noShowToday: number;
  checkInsToday: number;

  // Queue
  inQueue: number;
  inConsultation: number;
  avgWaitMinutes: number;

  // Encounters
  encountersToday: number;
  encountersThisWeek: number;

  // IPD
  totalBeds: number;
  occupiedBeds: number;
  availableBeds: number;
  cleaningBeds: number;
  admissionsToday: number;
  dischargesToday: number;

  // Finance
  revenueToday: number;
  revenueThisMonth: number;
  outstandingAmount: number;
  invoicesIssuedToday: number;
  paymentsToday: number;
  refundsToday: number;

  // Pharmacy
  prescriptionsToday: number;
  dispensingsToday: number;
  lowStockItems: number;
  expiringItems: number;

  // Lab
  pendingLabOrders: number;
  completedLabToday: number;
  criticalValues: number;

  // Radiology
  pendingStudies: number;
  completedStudiesToday: number;
  pendingReports: number;

  // Emergency
  erRegistrationsToday: number;
  erWaiting: number;

  // Notifications
  unreadNotifications: number;

  // Platform mode (superadmin)
  totalOrganizations?: number;
  totalFacilities?: number;
  totalStaff?: number;
  totalUsers?: number;
  totalDepartments?: number;
  platformAdmins?: number;
  totalRevenue?: number;
  totalAppointments?: number;
  organizations?: Array<{
    id: string;
    name: string;
    code: string;
    status: string;
    facilityCount: number;
    patientCount: number;
  }>;
}

export interface TimeSeriesPoint {
  date: string;
  value: number;
}

export interface ChartData {
  patientVolume: TimeSeriesPoint[];
  appointmentVolume: TimeSeriesPoint[];
  revenueTrend: TimeSeriesPoint[];
  bedOccupancy: { occupied: number; available: number; cleaning: number; total: number };
  appointmentsByStatus: Array<{ status: string; count: number }>;
  labWorkload: Array<{ date: string; ordered: number; completed: number }>;
  departmentActivity: Array<{ department: string; encounters: number }>;
  recentPatients: Array<{
    id: string;
    name: string;
    mrn: string;
    lastVisit: string;
    status: string;
  }>;
  upcomingAppointments: Array<{
    id: string;
    patientName: string;
    time: string;
    provider: string;
    type: string;
    status: string;
  }>;
  recentAdmissions: Array<{
    id: string;
    patientName: string;
    ward: string;
    admittedAt: string;
    status: string;
  }>;
  pendingLabResults: Array<{
    id: string;
    patientName: string;
    test: string;
    orderedAt: string;
    status: string;
  }>;
  lowStockMedications: Array<{
    id: string;
    name: string;
    quantity: number;
    reorderLevel: number;
    form: string;
  }>;
}

/* ── API calls ── */

export const dashboardApi = {
  /**
   * Fetch the domain summary for any entity type.
   * Backend scopes by tenant + facility automatically.
   */
  domainSummary: (domain: string, facilityId?: string | null) =>
    api.request<DomainSummary>(`/api/v1/analytics/domain-summary/${domain}`, opt(facilityId)),

  /**
   * Fetch aggregated dashboard metrics.
   * This hits a dedicated dashboard endpoint that computes all KPIs in one query.
   */
  metrics: (facilityId?: string | null) =>
    api.request<DashboardMetrics>('/api/v1/analytics/dashboard-metrics', opt(facilityId)),

  /**
   * Fetch chart-ready time series and categorical data.
   */
  chartData: (facilityId?: string | null, days?: number) =>
    api.request<ChartData>(`/api/v1/analytics/dashboard-charts?days=${days ?? 30}`, opt(facilityId)),

  /**
   * KPI definitions and snapshots (from existing analytics system).
   */
  kpiDefinitions: (facilityId?: string | null) =>
    api.request<Array<{ id: string; name: string; domain: string }>>('/api/v1/analytics/kpi-definitions', opt(facilityId)),
};
