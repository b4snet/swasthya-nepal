<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Organization;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Staff;
use App\Services\SlotService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Doctor schedule management (Phase 79): weekly schedule views, bulk template
 * updates, department-level schedule visibility, and doctor profile queries.
 *
 * This controller provides a higher-level view on top of the existing
 * ScheduleTemplate / ScheduleException / SlotService infrastructure.
 */
final class DoctorScheduleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlotService $slots,
    ) {}

    /**
     * GET /organizations/{org}/doctors — list all doctors (staff with
     * specialty or designation containing 'doctor') with profile data.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Staff::query()
            ->with('department:id,code,name')
            ->where('tenant_id', $organization->getKey())
            ->where('status', Staff::STATUS_ACTIVE);

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        // Optional filters
        if ($request->filled('departmentId')) {
            $query->where('department_id', $request->validated('departmentId'));
        }
        if ($request->filled('specialty')) {
            $query->where('specialty', $request->validated('specialty'));
        }
        if ($request->boolean('acceptsNewPatients')) {
            $query->where('accepts_new_patients', true);
        }

        $doctors = $query->get()
            ->map(fn (Staff $staff): array => self::presentDoctor($staff))
            ->values();

        return Envelope::success(data: $doctors, request: $request);
    }

    /**
     * GET /doctors/{staff}/weekly-schedule — get the weekly schedule for a
     * specific doctor: all active templates grouped by day of week, with
     * exceptions for the current week.
     */
    public function weeklySchedule(Request $request, Staff $staff): JsonResponse
    {
        AccessCheck::scoped($staff, write: false);

        $weekStart = $request->query('weekStart')
            ? CarbonImmutable::parse($request->query('weekStart'))->startOfWeek()
            : CarbonImmutable::now()->startOfWeek();

        $weekEnd = $weekStart->copy()->endOfWeek();

        $templates = ScheduleTemplate::query()
            ->with('staff:id,full_name')
            ->where('tenant_id', $staff->tenant_id)
            ->where('staff_id', $staff->getKey())
            ->where('status', ScheduleTemplate::STATUS_ACTIVE)
            ->where('valid_from', '<=', $weekEnd->toDateString())
            ->where(function ($q): void {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $weekStart->toDateString());
            })
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        $exceptions = ScheduleException::query()
            ->where('tenant_id', $staff->tenant_id)
            ->where('staff_id', $staff->getKey())
            ->where('exception_date', '>=', $weekStart->toDateString())
            ->where('exception_date', '<=', $weekEnd->toDateString())
            ->where('status', ScheduleException::STATUS_ACTIVE)
            ->get();

        // Group templates by day of week
        $byDay = [];
        for ($day = 0; $day <= 6; $day++) {
            $dayTemplates = $templates->where('day_of_week', $day)->values();
            $dayExceptions = $exceptions->filter(
                fn (ScheduleException $e) => (int) $e->exception_date->format('w') === $day
            )->values();

            $byDay[$day] = [
                'dayOfWeek' => $day,
                'dayName' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$day],
                'date' => $weekStart->copy()->addDays($day)->toDateString(),
                'templates' => $dayTemplates->map(fn (ScheduleTemplate $t): array => [
                    'id' => $t->getKey(),
                    'startsAt' => $t->starts_at->format('H:i'),
                    'endsAt' => $t->ends_at->format('H:i'),
                    'slotMinutes' => $t->slot_minutes,
                    'capacity' => $t->capacity,
                    'serviceId' => $t->service_id,
                ])->all(),
                'exceptions' => $dayExceptions->map(fn (ScheduleException $e): array => [
                    'id' => $e->getKey(),
                    'reason' => $e->reason,
                    'status' => $e->status,
                ])->all(),
                'isAvailable' => $dayTemplates->isNotEmpty() && $dayExceptions->isEmpty(),
            ];
        }

        return Envelope::success(data: [
            'staffId' => $staff->getKey(),
            'staffName' => $staff->full_name,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'days' => $byDay,
        ], request: $request);
    }

    /**
     * POST /organizations/{org}/doctors/{staff}/weekly-schedule — bulk update
     * the weekly schedule template for a doctor. Replaces the entire weekly
     * template set atomically.
     */
    public function updateWeeklySchedule(Request $request, Organization $organization, Staff $staff): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);
        AccessCheck::scoped($staff, write: true);

        $validated = $request->validate([
            'schedule' => ['required', 'array', 'max:7'],
            'schedule.*.dayOfWeek' => ['required', 'integer', 'min:0', 'max:6'],
            'schedule.*.slots' => ['required', 'array', 'min:1', 'max:10'],
            'schedule.*.slots.*.startsAt' => ['required', 'date_format:H:i'],
            'schedule.*.slots.*.endsAt' => ['required', 'date_format:H:i', 'after:schedule.*.slots.*.startsAt'],
            'schedule.*.slots.*.slotMinutes' => ['required', 'integer', 'min:5', 'max:120'],
            'schedule.*.slots.*.capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'schedule.*.slots.*.serviceId' => ['nullable', 'uuid'],
            'validFrom' => ['nullable', 'date'],
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
        ]);

        $context = TenantContext::current();
        $validFrom = $validated['validFrom'] ?? today()->toDateString();
        $validTo = $validated['validTo'] ?? null;

        DB::beginTransaction();
        try {
            // Deactivate existing templates for this staff member
            ScheduleTemplate::query()
                ->where('tenant_id', $organization->getKey())
                ->where('staff_id', $staff->getKey())
                ->where('status', ScheduleTemplate::STATUS_ACTIVE)
                ->update(['status' => ScheduleTemplate::STATUS_INACTIVE, 'updated_by' => $context->user?->getKey()]);

            $created = [];
            foreach ($validated['schedule'] as $day) {
                foreach ($day['slots'] as $slot) {
                    $template = ScheduleTemplate::query()->create([
                        'tenant_id' => $organization->getKey(),
                        'facility_id' => $staff->facility_id,
                        'staff_id' => $staff->getKey(),
                        'service_id' => $slot['serviceId'] ?? null,
                        'day_of_week' => $day['dayOfWeek'],
                        'starts_at' => $slot['startsAt'],
                        'ends_at' => $slot['endsAt'],
                        'slot_minutes' => $slot['slotMinutes'],
                        'capacity' => $slot['capacity'],
                        'valid_from' => $validFrom,
                        'valid_to' => $validTo,
                        'status' => ScheduleTemplate::STATUS_ACTIVE,
                        'created_by' => $context->user?->getKey(),
                    ]);

                    $created[] = $template;
                }
            }

            $this->audit->record(
                'doctor.schedule.bulk_updated',
                'schedule_template',
                $staff->getKey(),
                [
                    'staffId' => $staff->getKey(),
                    'totalSlots' => count($created),
                    'daysActive' => array_unique(array_map(fn ($t) => $t->day_of_week, $created)),
                    'validFrom' => $validFrom,
                    'validTo' => $validTo,
                ],
                $request,
            );

            DB::commit();

            return Envelope::success(
                data: ['created' => count($created), 'staffId' => $staff->getKey()],
                status: 201,
                request: $request,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * GET /organizations/{org}/departments/{dept}/schedule — department-level
     * schedule view: all doctors in a department with their weekly availability.
     */
    public function departmentSchedule(Request $request, Organization $organization, Department $department): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Staff::query()
            ->where('tenant_id', $organization->getKey())
            ->where('department_id', $department->getKey())
            ->where('status', Staff::STATUS_ACTIVE);

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $staffMembers = $query->get();
        $date = $request->query('date', today()->toDateString());
        $dayOfWeek = (int) CarbonImmutable::parse($date)->format('w');

        $result = $staffMembers->map(function (Staff $staff) use ($dayOfWeek, $date): array {
            $templates = ScheduleTemplate::query()
                ->where('tenant_id', $staff->tenant_id)
                ->where('staff_id', $staff->getKey())
                ->where('day_of_week', $dayOfWeek)
                ->where('status', ScheduleTemplate::STATUS_ACTIVE)
                ->where('valid_from', '<=', $date)
                ->where(function ($q) use ($date): void {
                    $q->whereNull('valid_to')
                        ->orWhere('valid_to', '>=', $date);
                })
                ->get();

            $hasException = ScheduleException::query()
                ->where('tenant_id', $staff->tenant_id)
                ->where('staff_id', $staff->getKey())
                ->where('exception_date', $date)
                ->where('status', ScheduleException::STATUS_ACTIVE)
                ->exists();

            return [
                'staffId' => $staff->getKey(),
                'fullName' => $staff->full_name,
                'designation' => $staff->designation,
                'specialty' => $staff->specialty,
                'isAvailable' => $templates->isNotEmpty() && ! $hasException,
                'templates' => $templates->map(fn (ScheduleTemplate $t): array => [
                    'startsAt' => $t->starts_at->format('H:i'),
                    'endsAt' => $t->ends_at->format('H:i'),
                    'slotMinutes' => $t->slot_minutes,
                    'capacity' => $t->capacity,
                ])->all(),
            ];
        })->values();

        return Envelope::success(data: [
            'departmentId' => $department->getKey(),
            'departmentName' => $department->name,
            'date' => $date,
            'doctors' => $result,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDoctor(Staff $staff): array
    {
        return [
            'id' => $staff->getKey(),
            'facilityId' => $staff->facility_id,
            'departmentId' => $staff->department_id,
            'department' => $staff->department ? ['id' => $staff->department->getKey(), 'name' => $staff->department->name] : null,
            'employeeCode' => $staff->employee_code,
            'fullName' => $staff->full_name,
            'designation' => $staff->designation,
            'status' => $staff->status,
            // Doctor profile
            'specialty' => $staff->specialty,
            'subSpecialty' => $staff->sub_specialty,
            'consultationFee' => $staff->consultation_fee,
            'consultationDurationMinutes' => $staff->consultation_duration_minutes,
            'bio' => $staff->bio,
            'acceptsNewPatients' => $staff->accepts_new_patients,
            'profileImageUrl' => $staff->profile_image_url,
            'availableDays' => $staff->available_days,
            'consultationTypes' => $staff->consultation_types,
        ];
    }
}
