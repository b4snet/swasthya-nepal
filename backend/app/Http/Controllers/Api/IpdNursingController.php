<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IpdNursing\AdministerMarEntryRequest;
use App\Http\Requests\IpdNursing\ScheduleMarEntryRequest;
use App\Http\Requests\IpdNursing\StoreNursingNoteRequest;
use App\Http\Requests\IpdNursing\StoreVitalObservationRequest;
use App\Models\Admission;
use App\Models\MarEntry;
use App\Models\NursingNote;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Models\VitalObservation;
use App\Services\IpdNursingService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 13 — the IPD nursing surface (DATABASE.md §3.27,
 * PRODUCT_REQUIREMENTS §6.5): structured nursing notes (draft → signed),
 * vital observations, and the MAR (scheduled dose → given/refused/missed/
 * held). Every write is the care-team act of an active nurse in the
 * admission's tenant and facility; audit payloads carry facts and ids only —
 * note content, vital values, and MAR reasons are clinical PHI that never
 * reach audit_events.
 */
final class IpdNursingController extends Controller
{
    public function __construct(
        private readonly IpdNursingService $nursing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST admissions/{admission}/nursing-notes — create a draft note.
     */
    public function storeNote(StoreNursingNoteRequest $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: true);

        $nurse = $this->currentNurse($admission);

        $note = $this->nursing->createNote(
            $admission,
            (array) $request->validated('content'),
            $nurse,
        );

        $this->audit->record(
            'nursing_note.created',
            'nursing_note',
            $note->getKey(),
            [
                'admissionId' => $admission->getKey(),
                'patientId' => $admission->patient_id,
                'status' => $note->status,
            ],
            $request,
        );

        return Envelope::success(data: self::presentNote($note), status: 201, request: $request);
    }

    /**
     * POST nursing-notes/{nursingNote}/sign — the author signs their draft
     * once; signed notes are immutable.
     */
    public function signNote(Request $request, NursingNote $nursingNote): JsonResponse
    {
        AccessCheck::scoped($nursingNote, write: true);

        $nurse = $this->currentNurse($nursingNote->admission);

        $signed = $this->nursing->signNote($nursingNote, $nurse);

        $this->audit->record(
            'nursing_note.signed',
            'nursing_note',
            $signed->getKey(),
            [
                'admissionId' => $signed->admission_id,
                'patientId' => $signed->admission->patient_id,
            ],
            $request,
        );

        return Envelope::success(data: self::presentNote($signed), request: $request);
    }

    /**
     * GET admissions/{admission}/nursing-notes — the admission's notes,
     * oldest first.
     */
    public function indexNotes(Request $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: false);

        $notes = NursingNote::query()
            ->where('tenant_id', $admission->tenant_id)
            ->where('admission_id', $admission->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (NursingNote $note): array => self::presentNote($note));

        return Envelope::success(data: $notes, request: $request);
    }

    /**
     * POST admissions/{admission}/vitals — record a vital observation.
     */
    public function recordVital(StoreVitalObservationRequest $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: true);

        $nurse = $this->currentNurse($admission);

        $vital = $this->nursing->recordVital(
            $admission,
            (string) $request->validated('type'),
            (array) $request->validated('value'),
            $request->date('measuredAt', null) ?? now(),
            $nurse,
        );

        $this->audit->record(
            'vital_observation.recorded',
            'vital_observation',
            $vital->getKey(),
            [
                'admissionId' => $admission->getKey(),
                'patientId' => $admission->patient_id,
                'type' => $vital->type,
                'measuredAt' => $vital->measured_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: self::presentVital($vital), status: 201, request: $request);
    }

    /**
     * GET admissions/{admission}/vitals — the admission's observations,
     * earliest first.
     */
    public function indexVitals(Request $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: false);

        $vitals = VitalObservation::query()
            ->where('tenant_id', $admission->tenant_id)
            ->where('admission_id', $admission->getKey())
            ->orderBy('measured_at')
            ->orderBy('id')
            ->get()
            ->map(fn (VitalObservation $vital): array => self::presentVital($vital));

        return Envelope::success(data: $vitals, request: $request);
    }

    /**
     * POST admissions/{admission}/mar — schedule a dose of a prescription
     * line (one per line + time, DB-enforced).
     */
    public function scheduleMar(ScheduleMarEntryRequest $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: true);

        $nurse = $this->currentNurse($admission);

        $entry = $this->nursing->scheduleMar(
            $admission,
            $this->lineInScope($admission, (string) $request->validated('prescriptionLineId')),
            $request->date('scheduledAt'),
            $nurse,
        );

        $this->audit->record(
            'mar_entry.scheduled',
            'mar_entry',
            $entry->getKey(),
            [
                'admissionId' => $admission->getKey(),
                'patientId' => $admission->patient_id,
                'prescriptionLineId' => $entry->prescription_line_id,
                'scheduledAt' => $entry->scheduled_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: self::presentMar($entry), status: 201, request: $request);
    }

    /**
     * POST mar-entries/{marEntry}/administer — scheduled → given | refused |
     * missed | held (identity-confirmed for given, reason captured).
     */
    public function administerMar(AdministerMarEntryRequest $request, MarEntry $marEntry): JsonResponse
    {
        AccessCheck::scoped($marEntry, write: true);

        $nurse = $this->currentNurse($marEntry->admission);

        $administered = $this->nursing->administerMar(
            $marEntry,
            (string) $request->validated('status'),
            $request->validated('reason'),
            $nurse,
        );

        $this->audit->record(
            'mar_entry.administered',
            'mar_entry',
            $administered->getKey(),
            [
                'admissionId' => $administered->admission_id,
                'patientId' => $administered->admission->patient_id,
                'prescriptionLineId' => $administered->prescription_line_id,
                'status' => $administered->status,
                'scheduledAt' => $administered->scheduled_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: self::presentMar($administered), request: $request);
    }

    /**
     * GET admissions/{admission}/mar — the admission's MAR, chronological.
     */
    public function indexMar(Request $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: false);

        $entries = MarEntry::query()
            ->where('tenant_id', $admission->tenant_id)
            ->where('admission_id', $admission->getKey())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(fn (MarEntry $entry): array => self::presentMar($entry));

        return Envelope::success(data: $entries, request: $request);
    }

    /**
     * The active nurse in the admission's tenant and facility. Nursing acts
     * are the care-team act of any in-scope nurse (unlike the encounter
     * provider guard for admit/discharge).
     */
    private function currentNurse(Admission $admission): Staff
    {
        $context = TenantContext::current();
        $nurse = $context->user?->staff()
            ->where('tenant_id', $admission->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($nurse === null || $nurse->facility_id !== $admission->facility_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'You are not authorized to perform this action.', 403);
        }

        return $nurse;
    }

    /**
     * A prescription line in the admission's tenant (existence is hidden out
     * of scope; patient/status gates live in the service).
     */
    private function lineInScope(Admission $admission, string $lineId): PrescriptionLine
    {
        $line = PrescriptionLine::query()
            ->where('tenant_id', $admission->tenant_id)
            ->where('id', $lineId)
            ->first();

        if ($line === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Prescription line not found.', 404);
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentNote(NursingNote $note): array
    {
        return [
            'id' => $note->getKey(),
            'admissionId' => $note->admission_id,
            'authorStaffId' => $note->author_staff_id,
            'status' => $note->status,
            'signedAt' => $note->signed_at?->toIso8601String(),
            'createdAt' => $note->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentVital(VitalObservation $vital): array
    {
        return [
            'id' => $vital->getKey(),
            'admissionId' => $vital->admission_id,
            'patientId' => $vital->patient_id,
            'type' => $vital->type,
            'value' => $vital->value,
            'measuredAt' => $vital->measured_at?->toIso8601String(),
            'measuredBy' => $vital->measured_by,
            'isAbnormal' => $vital->is_abnormal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentMar(MarEntry $entry): array
    {
        return [
            'id' => $entry->getKey(),
            'admissionId' => $entry->admission_id,
            'prescriptionLineId' => $entry->prescription_line_id,
            'scheduledAt' => $entry->scheduled_at?->toIso8601String(),
            'status' => $entry->status,
            'administeredBy' => $entry->administered_by,
            'administeredAt' => $entry->administered_at?->toIso8601String(),
        ];
    }
}
