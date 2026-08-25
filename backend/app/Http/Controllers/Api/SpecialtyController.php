<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\SpecialtyAssessment;
use App\Models\SpecialtyProfile;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reusable specialty-care controller. One controller powers all specialties
 * (oncology, dental, physiotherapy, dietetics, etc.) through the same
 * SpecialtyProfile + SpecialtyAssessment + CarePlan models.
 */
final class SpecialtyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ── Specialty Profiles ───────────────────────────────────────

    /** GET specialty-profiles — list specialty profiles, filterable by department. */
    public function listProfiles(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $departmentId = $request->query('departmentId');
        $status = $request->query('status');

        $query = SpecialtyProfile::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status));

        $profiles = $query->orderByDesc('created_at')->limit(200)->get();

        return Envelope::success(data: $profiles->map(fn (SpecialtyProfile $p): array => self::presentProfile($p))->values(), request: $request);
    }

    /** POST specialty-profiles — create a specialty profile. */
    public function storeProfile(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $request->validate([
            'patientId' => 'required|uuid',
            'departmentId' => 'required|uuid',
            'encounterId' => 'nullable|uuid',
            'primaryDiagnosis' => 'nullable|string|max:255',
            'diagnosisCode' => 'nullable|string|max:50',
            'clinicalSummary' => 'nullable|string',
            'customFields' => 'nullable|array',
            'diagnosedAt' => 'nullable|date',
        ]);

        $existing = SpecialtyProfile::where('tenant_id', (string) $context->tenantId())
            ->where('patient_id', $request->input('patientId'))
            ->where('department_id', $request->input('departmentId'))
            ->first();

        if ($existing) {
            return Envelope::success(data: self::presentProfile($existing), request: $request);
        }

        $profile = SpecialtyProfile::create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $context->facilityId(),
            'patient_id' => $request->input('patientId'),
            'department_id' => $request->input('departmentId'),
            'encounter_id' => $request->input('encounterId'),
            'primary_diagnosis' => $request->input('primaryDiagnosis'),
            'diagnosis_code' => $request->input('diagnosisCode'),
            'status' => SpecialtyProfile::STATUS_ACTIVE,
            'clinical_summary' => $request->input('clinicalSummary'),
            'custom_fields' => $request->input('customFields'),
            'diagnosed_at' => $request->input('diagnosedAt'),
            'created_by' => $this->currentStaffId($context),
        ]);

        $this->audit->record('specialty_profile.created', 'specialty_profile', $profile->getKey(), [
            'departmentId' => $profile->department_id,
            'patientId' => $profile->patient_id,
        ], $request);

        return Envelope::success(data: self::presentProfile($profile), status: 201, request: $request);
    }

    /** GET specialty-profiles/{profile} — show with assessments and care plans. */
    public function showProfile(SpecialtyProfile $specialtyProfile, Request $request): JsonResponse
    {
        AccessCheck::scoped($specialtyProfile, write: false);

        $assessments = $specialtyProfile->assessments()->orderByDesc('created_at')->limit(50)->get();
        $carePlans = $specialtyProfile->carePlans()->orderByDesc('created_at')->limit(20)->get();

        return Envelope::success(data: [
            'profile' => self::presentProfile($specialtyProfile),
            'assessments' => $assessments->map(fn (SpecialtyAssessment $a): array => self::presentAssessment($a))->values(),
            'carePlans' => $carePlans->map(fn (CarePlan $c): array => self::presentCarePlan($c))->values(),
        ], request: $request);
    }

    // ── Assessments ──────────────────────────────────────────────

    /** POST specialty-profiles/{profile}/assessments — create an assessment. */
    public function storeAssessment(Request $request, SpecialtyProfile $specialtyProfile): JsonResponse
    {
        AccessCheck::scoped($specialtyProfile, write: true);
        $context = TenantContext::current();

        $request->validate([
            'assessmentType' => 'required|string|max:100',
            'formTemplateId' => 'nullable|uuid',
            'responses' => 'nullable|array',
            'notes' => 'nullable|string',
            'assessedAt' => 'nullable|date',
        ]);

        $assessment = SpecialtyAssessment::create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $context->facilityId(),
            'specialty_profile_id' => $specialtyProfile->getKey(),
            'form_template_id' => $request->input('formTemplateId'),
            'assessment_type' => $request->input('assessmentType'),
            'status' => SpecialtyAssessment::STATUS_DRAFT,
            'responses' => $request->input('responses'),
            'notes' => $request->input('notes'),
            'assessed_by_staff_id' => $this->currentStaffId($context),
            'assessed_at' => $request->input('assessedAt') ?? now(),
            'created_by' => $this->currentStaffId($context),
        ]);

        $this->audit->record('specialty_assessment.created', 'specialty_assessment', $assessment->getKey(), [
            'assessmentType' => $assessment->assessment_type,
        ], $request);

        return Envelope::success(data: self::presentAssessment($assessment), status: 201, request: $request);
    }

    // ── Care Plans ───────────────────────────────────────────────

    /** POST specialty-profiles/{profile}/care-plans — create a care plan. */
    public function storeCarePlan(Request $request, SpecialtyProfile $specialtyProfile): JsonResponse
    {
        AccessCheck::scoped($specialtyProfile, write: true);
        $context = TenantContext::current();

        $request->validate([
            'planName' => 'required|string|max:255',
            'goals' => 'nullable|array',
            'goals.*' => 'string',
            'interventions' => 'nullable|array',
            'interventions.*' => 'string',
            'milestones' => 'nullable|array',
            'responsibleStaffId' => 'nullable|uuid',
            'startDate' => 'nullable|date',
            'targetEndDate' => 'nullable|date',
            'reviewDate' => 'nullable|date',
        ]);

        $plan = CarePlan::create([
            'specialty_profile_id' => $specialtyProfile->getKey(),
            'patient_id' => $specialtyProfile->patient_id,
            'department_id' => $specialtyProfile->department_id,
            'plan_name' => $request->input('planName'),
            'status' => CarePlan::STATUS_DRAFT,
            'goals' => $request->input('goals'),
            'interventions' => $request->input('interventions'),
            'milestones' => $request->input('milestones'),
            'responsible_staff_id' => $request->input('responsibleStaffId'),
            'start_date' => $request->input('startDate'),
            'target_end_date' => $request->input('targetEndDate'),
            'review_date' => $request->input('reviewDate'),
            'created_by' => $this->currentStaffId($context),
        ]);

        $this->audit->record('care_plan.created', 'care_plan', $plan->getKey(), [
            'planName' => $plan->plan_name,
            'specialtyProfileId' => $specialtyProfile->getKey(),
        ], $request);

        return Envelope::success(data: self::presentCarePlan($plan), status: 201, request: $request);
    }

    /** POST care-plans/{plan}/activate */
    public function activateCarePlan(CarePlan $carePlan, Request $request): JsonResponse
    {
        $context = TenantContext::current();
        if ($carePlan->status !== CarePlan::STATUS_DRAFT) {
            throw new AppExceptionsApiException(ErrorCodes::CONFLICT, 'Only a draft care plan can be activated.', 409);
        }
        $carePlan->update(['status' => CarePlan::STATUS_ACTIVE, 'updated_by' => $this->currentStaffId($context)]);
        $this->audit->record('care_plan.activated', 'care_plan', $carePlan->getKey(), [], $request);
        return Envelope::success(data: self::presentCarePlan($carePlan), request: $request);
    }

    /** POST care-plans/{plan}/complete */
    public function completeCarePlan(CarePlan $carePlan, Request $request): JsonResponse
    {
        $context = TenantContext::current();
        if ($carePlan->status !== CarePlan::STATUS_ACTIVE) {
            throw new AppExceptionsApiException(ErrorCodes::CONFLICT, 'Only an active care plan can be completed.', 409);
        }
        $carePlan->update(['status' => CarePlan::STATUS_COMPLETED, 'updated_by' => $this->currentStaffId($context)]);
        $this->audit->record('care_plan.completed', 'care_plan', $carePlan->getKey(), [], $request);
        return Envelope::success(data: self::presentCarePlan($carePlan), request: $request);
    }

    private static function presentProfile(SpecialtyProfile $p): array {
        return [
            'id' => $p->getKey(), 'patientId' => $p->patient_id, 'departmentId' => $p->department_id,
            'encounterId' => $p->encounter_id, 'primaryDiagnosis' => $p->primary_diagnosis,
            'diagnosisCode' => $p->diagnosis_code, 'status' => $p->status,
            'clinicalSummary' => $p->clinical_summary, 'customFields' => $p->custom_fields,
            'diagnosedAt' => $p->diagnosed_at?->toIso8601String(), 'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }

    private static function presentAssessment(SpecialtyAssessment $a): array {
        return [
            'id' => $a->getKey(), 'specialtyProfileId' => $a->specialty_profile_id,
            'formTemplateId' => $a->form_template_id, 'assessmentType' => $a->assessment_type,
            'status' => $a->status, 'responses' => $a->responses, 'notes' => $a->notes,
            'assessedAt' => $a->assessed_at?->toIso8601String(),
        ];
    }

    private static function presentCarePlan(CarePlan $c): array {
        return [
            'id' => $c->getKey(), 'specialtyProfileId' => $c->specialty_profile_id,
            'patientId' => $c->patient_id, 'departmentId' => $c->department_id,
            'planName' => $c->plan_name, 'status' => $c->status, 'goals' => $c->goals,
            'interventions' => $c->interventions, 'milestones' => $c->milestones,
            'responsibleStaffId' => $c->responsible_staff_id,
            'startDate' => $c->start_date?->toDateString(), 'targetEndDate' => $c->target_end_date?->toDateString(),
            'reviewDate' => $c->review_date?->toDateString(), 'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }

    private function currentStaffId(TenantContext $context): ?string {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }
}
