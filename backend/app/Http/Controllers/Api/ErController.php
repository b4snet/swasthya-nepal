<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Er\AssignTriageRequest;
use App\Http\Requests\Er\ErDispositionRequest;
use App\Http\Requests\Er\StoreErEventRequest;
use App\Http\Requests\Er\StoreErRegistrationRequest;
use App\Http\Requests\Er\StoreTriageScaleRequest;
use App\Http\Requests\Er\UpdateTriageScaleRequest;
use App\Models\Encounter;
use App\Models\ErEvent;
use App\Models\ErRegistration;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\TriageAssignment;
use App\Models\TriageScale;
use App\Services\ErService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 14 — Emergency (ROADMAP Phase 9, PRODUCT_REQUIREMENTS §6.6):
 * minimal-data registration, configurable triage, time-stamped ER events,
 * and audited disposition. Parallel ER documentation (assessment, vitals,
 * orders, treatment) reuses the existing clinical spine (clinical notes,
 * vitals, lab orders, prescriptions).
 *
 * Audit payloads carry facts and ids only — complaints, event notes, and
 * override reasons are PHI that never reach audit_events.
 */
final class ErController extends Controller
{
    public function __construct(
        private readonly ErService $er,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST er/registrations — minimal-data registration.
     */
    public function storeRegistration(StoreErRegistrationRequest $request): JsonResponse
    {
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);
        $registrar = $this->currentStaff($facility->tenant_id, $facility->getKey());

        [$registration, $patient, $encounter] = $this->er->register(
            $facility,
            $registrar,
            $request->validated('patientName'),
            $request->validated('sex'),
            $request->validated('dateOfBirth'),
            $request->validated('estimatedAge'),
            $request->validated('presentingComplaint'),
        );

        $this->audit->record(
            'er.registered',
            'er_registration',
            $registration->getKey(),
            [
                'patientId' => $patient->getKey(),
                'encounterId' => $encounter->getKey(),
                'registeredAt' => $registration->registered_at?->toIso8601String(),
                'isUnidentified' => $registration->is_unidentified,
            ],
            $request,
        );

        return Envelope::success(data: self::presentRegistration($registration, $patient, $encounter), status: 201, request: $request);
    }

    /**
     * GET organizations/{organization}/er/triage-scales — the acuity catalog.
     */
    public function indexScales(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();

        $query = TriageScale::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('level')
            ->orderBy('code');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $scales = $query->get()->map(fn (TriageScale $scale): array => self::presentScale($scale));

        return Envelope::success(data: $scales, request: $request);
    }

    /**
     * POST organizations/{organization}/er/triage-scales — configure the
     * acuity catalog.
     */
    public function storeScale(StoreTriageScaleRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);
        $context = TenantContext::current();

        $scale = TriageScale::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'level' => $request->validated('level'),
            'color' => $request->validated('color'),
            'reassessment_minutes' => $request->validated('reassessmentMinutes'),
            'is_default' => (bool) $request->validated('isDefault', false),
            'status' => TriageScale::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'triage_scale.created',
            'triage_scale',
            $scale->getKey(),
            ['facilityId' => $facility->getKey(), 'code' => $scale->code, 'level' => $scale->level],
            $request,
        );

        return Envelope::success(data: self::presentScale($scale), status: 201, request: $request);
    }

    /**
     * PATCH er/triage-scales/{triageScale} — optimistic-locked update.
     */
    public function updateScale(UpdateTriageScaleRequest $request, TriageScale $triageScale): JsonResponse
    {
        AccessCheck::scoped($triageScale, write: true);

        $context = TenantContext::current();
        $clientVersion = (int) $request->validated('lockVersion');

        $updated = DB::table('triage_scales')
            ->where('tenant_id', $triageScale->tenant_id)
            ->where('id', $triageScale->getKey())
            ->where('lock_version', $clientVersion)
            ->update([
                'code' => $request->validated('code', $triageScale->code),
                'name' => $request->validated('name', $triageScale->name),
                'level' => $request->validated('level', $triageScale->level),
                'color' => $request->validated('color', $triageScale->color),
                'reassessment_minutes' => $request->validated('reassessmentMinutes', $triageScale->reassessment_minutes),
                'is_default' => (bool) $request->validated('isDefault', $triageScale->is_default),
                'status' => $request->validated('status', $triageScale->status),
                'lock_version' => $triageScale->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(
                ErrorCodes::LOCK_CONFLICT,
                'This triage scale was changed concurrently. Reload and retry.',
                409,
            );
        }

        $this->audit->record(
            'triage_scale.updated',
            'triage_scale',
            $triageScale->getKey(),
            ['facilityId' => $triageScale->facility_id, 'code' => $request->validated('code', $triageScale->code), 'level' => (int) $request->validated('level', $triageScale->level)],
            $request,
        );

        return Envelope::success(data: self::presentScale($triageScale->refresh()), request: $request);
    }

    /**
     * POST er/encounters/{encounter}/triage — assign/reassign the acuity
     * level; overrideReason is the clinical-authority override path.
     */
    public function assignTriage(AssignTriageRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        $this->assertErEncounter($encounter);

        $context = TenantContext::current();
        $assessor = $this->currentStaff($encounter->tenant_id, $encounter->facility_id);

        $isOverride = $request->filled('overrideReason');
        if ($isOverride && ! $context->can('er:disposition')) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Triage override requires clinical authority.',
                403,
            );
        }

        $scale = TriageScale::query()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('facility_id', $encounter->facility_id)
            ->where('id', $request->validated('scaleId'))
            ->where('status', TriageScale::STATUS_ACTIVE)
            ->first();

        if ($scale === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Triage scale not found.', 404);
        }

        $assignment = $this->er->assignTriage(
            $encounter,
            $scale,
            $assessor,
            $isOverride,
            $request->validated('overrideReason'),
        );

        $this->audit->record(
            $isOverride ? 'triage.overridden' : 'triage.assigned',
            'triage_assignment',
            $assignment->getKey(),
            [
                'encounterId' => $encounter->getKey(),
                'patientId' => $encounter->patient_id,
                'scaleId' => $scale->getKey(),
                'level' => $assignment->level,
                'isOverride' => $assignment->is_override,
            ],
            $request,
        );

        return Envelope::success(data: self::presentAssignment($assignment), status: 201, request: $request);
    }

    /**
     * POST er/encounters/{encounter}/events — append a time-stamped event.
     */
    public function storeEvent(StoreErEventRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        $this->assertErEncounter($encounter);

        $actor = $this->currentStaff($encounter->tenant_id, $encounter->facility_id);

        $event = $this->er->recordEvent(
            $encounter,
            (string) $request->validated('eventType'),
            $request->validated('notes'),
            $request->date('occurredAt', null) ?? now(),
            $actor,
        );

        $this->audit->record(
            'er.event',
            'er_event',
            $event->getKey(),
            [
                'encounterId' => $encounter->getKey(),
                'patientId' => $encounter->patient_id,
                'eventType' => $event->event_type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: self::presentEvent($event), status: 201, request: $request);
    }

    /**
     * GET er/encounters/{encounter}/events — the time-stamped log.
     */
    public function indexEvents(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $this->assertErEncounter($encounter);

        $events = ErEvent::query()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ErEvent $event): array => self::presentEvent($event));

        return Envelope::success(data: $events, request: $request);
    }

    /**
     * POST er/encounters/{encounter}/disposition — audited admit/transfer/
     * discharge disposition.
     */
    public function disposition(ErDispositionRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        $this->assertErEncounter($encounter);

        $actor = $this->currentStaff($encounter->tenant_id, $encounter->facility_id);

        [$disposed, $admission, $event] = $this->er->dispose(
            $encounter,
            (string) $request->validated('disposition'),
            $request->validated('notes'),
            $request->validated('bedId'),
            $request->validated('admittingDiagnosis'),
            $actor,
        );

        $this->audit->record(
            'er.disposition',
            'er_event',
            $event->getKey(),
            [
                'encounterId' => $disposed->getKey(),
                'patientId' => $disposed->patient_id,
                'disposition' => $disposed->disposition,
                'admissionId' => $admission?->getKey(),
                'occurredAt' => $event->occurred_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: [
            'encounter' => [
                'id' => $disposed->getKey(),
                'disposition' => $disposed->disposition,
                'status' => $disposed->status,
            ],
            'admissionId' => $admission?->getKey(),
            'eventId' => $event->getKey(),
        ], request: $request);
    }

    /**
     * GET er/queue — the facility's open ER encounters, triage-driven
     * priority (most urgent level first; untriaged after triaged, oldest
     * first). The triage level IS the queue priority (ROADMAP Phase 9).
     */
    public function queue(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $rows = DB::table('encounters')
            ->leftJoin('er_registrations', function ($join): void {
                $join->on('er_registrations.encounter_id', '=', 'encounters.id')
                    ->where('er_registrations.tenant_id', '=', DB::raw('encounters.tenant_id'));
            })
            ->leftJoin('triage_assignments', function ($join): void {
                $join->on('triage_assignments.encounter_id', '=', 'encounters.id')
                    ->where('triage_assignments.tenant_id', '=', DB::raw('encounters.tenant_id'))
                    ->where('triage_assignments.status', '=', 'active');
            })
            ->where('encounters.tenant_id', $context->tenantId())
            ->where('encounters.type', Encounter::TYPE_ER)
            ->whereIn('encounters.status', [Encounter::STATUS_OPEN, Encounter::STATUS_IN_PROGRESS])
            ->when($context->facilityId() !== null, fn ($query) => $query->where('encounters.facility_id', $context->facilityId()))
            ->orderByRaw('triage_assignments.level asc nulls last')
            ->orderBy('er_registrations.registered_at')
            ->select([
                'encounters.id as encounter_id',
                'encounters.patient_id',
                'encounters.facility_id',
                'encounters.started_at',
                'er_registrations.registered_at',
                'er_registrations.presenting_complaint',
                'triage_assignments.level',
                'triage_assignments.color',
                'triage_assignments.assessed_at',
            ])
            ->get()
            ->map(function (object $row): array {
                return [
                    'encounterId' => $row->encounter_id,
                    'patientId' => $row->patient_id,
                    'facilityId' => $row->facility_id,
                    'registeredAt' => $row->registered_at !== null
                        ? Carbon::parse($row->registered_at)->toIso8601String()
                        : null,
                    'triageLevel' => $row->level !== null ? (int) $row->level : null,
                    'triageColor' => $row->color,
                    'triageAssessedAt' => $row->assessed_at !== null
                        ? Carbon::parse($row->assessed_at)->toIso8601String()
                        : null,
                ];
            });

        return Envelope::success(data: $rows, request: $request);
    }

    /**
     * The actor's staff record in the given tenant+facility.
     */
    private function currentStaff(string $tenantId, string $facilityId): Staff
    {
        $context = TenantContext::current();
        $staff = $context->user?->staff()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->facility_id !== $facilityId) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'You are not authorized to perform this action.', 403);
        }

        return $staff;
    }

    private function assertErEncounter(Encounter $encounter): void
    {
        if ($encounter->type !== Encounter::TYPE_ER) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This is not an emergency encounter.', 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRegistration(ErRegistration $registration, Patient $patient, Encounter $encounter): array
    {
        return [
            'id' => $registration->getKey(),
            'patientId' => $patient->getKey(),
            'mrn' => $patient->mrn,
            'patientName' => $patient->full_name,
            'isUnidentified' => $registration->is_unidentified,
            'encounterId' => $encounter->getKey(),
            'registeredAt' => $registration->registered_at?->toIso8601String(),
            'presentingComplaint' => $registration->presenting_complaint,
            'estimatedAge' => $registration->estimated_age,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentScale(TriageScale $scale): array
    {
        return [
            'id' => $scale->getKey(),
            'facilityId' => $scale->facility_id,
            'code' => $scale->code,
            'name' => $scale->name,
            'level' => $scale->level,
            'color' => $scale->color,
            'reassessmentMinutes' => $scale->reassessment_minutes,
            'isDefault' => $scale->is_default,
            'status' => $scale->status,
            'lockVersion' => $scale->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAssignment(TriageAssignment $assignment): array
    {
        return [
            'id' => $assignment->getKey(),
            'encounterId' => $assignment->encounter_id,
            'patientId' => $assignment->patient_id,
            'scaleId' => $assignment->triage_scale_id,
            'level' => $assignment->level,
            'color' => $assignment->color,
            'assessedBy' => $assignment->assessed_by_staff_id,
            'assessedAt' => $assignment->assessed_at?->toIso8601String(),
            'isOverride' => $assignment->is_override,
            'status' => $assignment->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentEvent(ErEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'encounterId' => $event->encounter_id,
            'patientId' => $event->patient_id,
            'eventType' => $event->event_type,
            'occurredAt' => $event->occurred_at?->toIso8601String(),
            'actorStaffId' => $event->actor_staff_id,
        ];
    }
}
