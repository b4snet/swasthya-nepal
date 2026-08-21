<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard data controller.
 *
 * Every metric is computed server-side against authorized, tenant-scoped data.
 * No client-side aggregation from raw datasets.
 *
 * The endpoint respects:
 * - Tenant isolation (claims-based)
 * - Facility scoping (X-Facility-Id header)
 * - Role-based visibility
 */
class DashboardController extends Controller
{
    /**
     * Aggregated dashboard metrics — one endpoint, all KPIs.
     *
     * GET /api/v1/analytics/dashboard-metrics
     */
    public function metrics(Request $request): JsonResponse
    {
        $tenantId = TenantContext::current()->tenantId;
        $facilityId = $request->header('X-Facility-Id');
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $monthStart = Carbon::now()->startOfMonth();

        $scope = function ($query) use ($tenantId, $facilityId) {
            $query->where('tenant_id', $tenantId);
            if ($facilityId) {
                $query->where('facility_id', $facilityId);
            }
        };

        // Patients
        $totalPatients = DB::table('patients')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->count();
        $newPatientsToday = DB::table('patients')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('created_at', $today)->count();
        $newPatientsThisWeek = DB::table('patients')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->where('created_at', '>=', $weekStart)->count();

        // Appointments
        $apptQuery = DB::table('appointments')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('starts_at', $today);
        $appointmentsToday = (clone $apptQuery)->count();
        $completedToday = (clone $apptQuery)->where('status', 'completed')->count();
        $cancelledToday = (clone $apptQuery)->where('status', 'cancelled')->count();
        $noShowToday = (clone $apptQuery)->where('status', 'no_show')->count();

        // Queue (from appointments checked in today)
        $queueQuery = DB::table('appointments')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('starts_at', $today);
        $checkInsToday = (clone $queueQuery)->where('status', 'checked_in')->count();
        $inQueue = $checkInsToday;
        $inConsultation = (clone $queueQuery)->where('status', 'in_consultation')->count();

        // Encounters
        $encQuery = DB::table('encounters')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $encountersToday = (clone $encQuery)->whereDate('created_at', $today)->count();
        $encountersThisWeek = (clone $encQuery)->where('created_at', '>=', $weekStart)->count();

        // Beds
        $bedQuery = DB::table('beds')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $totalBeds = (clone $bedQuery)->where('status', '!=', 'decommissioned')->count();
        $occupiedBeds = (clone $bedQuery)->where('status', 'occupied')->count();
        $availableBeds = (clone $bedQuery)->where('status', 'available')->count();
        $cleaningBeds = (clone $bedQuery)->where('status', 'cleaning')->count();

        // Admissions / Discharges
        $admQuery = DB::table('admissions')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $admissionsToday = (clone $admQuery)->whereDate('admitted_at', $today)->count();
        $dischargesToday = (clone $admQuery)->whereDate('discharged_at', $today)->count();

        // Finance
        $invQuery = DB::table('invoices')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $revenueToday = (clone $invQuery)->whereDate('created_at', $today)->where('status', 'paid')->sum('total_minor');
        $revenueThisMonth = (clone $invQuery)->where('created_at', '>=', $monthStart)->where('status', 'paid')->sum('total_minor');
        $outstandingAmount = (clone $invQuery)->where('status', 'issued')->sum('total_minor');
        $invoicesIssuedToday = (clone $invQuery)->whereDate('created_at', $today)->count();
        $paymentsToday = DB::table('payments')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('created_at', $today)->sum('amount_minor');
        $refundsToday = DB::table('refund_requests')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('created_at', $today)->where('status', 'approved')->count();

        // Pharmacy
        $rxQuery = DB::table('prescriptions')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $prescriptionsToday = (clone $rxQuery)->whereDate('created_at', $today)->count();
        $dispensingsToday = DB::table('dispensings')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereDate('created_at', $today)->count();
        $lowStockItems = DB::table('inventory_items')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count();
        $expiringItems = DB::table('stock_batches')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->whereBetween('expiry_date', [$today, $today->copy()->addMonths(3)])->count();

        // Laboratory
        $labQuery = DB::table('lab_orders')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $pendingLabOrders = (clone $labQuery)->whereIn('status', ['ordered', 'collected'])->count();
        $completedLabToday = (clone $labQuery)->whereDate('created_at', $today)->where('status', 'reported')->count();
        $criticalValues = DB::table('critical_value_events')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))->where('acknowledged_at', null)->count();

        // Radiology
        $radQuery = DB::table('studies')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $pendingStudies = (clone $radQuery)->whereIn('status', ['scheduled', 'in_progress'])->count();
        $completedStudiesToday = (clone $radQuery)->whereDate('created_at', $today)->where('status', 'reported')->count();
        $pendingReports = (clone $radQuery)->where('status', 'completed')->count();

        // Emergency
        $erQuery = DB::table('er_registrations')->where('tenant_id', $tenantId)->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));
        $erRegistrationsToday = (clone $erQuery)->whereDate('created_at', $today)->count();
        $erWaiting = (clone $erQuery)->whereIn('status', ['registered', 'triaged'])->count();

        // Notifications
        $unreadNotifications = DB::table('notifications')->where('tenant_id', $tenantId)->whereNull('read_at')->count();

        return response()->json([
            'totalPatients' => $totalPatients,
            'newPatientsToday' => $newPatientsToday,
            'newPatientsThisWeek' => $newPatientsThisWeek,
            'appointmentsToday' => $appointmentsToday,
            'completedToday' => $completedToday,
            'cancelledToday' => $cancelledToday,
            'noShowToday' => $noShowToday,
            'checkInsToday' => $checkInsToday,
            'inQueue' => $inQueue,
            'inConsultation' => $inConsultation,
            'avgWaitMinutes' => 0, // Requires timing data
            'encountersToday' => $encountersToday,
            'encountersThisWeek' => $encountersThisWeek,
            'totalBeds' => $totalBeds,
            'occupiedBeds' => $occupiedBeds,
            'availableBeds' => $availableBeds,
            'cleaningBeds' => $cleaningBeds,
            'admissionsToday' => $admissionsToday,
            'dischargesToday' => $dischargesToday,
            'revenueToday' => (int) $revenueToday,
            'revenueThisMonth' => (int) $revenueThisMonth,
            'outstandingAmount' => (int) $outstandingAmount,
            'invoicesIssuedToday' => $invoicesIssuedToday,
            'paymentsToday' => (int) $paymentsToday,
            'refundsToday' => $refundsToday,
            'prescriptionsToday' => $prescriptionsToday,
            'dispensingsToday' => $dispensingsToday,
            'lowStockItems' => $lowStockItems,
            'expiringItems' => $expiringItems,
            'pendingLabOrders' => $pendingLabOrders,
            'completedLabToday' => $completedLabToday,
            'criticalValues' => $criticalValues,
            'pendingStudies' => $pendingStudies,
            'completedStudiesToday' => $completedStudiesToday,
            'pendingReports' => $pendingReports,
            'erRegistrationsToday' => $erRegistrationsToday,
            'erWaiting' => $erWaiting,
            'unreadNotifications' => $unreadNotifications,
        ]);
    }

    /**
     * Chart-ready data — time series and categorical breakdowns.
     *
     * GET /api/v1/analytics/dashboard-charts
     */
    public function charts(Request $request): JsonResponse
    {
        $tenantId = TenantContext::current()->tenantId;
        $facilityId = $request->header('X-Facility-Id');
        $days = (int) $request->query('days', 30);
        $startDate = Carbon::now()->subDays($days);

        // Patient volume over time
        $patientVolume = DB::table('patients')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as value'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Appointment volume
        $appointmentVolume = DB::table('appointments')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->where('starts_at', '>=', $startDate)
            ->select(DB::raw('DATE(starts_at) as date'), DB::raw('COUNT(*) as value'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Revenue trend
        $revenueTrend = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->where('created_at', '>=', $startDate)
            ->where('status', 'paid')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COALESCE(SUM(total_minor), 0) as value'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Bed occupancy
        $bedOccupancy = DB::table('beds')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->where('status', '!=', 'decommissioned')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Appointments by status
        $appointmentsByStatus = DB::table('appointments')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->whereDate('starts_at', Carbon::today())
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        // Recent patients
        $recentPatients = DB::table('patients')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'full_name as name', 'mrn', 'created_at as lastVisit', 'status']);

        // Upcoming appointments
        $upcomingAppointments = DB::table('appointments')
            ->leftJoin('patients', 'patients.id', '=', 'appointments.patient_id')
            ->where('appointments.tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('appointments.facility_id', $facilityId))
            ->where('appointments.starts_at', '>=', Carbon::now())
            ->where('appointments.starts_at', '<=', Carbon::now()->addHours(4))
            ->orderBy('appointments.starts_at')
            ->limit(10)
            ->get([
                'appointments.id',
                'patients.full_name as patientName',
                'appointments.starts_at as time',
                'appointments.appointment_type as type',
                'appointments.status',
            ]);

        // Recent admissions
        $recentAdmissions = DB::table('admissions')
            ->leftJoin('patients', 'patients.id', '=', 'admissions.patient_id')
            ->leftJoin('wards', 'wards.id', '=', 'admissions.ward_id')
            ->where('admissions.tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('admissions.facility_id', $facilityId))
            ->orderByDesc('admissions.admitted_at')
            ->limit(10)
            ->get([
                'admissions.id',
                'patients.full_name as patientName',
                'wards.name as ward',
                'admissions.admitted_at',
                'admissions.status',
            ]);

        // Low stock medications
        $lowStockMedications = DB::table('inventory_items')
            ->where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->orderBy('quantity_on_hand')
            ->limit(10)
            ->get(['id', 'name', 'quantity_on_hand as quantity', 'reorder_level', 'form']);

        return response()->json([
            'patientVolume' => $patientVolume,
            'appointmentVolume' => $appointmentVolume,
            'revenueTrend' => $revenueTrend,
            'bedOccupancy' => [
                'occupied' => (int) ($bedOccupancy['occupied'] ?? 0),
                'available' => (int) ($bedOccupancy['available'] ?? 0),
                'cleaning' => (int) ($bedOccupancy['cleaning'] ?? 0),
                'total' => $bedOccupancy->sum(),
            ],
            'appointmentsByStatus' => $appointmentsByStatus,
            'recentPatients' => $recentPatients,
            'upcomingAppointments' => $upcomingAppointments,
            'recentAdmissions' => $recentAdmissions,
            'lowStockMedications' => $lowStockMedications,
        ]);
    }
}
