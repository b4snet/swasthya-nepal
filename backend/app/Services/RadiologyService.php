<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Encounter;
use App\Models\ImageReference;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Modality;
use App\Models\RadiologyReport;
use App\Models\Study;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 16 — the Radiology lifecycle (ROADMAP Phase 11,
 * PRODUCT_REQUIREMENTS §6.9, DATABASE.md §3.29, CLINICAL_SAFETY §8).
 *
 * Ordering reuses the SHARED order surface: a radiology order is a
 * `lab_orders` row whose items reference the radiology catalog
 * (lab_tests.category = 'radiology'), and a `studies` row is created with
 * it (one study per order — partial unique). The study then walks its own
 * imaging state machine:
 *
 *   ordered → scheduled (modality + slot) → performed (radiographer) →
 *   reported (a verified final report exists)
 *
 * Every transition is a compare-and-swap on (status, lock_version): a
 * concurrent actor affects 0 rows and gets 409 CONFLICT — no double-schedule,
 * no double-perform, no double-verify. Report discipline mirrors the lab
 * (CLINICAL_SAFETY §8): preliminary vs final is explicit with visible
 * timing (reported_at / verified_at), verification is a distinct audited act
 * by a DIFFERENT radiologist (entry ≠ verification), and amendments are NEW
 * rows (original preserved, exactly one active final per study).
 */
final class RadiologyService
{
    /**
     * Create the order + study in one transaction (the study is created with
     * the order — a radiology order IS a study request).
     *
     * @param  list<string>  $testIds
     * @return array{0: LabOrder, 1: Study}
     */
    public function createOrder(Encounter $encounter, array $testIds, string $priority, ?string $clinicalIndication, string $providerStaffId): array
    {
        $context = TenantContext::current();

        $tests = LabTest::query()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('facility_id', $encounter->facility_id)
            ->whereIn('id', $testIds)
            ->where('category', LabTest::CATEGORY_RADIOLOGY)
            ->where('status', LabTest::STATUS_ACTIVE)
            ->get();

        if ($tests->count() !== count($testIds)) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'Every ordered study must be an active radiology catalog item in scope.',
                422,
            );
        }

        return DB::transaction(function () use ($encounter, $tests, $priority, $clinicalIndication, $providerStaffId, $context): array {
            $order = LabOrder::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'ordered_by_staff_id' => $providerStaffId,
                'priority' => $priority,
                'status' => LabOrder::STATUS_ORDERED,
                'clinical_indication' => $clinicalIndication,
                'ordered_at' => now(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            foreach ($tests as $test) {
                LabOrderItem::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'facility_id' => $order->facility_id,
                    'lab_order_id' => $order->getKey(),
                    'lab_test_id' => $test->getKey(),
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            $study = Study::query()->create([
                'tenant_id' => $order->tenant_id,
                'facility_id' => $order->facility_id,
                'lab_order_id' => $order->getKey(),
                'status' => Study::STATUS_ORDERED,
                'ordered_at' => now(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            return [$order, $study];
        });
    }

    /**
     * ordered → scheduled. CAS on (status, lock_version); the modality must
     * be active and in the same tenant+facility.
     */
    public function schedule(Study $study, string $modalityId, string $scheduledAt, ?string $preparationInstructions, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        $modality = Modality::query()
            ->where('tenant_id', $study->tenant_id)
            ->where('facility_id', $study->facility_id)
            ->where('id', $modalityId)
            ->where('status', Modality::STATUS_ACTIVE)
            ->first();

        if ($modality === null) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The modality must be an active modality in scope.',
                422,
            );
        }

        DB::transaction(function () use ($study, $modality, $scheduledAt, $preparationInstructions, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_ORDERED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_SCHEDULED,
                    'modality_id' => $modality->getKey(),
                    'scheduled_at' => $scheduledAt,
                    'preparation_instructions' => $preparationInstructions,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * scheduled → performed (the radiographer captures the images).
     */
    public function perform(Study $study, string $performedByStaffId, int $clientLockVersion, ?string $procedureStartedAt = null): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $performedByStaffId, $clientLockVersion, $procedureStartedAt, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_SCHEDULED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_PERFORMED,
                    'procedure_started_at' => $procedureStartedAt,
                    'performed_at' => now(),
                    'performed_by_staff_id' => $performedByStaffId,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * ordered/scheduled → cancelled (terminal, reason required — CHECK).
     */
    public function cancel(Study $study, string $reason, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $reason, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->whereIn('status', [Study::STATUS_ORDERED, Study::STATUS_SCHEDULED])
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_CANCELLED,
                    'cancel_reason' => $reason,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * performed → reported. The report is DRAFTED by a radiologist
     * (radiology:report); the study only reaches `reported` once a verified
     * report exists. Reports attach to a study that exists in the same
     * tenant (composite FK) — a report can never dangle.
     */
    public function draftReport(Study $study, string $reportType, string $content, ?string $impression, ?string $criticalFindings, string $reporterStaffId): RadiologyReport
    {
        if ($study->status !== Study::STATUS_PERFORMED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'A report can only be drafted once the study has been performed (current status: '.$study->status.').',
                409,
            );
        }

        $context = TenantContext::current();

        return DB::transaction(function () use ($study, $reportType, $content, $impression, $criticalFindings, $reporterStaffId, $context): RadiologyReport {
            return RadiologyReport::query()->create([
                'tenant_id' => $study->tenant_id,
                'facility_id' => $study->facility_id,
                'study_id' => $study->getKey(),
                'report_type' => $reportType,
                'status' => RadiologyReport::STATUS_DRAFT,
                'content' => $content,
                'impression' => $impression,
                'critical_findings' => $criticalFindings,
                'reported_by_staff_id' => $reporterStaffId,
                'reported_at' => now(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);
        });
    }

    /**
     * draft → preliminary|final. Verification is a distinct audited act by a
     * DIFFERENT staff member (entry ≠ verification — CLINICAL_SAFETY §8).
     * A final report release also advances the study to `reported` (CAS —
     * one release per study; a preliminary report does not release the study).
     */
    public function verifyReport(RadiologyReport $report, string $verifierStaffId, int $clientLockVersion): RadiologyReport
    {
        if ($report->reported_by_staff_id === $verifierStaffId) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'The report must be verified by a different staff member than the one who drafted it.',
                403,
            );
        }

        $context = TenantContext::current();

        DB::transaction(function () use ($report, $verifierStaffId, $clientLockVersion, $context): void {
            $nextStatus = $report->report_type === RadiologyReport::TYPE_FINAL
                ? RadiologyReport::STATUS_FINAL
                : RadiologyReport::STATUS_PRELIMINARY;

            $affected = RadiologyReport::query()
                ->whereKey($report->getKey())
                ->where('status', RadiologyReport::STATUS_DRAFT)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => $nextStatus,
                    'verified_by_staff_id' => $verifierStaffId,
                    'verified_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The report changed concurrently. Refresh and retry.',
                    409,
                );
            }

            if ($nextStatus === RadiologyReport::STATUS_FINAL) {
                $studyAffected = Study::query()
                    ->whereKey($report->study_id)
                    ->where('status', Study::STATUS_PERFORMED)
                    ->update([
                        'status' => Study::STATUS_REPORTED,
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_by' => $context->user?->getKey(),
                    ]);

                // The study must have been performed (drafting enforced it)
                // and only this release path moves it to reported — a
                // concurrent second final release is rejected by the partial
                // unique (one ACTIVE final per study).
                if ($studyAffected !== 1) {
                    throw new ApiException(
                        ErrorCodes::LOCK_CONFLICT,
                        'The study changed concurrently. Refresh and retry.',
                        409,
                    );
                }
            }
        });

        return $report->fresh();
    }

    /**
     * final → amended: the current final is superseded (status 'amended',
     * preserved — never edited) and a NEW report row references it via
     * parent_report_id. The amendment is drafted by a radiologist and must
     * go through the SAME verification discipline (verifyReport).
     */
    public function amendReport(RadiologyReport $currentFinal, string $content, ?string $impression, ?string $criticalFindings, string $reporterStaffId): RadiologyReport
    {
        if ($currentFinal->status !== RadiologyReport::STATUS_FINAL) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Only the current final report can be amended (current status: '.$currentFinal->status.').',
                409,
            );
        }

        $study = $currentFinal->study;

        $context = TenantContext::current();

        return DB::transaction(function () use ($currentFinal, $study, $content, $impression, $criticalFindings, $reporterStaffId, $context): RadiologyReport {
            $affected = RadiologyReport::query()
                ->whereKey($currentFinal->getKey())
                ->where('status', RadiologyReport::STATUS_FINAL)
                ->update([
                    'status' => RadiologyReport::STATUS_AMENDED,
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The report changed concurrently. Refresh and retry.',
                    409,
                );
            }

            // The study moves BACK to performed: the amendment is a new
            // draft that must go through the SAME verification discipline,
            // and a verified final re-releases the study (verifyReport's
            // CAS expects performed).
            Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_REPORTED)
                ->update([
                    'status' => Study::STATUS_PERFORMED,
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);

            return RadiologyReport::query()->create([
                'tenant_id' => $study->tenant_id,
                'facility_id' => $study->facility_id,
                'study_id' => $study->getKey(),
                'report_type' => RadiologyReport::TYPE_FINAL,
                'status' => RadiologyReport::STATUS_DRAFT,
                'content' => $content,
                'impression' => $impression,
                'critical_findings' => $criticalFindings,
                'reported_by_staff_id' => $reporterStaffId,
                'reported_at' => now(),
                'parent_report_id' => $currentFinal->getKey(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);
        });
    }

    /**
     * Attach DICOM/PACS references to a performed/reported study. The
     * composite FK is the no-dangling guarantee — a reference can only exist
     * against a study that exists in the same tenant.
     *
     * @param  list<array{referenceType: string, referenceValue: string, description?: ?string}>  $references
     * @return list<ImageReference>
     */
    public function addImageReferences(Study $study, array $references): array
    {
        if (! in_array($study->status, [Study::STATUS_PERFORMED, Study::STATUS_REPORTED], true)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Image references can only be attached once the study has been performed (current status: '.$study->status.').',
                409,
            );
        }

        $context = TenantContext::current();

        $created = [];
        DB::transaction(function () use ($study, $references, $context, &$created): void {
            foreach ($references as $reference) {
                $created[] = ImageReference::query()->create([
                    'tenant_id' => $study->tenant_id,
                    'facility_id' => $study->facility_id,
                    'study_id' => $study->getKey(),
                    'reference_type' => $reference['referenceType'],
                    'reference_value' => $reference['referenceValue'],
                    'description' => $reference['description'] ?? null,
                    'created_by' => $context->user?->getKey(),
                ]);
            }
        });

        return $created;
    }

    // ──────────────────── Extended Study Lifecycle ───────────────────────

    /**
     * Assign a technician to a scheduled study.
     */
    public function assignTechnician(Study $study, string $technicianStaffId, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $technicianStaffId, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->whereIn('status', [Study::STATUS_SCHEDULED, Study::STATUS_ORDERED])
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'assigned_technician_id' => $technicianStaffId,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * scheduled → arrived (patient checked in).
     */
    public function arrive(Study $study, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_SCHEDULED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_ARRIVED,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * arrived → in_progress (acquisition started).
     */
    public function startAcquisition(Study $study, int $clientLockVersion, ?string $procedureStartedAt = null): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $clientLockVersion, $procedureStartedAt, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_ARRIVED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_IN_PROGRESS,
                    'procedure_started_at' => $procedureStartedAt ?? now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * in_progress → acquired (images captured, pending review).
     */
    public function completeAcquisition(Study $study, string $performedByStaffId, int $clientLockVersion, ?string $acquisitionNotes = null): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $performedByStaffId, $clientLockVersion, $acquisitionNotes, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_IN_PROGRESS)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_ACQUIRED,
                    'performed_at' => now(),
                    'performed_by_staff_id' => $performedByStaffId,
                    'acquisition_notes' => $acquisitionNotes,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * acquired → pending_interpretation (ready for radiologist).
     * This is an alias of the existing perform() but with more granular steps.
     */
    public function markPendingInterpretation(Study $study, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_ACQUIRED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_PENDING_INTERPRETATION,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * performed → rejected (quality issues, repeat required).
     */
    public function reject(Study $study, string $reason, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $reason, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->whereIn('status', [Study::STATUS_PERFORMED, Study::STATUS_ACQUIRED, Study::STATUS_PENDING_INTERPRETATION])
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_REJECTED,
                    'cancel_reason' => $reason, // reuse field for rejection reason
                    'repeat_acquisition_reason' => $reason,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * verified → released_to_clinician (result delivery to ordering clinician).
     */
    public function releaseToClinician(Study $study, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($study, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_VERIFIED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_RELEASED_TO_CLINICIAN,
                    'released_to_clinician_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * Reschedule a study (scheduled → rescheduled).
     */
    public function reschedule(Study $study, string $modalityId, string $scheduledAt, ?string $rescheduleReason, int $clientLockVersion): Study
    {
        $context = TenantContext::current();

        $modality = Modality::query()
            ->where('tenant_id', $study->tenant_id)
            ->where('facility_id', $study->facility_id)
            ->where('id', $modalityId)
            ->where('status', Modality::STATUS_ACTIVE)
            ->first();

        if ($modality === null) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The modality must be an active modality in scope.',
                422,
            );
        }

        DB::transaction(function () use ($study, $modality, $scheduledAt, $rescheduleReason, $clientLockVersion, $context): void {
            $affected = Study::query()
                ->whereKey($study->getKey())
                ->where('status', Study::STATUS_SCHEDULED)
                ->where('lock_version', $clientLockVersion)
                ->update([
                    'status' => Study::STATUS_SCHEDULED, // stays scheduled
                    'modality_id' => $modality->getKey(),
                    'scheduled_at' => $scheduledAt,
                    'rescheduled_at' => now(),
                    'reschedule_reason' => $rescheduleReason,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'The study changed concurrently. Refresh and retry.',
                    409,
                );
            }
        });

        return $study->fresh();
    }

    /**
     * Log a study event for explicit audit trail.
     */
    public function logStudyEvent(Study $study, string $eventType, ?string $description = null, ?string $actorStaffId = null, ?array $metadata = null): void
    {
        $context = TenantContext::current();

        StudyEvent::query()->create([
            'tenant_id' => $study->tenant_id,
            'facility_id' => $study->facility_id,
            'study_id' => $study->getKey(),
            'event_type' => $eventType,
            'event_description' => $description,
            'actor_staff_id' => $actorStaffId,
            'metadata' => $metadata,
            'created_by' => $context->user?->getKey(),
        ]);
    }

    // ──────────────────── Result Delivery ────────────────────────────────

    /**
     * After final report verification, release to clinician and log event.
     * This should be called after verifyReport when the report is FINAL.
     */
    public function deliverResult(Study $study, int $clientLockVersion): Study
    {
        return $this->releaseToClinician($study, $clientLockVersion);
    }

    // ──────────────────── Scheduling Helpers ────────────────────────────

    /**
     * Check if a modality is available at a given time (respecting schedule exceptions).
     */
    public function isModalityAvailable(Modality $modality, string $scheduledAt): bool
    {
        $date = \Carbon\Carbon::parse($scheduledAt)->toDateString();
        $time = \Carbon\Carbon::parse($scheduledAt)->format('H:i:s');

        // Check if modality is active
        if ($modality->status !== Modality::STATUS_ACTIVE) {
            return false;
        }

        // Check schedule exceptions
        $exception = ModalityScheduleException::query()
            ->where('tenant_id', $modality->tenant_id)
            ->where('facility_id', $modality->facility_id)
            ->where('modality_id', $modality->getKey())
            ->where('exception_date', $date)
            ->where(function ($query) use ($time) {
                $query->whereNull('start_time')
                    ->orWhere(function ($q) use ($time) {
                        $q->where('start_time', '<=', $time)
                          ->where(function ($q2) use ($time) {
                              $q2->whereNull('end_time')
                                 ->orWhere('end_time', '>=', $time);
                          });
                      });
            })
            ->where('deleted_at', null)
            ->first();

        if ($exception) {
            if ($exception->is_blocked) {
                return false; // blocked time
            }
            // extra hours = available
            return true;
        }

        // Check operating hours
        if ($modality->operating_hours_start && $modality->operating_hours_end) {
            if ($time < $modality->operating_hours_start || $time > $modality->operating_hours_end) {
                return false;
            }
        }

        return true;
    }
}
