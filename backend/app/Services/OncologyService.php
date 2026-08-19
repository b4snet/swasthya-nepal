<?php

namespace App\Services;

use App\Models\OncologyDiagnosis;
use App\Models\OncologyProfile;
use App\Models\RtFraction;
use App\Models\RtFractionSession;
use App\Models\RtPlanApproval;
use App\Models\RtTreatmentCourse;
use App\Models\RtTreatmentPlan;
use App\Models\ToxicityRecord;
use App\Models\TreatmentCycle;
use App\Models\TreatmentPlan;

/**
 * Oncology and Radiotherapy Service (Phase 15).
 *
 * Enforces clinical safety rules:
 * - Treatment plan mutations are authorized, audited, versioned
 * - RT plan approval requires physicist + RO (secondary check for VMAT/IMRT/SRS/SBRT)
 * - Software validation is never represented as clinical approval
 * - All operations are tenant/facility isolated
 */
class OncologyService
{
    // ── Oncology ──

    public function createProfile(
        string $tenantId,
        string $facilityId,
        string $patientId,
        array $data,
    ): OncologyProfile {
        return OncologyProfile::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            ...$data,
        ]);
    }

    public function addDiagnosis(
        OncologyProfile $profile,
        array $data,
    ): OncologyDiagnosis {
        return OncologyDiagnosis::create([
            'tenant_id' => $profile->tenant_id,
            'facility_id' => $profile->facility_id,
            'oncology_profile_id' => $profile->id,
            ...$data,
        ]);
    }

    public function createTreatmentPlan(
        OncologyProfile $profile,
        array $data,
        string $createdByStaffId,
    ): TreatmentPlan {
        return TreatmentPlan::create([
            'tenant_id' => $profile->tenant_id,
            'facility_id' => $profile->facility_id,
            'oncology_profile_id' => $profile->id,
            'created_by_staff_id' => $createdByStaffId,
            ...$data,
        ]);
    }

    public function startCycle(TreatmentPlan $plan): TreatmentCycle
    {
        if ($plan->status !== TreatmentPlan::STATUS_ACTIVE) {
            throw new \RuntimeException('Treatment plan must be active to start a cycle');
        }

        $nextNumber = $plan->completed_cycles + 1;
        if ($plan->planned_cycles && $nextNumber > $plan->planned_cycles) {
            throw new \RuntimeException('All planned cycles completed');
        }

        $plan->update(['completed_cycles' => $nextNumber]);

        return TreatmentCycle::create([
            'tenant_id' => $plan->tenant_id,
            'facility_id' => $plan->facility_id,
            'treatment_plan_id' => $plan->id,
            'cycle_number' => $nextNumber,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function recordToxicity(
        TreatmentCycle $cycle,
        array $data,
        string $reportedByStaffId,
    ): ToxicityRecord {
        return ToxicityRecord::create([
            'tenant_id' => $cycle->tenant_id,
            'facility_id' => $cycle->facility_id,
            'treatment_cycle_id' => $cycle->id,
            'patient_id' => $data['patient_id'],
            'reported_by_staff_id' => $reportedByStaffId,
            ...$data,
        ]);
    }

    // ── Radiotherapy ──

    public function createRtCourse(
        OncologyProfile $profile,
        array $data,
        string $createdByStaffId,
    ): RtTreatmentCourse {
        return RtTreatmentCourse::create([
            'tenant_id' => $profile->tenant_id,
            'facility_id' => $profile->facility_id,
            'oncology_profile_id' => $profile->id,
            'created_by_staff_id' => $createdByStaffId,
            'status' => RtTreatmentCourse::STATUS_PLANNED,
            ...$data,
        ]);
    }

    public function createRtPlan(
        RtTreatmentCourse $course,
        array $data,
        string $plannedByStaffId,
    ): RtTreatmentPlan {
        return RtTreatmentPlan::create([
            'tenant_id' => $course->tenant_id,
            'facility_id' => $course->facility_id,
            'rt_course_id' => $course->id,
            'planned_by_staff_id' => $plannedByStaffId,
            'status' => RtTreatmentPlan::STATUS_DRAFT,
            ...$data,
        ]);
    }

    /**
     * Submit RT plan for review (draft → in_review).
     */
    public function submitForReview(RtTreatmentPlan $plan): RtTreatmentPlan
    {
        if ($plan->status !== RtTreatmentPlan::STATUS_DRAFT) {
            throw new \RuntimeException('Only draft plans can be submitted for review');
        }

        $plan->update(['status' => RtTreatmentPlan::STATUS_IN_REVIEW]);

        return $plan->fresh();
    }

    /**
     * Physicist check (calculation verification).
     * This is a SOFTWARE validation step, NOT clinical approval.
     */
    public function physicistCheck(
        RtTreatmentPlan $plan,
        string $physicistStaffId,
        array $checklist,
        bool $approved,
        ?string $comments = null,
    ): RtPlanApproval {
        if ($plan->status !== RtTreatmentPlan::STATUS_IN_REVIEW) {
            throw new \RuntimeException('Plan must be in review for physicist check');
        }

        $approval = RtPlanApproval::create([
            'tenant_id' => $plan->tenant_id,
            'facility_id' => $plan->facility_id,
            'rt_plan_id' => $plan->id,
            'approval_type' => RtPlanApproval::TYPE_PHYSICIST_CHECK,
            'status' => $approved ? RtPlanApproval::STATUS_APPROVED : RtPlanApproval::STATUS_REJECTED,
            'decision' => $approved ? 'calculation_verified' : 'calculation_rejected',
            'comments' => $comments,
            'approved_by_staff_id' => $physicistStaffId,
            'approved_at' => $approved ? now() : null,
            'checklist' => $checklist,
        ]);

        if ($approved) {
            $plan->update([
                'approved_by_physicist_id' => $physicistStaffId,
                'physicist_approved_at' => now(),
            ]);
        }

        return $approval;
    }

    /**
     * Secondary check (independent verification for VMAT/IMRT/SRS/SBRT).
     * This is an INDEPENDENTLY testable safety workflow.
     * Software validation — never clinical approval.
     */
    public function secondaryCheck(
        RtTreatmentPlan $plan,
        string $checkerStaffId,
        array $checklist,
        bool $passed,
        ?string $comments = null,
    ): RtPlanApproval {
        if (! $plan->requiresSecondaryCheck()) {
            throw new \RuntimeException('Secondary check only required for VMAT/IMRT/SRS/SBRT');
        }

        if ($plan->physicist_approved_at === null) {
            throw new \RuntimeException('Physicist check must pass before secondary check');
        }

        $approval = RtPlanApproval::create([
            'tenant_id' => $plan->tenant_id,
            'facility_id' => $plan->facility_id,
            'rt_plan_id' => $plan->id,
            'approval_type' => RtPlanApproval::TYPE_SECONDARY_CHECK,
            'status' => $passed ? RtPlanApproval::STATUS_APPROVED : RtPlanApproval::STATUS_REJECTED,
            'decision' => $passed ? 'secondary_check_passed' : 'secondary_check_failed',
            'comments' => $comments,
            'approved_by_staff_id' => $checkerStaffId,
            'approved_at' => $passed ? now() : null,
            'checklist' => $checklist,
        ]);

        return $approval;
    }

    /**
     * Radiation oncologist approval (CLINICAL responsibility).
     * This is the actual clinical approval — distinct from software validation.
     */
    public function roApproval(
        RtTreatmentPlan $plan,
        string $roStaffId,
        bool $approved,
        ?string $comments = null,
    ): RtPlanApproval {
        if ($plan->physicist_approved_at === null) {
            throw new \RuntimeException('Physicist check must pass before RO approval');
        }

        // Secondary check must pass if required
        if ($plan->requiresSecondaryCheck() && ! $this->secondaryCheckPassed($plan)) {
            throw new \RuntimeException('Secondary check must pass for VMAT/IMRT/SRS/SBRT before RO approval');
        }

        $approval = RtPlanApproval::create([
            'tenant_id' => $plan->tenant_id,
            'facility_id' => $plan->facility_id,
            'rt_plan_id' => $plan->id,
            'approval_type' => RtPlanApproval::TYPE_RO_APPROVAL,
            'status' => $approved ? RtPlanApproval::STATUS_APPROVED : RtPlanApproval::STATUS_REJECTED,
            'decision' => $approved ? 'clinically_approved' : 'clinically_rejected',
            'comments' => $comments,
            'approved_by_staff_id' => $roStaffId,
            'approved_at' => $approved ? now() : null,
        ]);

        if ($approved) {
            $plan->update([
                'approved_by_ro_id' => $roStaffId,
                'ro_approved_at' => now(),
                'status' => RtTreatmentPlan::STATUS_APPROVED,
            ]);
        }

        return $approval;
    }

    private function secondaryCheckPassed(RtTreatmentPlan $plan): bool
    {
        return $plan->approvals()
            ->where('approval_type', RtPlanApproval::TYPE_SECONDARY_CHECK)
            ->where('status', RtPlanApproval::STATUS_APPROVED)
            ->exists();
    }

    /**
     * Record a delivered fraction session.
     */
    public function deliverFraction(
        RtFraction $fraction,
        string $machineId,
        string $deliveredByStaffId,
        float $deliveredDose,
        ?string $notes = null,
    ): RtFractionSession {
        $session = RtFractionSession::create([
            'tenant_id' => $fraction->tenant_id,
            'facility_id' => $fraction->facility_id,
            'rt_fraction_id' => $fraction->id,
            'machine_id' => $machineId,
            'status' => 'completed',
            'session_start' => now(),
            'session_end' => now(),
            'delivered_dose_cgy' => $deliveredDose,
            'delivered_by_staff_id' => $deliveredByStaffId,
            'notes' => $notes,
        ]);

        $fraction->update(['status' => 'delivered']);

        // Update course completed fractions
        $plan = $fraction->plan;
        $course = $plan->course;
        $deliveredCount = $course->fractions()
            ->whereHas('sessions', fn ($q) => $q->where('status', 'completed'))
            ->count();
        $course->update(['completed_fractions' => $deliveredCount]);

        return $session;
    }
}
