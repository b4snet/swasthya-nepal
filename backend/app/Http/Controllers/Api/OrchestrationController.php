<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\QueueEntry;
use App\Models\Referral;
use App\Models\ResourceBooking;
use App\Models\Room;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Theatre;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrchestrationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ── Queue Management ──────────────────────────────────────────

    public function enqueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => 'required|uuid',
            'department' => 'required|string|max:100',
            'appointment_id' => 'nullable|uuid',
            'provider_staff_id' => 'nullable|uuid',
            'priority' => 'sometimes|string|in:emergency,urgent,normal,routine',
            'waiting_room' => 'nullable|string',
        ]);

        $ctx = TenantContext::current();

        $lastToken = QueueEntry::where('tenant_id', $ctx->tenantId())
            ->where('department', $data['department'])
            ->whereDate('created_at', now()->toDateString())
            ->max('token_number') ?? 0;

        $entry = QueueEntry::create([
            'tenant_id' => $ctx->tenantId(),
            'facility_id' => $ctx->facilityId(),
            'department' => $data['department'],
            'queue_code' => 'Q-' . strtoupper(Str::random(8)),
            'patient_id' => $data['patient_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'provider_staff_id' => $data['provider_staff_id'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'waiting',
            'token_number' => $lastToken + 1,
            'waiting_room' => $data['waiting_room'] ?? null,
        ]);

        $this->audit->record('queue.enqueued', 'queue_entry', $entry->getKey(), [
            'department' => $entry->department,
            'token' => $entry->token_number,
            'priority' => $entry->priority,
        ], $request);

        return Envelope::success(data: $entry, status: 201, request: $request);
    }

    public function listQueue(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = QueueEntry::where('tenant_id', $ctx->tenantId());

        if ($dept = $request->query('department')) $query->where('department', $dept);
        if ($status = $request->query('status')) $query->where('status', $status);

        $entries = $query->with('patient:id,first_name,last_name')
            ->orderBy('priority')->orderBy('token_number')
            ->paginate(50);

        return Envelope::success(data: $entries, request: $request);
    }

    public function callNext(Request $request, string $department): JsonResponse
    {
        $ctx = TenantContext::current();
        $next = QueueEntry::where('tenant_id', $ctx->tenantId())
            ->where('department', $department)
            ->where('status', 'waiting')
            ->orderByRaw("CASE priority WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderBy('token_number')
            ->first();

        if (!$next) {
            return Envelope::success(data: ['message' => 'No patients waiting'], request: $request);
        }

        $next->update(['status' => 'called', 'called_at' => now()]);

        $this->audit->record('queue.called', 'queue_entry', $next->getKey(), [
            'department' => $department,
            'token' => $next->token_number,
        ], $request);

        return Envelope::success(data: $next, request: $request);
    }

    public function startConsultation(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);
        $entry->update(['status' => 'in_progress', 'started_at' => now()]);

        $this->audit->record('queue.started', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    public function completeQueue(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);
        $entry->update(['status' => 'completed', 'completed_at' => now()]);

        $this->audit->record('queue.completed', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    // ── Resource Booking ──────────────────────────────────────────

    public function bookResource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_type' => 'required|string|in:ot,imaging,equipment,room,bed',
            'resource_id' => 'required|uuid',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'patient_id' => 'nullable|uuid',
            'appointment_id' => 'nullable|uuid',
            'provider_staff_id' => 'nullable|uuid',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'notes' => 'nullable|string',
        ]);

        $ctx = TenantContext::current();

        $conflict = ResourceBooking::where('tenant_id', $ctx->tenantId())
            ->where('resource_type', $data['resource_type'])
            ->where('resource_id', $data['resource_id'])
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->first();

        if ($conflict) {
            return response()->json([
                'error' => 'Resource conflict',
                'conflicting_booking' => $conflict->getKey(),
            ], 409);
        }

        $booking = ResourceBooking::create([
            'tenant_id' => $ctx->tenantId(),
            'facility_id' => $ctx->facilityId(),
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'booking_code' => 'RB-' . strtoupper(Str::random(8)),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'patient_id' => $data['patient_id'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'provider_staff_id' => $data['provider_staff_id'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'status' => 'reserved',
            'notes' => $data['notes'] ?? null,
            'prepared_by' => $ctx->user?->getKey(),
        ]);

        $this->audit->record('resource.booked', 'resource_booking', $booking->getKey(), [
            'resourceType' => $booking->resource_type,
            'resourceId' => $booking->resource_id,
        ], $request);

        return Envelope::success(data: $booking, status: 201, request: $request);
    }

    public function listBookings(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = ResourceBooking::where('tenant_id', $ctx->tenantId());

        if ($type = $request->query('resource_type')) $query->where('resource_type', $type);
        if ($rid = $request->query('resource_id')) $query->where('resource_id', $rid);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($date = $request->query('date')) {
            $query->whereDate('starts_at', $date);
        }

        $bookings = $query->orderBy('starts_at')->paginate(50);

        return Envelope::success(data: $bookings, request: $request);
    }

    public function cancelBooking(Request $request, ResourceBooking $booking): JsonResponse
    {
        AccessCheck::scoped($booking, write: true);
        $booking->update(['status' => 'cancelled']);

        $this->audit->record('resource.booking_cancelled', 'resource_booking', $booking->getKey(), [], $request);

        return Envelope::success(data: $booking, request: $request);
    }

    // ── Provider Availability ─────────────────────────────────────

    public function providerAvailability(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $data = $request->validate([
            'staff_id' => 'required|uuid',
            'date' => 'required|date',
        ]);

        $date = \Carbon\Carbon::parse($data['date']);
        $dayOfWeek = $date->dayOfWeekIso;

        $templates = ScheduleTemplate::where('tenant_id', $ctx->tenantId())
            ->where('staff_id', $data['staff_id'])
            ->where('day_of_week', $dayOfWeek)
            ->where('status', 'active')
            ->where('valid_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            })
            ->get();

        $exceptions = ScheduleException::where('tenant_id', $ctx->tenantId())
            ->where('staff_id', $data['staff_id'])
            ->whereDate('exception_date', $date)
            ->get();

        $bookedSlots = Appointment::where('tenant_id', $ctx->tenantId())
            ->where('provider_staff_id', $data['staff_id'])
            ->whereDate('starts_at', $date)
            ->whereNotIn('status', ['cancelled'])
            ->pluck('starts_at', 'ends_at');

        return Envelope::success(data: [
            'templates' => $templates,
            'exceptions' => $exceptions,
            'booked' => $bookedSlots,
            'date' => $date->toDateString(),
            'staff_id' => $data['staff_id'],
        ], request: $request);
    }

    // ── Hospital Capacity ─────────────────────────────────────────

    public function hospitalCapacity(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $tid = $ctx->tenantId();

        $totalBeds = Bed::where('tenant_id', $tid)->count();
        $availableBeds = Bed::where('tenant_id', $tid)->where('status', 'available')->count();
        $occupiedBeds = Bed::where('tenant_id', $tid)->where('status', 'occupied')->count();

        $totalTheatres = Theatre::where('tenant_id', $tid)->where('status', 'active')->count();
        $bookedOT = ResourceBooking::where('tenant_id', $tid)
            ->where('resource_type', 'ot')
            ->whereIn('status', ['reserved', 'confirmed', 'in_progress'])
            ->whereDate('starts_at', now()->toDateString())
            ->count();

        $todayAppointments = Appointment::where('tenant_id', $tid)
            ->whereDate('starts_at', now()->toDateString())
            ->count();
        $completedAppointments = Appointment::where('tenant_id', $tid)
            ->whereDate('starts_at', now()->toDateString())
            ->where('status', 'completed')
            ->count();

        $pendingReferrals = Referral::where('tenant_id', $tid)
            ->whereIn('status', ['pending', 'accepted'])
            ->count();

        $data = [
            'beds' => [
                'total' => $totalBeds,
                'available' => $availableBeds,
                'occupied' => $occupiedBeds,
                'occupancy_pct' => $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 1) : 0,
            ],
            'theatres' => [
                'total' => $totalTheatres,
                'booked_today' => $bookedOT,
            ],
            'appointments' => [
                'today' => $todayAppointments,
                'completed' => $completedAppointments,
                'remaining' => $todayAppointments - $completedAppointments,
            ],
            'referrals' => [
                'pending' => $pendingReferrals,
            ],
        ];

        return Envelope::success(data: $data, request: $request);
    }

    // ── Patient Flow ──────────────────────────────────────────────

    public function patientFlow(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $tid = $ctx->tenantId();

        $data = [
            'arrivals' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'booked')->count(),
            'checked_in' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'checked_in')->count(),
            'in_consultation' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'in_consultation')->count(),
            'completed' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'completed')->count(),
            'cancelled' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'cancelled')->count(),
            'no_show' => Appointment::where('tenant_id', $tid)
                ->whereDate('starts_at', now()->toDateString())
                ->where('status', 'no_show')->count(),
            'waiting_in_queue' => QueueEntry::where('tenant_id', $tid)->where('status', 'waiting')->count(),
            'in_queue_consultation' => QueueEntry::where('tenant_id', $tid)->where('status', 'in_progress')->count(),
        ];

        return Envelope::success(data: $data, request: $request);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $tid = $ctx->tenantId();
        $capacity = $this->hospitalCapacity($request)->getData(true)['data'] ?? [];
        $flow = $this->patientFlow($request)->getData(true)['data'] ?? [];

        return Envelope::success(data: [
            'capacity' => $capacity,
            'flow' => $flow,
            'queue_depth' => QueueEntry::where('tenant_id', $tid)->where('status', 'waiting')->count(),
            'active_bookings' => ResourceBooking::where('tenant_id', $tid)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->where('starts_at', '>=', now())->count(),
            'pending_referrals' => Referral::where('tenant_id', $tid)
                ->whereIn('status', ['pending', 'accepted'])->count(),
        ], request: $request);
    }
}
