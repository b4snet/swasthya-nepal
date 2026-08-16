<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AnesthesiaRecord;
use App\Models\BloodUnit;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\CompatibilityResult;
use App\Models\CriticalCareNote;
use App\Models\Crossmatch;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\IcuAdmission;
use App\Models\IcuAlert;
use App\Models\IcuBed;
use App\Models\IcuObservationSet;
use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\ReactionReport;
use App\Models\RecoveryRecord;
use App\Models\SurgicalEvent;
use App\Models\SurgicalTeamMember;
use App\Models\Theatre;
use App\Models\Transfusion;
use App\Models\WarningScore;
use App\Support\ErrorCodes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 20 — OT, ICU, and Blood Bank (ROADMAP Phase 16, PRODUCT
 * REQUIREMENTS §6.10–6.12, DATABASE.md §3.48–3.50).
 *
 * Life-critical safety invariants, enforced at the service + DB layer:
 *
 * OT:
 *   - Scheduling conflict detection: a theatre cannot host two overlapping
 *     cases — the theatre row is locked (SELECT … FOR UPDATE) so concurrent
 *     schedulers serialize.
 *   - Checklist compliance: a procedure CANNOT close (ot:close) while any
 *     safety-checklist step is incomplete — checklist compliance is a
 *     monitored safety metric.
 *
 * ICU:
 *   - One open ICU admission per patient and one admission per occupied
 *     ICU bed (DB partial uniques backstop double-booking); the bed is
 *     CAS-guarded available → occupied.
 *   - Observation schedules are ENFORCED: recording an observation AFTER
 *     the previous due time creates a missed_observation alert — a missed
 *     ICU observation is a patient-safety event by design, and the audit
 *     trail proves the schedule was met.
 *   - Warning scores are COMPUTED from observation values (never
 *     hand-entered); a score escalation or threshold breach creates an
 *     alert that MUST be acknowledged (who saw it, when).
 *
 * Blood Bank:
 *   - Expired or untested units are NEVER issuable (CAS issue guard).
 *   - Issue requires a COMPATIBLE crossmatch for (unit, patient).
 *   - Transfusion requires DUAL verification: started by one staff member
 *     and verified by a DIFFERENT staff member — a wrong unit is a
 *     life-threatening error. Completion is refused until verified.
 *   - Every unit is traceable to its donor and its recipient.
 *
 * Donor personal data is protected to the same standard as patient data:
 * names, DOB, and phone are never part of audit payloads.
 */
final class OtIcuBloodBankService
{
    /**
     * Simplified NEWS-style early-warning scoring (scale_version 'news-1').
     * Scores are computed from observed values only.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SCORE_BANDS = [
        // key => [lowest value in band (inclusive), score]
        'respiratory_rate' => [[0, 3], [9, 1], [12, 0], [21, 2], [25, 3]],
        'spo2' => [[0, 3], [92, 2], [94, 1], [96, 0]],
        'heart_rate' => [[0, 3], [41, 1], [51, 0], [91, 1], [111, 2], [131, 3]],
        'sbp' => [[0, 3], [91, 2], [101, 1], [111, 0], [220, 3]],
        'temperature_c' => [[0, 3], [35.1, 1], [36.1, 0], [38.1, 1], [39.1, 2]],
    ];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private const SEVERITY_BANDS = [
        // [min total (inclusive), severity]
        [0, WarningScore::SEVERITY_LOW],
        [5, WarningScore::SEVERITY_MEDIUM],
        [7, WarningScore::SEVERITY_HIGH],
        [10, WarningScore::SEVERITY_EMERGENCY],
    ];

    private const SEVERITY_RANK = [
        WarningScore::SEVERITY_LOW => 0,
        WarningScore::SEVERITY_MEDIUM => 1,
        WarningScore::SEVERITY_HIGH => 2,
        WarningScore::SEVERITY_EMERGENCY => 3,
    ];

    // ─────────────────────────── Operating Theatre ───────────────────────────

    public function createTheatre(
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $status = Theatre::STATUS_ACTIVE,
        ?string $createdBy = null,
    ): Theatre {
        return Theatre::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'status' => $status,
            'created_by' => $createdBy,
        ]);
    }

    public function createProcedureRequest(
        string $tenantId,
        string $facilityId,
        string $patientId,
        ?string $encounterId,
        string $requestedByStaffId,
        string $procedureName,
        string $priority = ProcedureRequest::PRIORITY_ROUTINE,
        ?string $createdBy = null,
    ): ProcedureRequest {
        return ProcedureRequest::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'requested_by_staff_id' => $requestedByStaffId,
            'procedure_name' => $procedureName,
            'priority' => $priority,
            'status' => ProcedureRequest::STATUS_REQUESTED,
            'equipment_requirements' => [],
            'team_requirements' => [],
            'lock_version' => 0,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Assign a theatre/date/time to a request. The theatre row is locked
     * (SELECT … FOR UPDATE) so concurrent schedulers serialize: two cases
     * cannot overlap on one theatre (PRODUCT_REQUIREMENTS §6.10
     * "conflict detection (two cases on one theatre)"). The theatre must be
     * active and no overlapping case may already be scheduled.
     */
    public function scheduleProcedureRequest(
        ProcedureRequest $request,
        string $theatreId,
        CarbonInterface $scheduledAt,
        int $durationMinutes,
        ?string $updatedBy = null,
    ): ProcedureRequest {
        return DB::transaction(function () use ($request, $theatreId, $scheduledAt, $durationMinutes, $updatedBy): ProcedureRequest {
            $request->refresh();

            if (! in_array($request->status, [ProcedureRequest::STATUS_REQUESTED, ProcedureRequest::STATUS_SCHEDULED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a requested or scheduled procedure can be re-scheduled.', 409);
            }

            $theatre = Theatre::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('facility_id', $request->facility_id)
                ->where('id', $theatreId)
                ->lockForUpdate()
                ->first();

            if ($theatre === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Theatre not found.', 404);
            }

            if ($theatre->status !== Theatre::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'An inactive theatre cannot be scheduled.', 409);
            }

            $newStart = $scheduledAt->getTimestamp();
            $newEnd = $scheduledAt->addMinutes($durationMinutes)->getTimestamp();

            $overlap = ProcedureRequest::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('facility_id', $request->facility_id)
                ->where('theatre_id', $theatreId)
                ->whereIn('status', [ProcedureRequest::STATUS_SCHEDULED])
                ->where('id', '!=', $request->getKey())
                ->whereNotNull('scheduled_at')
                ->get()
                ->contains(function (ProcedureRequest $existing) use ($newStart, $newEnd): bool {
                    $existingStart = $existing->scheduled_at?->getTimestamp();
                    $existingEnd = $existing->scheduled_at?->addMinutes((int) $existing->scheduled_duration_minutes)->getTimestamp();

                    if ($existingStart === null || $existingEnd === null) {
                        return false;
                    }

                    return $newStart < $existingEnd && $existingStart < $newEnd;
                });

            if ($overlap) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'This theatre already has a scheduled case in the requested time window.',
                    409,
                );
            }

            $affected = DB::table('procedure_requests')
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->getKey())
                ->whereIn('status', [ProcedureRequest::STATUS_REQUESTED, ProcedureRequest::STATUS_SCHEDULED])
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => ProcedureRequest::STATUS_SCHEDULED,
                    'theatre_id' => $theatreId,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_duration_minutes' => $durationMinutes,
                    'lock_version' => $request->lock_version + 1,
                    'updated_by' => $updatedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The procedure request was concurrently modified; reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    public function cancelProcedureRequest(ProcedureRequest $request, ?string $updatedBy = null): ProcedureRequest
    {
        return DB::transaction(function () use ($request, $updatedBy): ProcedureRequest {
            $request->refresh();

            if (! in_array($request->status, [ProcedureRequest::STATUS_REQUESTED, ProcedureRequest::STATUS_SCHEDULED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a requested or scheduled procedure can be cancelled.', 409);
            }

            $affected = DB::table('procedure_requests')
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->getKey())
                ->whereIn('status', [ProcedureRequest::STATUS_REQUESTED, ProcedureRequest::STATUS_SCHEDULED])
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => ProcedureRequest::STATUS_CANCELLED,
                    'lock_version' => $request->lock_version + 1,
                    'updated_by' => $updatedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The procedure request was concurrently modified; reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Start the case: create the procedure record from the scheduled request
     * and snapshot the facility's safety-checklist steps into checklist_items
     * (each step records WHO completed it and WHEN — PRODUCT_REQUIREMENTS
     * §6.10 "checklist completion (each step, who, when)"). The request and
     * the procedure move to in_progress together.
     *
     * @param  list<array{key: string, label: string}>|null  $steps
     * @return array{0: Procedure, 1: list<ChecklistItem>}
     */
    public function startProcedure(
        ProcedureRequest $request,
        string $checklistTemplateId,
        ?string $surgeonStaffId,
        ?string $startedByStaffId = null,
        ?array $steps = null,
    ): array {
        return DB::transaction(function () use ($request, $checklistTemplateId, $surgeonStaffId, $startedByStaffId, $steps): array {
            $request->refresh();

            if ($request->status !== ProcedureRequest::STATUS_SCHEDULED || $request->theatre_id === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a scheduled procedure can start.', 409);
            }

            $template = ChecklistTemplate::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('facility_id', $request->facility_id)
                ->where('id', $checklistTemplateId)
                ->first();

            if ($template === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Checklist template not found.', 404);
            }

            $templateSteps = $steps ?? $template->steps;
            if (! is_array($templateSteps) || $templateSteps === []) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The checklist template must define at least one step.', 422);
            }

            $procedure = Procedure::query()->create([
                'tenant_id' => $request->tenant_id,
                'facility_id' => $request->facility_id,
                'procedure_request_id' => $request->getKey(),
                'patient_id' => $request->patient_id,
                'encounter_id' => $request->encounter_id,
                'theatre_id' => $request->theatre_id,
                'status' => Procedure::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'surgeon_staff_id' => $surgeonStaffId,
                'lock_version' => 0,
                'created_by' => $startedByStaffId,
            ]);

            $items = [];
            $sequence = 1;
            foreach ($templateSteps as $step) {
                $items[] = ChecklistItem::query()->create([
                    'tenant_id' => $request->tenant_id,
                    'facility_id' => $request->facility_id,
                    'procedure_id' => $procedure->getKey(),
                    'checklist_template_id' => $template->getKey(),
                    'step_key' => is_array($step) ? ($step['key'] ?? 'step_'.$sequence) : (string) $step,
                    'step_label' => is_array($step) ? ($step['label'] ?? (string) ($step['key'] ?? 'Step '.$sequence)) : (string) $step,
                    'sequence' => $sequence,
                    'category' => $template->category,
                    'created_by' => $startedByStaffId,
                ]);
                $sequence++;
            }

            $affected = DB::table('procedure_requests')
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->getKey())
                ->where('status', ProcedureRequest::STATUS_SCHEDULED)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => ProcedureRequest::STATUS_IN_PROGRESS,
                    'lock_version' => $request->lock_version + 1,
                    'updated_by' => $startedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The procedure request was concurrently modified; reload and retry.', 409);
            }

            return [$procedure, $items];
        });
    }

    public function addTeamMember(
        Procedure $procedure,
        string $staffId,
        string $role,
        ?CarbonInterface $timeIn = null,
        ?string $createdBy = null,
    ): SurgicalTeamMember {
        return DB::transaction(function () use ($procedure, $staffId, $role, $timeIn, $createdBy): SurgicalTeamMember {
            $procedure->refresh();

            if ($procedure->status !== Procedure::STATUS_IN_PROGRESS) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Team members can only be logged on an in-progress procedure.', 409);
            }

            return SurgicalTeamMember::query()->create([
                'tenant_id' => $procedure->tenant_id,
                'facility_id' => $procedure->facility_id,
                'procedure_id' => $procedure->getKey(),
                'staff_id' => $staffId,
                'role' => $role,
                'time_in' => $timeIn ?? now(),
                'created_by' => $createdBy,
            ]);
        });
    }

    public function startAnesthesia(
        Procedure $procedure,
        string $anesthetistStaffId,
        string $anesthesiaType,
        ?CarbonInterface $startedAt = null,
        ?string $notes = null,
        ?string $createdBy = null,
    ): AnesthesiaRecord {
        return DB::transaction(function () use ($procedure, $anesthetistStaffId, $anesthesiaType, $startedAt, $notes, $createdBy): AnesthesiaRecord {
            $procedure->refresh();

            if ($procedure->status !== Procedure::STATUS_IN_PROGRESS) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Anesthesia can only be recorded on an in-progress procedure.', 409);
            }

            return AnesthesiaRecord::query()->create([
                'tenant_id' => $procedure->tenant_id,
                'facility_id' => $procedure->facility_id,
                'procedure_id' => $procedure->getKey(),
                'anesthetist_staff_id' => $anesthetistStaffId,
                'anesthesia_type' => $anesthesiaType,
                'started_at' => $startedAt ?? now(),
                'status' => AnesthesiaRecord::STATUS_ACTIVE,
                'notes' => $notes,
                'lock_version' => 0,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function recordSurgicalEvent(
        Procedure $procedure,
        string $eventType,
        ?CarbonInterface $occurredAt = null,
        ?string $staffId = null,
        ?string $notes = null,
        ?string $createdBy = null,
    ): SurgicalEvent {
        return DB::transaction(function () use ($procedure, $eventType, $occurredAt, $staffId, $notes, $createdBy): SurgicalEvent {
            $procedure->refresh();

            if ($procedure->status === Procedure::STATUS_COMPLETED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A completed procedure cannot receive new events.', 409);
            }

            return SurgicalEvent::query()->create([
                'tenant_id' => $procedure->tenant_id,
                'facility_id' => $procedure->facility_id,
                'procedure_id' => $procedure->getKey(),
                'event_type' => $eventType,
                'occurred_at' => $occurredAt ?? now(),
                'staff_id' => $staffId,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * Complete one safety-checklist step — records WHO and WHEN. CAS on
     * completed_at IS NULL makes a double completion affect zero rows.
     */
    public function completeChecklistItem(ChecklistItem $item, ?string $staffId = null): ChecklistItem
    {
        return DB::transaction(function () use ($item, $staffId): ChecklistItem {
            $item->refresh();

            if ($item->completed_at !== null) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This checklist step is already completed.', 409);
            }

            $affected = DB::table('checklist_items')
                ->where('tenant_id', $item->tenant_id)
                ->where('id', $item->getKey())
                ->whereNull('completed_at')
                ->update([
                    'completed_at' => now(),
                    'completed_by_staff_id' => $staffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This checklist step was concurrently modified; reload and retry.', 409);
            }

            return $item->refresh();
        });
    }

    /**
     * Close the case (ot:close). CHECKLIST COMPLIANCE is the gate: a
     * procedure cannot close while any safety-checklist step is incomplete
     * (PRODUCT_REQUIREMENTS §6.10 "checklist compliance is a monitored
     * safety metric"). The request and procedure complete together.
     */
    public function closeProcedure(Procedure $procedure, ?string $staffId = null): Procedure
    {
        return DB::transaction(function () use ($procedure, $staffId): Procedure {
            $procedure->refresh();

            if ($procedure->status !== Procedure::STATUS_IN_PROGRESS) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only an in-progress procedure can be closed.', 409);
            }

            $incomplete = $procedure->checklistItems()->whereNull('completed_at')->count();

            if ($incomplete > 0) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('The surgical safety checklist is incomplete: %d step(s) not completed. A case cannot close with an incomplete checklist.', $incomplete),
                    422,
                );
            }

            $affected = DB::table('procedures')
                ->where('tenant_id', $procedure->tenant_id)
                ->where('id', $procedure->getKey())
                ->where('status', Procedure::STATUS_IN_PROGRESS)
                ->where('lock_version', $procedure->lock_version)
                ->update([
                    'status' => Procedure::STATUS_COMPLETED,
                    'ended_at' => now(),
                    'lock_version' => $procedure->lock_version + 1,
                    'updated_by' => $staffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The procedure was concurrently modified; reload and retry.', 409);
            }

            $request = $procedure->procedureRequest()->first();

            if ($request !== null) {
                DB::table('procedure_requests')
                    ->where('tenant_id', $request->tenant_id)
                    ->where('id', $request->getKey())
                    ->where('status', ProcedureRequest::STATUS_IN_PROGRESS)
                    ->update([
                        'status' => ProcedureRequest::STATUS_COMPLETED,
                        'updated_at' => now(),
                    ]);
            }

            return $procedure->refresh();
        });
    }

    /**
     * Admit the patient to PACU recovery after a completed procedure.
     */
    public function admitToRecovery(
        Procedure $procedure,
        string $admittedByStaffId,
        array $observations = [],
        ?CarbonInterface $admittedAt = null,
        ?string $createdBy = null,
    ): RecoveryRecord {
        return DB::transaction(function () use ($procedure, $admittedByStaffId, $observations, $admittedAt, $createdBy): RecoveryRecord {
            $procedure->refresh();

            if ($procedure->status !== Procedure::STATUS_COMPLETED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a completed procedure can be admitted to recovery.', 409);
            }

            return RecoveryRecord::query()->create([
                'tenant_id' => $procedure->tenant_id,
                'facility_id' => $procedure->facility_id,
                'procedure_id' => $procedure->getKey(),
                'admitted_at' => $admittedAt ?? now(),
                'admitted_by_staff_id' => $admittedByStaffId,
                'observations' => $observations,
                'status' => RecoveryRecord::STATUS_IN_RECOVERY,
                'lock_version' => 0,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function dischargeRecovery(
        RecoveryRecord $record,
        string $dischargedByStaffId,
        ?CarbonInterface $dischargedAt = null,
    ): RecoveryRecord {
        return DB::transaction(function () use ($record, $dischargedByStaffId, $dischargedAt): RecoveryRecord {
            $record->refresh();

            if ($record->status !== RecoveryRecord::STATUS_IN_RECOVERY) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This recovery record is already discharged.', 409);
            }

            $affected = DB::table('recovery_records')
                ->where('tenant_id', $record->tenant_id)
                ->where('id', $record->getKey())
                ->where('status', RecoveryRecord::STATUS_IN_RECOVERY)
                ->where('lock_version', $record->lock_version)
                ->update([
                    'status' => RecoveryRecord::STATUS_DISCHARGED,
                    'discharged_at' => $dischargedAt ?? now(),
                    'discharged_by_staff_id' => $dischargedByStaffId,
                    'lock_version' => $record->lock_version + 1,
                    'updated_by' => $dischargedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The recovery record was concurrently modified; reload and retry.', 409);
            }

            return $record->refresh();
        });
    }

    // ─────────────────────────────── ICU ───────────────────────────────

    public function createIcuBed(
        string $tenantId,
        string $facilityId,
        string $bedCode,
        string $acuitySupported = 'level_3',
        string $status = IcuBed::STATUS_AVAILABLE,
        ?string $createdBy = null,
    ): IcuBed {
        return IcuBed::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_code' => $bedCode,
            'status' => $status,
            'acuity_supported' => $acuitySupported,
            'lock_version' => 0,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Admit a patient to the ICU with acuity-based bed assignment. The bed
     * must be available (CAS available → occupied) and the patient must not
     * already have an open ICU admission (DB partial unique backstop). The
     * observation schedule starts immediately: next_observation_due_at =
     * now + interval (PRODUCT_REQUIREMENTS §6.11 "observation scheduling
     * must be enforced").
     */
    public function admitToIcu(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $icuBedId,
        string $source,
        string $acuity,
        int $observationIntervalMinutes,
        string $admittedByStaffId,
        ?string $admissionId = null,
        ?string $handoverNotes = null,
        ?CarbonInterface $admittedAt = null,
    ): IcuAdmission {
        return DB::transaction(function () use (
            $tenantId, $facilityId, $patientId, $icuBedId, $source, $acuity,
            $observationIntervalMinutes, $admittedByStaffId, $admissionId,
            $handoverNotes, $admittedAt,
        ): IcuAdmission {
            $bed = IcuBed::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('id', $icuBedId)
                ->first();

            if ($bed === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'ICU bed not found.', 404);
            }

            if ($bed->status !== IcuBed::STATUS_AVAILABLE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This ICU bed is not available.', 409);
            }

            $admittedAt = $admittedAt ?? now();

            $admission = IcuAdmission::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'admission_id' => $admissionId,
                'icu_bed_id' => $icuBedId,
                'source' => $source,
                'acuity' => $acuity,
                'observation_interval_minutes' => $observationIntervalMinutes,
                'next_observation_due_at' => $admittedAt->addMinutes($observationIntervalMinutes),
                'status' => IcuAdmission::STATUS_ADMITTED,
                'admitted_at' => $admittedAt,
                'admitted_by_staff_id' => $admittedByStaffId,
                'transfer_handover_notes' => $handoverNotes,
                'lock_version' => 0,
                'created_by' => $admittedByStaffId,
            ]);

            $affected = DB::table('icu_beds')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('id', $icuBedId)
                ->where('status', IcuBed::STATUS_AVAILABLE)
                ->where('lock_version', $bed->lock_version)
                ->update([
                    'status' => IcuBed::STATUS_OCCUPIED,
                    'lock_version' => $bed->lock_version + 1,
                    'updated_by' => $admittedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The ICU bed was concurrently taken; reload and retry.', 409);
            }

            return $admission;
        });
    }

    /**
     * Record an ICU observation set. The warning score is COMPUTED from the
     * observed values; a missed observation (recording after the previous
     * due time) escalates, and a score escalation / threshold breach opens
     * an alert that MUST be acknowledged. next_observation_due_at advances.
     *
     * @param  array<string, int|float>  $values
     * @return array{0: IcuObservationSet, 1: WarningScore, 2: list<IcuAlert>}
     */
    public function recordObservation(
        IcuAdmission $admission,
        string $observedByStaffId,
        array $values,
        ?string $notes = null,
        ?CarbonInterface $observedAt = null,
    ): array {
        return DB::transaction(function () use ($admission, $observedByStaffId, $values, $notes, $observedAt): array {
            $admission->refresh();

            if (! in_array($admission->status, [IcuAdmission::STATUS_ADMITTED, IcuAdmission::STATUS_TRANSFERRED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Observations can only be recorded on an open ICU admission.', 409);
            }

            $observedAt = $observedAt ?? now();

            $set = IcuObservationSet::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'icu_admission_id' => $admission->getKey(),
                'observed_at' => $observedAt,
                'observed_by_staff_id' => $observedByStaffId,
                'values' => $values,
                'notes' => $notes,
                'created_by' => $observedByStaffId,
            ]);

            [$total, $breakdown] = $this->computeWarningScore($values);
            $severity = $this->severityFor($total);

            $score = WarningScore::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'icu_admission_id' => $admission->getKey(),
                'observation_set_id' => $set->getKey(),
                'score_total' => $total,
                'severity' => $severity,
                'breakdown' => $breakdown,
                'scale_version' => 'news-1',
                'computed_at' => $observedAt,
                'created_by' => $observedByStaffId,
            ]);

            $alerts = [];

            // MISSED observation escalation: recording AFTER the previous due
            // time is a patient-safety event (ROADMAP Phase 16) — the alert
            // proves the schedule was not met.
            if ($admission->next_observation_due_at !== null && $observedAt->greaterThan($admission->next_observation_due_at)) {
                $alerts[] = IcuAlert::query()->create([
                    'tenant_id' => $admission->tenant_id,
                    'facility_id' => $admission->facility_id,
                    'icu_admission_id' => $admission->getKey(),
                    'alert_type' => IcuAlert::TYPE_MISSED_OBSERVATION,
                    'severity' => 'medium',
                    'message' => sprintf(
                        'ICU observation was recorded %d minute(s) after the scheduled due time.',
                        max(0, (int) $observedAt->diffInMinutes($admission->next_observation_due_at)),
                    ),
                    'status' => IcuAlert::STATUS_OPEN,
                    'created_by' => $observedByStaffId,
                ]);
            }

            // Score escalation: the computed severity jumped ABOVE the
            // previous observation's severity — escalate for acknowledgment.
            $previous = WarningScore::query()
                ->where('tenant_id', $admission->tenant_id)
                ->where('icu_admission_id', $admission->getKey())
                ->where('id', '!=', $score->getKey())
                ->latest('computed_at')
                ->first();

            if ($previous !== null && self::SEVERITY_RANK[$severity] > self::SEVERITY_RANK[$previous->severity]) {
                $alerts[] = IcuAlert::query()->create([
                    'tenant_id' => $admission->tenant_id,
                    'facility_id' => $admission->facility_id,
                    'icu_admission_id' => $admission->getKey(),
                    'warning_score_id' => $score->getKey(),
                    'alert_type' => IcuAlert::TYPE_SCORE_ESCALATION,
                    'severity' => $severity,
                    'message' => sprintf(
                        'Early-warning score escalated from %s to %s (score %d).',
                        $previous->severity,
                        $severity,
                        $total,
                    ),
                    'status' => IcuAlert::STATUS_OPEN,
                    'created_by' => $observedByStaffId,
                ]);
            }

            // Threshold breach: single-variable life-critical thresholds.
            $breach = $this->thresholdBreach($values);
            if ($breach !== null) {
                $alerts[] = IcuAlert::query()->create([
                    'tenant_id' => $admission->tenant_id,
                    'facility_id' => $admission->facility_id,
                    'icu_admission_id' => $admission->getKey(),
                    'warning_score_id' => $score->getKey(),
                    'alert_type' => IcuAlert::TYPE_THRESHOLD_BREACH,
                    'severity' => 'emergency',
                    'message' => $breach,
                    'status' => IcuAlert::STATUS_OPEN,
                    'created_by' => $observedByStaffId,
                ]);
            }

            // Advance the schedule (the trail must prove the schedule).
            DB::table('icu_admissions')
                ->where('tenant_id', $admission->tenant_id)
                ->where('id', $admission->getKey())
                ->update([
                    'next_observation_due_at' => $observedAt->addMinutes($admission->observation_interval_minutes),
                    'updated_at' => now(),
                ]);

            return [$set, $score, $alerts];
        });
    }

    /**
     * Acknowledge an open alert (WHO saw it, WHEN). CAS on status makes a
     * double acknowledgment affect zero rows.
     */
    public function acknowledgeAlert(IcuAlert $alert, string $staffId): IcuAlert
    {
        return DB::transaction(function () use ($alert, $staffId): IcuAlert {
            $alert->refresh();

            if ($alert->status !== IcuAlert::STATUS_OPEN) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only an open alert can be acknowledged.', 409);
            }

            $affected = DB::table('icu_alerts')
                ->where('tenant_id', $alert->tenant_id)
                ->where('id', $alert->getKey())
                ->where('status', IcuAlert::STATUS_OPEN)
                ->update([
                    'status' => IcuAlert::STATUS_ACKNOWLEDGED,
                    'acknowledged_at' => now(),
                    'acknowledged_by_staff_id' => $staffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The alert was concurrently acknowledged; reload and retry.', 409);
            }

            return $alert->refresh();
        });
    }

    public function documentCare(
        IcuAdmission $admission,
        string $noteType,
        string $content,
        string $authoredByStaffId,
        ?CarbonInterface $authoredAt = null,
    ): CriticalCareNote {
        return DB::transaction(function () use ($admission, $noteType, $content, $authoredByStaffId, $authoredAt): CriticalCareNote {
            $admission->refresh();

            if (! in_array($admission->status, [IcuAdmission::STATUS_ADMITTED, IcuAdmission::STATUS_TRANSFERRED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Notes can only be documented on an open ICU admission.', 409);
            }

            return CriticalCareNote::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'icu_admission_id' => $admission->getKey(),
                'note_type' => $noteType,
                'content' => $content,
                'authored_at' => $authoredAt ?? now(),
                'authored_by_staff_id' => $authoredByStaffId,
                'created_by' => $authoredByStaffId,
            ]);
        });
    }

    /**
     * Step down / discharge from ICU with handover documentation. Releases
     * the ICU bed (occupied → available) in the same transaction. CAS on
     * (status, lock_version) — a double transfer affects zero rows.
     */
    public function transferOutOfIcu(
        IcuAdmission $admission,
        string $dischargedByStaffId,
        ?string $handoverNotes = null,
        ?CarbonInterface $dischargedAt = null,
    ): IcuAdmission {
        return DB::transaction(function () use ($admission, $dischargedByStaffId, $handoverNotes, $dischargedAt): IcuAdmission {
            $admission->refresh();

            if (! in_array($admission->status, [IcuAdmission::STATUS_ADMITTED, IcuAdmission::STATUS_TRANSFERRED], true)) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only an open ICU admission can be transferred out.', 409);
            }

            $affected = DB::table('icu_admissions')
                ->where('tenant_id', $admission->tenant_id)
                ->where('id', $admission->getKey())
                ->whereIn('status', [IcuAdmission::STATUS_ADMITTED, IcuAdmission::STATUS_TRANSFERRED])
                ->where('lock_version', $admission->lock_version)
                ->update([
                    'status' => IcuAdmission::STATUS_TRANSFERRED,
                    'discharged_at' => $dischargedAt ?? now(),
                    'discharged_by_staff_id' => $dischargedByStaffId,
                    'transfer_handover_notes' => $handoverNotes ?? $admission->transfer_handover_notes,
                    'lock_version' => $admission->lock_version + 1,
                    'updated_by' => $dischargedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The ICU admission was concurrently modified; reload and retry.', 409);
            }

            DB::table('icu_beds')
                ->where('tenant_id', $admission->tenant_id)
                ->where('facility_id', $admission->facility_id)
                ->where('id', $admission->icu_bed_id)
                ->where('status', IcuBed::STATUS_OCCUPIED)
                ->update([
                    'status' => IcuBed::STATUS_AVAILABLE,
                    'updated_at' => now(),
                ]);

            return $admission->refresh();
        });
    }

    // ───────────────────────────── Blood Bank ─────────────────────────────

    public function registerDonor(
        string $tenantId,
        string $facilityId,
        string $donorNumber,
        string $fullName,
        string $dateOfBirth,
        ?string $sex,
        ?string $bloodGroup,
        ?string $rhFactor,
        ?string $phone,
        array $screening = [],
        ?string $createdBy = null,
    ): Donor {
        return Donor::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'donor_number' => $donorNumber,
            'full_name' => $fullName,
            'date_of_birth' => $dateOfBirth,
            'sex' => $sex,
            'blood_group' => $bloodGroup,
            'rh_factor' => $rhFactor,
            'phone' => $phone,
            'status' => Donor::STATUS_ACTIVE,
            'screening' => $screening,
            'lock_version' => 0,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Record a donation and process it into componentized blood units
     * (PRODUCT_REQUIREMENTS §6.12 "donation → unit number → processing into
     * components"). A deferred donor cannot donate. Each component becomes
     * a quarantined blood unit pending testing — units are never issuable
     * until tested and compatible.
     *
     * @param  list<array{component_type: string, expiry_days: int}>  $components
     * @return array{0: Donation, 1: list<BloodUnit>}
     */
    public function recordDonation(
        Donor $donor,
        string $phlebotomistStaffId,
        array $components,
        int $volumeMl = 450,
        ?CarbonInterface $donatedAt = null,
        ?string $createdBy = null,
    ): array {
        return DB::transaction(function () use ($donor, $phlebotomistStaffId, $components, $volumeMl, $donatedAt, $createdBy): array {
            $donor->refresh();

            if ($donor->status !== Donor::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A deferred or inactive donor cannot donate.', 409);
            }

            if ($donor->deferral_until !== null && $donor->deferral_until->isFuture()) {
                throw new ApiException(ErrorCodes::CONFLICT, sprintf('This donor is deferred until %s.', $donor->deferral_until->toDateString()), 409);
            }

            if ($components === []) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A donation must produce at least one component.', 422);
            }

            // Immutable: addDays must not mutate the shared instance (the
            // collected_at/expiry_at array entries would alias the same
            // object and both serialize to the mutated time).
            $donatedAt = CarbonImmutable::parse($donatedAt ?? now());

            $donation = Donation::query()->create([
                'tenant_id' => $donor->tenant_id,
                'facility_id' => $donor->facility_id,
                'donor_id' => $donor->getKey(),
                'donated_at' => $donatedAt,
                'phlebotomist_staff_id' => $phlebotomistStaffId,
                'volume_ml' => $volumeMl,
                'screening_result' => 'eligible',
                'status' => Donation::STATUS_COLLECTED,
                'lock_version' => 0,
                'created_by' => $createdBy,
            ]);

            $units = [];
            foreach ($components as $component) {
                $type = is_array($component) ? ($component['component_type'] ?? 'whole_blood') : (string) $component;
                $expiryDays = is_array($component) ? (int) ($component['expiry_days'] ?? 35) : 35;
                $expiryDays = max(1, $expiryDays);

                $units[] = BloodUnit::query()->create([
                    'tenant_id' => $donor->tenant_id,
                    'facility_id' => $donor->facility_id,
                    'donation_id' => $donation->getKey(),
                    'unit_number' => $this->nextUnitNumber($donor->tenant_id),
                    'component_type' => $type,
                    'blood_group' => $donor->blood_group,
                    'rh_factor' => $donor->rh_factor,
                    'collected_at' => $donatedAt,
                    'expiry_at' => $donatedAt->addDays($expiryDays),
                    'tested' => false,
                    'test_results' => [],
                    'status' => BloodUnit::STATUS_QUARANTINED,
                    'lock_version' => 0,
                    'created_by' => $createdBy,
                ]);
            }

            DB::table('donations')
                ->where('tenant_id', $donation->tenant_id)
                ->where('id', $donation->getKey())
                ->update([
                    'status' => Donation::STATUS_PROCESSED,
                    'updated_at' => now(),
                ]);

            return [$donation, $units];
        });
    }

    /**
     * Test a quarantined unit. Passing screening moves it to available;
     * failing screening (suitable=false) discards it — an unsuitable unit is
     * never issuable. CAS on status makes a double test affect zero rows.
     *
     * @param  array<string, mixed>  $testResults
     */
    public function testBloodUnit(
        BloodUnit $unit,
        string $testedByStaffId,
        array $testResults = [],
        bool $suitable = true,
    ): BloodUnit {
        return DB::transaction(function () use ($unit, $testedByStaffId, $testResults, $suitable): BloodUnit {
            $unit->refresh();

            if ($unit->status !== BloodUnit::STATUS_QUARANTINED) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a quarantined unit can be tested.', 409);
            }

            $affected = DB::table('blood_units')
                ->where('tenant_id', $unit->tenant_id)
                ->where('id', $unit->getKey())
                ->where('status', BloodUnit::STATUS_QUARANTINED)
                ->where('lock_version', $unit->lock_version)
                ->update([
                    'status' => $suitable ? BloodUnit::STATUS_AVAILABLE : BloodUnit::STATUS_DISCARDED,
                    'tested' => true,
                    'test_results' => $testResults,
                    'discard_reason' => $suitable ? null : 'Failed screening',
                    'discarded_at' => $suitable ? null : now(),
                    'discarded_by_staff_id' => $suitable ? null : $testedByStaffId,
                    'lock_version' => $unit->lock_version + 1,
                    'updated_by' => $testedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The blood unit was concurrently modified; reload and retry.', 409);
            }

            return $unit->refresh();
        });
    }

    /**
     * Request a crossmatch of one unit against one patient. One crossmatch
     * per (unit, patient) — the DB unique backstops duplicates.
     */
    public function requestCrossmatch(
        BloodUnit $unit,
        string $patientId,
        string $requestedByStaffId,
        ?CarbonInterface $requestedAt = null,
    ): Crossmatch {
        return DB::transaction(function () use ($unit, $patientId, $requestedByStaffId, $requestedAt): Crossmatch {
            $unit->refresh();

            if (! in_array($unit->status, [BloodUnit::STATUS_AVAILABLE, BloodUnit::STATUS_CROSSMATCHED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an available unit can be crossmatched.', 409);
            }

            return Crossmatch::query()->create([
                'tenant_id' => $unit->tenant_id,
                'facility_id' => $unit->facility_id,
                'blood_unit_id' => $unit->getKey(),
                'patient_id' => $patientId,
                'status' => Crossmatch::STATUS_REQUESTED,
                'requested_at' => $requestedAt ?? now(),
                'requested_by_staff_id' => $requestedByStaffId,
                'lock_version' => 0,
                'created_by' => $requestedByStaffId,
            ]);
        });
    }

    /**
     * Perform the crossmatch: record the compatibility check and set the
     * result. Compatible requires ABO/Rh compatibility AND a negative
     * antibody screen (PRODUCT_REQUIREMENTS §6.12). CAS on status.
     */
    public function performCrossmatch(
        Crossmatch $crossmatch,
        string $checkedByStaffId,
        string $patientBloodGroup,
        ?string $patientRhFactor,
        bool $aboRhCompatible,
        string $antibodyScreen = 'negative',
        ?string $notes = null,
    ): Crossmatch {
        return DB::transaction(function () use ($crossmatch, $checkedByStaffId, $patientBloodGroup, $patientRhFactor, $aboRhCompatible, $antibodyScreen, $notes): Crossmatch {
            $crossmatch->refresh();

            if (! in_array($crossmatch->status, [Crossmatch::STATUS_REQUESTED, Crossmatch::STATUS_CROSSMATCHED], true)) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a requested crossmatch can be performed.', 409);
            }

            $compatible = $aboRhCompatible && $antibodyScreen === 'negative';

            $result = CompatibilityResult::query()->create([
                'tenant_id' => $crossmatch->tenant_id,
                'facility_id' => $crossmatch->facility_id,
                'patient_id' => $crossmatch->patient_id,
                'patient_blood_group' => $patientBloodGroup,
                'patient_rh_factor' => $patientRhFactor,
                'abo_rh_compatible' => $aboRhCompatible,
                'antibody_screen' => $antibodyScreen,
                'result' => $compatible ? CompatibilityResult::RESULT_COMPATIBLE : CompatibilityResult::RESULT_INCOMPATIBLE,
                'notes' => $notes,
                'checked_at' => now(),
                'checked_by_staff_id' => $checkedByStaffId,
                'created_by' => $checkedByStaffId,
            ]);

            $affected = DB::table('crossmatches')
                ->where('tenant_id', $crossmatch->tenant_id)
                ->where('id', $crossmatch->getKey())
                ->whereIn('status', [Crossmatch::STATUS_REQUESTED, Crossmatch::STATUS_CROSSMATCHED])
                ->where('lock_version', $crossmatch->lock_version)
                ->update([
                    'status' => $compatible ? Crossmatch::STATUS_COMPATIBLE : Crossmatch::STATUS_INCOMPATIBLE,
                    'compatibility_result_id' => $result->getKey(),
                    'crossmatched_at' => now(),
                    'crossmatched_by_staff_id' => $checkedByStaffId,
                    'lock_version' => $crossmatch->lock_version + 1,
                    'updated_by' => $checkedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The crossmatch was concurrently modified; reload and retry.', 409);
            }

            return $crossmatch->refresh();
        });
    }

    /**
     * Issue a unit to a patient. EXPIRED or UNTESTED units are NEVER
     * issuable (PRODUCT_REQUIREMENTS §6.12 "expired or unsuitable units
     * must never be issuable"), and issue requires a COMPATIBLE crossmatch
     * for this (unit, patient). CAS on status + lock_version.
     */
    public function issueBloodUnit(
        BloodUnit $unit,
        string $patientId,
        ?string $issuedByStaffId = null,
    ): BloodUnit {
        return DB::transaction(function () use ($unit, $patientId, $issuedByStaffId): BloodUnit {
            $unit->refresh();

            if (! in_array($unit->status, [BloodUnit::STATUS_AVAILABLE, BloodUnit::STATUS_CROSSMATCHED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This unit cannot be issued in its current state.', 409);
            }

            if (! $unit->tested) {
                throw new ApiException(ErrorCodes::CONFLICT, 'An untested unit cannot be issued.', 409);
            }

            if ($unit->expiry_at->isPast()) {
                throw new ApiException(ErrorCodes::CONFLICT, 'An expired unit cannot be issued.', 409);
            }

            $crossmatch = Crossmatch::query()
                ->where('tenant_id', $unit->tenant_id)
                ->where('blood_unit_id', $unit->getKey())
                ->where('patient_id', $patientId)
                ->where('status', Crossmatch::STATUS_COMPATIBLE)
                ->latest('requested_at')
                ->first();

            if ($crossmatch === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A compatible crossmatch for this unit and patient is required before issue.', 409);
            }

            $affected = DB::table('blood_units')
                ->where('tenant_id', $unit->tenant_id)
                ->where('id', $unit->getKey())
                ->whereIn('status', [BloodUnit::STATUS_AVAILABLE, BloodUnit::STATUS_CROSSMATCHED])
                ->where('lock_version', $unit->lock_version)
                ->update([
                    'status' => BloodUnit::STATUS_ISSUED,
                    'issued_to_patient_id' => $patientId,
                    'lock_version' => $unit->lock_version + 1,
                    'updated_by' => $issuedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The blood unit was concurrently modified; reload and retry.', 409);
            }

            return $unit->refresh();
        });
    }

    /**
     * Start a transfusion. The unit must be ISSUED to this exact patient
     * (positive identification of unit and patient — PRODUCT_REQUIREMENTS
     * §6.12) with a compatible crossmatch. One transfusion per crossmatch
     * (DB unique backstop).
     */
    public function startTransfusion(
        BloodUnit $unit,
        string $patientId,
        string $crossmatchId,
        string $startedByStaffId,
        ?string $encounterId = null,
        ?CarbonInterface $startedAt = null,
    ): Transfusion {
        return DB::transaction(function () use ($unit, $patientId, $crossmatchId, $startedByStaffId, $encounterId, $startedAt): Transfusion {
            $unit->refresh();

            if ($unit->status !== BloodUnit::STATUS_ISSUED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an issued unit can be transfused.', 409);
            }

            if ($unit->issued_to_patient_id !== $patientId) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This unit is issued to a different patient — wrong-unit transfusion is a life-threatening error.', 409);
            }

            $crossmatch = Crossmatch::query()
                ->where('tenant_id', $unit->tenant_id)
                ->where('facility_id', $unit->facility_id)
                ->where('id', $crossmatchId)
                ->where('blood_unit_id', $unit->getKey())
                ->where('patient_id', $patientId)
                ->where('status', Crossmatch::STATUS_COMPATIBLE)
                ->first();

            if ($crossmatch === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A compatible crossmatch for this unit and patient is required.', 409);
            }

            return Transfusion::query()->create([
                'tenant_id' => $unit->tenant_id,
                'facility_id' => $unit->facility_id,
                'blood_unit_id' => $unit->getKey(),
                'patient_id' => $patientId,
                'crossmatch_id' => $crossmatchId,
                'encounter_id' => $encounterId,
                'started_at' => $startedAt ?? now(),
                'started_by_staff_id' => $startedByStaffId,
                'status' => Transfusion::STATUS_STARTED,
                'lock_version' => 0,
                'created_by' => $startedByStaffId,
            ]);
        });
    }

    /**
     * DUAL verification: a DIFFERENT staff member verifies the unit and
     * patient identity before the transfusion can proceed (ROADMAP Phase 16
     * "dual verification in-app"; a wrong unit is an incident by design).
     * The verifier MUST differ from the starter. CAS on verified_at IS NULL.
     */
    public function verifyTransfusion(Transfusion $transfusion, string $verifiedByStaffId): Transfusion
    {
        return DB::transaction(function () use ($transfusion, $verifiedByStaffId): Transfusion {
            $transfusion->refresh();

            if ($transfusion->status !== Transfusion::STATUS_STARTED) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a started transfusion can be verified.', 409);
            }

            if ($transfusion->verified_at !== null) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This transfusion is already verified.', 409);
            }

            if ($transfusion->started_by_staff_id === $verifiedByStaffId) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Dual verification requires a DIFFERENT staff member to verify the transfusion.',
                    409,
                );
            }

            $affected = DB::table('transfusions')
                ->where('tenant_id', $transfusion->tenant_id)
                ->where('id', $transfusion->getKey())
                ->where('status', Transfusion::STATUS_STARTED)
                ->whereNull('verified_at')
                ->where('lock_version', $transfusion->lock_version)
                ->update([
                    'verified_at' => now(),
                    'verified_by_staff_id' => $verifiedByStaffId,
                    'lock_version' => $transfusion->lock_version + 1,
                    'updated_by' => $verifiedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The transfusion was concurrently modified; reload and retry.', 409);
            }

            return $transfusion->refresh();
        });
    }

    /**
     * Complete a transfusion — refused until dual verification happened.
     * The unit becomes transfused (its recipient traceability is complete).
     */
    public function completeTransfusion(
        Transfusion $transfusion,
        string $stoppedByStaffId,
        int $volumeTransfusedMl,
        ?CarbonInterface $stoppedAt = null,
    ): Transfusion {
        return DB::transaction(function () use ($transfusion, $stoppedByStaffId, $volumeTransfusedMl, $stoppedAt): Transfusion {
            $transfusion->refresh();

            if ($transfusion->status !== Transfusion::STATUS_STARTED) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a started transfusion can be completed.', 409);
            }

            if ($transfusion->verified_at === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The transfusion must be dual-verified before it can complete.', 409);
            }

            if ($volumeTransfusedMl <= 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Transfused volume must be positive.', 422);
            }

            $affected = DB::table('transfusions')
                ->where('tenant_id', $transfusion->tenant_id)
                ->where('id', $transfusion->getKey())
                ->where('status', Transfusion::STATUS_STARTED)
                ->whereNotNull('verified_at')
                ->where('lock_version', $transfusion->lock_version)
                ->update([
                    'status' => Transfusion::STATUS_COMPLETED,
                    'stopped_at' => $stoppedAt ?? now(),
                    'stopped_by_staff_id' => $stoppedByStaffId,
                    'volume_transfused_ml' => $volumeTransfusedMl,
                    'lock_version' => $transfusion->lock_version + 1,
                    'updated_by' => $stoppedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The transfusion was concurrently modified; reload and retry.', 409);
            }

            DB::table('blood_units')
                ->where('tenant_id', $transfusion->tenant_id)
                ->where('id', $transfusion->blood_unit_id)
                ->where('status', BloodUnit::STATUS_ISSUED)
                ->update([
                    'status' => BloodUnit::STATUS_TRANSFUSED,
                    'updated_at' => now(),
                ]);

            return $transfusion->refresh();
        });
    }

    /**
     * Stop a started transfusion early (e.g. a reaction). The unit cannot be
     * reused — it stays issued (never returned to available).
     */
    public function stopTransfusion(
        Transfusion $transfusion,
        string $stoppedByStaffId,
        ?int $volumeTransfusedMl = null,
        ?CarbonInterface $stoppedAt = null,
    ): Transfusion {
        return DB::transaction(function () use ($transfusion, $stoppedByStaffId, $volumeTransfusedMl, $stoppedAt): Transfusion {
            $transfusion->refresh();

            if ($transfusion->status !== Transfusion::STATUS_STARTED) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a started transfusion can be stopped.', 409);
            }

            $affected = DB::table('transfusions')
                ->where('tenant_id', $transfusion->tenant_id)
                ->where('id', $transfusion->getKey())
                ->where('status', Transfusion::STATUS_STARTED)
                ->where('lock_version', $transfusion->lock_version)
                ->update([
                    'status' => Transfusion::STATUS_STOPPED,
                    'stopped_at' => $stoppedAt ?? now(),
                    'stopped_by_staff_id' => $stoppedByStaffId,
                    'volume_transfused_ml' => $volumeTransfusedMl ?? $transfusion->volume_transfused_ml,
                    'lock_version' => $transfusion->lock_version + 1,
                    'updated_by' => $stoppedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The transfusion was concurrently modified; reload and retry.', 409);
            }

            return $transfusion->refresh();
        });
    }

    /**
     * Report a transfusion reaction (PRODUCT_REQUIREMENTS §6.12 "reaction
     * reporting"). One report per transfusion (DB unique backstop).
     *
     * @param  list<string>  $symptoms
     */
    public function reportReaction(
        Transfusion $transfusion,
        string $reportedByStaffId,
        string $severity,
        array $symptoms = [],
        ?string $actionTaken = null,
        ?CarbonInterface $occurredAt = null,
    ): ReactionReport {
        return DB::transaction(function () use ($transfusion, $reportedByStaffId, $severity, $symptoms, $actionTaken, $occurredAt): ReactionReport {
            $transfusion->refresh();

            if ($transfusion->status === Transfusion::STATUS_COMPLETED || $transfusion->status === Transfusion::STATUS_ABORTED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A reaction cannot be reported after the transfusion has ended.', 409);
            }

            return ReactionReport::query()->create([
                'tenant_id' => $transfusion->tenant_id,
                'facility_id' => $transfusion->facility_id,
                'transfusion_id' => $transfusion->getKey(),
                'occurred_at' => $occurredAt ?? now(),
                'severity' => $severity,
                'symptoms' => $symptoms,
                'action_taken' => $actionTaken,
                'status' => ReactionReport::STATUS_REPORTED,
                'reported_by_staff_id' => $reportedByStaffId,
                'lock_version' => 0,
                'created_by' => $reportedByStaffId,
            ]);
        });
    }

    /**
     * Discard a unit with reason (expiry, contamination, recall/withdrawal —
     * PRODUCT_REQUIREMENTS §6.12). Terminal; a discarded unit is never
     * issuable. CAS on status + lock_version.
     */
    public function discardBloodUnit(
        BloodUnit $unit,
        string $discardedByStaffId,
        string $reason,
        ?CarbonInterface $discardedAt = null,
    ): BloodUnit {
        return DB::transaction(function () use ($unit, $discardedByStaffId, $reason, $discardedAt): BloodUnit {
            $unit->refresh();

            if (in_array($unit->status, [BloodUnit::STATUS_TRANSFUSED, BloodUnit::STATUS_DISCARDED], true)) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A transfused or already-discarded unit cannot be discarded.', 409);
            }

            $affected = DB::table('blood_units')
                ->where('tenant_id', $unit->tenant_id)
                ->where('id', $unit->getKey())
                ->whereNotIn('status', [BloodUnit::STATUS_TRANSFUSED, BloodUnit::STATUS_DISCARDED])
                ->where('lock_version', $unit->lock_version)
                ->update([
                    'status' => BloodUnit::STATUS_DISCARDED,
                    'discard_reason' => $reason,
                    'discarded_at' => $discardedAt ?? now(),
                    'discarded_by_staff_id' => $discardedByStaffId,
                    'lock_version' => $unit->lock_version + 1,
                    'updated_by' => $discardedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The blood unit was concurrently modified; reload and retry.', 409);
            }

            return $unit->refresh();
        });
    }

    // ──────────────────────────── internals ────────────────────────────

    /**
     * Compute the NEWS-style score from observed values.
     *
     * @param  array<string, int|float>  $values
     * @return array{0: int, 1: array<string, int>}
     */
    private function computeWarningScore(array $values): array
    {
        $breakdown = [];
        $total = 0;

        foreach (self::SCORE_BANDS as $key => $bands) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $score = 0;
            foreach ($bands as $band) {
                if ((float) $value >= (float) $band[0]) {
                    $score = $band[1];
                }
            }
            $breakdown[$key] = $score;
            $total += $score;
        }

        // Consciousness: any GCS below 15 is scored 3 (not alert).
        $gcsEye = $values['gcs_eye'] ?? null;
        $gcsVerbal = $values['gcs_verbal'] ?? null;
        $gcsMotor = $values['gcs_motor'] ?? null;
        if ($gcsEye !== null && $gcsVerbal !== null && $gcsMotor !== null) {
            $gcs = $gcsEye + $gcsVerbal + $gcsMotor;
            if ($gcs < 15) {
                $breakdown['consciousness'] = 3;
                $total += 3;
            }
        }

        return [$total, $breakdown];
    }

    private function severityFor(int $total): string
    {
        // Highest threshold first — the total qualifies for the highest
        // severity band it reaches.
        foreach (array_reverse(self::SEVERITY_BANDS) as $band) {
            if ($total >= $band[0]) {
                return $band[1];
            }
        }

        return WarningScore::SEVERITY_LOW;
    }

    /**
     * @param  array<string, int|float>  $values
     */
    private function thresholdBreach(array $values): ?string
    {
        $spo2 = $values['spo2'] ?? null;
        if ($spo2 !== null && (float) $spo2 < 85.0) {
            return 'SpO2 below 85% — immediate attention required.';
        }

        $hr = $values['heart_rate'] ?? null;
        if ($hr !== null && ((float) $hr > 140.0 || (float) $hr < 40.0)) {
            return 'Heart rate outside 40–140 bpm — immediate attention required.';
        }

        return null;
    }

    private function nextUnitNumber(string $tenantId): string
    {
        do {
            $number = 'BU-'.date('Ymd').'-'.random_int(1000, 99999);
        } while (BloodUnit::query()->where('tenant_id', $tenantId)->where('unit_number', $number)->exists());

        return $number;
    }
}
