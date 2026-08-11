<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Http\Requests\Appointment\CancelAppointmentRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\SlotService;
use App\Services\TokenIssuer;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The front-desk booking surface (DATABASE.md §3.15): book a slot, check
 * the patient in (issuing a queue token), view the day's queue, cancel with
 * a reason. Slot double-booking is prevented by validating against derived
 * availability AND racing on the partial unique index.
 */
final class AppointmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlotService $slots,
        private readonly TokenIssuer $tokens,
    ) {}

    public function index(Request $request, ?string $facilityId = null): JsonResponse
    {
        $context = TenantContext::current();
        $query = Appointment::query()
            ->with('patient:id,mrn,full_name', 'provider:id,full_name')
            ->where('tenant_id', $context->tenantId());

        if ($context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        if ($request->has('date')) {
            $query->whereDate('starts_at', (string) $request->query('date'));
        }

        if ($request->has('providerStaffId')) {
            $query->where('provider_staff_id', (string) $request->query('providerStaffId'));
        }

        $appointments = $query->orderBy('starts_at')->get()
            ->map(fn (Appointment $appointment): array => self::present($appointment))
            ->values();

        return Envelope::success(data: $appointments, request: $request);
    }

    public function store(BookAppointmentRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $patient = Patient::query()->find($request->validated('patientId'));
        if ($patient === null) {
            return Envelope::error(ErrorCodes::NOT_FOUND, 'Patient not found.', 404, request: $request);
        }
        AccessCheck::scoped($patient, write: true);

        $staff = AccessCheck::staff($request->validated('providerStaffId'), $context->tenantId(), write: true);

        $startsAt = CarbonImmutable::parse($request->validated('startsAt'));
        $endsAt = CarbonImmutable::parse($request->validated('endsAt'));

        // Validate against derived availability BEFORE insert — the partial
        // unique index is the final arbiter under parallel requests.
        $open = $this->slots->slotsFor(
            (string) $context->tenantId(),
            (string) $staff->getKey(),
            $startsAt->toDateString(),
        )->first(fn (array $slot): bool => $slot['startsAt'] === $startsAt->toISOString() && $slot['available']);

        if ($open === null) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'This slot is not available for booking — choose an open slot from availability.',
                409,
                request: $request,
            );
        }

        $facilityId = $context->facilityId() ?? $staff->facility_id;

        try {
            $appointment = DB::transaction(function () use ($request, $context, $patient, $staff, $facilityId, $startsAt, $endsAt): Appointment {
                return Appointment::query()->create([
                    'tenant_id' => $context->tenantId(),
                    'facility_id' => $facilityId,
                    'patient_id' => $patient->getKey(),
                    'provider_staff_id' => $staff->getKey(),
                    'service_id' => $request->validated('serviceId'),
                    'appointment_type' => $request->validated('appointmentType', 'opd'),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => Appointment::STATUS_BOOKED,
                    'source' => $request->validated('source', Appointment::SOURCE_COUNTER),
                    'lock_version' => 0,
                    'created_by' => $context->user?->getKey(),
                ]);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uq_appointments_tenant_provider_start')) {
                return Envelope::error(
                    ErrorCodes::CONFLICT,
                    'This slot was just booked by someone else — choose another slot.',
                    409,
                    request: $request,
                );
            }

            throw $e;
        }

        $this->audit->record(
            'appointment.booked',
            'appointment',
            $appointment->getKey(),
            ['patientId' => $patient->getKey(), 'providerStaffId' => $staff->getKey(), 'startsAt' => $startsAt->toISOString()],
            $request,
        );

        return Envelope::success(data: self::present($appointment), status: 201, request: $request);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        AccessCheck::scoped($appointment, write: false);

        return Envelope::success(data: self::present($appointment), request: $request);
    }

    /**
     * POST /appointments/{appointment}/check-in — the patient arrives; a
     * queue token is issued row-locked per (provider, date).
     */
    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        AccessCheck::scoped($appointment, write: true);

        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'Only a booked appointment can be checked in (current status: '.$appointment->status.').',
                409,
                request: $request,
            );
        }

        $context = TenantContext::current();
        $token = $this->tokens->issue(
            (string) $context->tenantId(),
            (string) $appointment->facility_id,
            (string) $appointment->provider_staff_id,
            $appointment->starts_at->toDateString(),
        );

        $appointment->status = Appointment::STATUS_CHECKED_IN;
        $appointment->token_no = $token;
        $appointment->checked_in_by = $context->user?->getKey();
        $appointment->checked_in_at = now();
        $appointment->lock_version += 1;
        $appointment->save();

        $this->audit->record(
            'appointment.checked_in',
            'appointment',
            $appointment->getKey(),
            ['patientId' => $appointment->patient_id, 'tokenNo' => $token, 'providerStaffId' => $appointment->provider_staff_id],
            $request,
        );

        return Envelope::success(data: self::present($appointment), request: $request);
    }

    /**
     * GET /appointments/queue?date=...&providerStaffId=... — the live queue
     * for one provider/day, ordered by token.
     */
    public function queue(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $date = (string) $request->query('date', today()->toDateString());

        $query = Appointment::query()
            ->with(['patient:id,mrn,full_name,date_of_birth,sex', 'encounter:id,appointment_id'])
            ->where('tenant_id', $context->tenantId())
            ->whereDate('starts_at', $date)
            ->whereIn('status', [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_CONSULTATION]);

        if ($context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        if ($request->has('providerStaffId')) {
            $query->where('provider_staff_id', (string) $request->query('providerStaffId'));
        }

        $entries = $query->orderBy('token_no')
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'appointmentId' => $appointment->getKey(),
                'tokenNo' => $appointment->token_no,
                'status' => $appointment->status,
                'patient' => [
                    'id' => $appointment->patient?->getKey(),
                    'mrn' => $appointment->patient?->mrn,
                    'fullName' => $appointment->patient?->full_name,
                ],
                'startsAt' => $appointment->starts_at?->toIso8601String(),
                'encounterId' => $appointment->encounter?->getKey(),
            ])
            ->values();

        return Envelope::success(data: $entries, request: $request);
    }

    /**
     * POST /appointments/{appointment}/cancel — reason required.
     */
    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        AccessCheck::scoped($appointment, write: true);

        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_COMPLETED], true)) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'An appointment in status '.$appointment->status.' cannot be cancelled.',
                409,
                request: $request,
            );
        }

        $appointment->status = Appointment::STATUS_CANCELLED;
        $appointment->cancel_reason = $request->validated('reason');
        $appointment->lock_version += 1;
        $appointment->save();

        $this->audit->record(
            'appointment.cancelled',
            'appointment',
            $appointment->getKey(),
            ['patientId' => $appointment->patient_id, 'reason' => $appointment->cancel_reason],
            $request,
        );

        return Envelope::success(data: self::present($appointment), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Appointment $appointment): array
    {
        return [
            'id' => $appointment->getKey(),
            'facilityId' => $appointment->facility_id,
            'patientId' => $appointment->patient_id,
            'patient' => $appointment->patient ? ['id' => $appointment->patient->getKey(), 'mrn' => $appointment->patient->mrn, 'fullName' => $appointment->patient->full_name] : null,
            'providerStaffId' => $appointment->provider_staff_id,
            'provider' => $appointment->provider ? ['id' => $appointment->provider->getKey(), 'fullName' => $appointment->provider->full_name] : null,
            'serviceId' => $appointment->service_id,
            'appointmentType' => $appointment->appointment_type,
            'startsAt' => $appointment->starts_at?->toIso8601String(),
            'endsAt' => $appointment->ends_at?->toIso8601String(),
            'status' => $appointment->status,
            'tokenNo' => $appointment->token_no,
            'source' => $appointment->source,
            'cancelReason' => $appointment->cancel_reason,
            'lockVersion' => $appointment->lock_version,
        ];
    }
}
