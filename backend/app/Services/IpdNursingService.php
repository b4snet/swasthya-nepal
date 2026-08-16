<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Admission;
use App\Models\MarEntry;
use App\Models\NursingNote;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Models\VitalObservation;
use App\Support\ErrorCodes;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 13 — the IPD nursing surface (DATABASE.md §3.27,
 * PRODUCT_REQUIREMENTS §6.5): structured nursing notes (draft → signed),
 * vital observations, and the medication administration record (scheduled
 * dose → given/refused/missed/held).
 *
 * Safety guarantees:
 *  - notes: signed is immutable (CAS draft → signed, author-only);
 *  - vitals: append-only observations tied to the admission's patient;
 *  - MAR: one administration per scheduled dose is DB-enforced (partial
 *    unique) with a 409 on any duplicate; the scheduled → final transition
 *    is a CAS; a cancelled/reversed prescription line can never be
 *    administered; refusal/miss reasons are captured (CLINICAL_SAFETY §190)
 *    and identity re-confirmation is required for 'given' (request layer).
 *
 * Every mutation happens inside a transaction; clinical content (note
 * content, vital values, reasons) is PHI and never leaves these tables.
 */
final class IpdNursingService
{
    private const OPEN_STATUSES = [
        Admission::STATUS_ADMITTED,
        Admission::STATUS_IN_WARD,
        Admission::STATUS_TRANSFERRED,
    ];

    /**
     * Create a draft nursing note on an open admission.
     *
     * @param  array<string, mixed>  $content
     */
    public function createNote(Admission $admission, array $content, Staff $author): NursingNote
    {
        return DB::transaction(function () use ($admission, $content, $author): NursingNote {
            $this->assertOpen($admission);

            return NursingNote::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'admission_id' => $admission->getKey(),
                'author_staff_id' => $author->getKey(),
                'content' => $content,
                'status' => NursingNote::STATUS_DRAFT,
                'created_by' => $author->user_id,
            ]);
        });
    }

    /**
     * Sign a draft nursing note — the author's own note, once, immutably.
     */
    public function signNote(NursingNote $note, Staff $author): NursingNote
    {
        return DB::transaction(function () use ($note, $author): NursingNote {
            if ($note->author_staff_id !== $author->getKey()) {
                throw new ApiException(
                    ErrorCodes::SCOPE_DENIED,
                    'Only the author can sign their nursing note.',
                    403,
                );
            }

            $updated = DB::table('nursing_notes')
                ->where('tenant_id', $note->tenant_id)
                ->where('id', $note->getKey())
                ->where('status', NursingNote::STATUS_DRAFT)
                ->update([
                    'status' => NursingNote::STATUS_SIGNED,
                    'signed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'This nursing note is already signed or was changed concurrently.',
                    409,
                );
            }

            return $note->refresh();
        });
    }

    /**
     * Record a vital observation against the admission.
     *
     * @param  array<string, mixed>  $value
     */
    public function recordVital(
        Admission $admission,
        string $type,
        array $value,
        CarbonInterface $measuredAt,
        Staff $measuredBy,
    ): VitalObservation {
        return DB::transaction(function () use ($admission, $type, $value, $measuredAt, $measuredBy): VitalObservation {
            $this->assertOpen($admission);

            return VitalObservation::query()->create([
                'tenant_id' => $admission->tenant_id,
                'facility_id' => $admission->facility_id,
                'admission_id' => $admission->getKey(),
                'encounter_id' => $admission->encounter_id,
                'patient_id' => $admission->patient_id,
                'type' => $type,
                'value' => $value,
                'measured_at' => $measuredAt,
                'measured_by' => $measuredBy->getKey(),
                'created_by' => $measuredBy->user_id,
            ]);
        });
    }

    /**
     * Schedule a dose of a prescription line on the admission's MAR. One
     * entry per (line, scheduled time) is DB-enforced.
     */
    public function scheduleMar(
        Admission $admission,
        PrescriptionLine $line,
        CarbonInterface $scheduledAt,
        Staff $nurse,
    ): MarEntry {
        return DB::transaction(function () use ($admission, $line, $scheduledAt, $nurse): MarEntry {
            $this->assertOpen($admission);

            // The line must belong to THIS patient and remain orderable —
            // a cancelled/reversed line can never be administered.
            if ($line->tenant_id !== $admission->tenant_id) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Prescription line not found.', 404);
            }

            $prescription = $line->prescription;
            if ($prescription->patient_id !== $admission->patient_id) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'This prescription line belongs to a different patient.',
                    409,
                );
            }

            if ($line->status !== PrescriptionLine::STATUS_ORDERED) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'Only an ordered prescription line can be administered (current status: '.$line->status.').',
                    409,
                );
            }

            if (MarEntry::query()
                ->where('tenant_id', $admission->tenant_id)
                ->where('prescription_line_id', $line->getKey())
                ->where('scheduled_at', $scheduledAt)
                ->exists()) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'A dose for this line is already scheduled at that time.',
                    409,
                );
            }

            try {
                return MarEntry::query()->create([
                    'tenant_id' => $admission->tenant_id,
                    'facility_id' => $admission->facility_id,
                    'admission_id' => $admission->getKey(),
                    'prescription_line_id' => $line->getKey(),
                    'scheduled_at' => $scheduledAt,
                    'status' => MarEntry::STATUS_SCHEDULED,
                    'created_by' => $nurse->user_id,
                ]);
            } catch (QueryException $e) {
                // Unique violation (uq_mar_entries_tenant_line_scheduled):
                // a concurrent scheduler won the same dose slot.
                if ($e->getCode() === '23505') {
                    throw new ApiException(
                        ErrorCodes::CONFLICT,
                        'A dose for this line is already scheduled at that time.',
                        409,
                    );
                }

                throw $e;
            }
        });
    }

    /**
     * Administer a scheduled MAR dose: scheduled → given | refused | missed |
     * held, CAS-guarded, with the nurse, time, and refusal/miss reason
     * captured. Identity re-confirmation (name + MRN) is enforced at the
     * request layer for 'given' (CLINICAL_SAFETY.md §190).
     */
    public function administerMar(
        MarEntry $entry,
        string $status,
        ?string $reason,
        Staff $nurse,
    ): MarEntry {
        return DB::transaction(function () use ($entry, $status, $reason, $nurse): MarEntry {
            if ($entry->status !== MarEntry::STATUS_SCHEDULED) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'This MAR entry is already administered (current status: '.$entry->status.').',
                    409,
                );
            }

            if (in_array($status, [MarEntry::STATUS_REFUSED, MarEntry::STATUS_MISSED, MarEntry::STATUS_HELD], true)
                && ($reason === null || trim($reason) === '')) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    'A reason is required when a dose is refused, missed, or held.',
                    422,
                );
            }

            $updated = DB::table('mar_entries')
                ->where('tenant_id', $entry->tenant_id)
                ->where('id', $entry->getKey())
                ->where('status', MarEntry::STATUS_SCHEDULED)
                ->update([
                    'status' => $status,
                    'administered_by' => $nurse->getKey(),
                    'administered_at' => now(),
                    'reason' => $reason,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(
                    ErrorCodes::LOCK_CONFLICT,
                    'This MAR entry was administered by another nurse. Reload and retry.',
                    409,
                );
            }

            return $entry->refresh();
        });
    }

    private function assertOpen(Admission $admission): void
    {
        if (! in_array($admission->status, self::OPEN_STATUSES, true)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This admission is not open for nursing documentation (current status: '.$admission->status.').',
                409,
            );
        }
    }
}
