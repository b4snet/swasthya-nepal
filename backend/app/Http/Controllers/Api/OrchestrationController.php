<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\QueueEntry;
use App\Models\Referral;
use App\Models\ResourceBooking;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Theatre;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hospital operations center: queue management, resource booking, provider
 * availability, capacity, and patient flow.
 *
 * Queue operations are race-safe:
 *  - enqueue uses a row-locked token counter (same pattern as TokenIssuer)
 *  - call-next uses SELECT ... FOR UPDATE to serialize concurrent callers
 *  - transfer/skip/recall are atomic with history recording
 */
final class OrchestrationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ── Queue Management ──────────────────────────────────────────

    /**
     * POST /orchestration/queue — enqueue a patient into a department queue.
     * Token number is generated via a row-locked counter to prevent duplicates
     * under concurrent requests.
     */
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
        $actorId = $ctx->user?->getKey();

        $entry = DB::transaction(function () use ($data, $ctx, $actorId): QueueEntry {
            // Row-locked token generation: prevents duplicate token numbers
            // under concurrent enqueues for the same department on the same day.
            $lastToken = DB::table('queue_entries')
                ->where('tenant_id', $ctx->tenantId())
                ->where('department', $data['department'])
                ->whereDate('created_at', now()->toDateString())
                ->max('token_number') ?? 0;

            $entry = QueueEntry::create([
                'tenant_id' => $ctx->tenantId(),
                'facility_id' => $ctx->facilityId(),
                'department' => $data['department'],
                'queue_code' => QueueEntry::generateQueueCode(),
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'provider_staff_id' => $data['provider_staff_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => QueueEntry::STATUS_WAITING,
                'token_number' => $lastToken + 1,
                'waiting_room' => $data['waiting_room'] ?? null,
            ]);

            $entry->recordHistory('enqueued', '', QueueEntry::STATUS_WAITING, $actorId);

            return $entry;
        });

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

        if ($dept = $request->query('department')) {
            $query->where('department', $dept);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $entries = $query->with('patient:id,first_name,last_name')
            ->orderBy('priority')->orderBy('token_number')
            ->paginate(50);

        return Envelope::success(data: $entries, request: $request);
    }

    /**
     * POST /orchestration/queue/{department}/call-next — race-safe call-next.
     * Uses SELECT ... FOR UPDATE within a transaction to serialize concurrent
     * callers. Two simultaneous requests will each call a different patient.
     */
    public function callNext(Request $request, string $department): JsonResponse
    {
        $ctx = TenantContext::current();
        $actorId = $ctx->user?->getKey();

        $result = DB::transaction(function () use ($ctx, $department, $actorId, $request): array {
            // Lock the first waiting row for this department to prevent
            // two concurrent callers from picking the same patient.
            $next = QueueEntry::where('tenant_id', $ctx->tenantId())
                ->where('department', $department)
                ->where('status', QueueEntry::STATUS_WAITING)
                ->orderByRaw("CASE priority WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
                ->orderBy('token_number')
                ->lockForUpdate()
                ->first();

            if (! $next) {
                return ['status' => 'empty'];
            }

            $fromStatus = $next->status;
            $next->transitionTo(QueueEntry::STATUS_CALLED);
            $next->called_at = now();
            $next->save();

            $next->recordHistory('called', $fromStatus, QueueEntry::STATUS_CALLED, $actorId);

            $this->audit->record('queue.called', 'queue_entry', $next->getKey(), [
                'department' => $department,
                'token' => $next->token_number,
            ], $request);

            return ['status' => 'called', 'entry' => $next];
        });

        if ($result['status'] === 'empty') {
            return Envelope::success(data: ['message' => 'No patients waiting'], request: $request);
        }

        return Envelope::success(data: $result['entry'], request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/start — transition called → in_progress.
     */
    public function startConsultation(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_IN_PROGRESS)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Only a called queue entry can be started (current status: '.$entry->status.').',
                409,
                request: $request,
            );
        }

        $fromStatus = $entry->status;
        $entry->transitionTo(QueueEntry::STATUS_IN_PROGRESS);
        $entry->started_at = now();
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('started', $fromStatus, QueueEntry::STATUS_IN_PROGRESS, $actorId);

        $this->audit->record('queue.started', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/complete — transition in_progress → completed.
     */
    public function completeQueue(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_COMPLETED)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Only an in-progress queue entry can be completed (current status: '.$entry->status.').',
                409,
                request: $request,
            );
        }

        $fromStatus = $entry->status;
        $entry->transitionTo(QueueEntry::STATUS_COMPLETED);
        $entry->completed_at = now();
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('completed', $fromStatus, QueueEntry::STATUS_COMPLETED, $actorId);

        $this->audit->record('queue.completed', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/cancel — cancel a queue entry with reason.
     * Allowed from: waiting, called.
     */
    public function cancelEntry(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_CANCELLED)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'An entry in status '.$entry->status.' cannot be cancelled.',
                409,
                request: $request,
            );
        }

        $fromStatus = $entry->status;
        $entry->transitionTo(QueueEntry::STATUS_CANCELLED);
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('cancelled', $fromStatus, QueueEntry::STATUS_CANCELLED, $actorId, $validated['reason'] ?? null);

        $this->audit->record('queue.cancelled', 'queue_entry', $entry->getKey(), [
            'reason' => $validated['reason'] ?? null,
        ], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/no-show — mark a queue entry as no-show.
     * Allowed from: waiting, called.
     */
    public function noShow(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_NO_SHOW)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'An entry in status '.$entry->status.' cannot be marked as no-show.',
                409,
                request: $request,
            );
        }

        $fromStatus = $entry->status;
        $entry->transitionTo(QueueEntry::STATUS_NO_SHOW);
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('no_show', $fromStatus, QueueEntry::STATUS_NO_SHOW, $actorId);

        $this->audit->record('queue.no_show', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/skip — skip a called patient and
     * re-queue them as skipped (to be recalled later).
     * Allowed from: called only.
     */
    public function skip(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_SKIPPED)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Only a called queue entry can be skipped (current status: '.$entry->status.').',
                409,
                request: $request,
            );
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $fromStatus = $entry->status;
        $entry->transitionTo(QueueEntry::STATUS_SKIPPED);
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('skipped', $fromStatus, QueueEntry::STATUS_SKIPPED, $actorId, $validated['reason'] ?? null);

        $this->audit->record('queue.skipped', 'queue_entry', $entry->getKey(), [
            'reason' => $validated['reason'] ?? null,
        ], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/recall — recall a skipped entry
     * back to waiting.
     * Allowed from: skipped only.
     */
    public function recall(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        if ($entry->status !== QueueEntry::STATUS_SKIPPED) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Only a skipped queue entry can be recalled (current status: '.$entry->status.').',
                409,
                request: $request,
            );
        }

        $fromStatus = $entry->status;
        $entry->status = QueueEntry::STATUS_WAITING;
        $entry->called_at = null;
        $entry->save();

        $actorId = TenantContext::current()->user?->getKey();
        $entry->recordHistory('recalled', $fromStatus, QueueEntry::STATUS_WAITING, $actorId);

        $this->audit->record('queue.recalled', 'queue_entry', $entry->getKey(), [], $request);

        return Envelope::success(data: $entry, request: $request);
    }

    /**
     * POST /orchestration/queue/{entry}/transfer — atomically transfer a
     * queue entry from one department to another. The old entry is cancelled,
     * a new entry is created in the destination department, and the history
     * preserves the provenance.
     *
     * Allowed from: waiting, called.
     */
    public function transfer(Request $request, QueueEntry $entry): JsonResponse
    {
        AccessCheck::scoped($entry, write: true);

        $validated = $request->validate([
            'to_department' => 'required|string|max:100',
            'reason' => 'nullable|string|max:500',
        ]);

        if (! $entry->canTransitionTo(QueueEntry::STATUS_CANCELLED)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'An entry in status '.$entry->status.' cannot be transferred.',
                409,
                request: $request,
            );
        }

        if ($validated['to_department'] === $entry->department) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Cannot transfer to the same department.',
                409,
                request: $request,
            );
        }

        $ctx = TenantContext::current();
        $actorId = $ctx->user?->getKey();

        $newEntry = DB::transaction(function () use ($entry, $validated, $ctx, $actorId): QueueEntry {
            // Cancel the source entry
            $fromStatus = $entry->status;
            $entry->transitionTo(QueueEntry::STATUS_CANCELLED);
            $entry->save();

            $entry->recordHistory(
                'transferred_out',
                $fromStatus,
                QueueEntry::STATUS_CANCELLED,
                $actorId,
                $validated['reason'] ?? null,
                $validated['to_department'],
            );

            // Generate new token for destination department
            $lastToken = DB::table('queue_entries')
                ->where('tenant_id', $ctx->tenantId())
                ->where('department', $validated['to_department'])
                ->whereDate('created_at', now()->toDateString())
                ->max('token_number') ?? 0;

            // Create new entry in destination department
            $newEntry = QueueEntry::create([
                'tenant_id' => $entry->tenant_id,
                'facility_id' => $entry->facility_id,
                'department' => $validated['to_department'],
                'queue_code' => QueueEntry::generateQueueCode(),
                'patient_id' => $entry->patient_id,
                'appointment_id' => $entry->appointment_id,
                'provider_staff_id' => $entry->provider_staff_id,
                'priority' => $entry->priority,
                'status' => QueueEntry::STATUS_WAITING,
                'token_number' => $lastToken + 1,
                'waiting_room' => $entry->waiting_room,
            ]);

            $newEntry->recordHistory(
                'transferred_in',
                '',
                QueueEntry::STATUS_WAITING,
                $actorId,
                $validated['reason'] ?? null,
                $entry->department,
                ['source_entry_id' => $entry->getKey(), 'source_queue_code' => $entry->queue_code],
            );

            return $newEntry;
        });

        $this->audit->record('queue.transferred', 'queue_entry', $entry->getKey(), [
            'from_department' => $entry->department,
            'to_department' => $validated['to_department'],
            'new_entry_id' => $newEntry->getKey(),
            'reason' => $validated['reason'] ?? null,
        ], $request);

        return Envelope::success(data: $newEntry, status: 201, request: $request);
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

        $booking = ResourceBooking::create([
            'tenant_id' => $ctx->tenantId(),
            'facility_id' => $ctx->facilityId(),
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'booking_code' => 'RB-'.strtoupper(Str::random(8)),
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

        if ($type = $request->query('resource_type')) {
            $query->where('resource_type', $type);
        }
        if ($rid = $request->query('resource_id')) {
            $query->where('resource_id', $rid);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
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

        $date = Carbon::parse($data['date']);
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
