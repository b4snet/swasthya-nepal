<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Admission;
use App\Models\Encounter;
use App\Models\ErEvent;
use App\Models\ErRegistration;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\TriageAssignment;
use App\Models\TriageScale;
use App\Support\ErrorCodes;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 14 — Emergency (ROADMAP Phase 9, PRODUCT_REQUIREMENTS §6.6):
 *
 *   minimal-data ER registration → configurable triage (scale-driven,
 *   reassessments supersede via CAS, clinical-authority overrides audited
 *   separately) → time-stamped ER events (append-only, medico-legal) →
 *   audited disposition (admit to IPD / transfer out / discharge).
 *
 * Safety guarantees:
 *  - registration works with PARTIAL data (unidentified patients get a
 *    documented placeholder + estimated age; identity is later resolved via
 *    the existing patient-merge controlled link) — speed over completeness;
 *  - one ACTIVE triage per ER encounter is DB-enforced (partial unique);
 *    a concurrent reassessment loses with 409 and changes nothing;
 *  - er_events are immutable (no update/delete path exists);
 *  - disposition 'admitted' claims the bed through the SAME CAS admission
 *    path as IPD (AdmissionService) — no double-booking; 'referred' carries
 *    the transfer documentation in the event, never in audit payloads.
 *
 * Every mutation is transactional; complaints, notes, and reasons are PHI
 * and never leave the clinical tables.
 */
final class ErService
{
    public function __construct(
        private readonly MrnIssuer $mrnIssuer,
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * Register a patient in the emergency department: create the full
     * patient record with the facts at hand, open the ER encounter, record
     * the registration, and stamp the first time-stamped event.
     *
     * @return array{0: ErRegistration, 1: Patient, 2: Encounter}
     */
    public function register(
        Facility $facility,
        Staff $registrar,
        ?string $patientName,
        ?string $sex,
        ?string $dateOfBirth,
        ?int $estimatedAge,
        ?string $presentingComplaint,
    ): array {
        return DB::transaction(function () use ($facility, $registrar, $patientName, $sex, $dateOfBirth, $estimatedAge, $presentingComplaint): array {
            $isUnidentified = $patientName === null || trim($patientName) === '';

            // A full record is always created; unidentified patients get a
            // documented placeholder and an estimated age when provided.
            // The sentinel DOB (1900-01-01) is the standard "unknown date"
            // convention — the source facts live on the registration.
            $patient = Patient::query()->create([
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->getKey(),
                'mrn' => $this->mrnIssuer->issue($facility->tenant_id),
                'full_name' => $isUnidentified ? 'Unidentified' : $patientName,
                'date_of_birth' => $dateOfBirth
                    ?? ($estimatedAge !== null ? now()->subYears($estimatedAge)->toDateString() : '1900-01-01'),
                'sex' => $sex ?? Patient::SEX_UNKNOWN,
                'status' => Patient::STATUS_ACTIVE,
                'consent_summary' => [],
                'lock_version' => 0,
                'created_by' => $registrar->user_id,
            ]);

            $encounter = Encounter::query()->create([
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->getKey(),
                'patient_id' => $patient->getKey(),
                'provider_staff_id' => $registrar->getKey(),
                'type' => Encounter::TYPE_ER,
                'status' => Encounter::STATUS_OPEN,
                'started_at' => now(),
                'lock_version' => 0,
                'created_by' => $registrar->user_id,
            ]);

            $registration = ErRegistration::query()->create([
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->getKey(),
                'patient_id' => $patient->getKey(),
                'encounter_id' => $encounter->getKey(),
                'registered_by' => $registrar->getKey(),
                'registered_at' => now(),
                'presenting_complaint' => $presentingComplaint,
                'estimated_age' => $estimatedAge,
                'is_unidentified' => $isUnidentified,
                'created_by' => $registrar->user_id,
            ]);

            ErEvent::query()->create([
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->getKey(),
                'encounter_id' => $encounter->getKey(),
                'patient_id' => $patient->getKey(),
                'event_type' => ErEvent::TYPE_REGISTERED,
                'occurred_at' => $registration->registered_at,
                'actor_staff_id' => $registrar->getKey(),
                'created_by' => $registrar->user_id,
            ]);

            return [$registration, $patient, $encounter];
        });
    }

    /**
     * Assign a triage level to an ER encounter. The previous active
     * assignment (if any) is CAS-superseded, so reassessment history is
     * preserved and exactly one active triage always exists per encounter.
     * An override (clinical authority) is recorded with its reason.
     */
    public function assignTriage(
        Encounter $encounter,
        TriageScale $scale,
        Staff $assessor,
        bool $isOverride,
        ?string $overrideReason,
    ): TriageAssignment {
        return DB::transaction(function () use ($encounter, $scale, $assessor, $isOverride, $overrideReason): TriageAssignment {
            // The encounter must be an open ER encounter.
            $this->assertOpenErEncounter($encounter);

            // Lock the current active assignment so concurrent reassessments
            // serialize; the loser re-reads the superseded row.
            $active = TriageAssignment::query()
                ->where('tenant_id', $encounter->tenant_id)
                ->where('encounter_id', $encounter->getKey())
                ->where('status', TriageAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                $superseded = DB::table('triage_assignments')
                    ->where('tenant_id', $encounter->tenant_id)
                    ->where('id', $active->getKey())
                    ->where('status', TriageAssignment::STATUS_ACTIVE)
                    ->where('lock_version', $active->lock_version)
                    ->update([
                        'status' => TriageAssignment::STATUS_SUPERSEDED,
                        'lock_version' => $active->lock_version + 1,
                        'updated_at' => now(),
                    ]);

                if ($superseded !== 1) {
                    throw new ApiException(
                        ErrorCodes::LOCK_CONFLICT,
                        'This encounter was re-triaged concurrently. Reload and retry.',
                        409,
                    );
                }
            }

            try {
                $assignment = TriageAssignment::query()->create([
                    'tenant_id' => $encounter->tenant_id,
                    'facility_id' => $encounter->facility_id,
                    'encounter_id' => $encounter->getKey(),
                    'patient_id' => $encounter->patient_id,
                    'triage_scale_id' => $scale->getKey(),
                    'level' => $scale->level,
                    'color' => $scale->color,
                    'assessed_by_staff_id' => $assessor->getKey(),
                    'assessed_at' => now(),
                    'is_override' => $isOverride,
                    'override_reason' => $isOverride ? $overrideReason : null,
                    'status' => TriageAssignment::STATUS_ACTIVE,
                    'lock_version' => 0,
                    'created_by' => $assessor->user_id,
                ]);
            } catch (QueryException $e) {
                // Partial-unique violation: a concurrent assessment inserted
                // its active row first — the loser changes nothing.
                if ($e->getCode() === '23505') {
                    throw new ApiException(
                        ErrorCodes::CONFLICT,
                        'This encounter was re-triaged concurrently. Reload and retry.',
                        409,
                    );
                }

                throw $e;
            }

            ErEvent::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'encounter_id' => $encounter->getKey(),
                'patient_id' => $encounter->patient_id,
                'event_type' => $active !== null ? ErEvent::TYPE_REASSESSED : ErEvent::TYPE_TRIAGED,
                'occurred_at' => $assignment->assessed_at,
                'actor_staff_id' => $assessor->getKey(),
                'created_by' => $assessor->user_id,
            ]);

            return $assignment;
        });
    }

    /**
     * Append an immutable, time-stamped ER event.
     */
    public function recordEvent(
        Encounter $encounter,
        string $eventType,
        ?string $notes,
        CarbonInterface $occurredAt,
        Staff $actor,
    ): ErEvent {
        return DB::transaction(function () use ($encounter, $eventType, $notes, $occurredAt, $actor): ErEvent {
            $this->assertOpenErEncounter($encounter);

            return ErEvent::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'encounter_id' => $encounter->getKey(),
                'patient_id' => $encounter->patient_id,
                'event_type' => $eventType,
                'notes' => $notes,
                'occurred_at' => $occurredAt,
                'actor_staff_id' => $actor->getKey(),
                'created_by' => $actor->user_id,
            ]);
        });
    }

    /**
     * ER disposition (audited admit/transfer/discharge):
     *
     *  - admitted: the patient is admitted to IPD through the SAME CAS bed
     *    claim as the IPD flow (admission_type 'emergency'); the encounter
     *    stays OPEN (the admission continues on it).
     *  - referred: transfer out with documentation — the encounter closes.
     *  - home: discharge with instructions — the encounter closes.
     *  - deceased: the encounter closes.
     *
     * Every disposition writes a time-stamped er_event and is audited; the
     * transfer documentation lives in the event, never in audit payloads.
     *
     * @return array{0: Encounter, 1: Admission|null, 2: ErEvent}
     */
    public function dispose(
        Encounter $encounter,
        string $disposition,
        ?string $notes,
        ?string $bedId,
        ?string $admittingDiagnosis,
        Staff $actor,
    ): array {
        return DB::transaction(function () use ($encounter, $disposition, $notes, $bedId, $admittingDiagnosis, $actor): array {
            $this->assertOpenErEncounter($encounter);

            if ($encounter->disposition !== null) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'This ER encounter is already disposed (current disposition: '.$encounter->disposition.').',
                    409,
                );
            }

            $admission = null;

            if ($disposition === Encounter::DISPOSITION_ADMITTED) {
                // The CAS bed claim — same guarantees as the IPD admission
                // flow; the admission continues on this open ER encounter.
                $admission = $this->admissions->admit(
                    $encounter,
                    (string) $bedId,
                    Admission::TYPE_EMERGENCY,
                    $admittingDiagnosis ?? 'Admitted via emergency department',
                    $actor,
                );
            }

            $eventType = match ($disposition) {
                Encounter::DISPOSITION_REFERRED => ErEvent::TYPE_TRANSFERRED_OUT,
                Encounter::DISPOSITION_HOME => ErEvent::TYPE_DISCHARGED,
                default => ErEvent::TYPE_DISPOSITION,
            };

            // CAS the disposition: a stale disposer affects zero rows.
            $updated = DB::table('encounters')
                ->where('tenant_id', $encounter->tenant_id)
                ->where('id', $encounter->getKey())
                ->whereIn('status', [Encounter::STATUS_OPEN, Encounter::STATUS_IN_PROGRESS])
                ->where('lock_version', $encounter->lock_version)
                ->update([
                    'disposition' => $disposition,
                    // An admitted ER visit continues on the same encounter;
                    // terminal dispositions close it.
                    'status' => $disposition === Encounter::DISPOSITION_ADMITTED
                        ? Encounter::STATUS_OPEN
                        : Encounter::STATUS_CLOSED,
                    'ended_at' => $disposition === Encounter::DISPOSITION_ADMITTED ? null : now(),
                    'discharged_at' => $disposition === Encounter::DISPOSITION_ADMITTED ? null : now(),
                    'discharged_by' => $disposition === Encounter::DISPOSITION_ADMITTED ? null : $actor->user_id,
                    'lock_version' => $encounter->lock_version + 1,
                    'updated_by' => $actor->user_id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'This encounter was disposed concurrently. Reload and retry.',
                    409,
                );
            }

            $event = ErEvent::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'encounter_id' => $encounter->getKey(),
                'patient_id' => $encounter->patient_id,
                'event_type' => $eventType,
                'notes' => $notes,
                'occurred_at' => now(),
                'actor_staff_id' => $actor->getKey(),
                'created_by' => $actor->user_id,
            ]);

            return [$encounter->refresh(), $admission, $event];
        });
    }

    private function assertOpenErEncounter(Encounter $encounter): void
    {
        if ($encounter->type !== Encounter::TYPE_ER) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This is not an emergency encounter.', 409);
        }

        if (! in_array($encounter->status, [Encounter::STATUS_OPEN, Encounter::STATUS_IN_PROGRESS], true)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This ER encounter is not open (current status: '.$encounter->status.').',
                409,
            );
        }
    }
}
