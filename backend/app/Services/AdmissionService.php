<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\Encounter;
use App\Models\Staff;
use App\Models\TransferEvent;
use App\Support\BedStatus;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 6 — IPD admission/discharge with bed release
 * (PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23).
 *
 *  - ADMIT: the encounter's provider admits the patient from an OPEN
 *    encounter, claiming a live available bed. The bed claim is a
 *    compare-and-swap (status = available AND current_admission_id IS NULL
 *    AND lock_version) — two clerks can never book the same bed; the
 *    partial unique uq_beds_tenant_current_admission is the DB backstop.
 *  - DISCHARGE: the provider closes the stay — a signed discharge-summary
 *    clinical note (type 'discharge') is written, the admission is
 *    CAS-advanced admitted/in_ward → discharged, and the bed is released
 *    (occupied → cleaning, current_admission_id cleared). The bed enters
 *    'cleaning' so it can never be immediately reassigned before it is
 *    turned over (cleaning → available is the normal path).
 *
 * Invariants enforced by the database, not just the application:
 * one open admission per patient and per encounter (partial uniques), and
 * one admission per occupied bed (uq_beds_tenant_current_admission).
 */
final class AdmissionService
{
    /**
     * Admit the encounter's patient, claiming an available bed atomically.
     *
     * @param  array<string, mixed>  $summary
     */
    public function admit(
        Encounter $encounter,
        string $bedId,
        string $admissionType,
        string $admittingDiagnosis,
        Staff $provider,
    ): Admission {
        return DB::transaction(function () use ($encounter, $bedId, $admissionType, $admittingDiagnosis, $provider): Admission {
            if ($encounter->status !== Encounter::STATUS_OPEN) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Only an open encounter can be admitted (current status: '.$encounter->status.').',
                    409,
                );
            }

            if (Admission::query()
                ->where('tenant_id', $encounter->tenant_id)
                ->where('patient_id', $encounter->patient_id)
                ->whereIn('status', [Admission::STATUS_ADMITTED, Admission::STATUS_IN_WARD, Admission::STATUS_TRANSFERRED])
                ->exists()) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This patient already has an open admission.', 409);
            }

            // The bed must exist, be in the same tenant+facility, be
            // available, and hold no current admission.
            $bed = Bed::query()
                ->where('tenant_id', $encounter->tenant_id)
                ->where('id', $bedId)
                ->lockForUpdate()
                ->first();

            if ($bed === null || $bed->facility_id !== $encounter->facility_id) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Bed not found.', 404);
            }

            if ($bed->status !== BedStatus::AVAILABLE || $bed->current_admission_id !== null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The selected bed is not available.', 409);
            }

            $admission = Admission::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'admission_number' => $this->nextNumber($encounter->tenant_id),
                'admission_type' => $admissionType,
                'admitting_diagnosis' => $admittingDiagnosis,
                'admitted_at' => now(),
                'status' => Admission::STATUS_ADMITTED,
                'lock_version' => 0,
                'created_by' => $provider->user_id ?? $provider->getKey(),
            ]);

            // Claim the bed: CAS on (status, current_admission_id,
            // lock_version) — a concurrent admitter holding the same stale
            // snapshot affects zero rows and the whole transaction rolls back
            // (their admission disappears with it).
            $claimed = DB::table('beds')
                ->where('id', $bed->getKey())
                ->where('status', BedStatus::AVAILABLE)
                ->whereNull('current_admission_id')
                ->where('lock_version', $bed->lock_version)
                ->update([
                    'status' => BedStatus::OCCUPIED,
                    'current_admission_id' => $admission->getKey(),
                    'lock_version' => $bed->lock_version + 1,
                    'updated_by' => $provider->user_id,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The selected bed was claimed by another admission. Reload and retry.', 409);
            }

            return $admission;
        });
    }

    /**
     * Discharge the admission: write the signed discharge summary, advance
     * the admission via CAS, and release the bed. All-or-nothing.
     *
     * @param  array<string, mixed>  $summary
     */
    public function discharge(
        Admission $admission,
        string $dischargeType,
        array $summary,
        Staff $provider,
    ): Admission {
        return DB::transaction(function () use ($admission, $dischargeType, $summary, $provider): Admission {
            // 'transferred' joined in slice 13: a patient whose bed changed
            // mid-stay is still the same open admission and discharges
            // normally (releasing the CURRENT bed).
            if (! in_array($admission->status, [Admission::STATUS_ADMITTED, Admission::STATUS_IN_WARD, Admission::STATUS_TRANSFERRED], true)) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Only an admitted patient can be discharged (current status: '.$admission->status.').',
                    409,
                );
            }

            // Structured discharge summary — a SIGNED clinical note of type
            // 'discharge' on the admission's encounter (DATABASE.md §3.23).
            // Its content is clinical PHI and never reaches audit payloads.
            $note = ClinicalNote::query()->create([
                'tenant_id' => $admission->tenant_id,
                'encounter_id' => $admission->encounter_id,
                'note_type' => ClinicalNote::TYPE_DISCHARGE,
                'author_staff_id' => $provider->getKey(),
                'content' => $summary,
                'status' => ClinicalNote::STATUS_SIGNED,
                'signed_at' => now(),
                'lock_version' => 0,
                'created_by' => $provider->user_id,
            ]);

            // CAS the admission: a stale discharger affects zero rows.
            $updated = DB::table('admissions')
                ->where('id', $admission->getKey())
                ->whereIn('status', [Admission::STATUS_ADMITTED, Admission::STATUS_IN_WARD, Admission::STATUS_TRANSFERRED])
                ->where('lock_version', $admission->lock_version)
                ->update([
                    'status' => Admission::STATUS_DISCHARGED,
                    'discharged_at' => now(),
                    'discharge_type' => $dischargeType,
                    'discharge_summary_id' => $note->getKey(),
                    'lock_version' => $admission->lock_version + 1,
                    'updated_by' => $provider->user_id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This admission was changed by another discharge. Reload and retry.', 409);
            }

            // Release the bed: occupied → cleaning, occupancy cleared. The
            // current_admission_id predicate is unique per bed (partial
            // unique), so this can only ever affect this admission's bed.
            DB::table('beds')
                ->where('tenant_id', $admission->tenant_id)
                ->where('current_admission_id', $admission->getKey())
                ->update([
                    'status' => BedStatus::CLEANING,
                    'current_admission_id' => null,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $provider->user_id,
                    'updated_at' => now(),
                ]);

            return $admission->refresh();
        });
    }

    /**
     * Transfer the admission to another bed (ROADMAP Phase 8,
     * PRODUCT_REQUIREMENTS §6.5): the patient moves bed-to-bed/ward-to-ward
     * with a captured reason, the admission CAS-advances to 'transferred',
     * and an immutable transfer_event preserves the historical bed timeline.
     *
     * Safety under contention:
     *  - the admission row and both beds are row-locked, so two transfers of
     *    the same admission serialize and a stale transferer loses the CAS;
     *  - the target bed is claimed by compare-and-swap (available AND no
     *    current admission AND lock_version) — two clerks can never book the
     *    same bed; uq_beds_tenant_current_admission is the DB backstop;
     *  - the vacated bed goes occupied → cleaning (never immediately
     *    reassignable), consistent with the discharge release.
     *
     * @return array{0: Admission, 1: TransferEvent}
     */
    public function transfer(
        Admission $admission,
        string $toBedId,
        string $reason,
        Staff $actor,
    ): array {
        return DB::transaction(function () use ($admission, $toBedId, $reason, $actor): array {
            // Lock the admission row so concurrent transfers of the SAME
            // admission serialize — the stale transferer sees the new status.
            $admission = Admission::query()
                ->where('tenant_id', $admission->tenant_id)
                ->whereKey($admission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($admission->status, [Admission::STATUS_ADMITTED, Admission::STATUS_IN_WARD, Admission::STATUS_TRANSFERRED], true)) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Only an admitted patient can be transferred (current status: '.$admission->status.').',
                    409,
                );
            }

            // The current bed (the from-bed) — the unique occupancy predicate
            // means only this admission's bed can match.
            $fromBed = Bed::query()
                ->where('tenant_id', $admission->tenant_id)
                ->where('current_admission_id', $admission->getKey())
                ->lockForUpdate()
                ->first();

            if ($fromBed === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This admission has no occupied bed to transfer from.', 409);
            }

            // The target bed must exist in the same tenant and facility.
            $toBed = Bed::query()
                ->where('tenant_id', $admission->tenant_id)
                ->where('id', $toBedId)
                ->lockForUpdate()
                ->first();

            if ($toBed === null || $toBed->facility_id !== $admission->facility_id) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Bed not found.', 404);
            }

            if ($toBed->getKey() === $fromBed->getKey()) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Select a different bed to transfer to.', 409);
            }

            if ($toBed->status !== BedStatus::AVAILABLE || $toBed->current_admission_id !== null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The selected bed is not available.', 409);
            }

            // Immutable transfer history — the audited bed timeline. The
            // reason is clinical context and stays out of audit payloads.
            $event = TransferEvent::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'admission_id' => $admission->getKey(),
                'from_bed_id' => $fromBed->getKey(),
                'to_bed_id' => $toBed->getKey(),
                'reason' => $reason,
                'transferred_by' => $actor->getKey(),
                'transferred_at' => now(),
                'created_by' => $actor->user_id,
            ]);

            // CAS the admission: admitted/in_ward/transferred → transferred.
            $updated = DB::table('admissions')
                ->where('id', $admission->getKey())
                ->whereIn('status', [Admission::STATUS_ADMITTED, Admission::STATUS_IN_WARD, Admission::STATUS_TRANSFERRED])
                ->where('lock_version', $admission->lock_version)
                ->update([
                    'status' => Admission::STATUS_TRANSFERRED,
                    'lock_version' => $admission->lock_version + 1,
                    'updated_by' => $actor->user_id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This admission was changed by another transfer. Reload and retry.', 409);
            }

            // Release the vacated bed: occupied → cleaning, occupancy
            // cleared. The current_admission_id predicate is unique per bed.
            DB::table('beds')
                ->where('tenant_id', $admission->tenant_id)
                ->where('current_admission_id', $admission->getKey())
                ->update([
                    'status' => BedStatus::CLEANING,
                    'current_admission_id' => null,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $actor->user_id,
                    'updated_at' => now(),
                ]);

            // Claim the target bed: CAS — a concurrent transferer holding the
            // same stale snapshot affects zero rows and rolls back.
            $claimed = DB::table('beds')
                ->where('id', $toBed->getKey())
                ->where('status', BedStatus::AVAILABLE)
                ->whereNull('current_admission_id')
                ->where('lock_version', $toBed->lock_version)
                ->update([
                    'status' => BedStatus::OCCUPIED,
                    'current_admission_id' => $admission->getKey(),
                    'lock_version' => $toBed->lock_version + 1,
                    'updated_by' => $actor->user_id,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The target bed was claimed by another transfer. Reload and retry.', 409);
            }

            return [$admission->refresh(), $event];
        });
    }

    private function nextNumber(string $tenantId): string
    {
        do {
            $number = 'ADM-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (Admission::query()->where('tenant_id', $tenantId)->where('admission_number', $number)->exists());

        return $number;
    }
}
