<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MultidisciplinaryReview;
use App\Models\OncologyDiagnosis;
use App\Models\OncologyEncounter;
use App\Models\OncologyProfile;
use App\Models\RtFraction;
use App\Models\RtFractionSession;
use App\Models\RtPlanApproval;
use App\Models\RtStructure;
use App\Models\RtTreatmentCourse;
use App\Models\RtTreatmentMachine;
use App\Models\RtTreatmentPlan;
use App\Models\Staff;
use App\Models\ToxicityRecord;
use App\Models\TreatmentCycle;
use App\Models\TreatmentMedication;
use App\Models\TreatmentPlan;
use App\Services\OncologyService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — Oncology & Radiotherapy Controller.
 *
 * Covers: oncology profiles, diagnoses, treatment plans, cycles,
 * toxicity, RT courses/plans/fractions/machines, structures,
 * secondary checks, and MDT reviews.
 */
final class OncologyController extends Controller
{
    public function __construct(
        private readonly OncologyService $oncology,
        private readonly AuditLogger $audit,
    ) {}

    // ──────────────────────────────────────────────────────
    //  ONCOLOGY PROFILES
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles */
    public function storeProfile(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        $validated = $request->validate([
            'patientId' => 'required|uuid',
            'primaryDiagnosis' => 'nullable|string',
            'cancerSite' => 'nullable|string',
            'histology' => 'nullable|string',
            'grade' => 'nullable|string',
            'tnmStaging' => 'nullable|string',
            'overallStage' => 'nullable|string',
            'performanceStatus' => 'nullable|string',
            'diagnosedAt' => 'nullable|date',
            'treatingPhysicianId' => 'nullable|uuid',
        ]);

        $profile = $this->oncology->createProfile(
            $context->tenantId(),
            $facilityId,
            $validated['patientId'],
            [
                'primary_diagnosis' => $validated['primaryDiagnosis'] ?? null,
                'cancer_site' => $validated['cancerSite'] ?? null,
                'histology' => $validated['histology'] ?? null,
                'grade' => $validated['grade'] ?? null,
                'tnm_staging' => $validated['tnmStaging'] ?? null,
                'overall_stage' => $validated['overallStage'] ?? null,
                'performance_status' => $validated['performanceStatus'] ?? null,
                'diagnosed_at' => $validated['diagnosedAt'] ?? null,
                'treating_physician_id' => $validated['treatingPhysicianId'] ?? null,
            ],
        );

        $this->audit->record('oncology_profile.created', 'oncology_profile', $profile->getKey(), [
            'patientId' => $profile->patient_id,
            'cancerSite' => $profile->cancer_site,
            'overallStage' => $profile->overall_stage,
        ], $request);

        return Envelope::success(data: $this->presentProfile($profile), status: 201, request: $request);
    }

    /** GET /oncology/profiles */
    public function listProfiles(Request $request): JsonResponse
    {
        $rows = OncologyProfile::query()
            ->orderByDesc('created_at')
            ->paginate(25);

        return Envelope::success(data: $rows->through(fn ($p) => $this->presentProfile($p)), request: $request);
    }

    /** GET /oncology/profiles/{profile} */
    public function showProfile(OncologyProfile $profile, Request $request): JsonResponse
    {
        AccessCheck::scoped($profile, read: true);
        $profile->load(['diagnoses', 'treatmentPlans.cycles', 'rtCourses.rtPlans.fractions']);

        return Envelope::success(data: $this->presentProfile($profile), request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  DIAGNOSES
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles/{profile}/diagnoses */
    public function storeDiagnosis(Request $request, OncologyProfile $profile): JsonResponse
    {
        AccessCheck::scoped($profile, write: true);

        $validated = $request->validate([
            'diagnosisCode' => 'nullable|string',
            'description' => 'required|string',
            'cancerSite' => 'required|string',
            'histology' => 'nullable|string',
            'grade' => 'nullable|string',
            'tnmT' => 'nullable|string',
            'tnmN' => 'nullable|string',
            'tnmM' => 'nullable|string',
            'overallStage' => 'required|string',
            'diagnosisType' => 'nullable|string',
            'diagnosedAt' => 'nullable|date',
        ]);

        $dx = $this->oncology->addDiagnosis($profile, [
            'diagnosis_code' => $validated['diagnosisCode'] ?? null,
            'description' => $validated['description'],
            'cancer_site' => $validated['cancerSite'],
            'histology' => $validated['histology'] ?? null,
            'grade' => $validated['grade'] ?? null,
            'tnm_t' => $validated['tnmT'] ?? null,
            'tnm_n' => $validated['tnmN'] ?? null,
            'tnm_m' => $validated['tnmM'] ?? null,
            'overall_stage' => $validated['overallStage'],
            'diagnosis_type' => $validated['diagnosisType'] ?? 'primary',
            'diagnosed_at' => $validated['diagnosedAt'] ?? null,
        ]);

        $this->audit->record('oncology_diagnosis.created', 'oncology_diagnosis', $dx->getKey(), [
            'profileId' => $profile->getKey(),
            'cancerSite' => $dx->cancer_site,
            'overallStage' => $dx->overall_stage,
        ], $request);

        return Envelope::success(data: $this->presentDiagnosis($dx), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  TREATMENT PLANS
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles/{profile}/treatment-plans */
    public function storeTreatmentPlan(Request $request, OncologyProfile $profile): JsonResponse
    {
        AccessCheck::scoped($profile, write: true);
        $context = TenantContext::current();
        $provider = $this->currentStaff($profile->tenant_id, $profile->facility_id);

        $validated = $request->validate([
            'planType' => 'required|string',
            'protocolCode' => 'nullable|string',
            'protocolName' => 'nullable|string',
            'intent' => 'nullable|string',
            'lineOfTherapy' => 'nullable|string',
            'plannedCycles' => 'nullable|integer|min:1',
            'encounterId' => 'nullable|uuid',
            'medications' => 'nullable|array',
            'medications.*.medicationName' => 'required|string',
            'medications.*.dose' => 'required|numeric|min:0.01',
            'medications.*.doseUnit' => 'required|string',
            'medications.*.route' => 'required|string',
            'medications.*.frequency' => 'required|string',
        ]);

        $plan = $this->oncology->createTreatmentPlan($profile, [
            'plan_type' => $validated['planType'],
            'protocol_code' => $validated['protocolCode'] ?? null,
            'protocol_name' => $validated['protocolName'] ?? null,
            'intent' => $validated['intent'] ?? 'curative',
            'line_of_therapy' => $validated['lineOfTherapy'] ?? 'first',
            'planned_cycles' => $validated['plannedCycles'] ?? null,
            'encounter_id' => $validated['encounterId'] ?? null,
        ], $provider->getKey());

        if (! empty($validated['medications'])) {
            foreach ($validated['medications'] as $med) {
                TreatmentMedication::create([
                    'tenant_id' => $plan->tenant_id,
                    'facility_id' => $plan->facility_id,
                    'treatment_plan_id' => $plan->id,
                    'medication_name' => $med['medicationName'],
                    'dose' => $med['dose'],
                    'dose_unit' => $med['doseUnit'],
                    'route' => $med['route'],
                    'frequency' => $med['frequency'],
                ]);
            }
        }

        $this->audit->record('treatment_plan.created', 'treatment_plan', $plan->getKey(), [
            'profileId' => $profile->getKey(),
            'planType' => $plan->plan_type,
            'protocolCode' => $plan->protocol_code,
        ], $request);

        return Envelope::success(data: $this->presentPlan($plan->fresh(['medications'])), status: 201, request: $request);
    }

    /** GET /oncology/treatment-plans/{plan} */
    public function showTreatmentPlan(TreatmentPlan $plan, Request $request): JsonResponse
    {
        AccessCheck::scoped($plan, read: true);
        $plan->load(['cycles', 'medications']);

        return Envelope::success(data: $this->presentPlan($plan), request: $request);
    }

    /** POST /oncology/treatment-plans/{plan}/start */
    public function startCycle(Request $request, TreatmentPlan $plan): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);

        $validated = $request->validate([
            'scheduledAt' => 'nullable|date',
        ]);

        $cycle = $this->oncology->startCycle($plan);

        if (! empty($validated['scheduledAt'])) {
            $cycle->update(['scheduled_at' => $validated['scheduledAt']]);
        }

        $this->audit->record('treatment_cycle.started', 'treatment_cycle', $cycle->getKey(), [
            'planId' => $plan->getKey(),
            'cycleNumber' => $cycle->cycle_number,
        ], $request);

        return Envelope::success(data: $this->presentCycle($cycle), status: 201, request: $request);
    }

    /** POST /oncology/cycles/{cycle}/complete */
    public function completeCycle(TreatmentCycle $cycle, Request $request): JsonResponse
    {
        AccessCheck::scoped($cycle, write: true);

        $cycle->update([
            'status' => TreatmentCycle::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->audit->record('treatment_cycle.completed', 'treatment_cycle', $cycle->getKey(), [
            'planId' => $cycle->treatment_plan_id,
            'cycleNumber' => $cycle->cycle_number,
        ], $request);

        return Envelope::success(data: $this->presentCycle($cycle->fresh()), request: $request);
    }

    /** POST /oncology/cycles/{cycle}/toxicity */
    public function storeToxicity(Request $request, TreatmentCycle $cycle): JsonResponse
    {
        AccessCheck::scoped($cycle, write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'patientId' => 'required|uuid',
            'toxicityType' => 'required|string',
            'ctcaeGrade' => 'required|string',
            'description' => 'nullable|string',
            'managementAction' => 'nullable|string',
            'outcome' => 'nullable|string',
            'doseModified' => 'nullable|boolean',
            'doseModification' => 'nullable|string',
            'onsetAt' => 'nullable|date',
        ]);

        $record = $this->oncology->recordToxicity($cycle, [
            'patient_id' => $validated['patientId'],
            'toxicity_type' => $validated['toxicityType'],
            'ctcae_grade' => $validated['ctcaeGrade'],
            'description' => $validated['description'] ?? null,
            'management_action' => $validated['managementAction'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'dose_modified' => $validated['doseModified'] ?? false,
            'dose_modification' => $validated['doseModification'] ?? null,
            'onset_at' => $validated['onsetAt'] ?? null,
        ], $context->user?->getKey() ?? '');

        $this->audit->record('toxicity_record.created', 'toxicity_record', $record->getKey(), [
            'cycleId' => $cycle->getKey(),
            'toxicityType' => $record->toxicity_type,
            'ctcaeGrade' => $record->ctcae_grade,
        ], $request);

        return Envelope::success(data: $this->presentToxicity($record), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  ONCOLOGY ENCOUNTERS
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles/{profile}/encounters */
    public function storeOncologyEncounter(Request $request, OncologyProfile $profile): JsonResponse
    {
        AccessCheck::scoped($profile, write: true);

        $validated = $request->validate([
            'encounterId' => 'required|uuid',
            'encounterType' => 'required|string',
            'performanceStatus' => 'nullable|string',
            'clinicalSummary' => 'nullable|string',
            'treatmentResponse' => 'nullable|string',
            'planNotes' => 'nullable|string',
        ]);

        $enc = OncologyEncounter::create([
            'tenant_id' => $profile->tenant_id,
            'facility_id' => $profile->facility_id,
            'encounter_id' => $validated['encounterId'],
            'oncology_profile_id' => $profile->id,
            'encounter_type' => $validated['encounterType'],
            'performance_status' => $validated['performanceStatus'] ?? null,
            'clinical_summary' => $validated['clinicalSummary'] ?? null,
            'treatment_response' => $validated['treatmentResponse'] ?? null,
            'plan_notes' => $validated['planNotes'] ?? null,
        ]);

        $this->audit->record('oncology_encounter.created', 'oncology_encounter', $enc->getKey(), [
            'profileId' => $profile->getKey(),
            'encounterType' => $enc->encounter_type,
        ], $request);

        return Envelope::success(data: $this->presentOncologyEncounter($enc), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  MULTIDISCIPLINARY REVIEW
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles/{profile}/mdt-reviews */
    public function storeMdtReview(Request $request, OncologyProfile $profile): JsonResponse
    {
        AccessCheck::scoped($profile, write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'reviewDate' => 'required|date',
            'decision' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'attendees' => 'nullable|array',
        ]);

        $review = MultidisciplinaryReview::create([
            'tenant_id' => $profile->tenant_id,
            'facility_id' => $profile->facility_id,
            'oncology_profile_id' => $profile->id,
            'review_date' => $validated['reviewDate'],
            'decision' => $validated['decision'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'attendees' => $validated['attendees'] ?? [],
            'reviewed_by_staff_id' => $context->user?->getKey(),
        ]);

        $this->audit->record('mdt_review.created', 'multidisciplinary_review', $review->getKey(), [
            'profileId' => $profile->getKey(),
            'reviewDate' => $review->review_date->toIso8601String(),
        ], $request);

        return Envelope::success(data: $this->presentMdtReview($review), status: 201, request: $request);
    }

    /** GET /oncology/profiles/{profile}/mdt-reviews */
    public function listMdtReviews(OncologyProfile $profile, Request $request): JsonResponse
    {
        AccessCheck::scoped($profile, read: true);
        $reviews = $profile->multidisciplinaryReviews()->orderByDesc('review_date')->get()
            ->map(fn ($r) => $this->presentMdtReview($r));

        return Envelope::success(data: $reviews, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  RT COURSES
    // ──────────────────────────────────────────────────────

    /** POST /oncology/profiles/{profile}/rt-courses */
    public function storeRtCourse(Request $request, OncologyProfile $profile): JsonResponse
    {
        AccessCheck::scoped($profile, write: true);
        $provider = $this->currentStaff($profile->tenant_id, $profile->facility_id);

        $validated = $request->validate([
            'intent' => 'nullable|string',
            'totalFractions' => 'required|integer|min:1',
            'totalDoseCgy' => 'required|numeric|min:1',
            'treatmentPlanId' => 'nullable|uuid',
        ]);

        $course = $this->oncology->createRtCourse($profile, [
            'intent' => $validated['intent'] ?? 'curative',
            'total_fractions' => $validated['totalFractions'],
            'total_dose_cgy' => $validated['totalDoseCgy'],
            'treatment_plan_id' => $validated['treatmentPlanId'] ?? null,
        ], $provider->getKey());

        $this->audit->record('rt_course.created', 'rt_treatment_course', $course->getKey(), [
            'profileId' => $profile->getKey(),
            'totalFractions' => $course->total_fractions,
            'totalDoseCgy' => $course->total_dose_cgy,
        ], $request);

        return Envelope::success(data: $this->presentRtCourse($course), status: 201, request: $request);
    }

    /** GET /oncology/rt-courses/{course} */
    public function showRtCourse(RtTreatmentCourse $course, Request $request): JsonResponse
    {
        AccessCheck::scoped($course, read: true);
        $course->load(['rtPlans.fractions.sessions', 'rtPlans.structures.doseConstraints', 'rtPlans.approvals']);

        return Envelope::success(data: $this->presentRtCourse($course), request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  RT PLANS
    // ──────────────────────────────────────────────────────

    /** POST /oncology/rt-courses/{course}/plans */
    public function storeRtPlan(Request $request, RtTreatmentCourse $course): JsonResponse
    {
        AccessCheck::scoped($course, write: true);
        $provider = $this->currentStaff($course->tenant_id, $course->facility_id);

        $validated = $request->validate([
            'planName' => 'required|string',
            'technique' => 'required|string',
            'energy' => 'nullable|string',
            'fractionDoseCgy' => 'required|integer|min:1',
            'numFractions' => 'required|integer|min:1',
            'totalDoseCgy' => 'required|numeric|min:1',
            'planNote' => 'nullable|string',
        ]);

        $plan = $this->oncology->createRtPlan($course, [
            'plan_name' => $validated['planName'],
            'technique' => $validated['technique'],
            'energy' => $validated['energy'] ?? null,
            'fraction_dose_cgy' => $validated['fractionDoseCgy'],
            'num_fractions' => $validated['numFractions'],
            'total_dose_cgy' => $validated['totalDoseCgy'],
            'plan_note' => $validated['planNote'] ?? null,
        ], $provider->getKey());

        // Create fraction placeholders
        for ($i = 1; $i <= $validated['numFractions']; $i++) {
            RtFraction::create([
                'tenant_id' => $plan->tenant_id,
                'facility_id' => $plan->facility_id,
                'rt_plan_id' => $plan->id,
                'fraction_number' => $i,
                'dose_cgy' => $validated['fractionDoseCgy'],
            ]);
        }

        $this->audit->record('rt_plan.created', 'rt_treatment_plan', $plan->getKey(), [
            'courseId' => $course->getKey(),
            'technique' => $plan->technique,
            'totalDoseCgy' => $plan->total_dose_cgy,
        ], $request);

        return Envelope::success(data: $this->presentRtPlan($plan->fresh(['fractions'])), status: 201, request: $request);
    }

    /** POST /oncology/rt-plans/{plan}/submit */
    public function submitRtPlan(RtTreatmentPlan $plan, Request $request): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);
        $updated = $this->oncology->submitForReview($plan);

        $this->audit->record('rt_plan.submitted', 'rt_treatment_plan', $plan->getKey(), [
            'courseId' => $plan->rt_course_id,
        ], $request);

        return Envelope::success(data: $this->presentRtPlan($updated), request: $request);
    }

    /** POST /oncology/rt-plans/{plan}/physicist-check */
    public function physicistCheck(Request $request, RtTreatmentPlan $plan): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);
        $provider = $this->currentStaff($plan->tenant_id, $plan->facility_id);

        $validated = $request->validate([
            'approved' => 'required|boolean',
            'checklist' => 'nullable|array',
            'comments' => 'nullable|string',
        ]);

        $approval = $this->oncology->physicistCheck(
            $plan,
            $provider->getKey(),
            $validated['checklist'] ?? [],
            $validated['approved'],
            $validated['comments'] ?? null,
        );

        $this->audit->record('rt_plan.phicist_check', 'rt_plan_approval', $approval->getKey(), [
            'planId' => $plan->getKey(),
            'approved' => $validated['approved'],
        ], $request);

        return Envelope::success(data: $this->presentApproval($approval), status: 201, request: $request);
    }

    /** POST /oncology/rt-plans/{plan}/secondary-check */
    public function secondaryCheck(Request $request, RtTreatmentPlan $plan): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);
        $provider = $this->currentStaff($plan->tenant_id, $plan->facility_id);

        $validated = $request->validate([
            'passed' => 'required|boolean',
            'checklist' => 'nullable|array',
            'comments' => 'nullable|string',
        ]);

        $approval = $this->oncology->secondaryCheck(
            $plan,
            $provider->getKey(),
            $validated['checklist'] ?? [],
            $validated['passed'],
            $validated['comments'] ?? null,
        );

        $this->audit->record('rt_plan.secondary_check', 'rt_plan_approval', $approval->getKey(), [
            'planId' => $plan->getKey(),
            'passed' => $validated['passed'],
        ], $request);

        return Envelope::success(data: $this->presentApproval($approval), status: 201, request: $request);
    }

    /** POST /oncology/rt-plans/{plan}/ro-approval */
    public function roApproval(Request $request, RtTreatmentPlan $plan): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);
        $provider = $this->currentStaff($plan->tenant_id, $plan->facility_id);

        $validated = $request->validate([
            'approved' => 'required|boolean',
            'comments' => 'nullable|string',
        ]);

        $approval = $this->oncology->roApproval(
            $plan,
            $provider->getKey(),
            $validated['approved'],
            $validated['comments'] ?? null,
        );

        $this->audit->record('rt_plan.ro_approval', 'rt_plan_approval', $approval->getKey(), [
            'planId' => $plan->getKey(),
            'approved' => $validated['approved'],
        ], $request);

        return Envelope::success(data: $this->presentApproval($approval), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  RT FRACTIONS
    // ──────────────────────────────────────────────────────

    /** GET /oncology/rt-plans/{plan}/fractions */
    public function listFractions(RtTreatmentPlan $plan, Request $request): JsonResponse
    {
        AccessCheck::scoped($plan, read: true);
        $fractions = $plan->fractions()->orderBy('fraction_number')->with('sessions')->get()
            ->map(fn ($f) => $this->presentFraction($f));

        return Envelope::success(data: $fractions, request: $request);
    }

    /** POST /oncology/rt-fractions/{fraction}/deliver */
    public function deliverFraction(Request $request, RtFraction $fraction): JsonResponse
    {
        AccessCheck::scoped($fraction, write: true);

        $validated = $request->validate([
            'machineId' => 'required|uuid',
            'deliveredDoseCgy' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $provider = $this->currentStaff($fraction->tenant_id, $fraction->facility_id);

        $session = $this->oncology->deliverFraction(
            $fraction,
            $validated['machineId'],
            $provider->getKey(),
            $validated['deliveredDoseCgy'],
            $validated['notes'] ?? null,
        );

        $this->audit->record('rt_fraction.delivered', 'rt_fraction_session', $session->getKey(), [
            'fractionId' => $fraction->getKey(),
            'deliveredDoseCgy' => $session->delivered_dose_cgy,
        ], $request);

        return Envelope::success(data: $this->presentSession($session), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  RT MACHINES
    // ──────────────────────────────────────────────────────

    /** GET /oncology/rt-machines */
    public function listMachines(Request $request): JsonResponse
    {
        $machines = RtTreatmentMachine::query()
            ->where('status', '!=', RtTreatmentMachine::STATUS_DECOMMISSIONED)
            ->orderBy('code')
            ->get()
            ->map(fn ($m) => $this->presentMachine($m));

        return Envelope::success(data: $machines, request: $request);
    }

    /** POST /oncology/rt-machines */
    public function storeMachine(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $validated = $request->validate([
            'code' => 'required|string|unique:rt_treatment_machines,code',
            'name' => 'required|string',
            'machineType' => 'required|string',
            'manufacturer' => 'nullable|string',
            'model' => 'nullable|string',
            'energyRange' => 'nullable|string',
            'dailyCapacity' => 'nullable|integer|min:1',
            'capabilities' => 'nullable|array',
        ]);

        $machine = RtTreatmentMachine::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'machine_type' => $validated['machineType'],
            'manufacturer' => $validated['manufacturer'] ?? null,
            'model' => $validated['model'] ?? null,
            'energy_range' => $validated['energyRange'] ?? null,
            'daily_capacity' => $validated['dailyCapacity'] ?? 30,
            'capabilities' => $validated['capabilities'] ?? [],
        ]);

        return Envelope::success(data: $this->presentMachine($machine), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  RT STRUCTURES
    // ──────────────────────────────────────────────────────

    /** POST /oncology/rt-plans/{plan}/structures */
    public function storeStructure(Request $request, RtTreatmentPlan $plan): JsonResponse
    {
        AccessCheck::scoped($plan, write: true);

        $validated = $request->validate([
            'structureName' => 'required|string',
            'structureType' => 'required|string',
            'volumeCc' => 'nullable|numeric|min:0',
            'meanDoseCgy' => 'nullable|numeric|min:0',
            'maxDoseCgy' => 'nullable|numeric|min:0',
        ]);

        $structure = RtStructure::create([
            'tenant_id' => $plan->tenant_id,
            'facility_id' => $plan->facility_id,
            'rt_plan_id' => $plan->id,
            'structure_name' => $validated['structureName'],
            'structure_type' => $validated['structureType'],
            'volume_cc' => $validated['volumeCc'] ?? null,
            'mean_dose_cgy' => $validated['meanDoseCgy'] ?? null,
            'max_dose_cgy' => $validated['maxDoseCgy'] ?? null,
        ]);

        return Envelope::success(data: $this->presentStructure($structure), status: 201, request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  STATISTICS
    // ──────────────────────────────────────────────────────

    /** GET /oncology/stats */
    public function stats(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();

        $base = OncologyProfile::where('tenant_id', $tenantId);
        if ($facilityId) {
            $base->where('facility_id', $facilityId);
        }

        $active = (clone $base)->where('status', 'active')->count();
        $inRemission = (clone $base)->where('status', 'in_remission')->count();

        $plansBase = TreatmentPlan::where('tenant_id', $tenantId);
        if ($facilityId) {
            $plansBase->where('facility_id', $facilityId);
        }

        $activePlans = (clone $plansBase)->where('status', 'active')->count();

        $rtBase = RtTreatmentCourse::where('tenant_id', $tenantId);
        if ($facilityId) {
            $rtBase->where('facility_id', $facilityId);
        }

        $activeRtCourses = (clone $rtBase)->where('status', 'in_progress')->count();

        return Envelope::success(data: [
            'active_profiles' => $active,
            'in_remission' => $inRemission,
            'active_treatment_plans' => $activePlans,
            'active_rt_courses' => $activeRtCourses,
        ], request: $request);
    }

    // ──────────────────────────────────────────────────────
    //  PRESENTERS
    // ──────────────────────────────────────────────────────

    private function currentStaff(string $tenantId, string $facilityId): Staff
    {
        $context = TenantContext::current();
        $staff = $context->user?->staff()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->facility_id !== $facilityId) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'Not authorized.', 403);
        }

        return $staff;
    }

    /** @return array<string, mixed> */
    private function presentProfile(OncologyProfile $p): array
    {
        return [
            'id' => $p->getKey(),
            'patientId' => $p->patient_id,
            'primaryDiagnosis' => $p->primary_diagnosis,
            'cancerSite' => $p->cancer_site,
            'histology' => $p->histology,
            'grade' => $p->grade,
            'tnmStaging' => $p->tnm_staging,
            'overallStage' => $p->overall_stage,
            'performanceStatus' => $p->performance_status,
            'status' => $p->status,
            'diagnosedAt' => $p->diagnosed_at?->toIso8601String(),
            'treatingPhysicianId' => $p->treating_physician_id,
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDiagnosis(OncologyDiagnosis $dx): array
    {
        return [
            'id' => $dx->getKey(),
            'diagnosisCode' => $dx->diagnosis_code,
            'description' => $dx->description,
            'cancerSite' => $dx->cancer_site,
            'histology' => $dx->histology,
            'grade' => $dx->grade,
            'tnmT' => $dx->tnm_t,
            'tnmN' => $dx->tnm_n,
            'tnmM' => $dx->tnm_m,
            'overallStage' => $dx->overall_stage,
            'diagnosisType' => $dx->diagnosis_type,
            'diagnosedAt' => $dx->diagnosed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPlan(TreatmentPlan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'oncologyProfileId' => $plan->oncology_profile_id,
            'planType' => $plan->plan_type,
            'protocolCode' => $plan->protocol_code,
            'protocolName' => $plan->protocol_name,
            'intent' => $plan->intent,
            'status' => $plan->status,
            'lineOfTherapy' => $plan->line_of_therapy,
            'plannedCycles' => $plan->planned_cycles,
            'completedCycles' => $plan->completed_cycles,
            'startedAt' => $plan->started_at?->toIso8601String(),
            'completedAt' => $plan->completed_at?->toIso8601String(),
            'medications' => $plan->relationLoaded('medications')
                ? $plan->medications->map(fn ($m) => [
                    'id' => $m->getKey(),
                    'medicationName' => $m->medication_name,
                    'dose' => $m->dose,
                    'doseUnit' => $m->dose_unit,
                    'route' => $m->route,
                    'frequency' => $m->frequency,
                ])->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentCycle(TreatmentCycle $c): array
    {
        return [
            'id' => $c->getKey(),
            'treatmentPlanId' => $c->treatment_plan_id,
            'cycleNumber' => $c->cycle_number,
            'status' => $c->status,
            'scheduledAt' => $c->scheduled_at?->toIso8601String(),
            'startedAt' => $c->started_at?->toIso8601String(),
            'completedAt' => $c->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentToxicity(ToxicityRecord $t): array
    {
        return [
            'id' => $t->getKey(),
            'toxicityType' => $t->toxicity_type,
            'ctcaeGrade' => $t->ctcae_grade,
            'description' => $t->description,
            'managementAction' => $t->management_action,
            'outcome' => $t->outcome,
            'doseModified' => $t->dose_modified,
            'onsetAt' => $t->onset_at?->toIso8601String(),
            'resolvedAt' => $t->resolved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOncologyEncounter(OncologyEncounter $e): array
    {
        return [
            'id' => $e->getKey(),
            'encounterId' => $e->encounter_id,
            'encounterType' => $e->encounter_type,
            'performanceStatus' => $e->performance_status,
            'clinicalSummary' => $e->clinical_summary,
            'treatmentResponse' => $e->treatment_response,
            'planNotes' => $e->plan_notes,
        ];
    }

    /** @return array<string, mixed> */
    private function presentMdtReview(MultidisciplinaryReview $r): array
    {
        return [
            'id' => $r->getKey(),
            'reviewDate' => $r->review_date->toIso8601String(),
            'decision' => $r->decision,
            'recommendations' => $r->recommendations,
            'attendees' => $r->attendees,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRtCourse(RtTreatmentCourse $c): array
    {
        return [
            'id' => $c->getKey(),
            'oncologyProfileId' => $c->oncology_profile_id,
            'intent' => $c->intent,
            'status' => $c->status,
            'totalFractions' => $c->total_fractions,
            'completedFractions' => $c->completed_fractions,
            'totalDoseCgy' => $c->total_dose_cgy,
            'startedAt' => $c->started_at?->toIso8601String(),
            'completedAt' => $c->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRtPlan(RtTreatmentPlan $p): array
    {
        return [
            'id' => $p->getKey(),
            'rtCourseId' => $p->rt_course_id,
            'planName' => $p->plan_name,
            'technique' => $p->technique,
            'energy' => $p->energy,
            'fractionDoseCgy' => $p->fraction_dose_cgy,
            'numFractions' => $p->num_fractions,
            'totalDoseCgy' => $p->total_dose_cgy,
            'status' => $p->status,
            'physicistApprovedAt' => $p->physicist_approved_at?->toIso8601String(),
            'roApprovedAt' => $p->ro_approved_at?->toIso8601String(),
            'requiresSecondaryCheck' => $p->requiresSecondaryCheck(),
            'isFullyApproved' => $p->isFullyApproved(),
            'fractions' => $p->relationLoaded('fractions')
                ? $p->fractions->map(fn ($f) => $this->presentFraction($f))->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentApproval(RtPlanApproval $a): array
    {
        return [
            'id' => $a->getKey(),
            'rtPlanId' => $a->rt_plan_id,
            'approvalType' => $a->approval_type,
            'status' => $a->status,
            'decision' => $a->decision,
            'comments' => $a->comments,
            'approvedAt' => $a->approved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentFraction(RtFraction $f): array
    {
        return [
            'id' => $f->getKey(),
            'fractionNumber' => $f->fraction_number,
            'doseCgy' => $f->dose_cgy,
            'status' => $f->status,
            'scheduledDate' => $f->scheduled_date?->toIso8601String(),
            'sessions' => $f->relationLoaded('sessions')
                ? $f->sessions->map(fn ($s) => $this->presentSession($s))->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentSession(RtFractionSession $s): array
    {
        return [
            'id' => $s->getKey(),
            'rtFractionId' => $s->rt_fraction_id,
            'machineId' => $s->machine_id,
            'status' => $s->status,
            'sessionStart' => $s->session_start?->toIso8601String(),
            'sessionEnd' => $s->session_end?->toIso8601String(),
            'deliveredDoseCgy' => $s->delivered_dose_cgy,
        ];
    }

    /** @return array<string, mixed> */
    private function presentMachine(RtTreatmentMachine $m): array
    {
        return [
            'id' => $m->getKey(),
            'code' => $m->code,
            'name' => $m->name,
            'machineType' => $m->machine_type,
            'manufacturer' => $m->manufacturer,
            'model' => $m->model,
            'energyRange' => $m->energy_range,
            'status' => $m->status,
            'dailyCapacity' => $m->daily_capacity,
            'capabilities' => $m->capabilities,
        ];
    }

    /** @return array<string, mixed> */
    private function presentStructure(RtStructure $s): array
    {
        return [
            'id' => $s->getKey(),
            'structureName' => $s->structure_name,
            'structureType' => $s->structure_type,
            'volumeCc' => $s->volume_cc,
            'meanDoseCgy' => $s->mean_dose_cgy,
            'maxDoseCgy' => $s->max_dose_cgy,
        ];
    }
}
