<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleExceptionRequest;
use App\Http\Requests\Schedule\StoreScheduleTemplateRequest;
use App\Models\Organization;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Staff;
use App\Services\SlotService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doctor availability (DATABASE.md §3.16): recurring templates, exceptions
 * (leave/holiday/block), and the DERIVED availability endpoint that front
 * desk and the patient portal use to find open slots.
 *
 * Availability is always computed (SlotService) — never stored, so it can
 * never go stale against bookings and exceptions.
 */
final class ScheduleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlotService $slots,
    ) {}

    public function templates(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = ScheduleTemplate::query()
            ->with('staff:id,full_name,designation')
            ->where('tenant_id', $organization->getKey())
            ->orderBy('day_of_week');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $templates = $query->get()
            ->map(fn (ScheduleTemplate $template): array => self::presentTemplate($template))
            ->values();

        return Envelope::success(data: $templates, request: $request);
    }

    public function storeTemplate(StoreScheduleTemplateRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $staff = AccessCheck::staff($request->validated('staffId'), $organization->getKey(), write: true);
        $facilityId = $context->facilityId() ?? $staff->facility_id;

        $template = ScheduleTemplate::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facilityId,
            'staff_id' => $staff->getKey(),
            'service_id' => $request->validated('serviceId'),
            'day_of_week' => $request->validated('dayOfWeek'),
            'starts_at' => $request->validated('startsAt'),
            'ends_at' => $request->validated('endsAt'),
            'slot_minutes' => $request->validated('slotMinutes'),
            'capacity' => $request->validated('capacity'),
            'valid_from' => $request->validated('validFrom'),
            'valid_to' => $request->validated('validTo'),
            'status' => ScheduleTemplate::STATUS_ACTIVE,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'schedule.template.created',
            'schedule_template',
            $template->getKey(),
            ['staffId' => $staff->getKey(), 'dayOfWeek' => $template->day_of_week, 'startsAt' => $template->starts_at->format('H:i'), 'endsAt' => $template->ends_at->format('H:i')],
            $request,
        );

        return Envelope::success(data: self::presentTemplate($template), status: 201, request: $request);
    }

    public function exceptions(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = ScheduleException::query()
            ->with('staff:id,full_name')
            ->where('tenant_id', $organization->getKey())
            ->orderByDesc('exception_date');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $exceptions = $query->get()
            ->map(fn (ScheduleException $exception): array => self::presentException($exception))
            ->values();

        return Envelope::success(data: $exceptions, request: $request);
    }

    public function storeException(StoreScheduleExceptionRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $staff = AccessCheck::staff($request->validated('staffId'), $organization->getKey(), write: true);
        $facilityId = $context->facilityId() ?? $staff->facility_id;

        $exception = ScheduleException::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facilityId,
            'staff_id' => $staff->getKey(),
            'exception_date' => $request->validated('exceptionDate'),
            'reason' => $request->validated('reason'),
            'status' => ScheduleException::STATUS_ACTIVE,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'schedule.exception.created',
            'schedule_exception',
            $exception->getKey(),
            ['staffId' => $staff->getKey(), 'exceptionDate' => $exception->exception_date->toDateString(), 'reason' => $exception->reason],
            $request,
        );

        return Envelope::success(data: self::presentException($exception), status: 201, request: $request);
    }

    /**
     * GET /staff/{staff}/availability?date=YYYY-MM-DD — derived open slots
     * for one provider on one date (DATABASE.md §3.16). The route parameter
     * name MUST match the method parameter for implicit binding.
     */
    public function availability(Request $request, Staff $staff): JsonResponse
    {
        AccessCheck::scoped($staff, write: false);

        $date = (string) $request->query('date', today()->toDateString());
        $includeUnavailable = $request->boolean('includeUnavailable');
        $context = TenantContext::current();

        $slots = $this->slots->slotsFor(
            (string) $context->tenantId(),
            (string) $staff->getKey(),
            $date,
            $includeUnavailable,
        );

        return Envelope::success(data: $slots->values()->all(), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentTemplate(ScheduleTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'facilityId' => $template->facility_id,
            'staffId' => $template->staff_id,
            'staff' => $template->staff ? ['id' => $template->staff->getKey(), 'fullName' => $template->staff->full_name, 'designation' => $template->staff->designation] : null,
            'serviceId' => $template->service_id,
            'dayOfWeek' => $template->day_of_week,
            'startsAt' => $template->starts_at->format('H:i'),
            'endsAt' => $template->ends_at->format('H:i'),
            'slotMinutes' => $template->slot_minutes,
            'capacity' => $template->capacity,
            'validFrom' => $template->valid_from->toDateString(),
            'validTo' => $template->valid_to?->toDateString(),
            'status' => $template->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentException(ScheduleException $exception): array
    {
        return [
            'id' => $exception->getKey(),
            'facilityId' => $exception->facility_id,
            'staffId' => $exception->staff_id,
            'exceptionDate' => $exception->exception_date->toDateString(),
            'reason' => $exception->reason,
            'status' => $exception->status,
        ];
    }
}
