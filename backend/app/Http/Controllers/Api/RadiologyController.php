<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Radiology\AmendRadiologyReportRequest;
use App\Http\Requests\Radiology\CancelStudyRequest;
use App\Http\Requests\Radiology\DraftRadiologyReportRequest;
use App\Http\Requests\Radiology\PerformStudyRequest;
use App\Http\Requests\Radiology\ScheduleStudyRequest;
use App\Http\Requests\Radiology\StoreImageReferenceRequest;
use App\Http\Requests\Radiology\StoreModalityRequest;
use App\Http\Requests\Radiology\StoreRadiologyOrderRequest;
use App\Http\Requests\Radiology\UpdateModalityRequest;
use App\Http\Requests\Radiology\VerifyRadiologyReportRequest;
use App\Models\Encounter;
use App\Models\LabOrder;
use App\Models\Modality;
use App\Models\Patient;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\Study;
use App\Services\RadiologyService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 16 — Radiology (ROADMAP Phase 11, PRODUCT_REQUIREMENTS
 * §6.9, DATABASE.md §3.29, CLINICAL_SAFETY §8). The clinician orders a
 * study from an open encounter (shared order surface + a `studies` row);
 * the radiology team schedules it on a modality, the radiographer performs
 * it, the radiologist drafts the report, a DIFFERENT radiologist verifies
 * it (preliminary vs final explicit with visible timing), and amendments
 * are new preserved versions. DICOM references attach to performed studies
 * — the composite FK is the no-dangling guarantee.
 */
final class RadiologyController extends Controller
{
    public function __construct(
        private readonly RadiologyService $radiology,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /encounters/{encounter}/radiology-orders — the provider orders
     * one or more imaging studies; the order and the study are created
     * atomically.
     */
    public function storeOrder(StoreRadiologyOrderRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if (! in_array($encounter->status, [Encounter::STATUS_OPEN, Encounter::STATUS_IN_PROGRESS], true)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Imaging can only be ordered on an open encounter (current status: '.$encounter->status.').',
                409,
            );
        }

        $context = TenantContext::current();
        $provider = $this->currentStaff($encounter->tenant_id, $encounter->facility_id);

        [$order, $study] = $this->radiology->createOrder(
            $encounter,
            $request->validated('testIds'),
            $request->validated('priority', LabOrder::PRIORITY_ROUTINE),
            $request->validated('clinicalIndication'),
            $provider->getKey(),
        );

        $this->audit->record(
            'radiology_order.created',
            'lab_order',
            $order->getKey(),
            ['patientId' => $order->patient_id, 'encounterId' => $order->encounter_id, 'orderedByStaffId' => $order->ordered_by_staff_id, 'studyId' => $study->getKey(), 'priority' => $order->priority],
            $request,
        );

        return Envelope::success(data: $this->presentStudy($study->fresh(['modality', 'reports', 'imageReferences'])), status: 201, request: $request);
    }

    /**
     * GET /radiology/queue — the radiology department's worklist:
     * ordered + scheduled studies, priority-aware.
     */
    public function queue(Request $request): JsonResponse
    {
        $rows = Study::query()
            ->join('lab_orders', function ($join): void {
                $join->on('lab_orders.id', '=', 'studies.lab_order_id')
                    ->on('lab_orders.tenant_id', '=', 'studies.tenant_id');
            })
            ->whereIn('studies.status', [Study::STATUS_ORDERED, Study::STATUS_SCHEDULED])
            ->orderByRaw("case lab_orders.priority when 'stat' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('studies.ordered_at')
            ->select('studies.*')
            ->with('modality:id,code,name', 'order:id,priority,patient_id')
            ->get()
            ->map(fn (Study $study): array => $this->presentStudy($study))
            ->values();

        return Envelope::success(data: $rows, request: $request);
    }

    /**
     * GET /radiology/modalities — the facility's imaging machine catalog.
     */
    public function modalities(Request $request): JsonResponse
    {
        $rows = Modality::query()
            ->where('status', '!=', Modality::STATUS_DOWN)
            ->orderBy('code')
            ->get()
            ->map(fn (Modality $modality): array => $this->presentModality($modality))
            ->values();

        return Envelope::success(data: $rows, request: $request);
    }

    /**
     * POST /radiology/modalities — facility admin creates a modality.
     */
    public function storeModality(StoreModalityRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $facility = AccessCheck::facility($context->facilityId(), write: true);

        $modality = Modality::query()->create([
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'modality_type' => $request->validated('modalityType'),
            'daily_capacity' => $request->validated('dailyCapacity'),
            'status' => $request->validated('status', Modality::STATUS_ACTIVE),
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'modality.created',
            'modality',
            $modality->getKey(),
            ['facilityId' => $facility->getKey(), 'code' => $modality->code, 'modalityType' => $modality->modality_type],
            $request,
        );

        return Envelope::success(data: $this->presentModality($modality), status: 201, request: $request);
    }

    /**
     * PATCH /radiology/modalities/{modality} — CAS-update a modality
     * (capacity, status — `down` documents modality downtime).
     */
    public function updateModality(UpdateModalityRequest $request, Modality $modality): JsonResponse
    {
        AccessCheck::scoped($modality, write: true);

        $context = TenantContext::current();

        $affected = Modality::query()
            ->whereKey($modality->getKey())
            ->where('lock_version', $request->validated('lockVersion'))
            ->update([
                'name' => $request->validated('name', $modality->name),
                'daily_capacity' => $request->validated('dailyCapacity', $modality->daily_capacity),
                'status' => $request->validated('status', $modality->status),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by' => $context->user?->getKey(),
            ]);

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::LOCK_CONFLICT,
                'The modality changed concurrently. Refresh and retry.',
                409,
            );
        }

        $this->audit->record(
            'modality.updated',
            'modality',
            $modality->getKey(),
            ['facilityId' => $modality->facility_id, 'code' => $modality->code],
            $request,
        );

        return Envelope::success(data: $this->presentModality($modality->fresh()), request: $request);
    }

    /**
     * GET /studies/{study} — the study with its reports and image refs
     * (full traceability: study → modality → order).
     */
    public function showStudy(Request $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: false);

        $study->load('modality:id,code,name', 'order:id,priority,patient_id,clinical_indication,ordered_by_staff_id', 'reports', 'imageReferences');

        return Envelope::success(data: $this->presentStudy($study), request: $request);
    }

    /**
     * POST /studies/{study}/schedule — ordered → scheduled (modality + slot).
     */
    public function schedule(ScheduleStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $scheduled = $this->radiology->schedule(
            $study,
            $request->validated('modalityId'),
            $request->validated('scheduledAt'),
            $request->validated('preparationInstructions'),
            $request->validated('lockVersion'),
        );

        $this->audit->record(
            'radiology_study.scheduled',
            'study',
            $scheduled->getKey(),
            ['facilityId' => $scheduled->facility_id, 'modalityId' => $scheduled->modality_id, 'scheduledAt' => $scheduled->scheduled_at?->toIso8601String()],
            $request,
        );

        return Envelope::success(data: $this->presentStudy($scheduled->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/perform — scheduled → performed (radiographer).
     */
    public function perform(PerformStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $context = TenantContext::current();
        $actor = $this->currentStaff($study->tenant_id, $study->facility_id);

        $performed = $this->radiology->perform($study, $actor->getKey(), $request->validated('lockVersion'));

        $this->audit->record(
            'radiology_study.performed',
            'study',
            $performed->getKey(),
            ['facilityId' => $performed->facility_id, 'performedByStaffId' => $performed->performed_by_staff_id, 'performedAt' => $performed->performed_at?->toIso8601String()],
            $request,
        );

        return Envelope::success(data: $this->presentStudy($performed->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/cancel — ordered|scheduled → cancelled
     * (terminal, reason required).
     */
    public function cancelStudy(CancelStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $cancelled = $this->radiology->cancel($study, $request->validated('reason'), $request->validated('lockVersion'));

        $this->audit->record(
            'radiology_study.cancelled',
            'study',
            $cancelled->getKey(),
            ['facilityId' => $cancelled->facility_id],
            $request,
        );

        return Envelope::success(data: $this->presentStudy($cancelled->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/report — draft the preliminary/final report
     * (radiology:report). The draft is NOT yet released.
     */
    public function draftReport(DraftRadiologyReportRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $actor = $this->currentStaff($study->tenant_id, $study->facility_id);

        $report = $this->radiology->draftReport(
            $study,
            $request->validated('reportType'),
            $request->validated('content'),
            $request->validated('impression'),
            $request->validated('criticalFindings'),
            $actor->getKey(),
        );

        $this->audit->record(
            'radiology_report.drafted',
            'radiology_report',
            $report->getKey(),
            ['studyId' => $study->getKey(), 'reportType' => $report->report_type, 'reportedByStaffId' => $report->reported_by_staff_id],
            $request,
        );

        return Envelope::success(data: $this->presentReport($report), status: 201, request: $request);
    }

    /**
     * POST /radiology-reports/{report}/verify — draft → preliminary|final.
     * The verifier must hold radiology:verify (route gate) AND be a
     * different staff member than the drafter (entry ≠ verification).
     * A final release advances the study to `reported`.
     */
    public function verifyReport(VerifyRadiologyReportRequest $request, RadiologyReport $report): JsonResponse
    {
        AccessCheck::scoped($report, write: true);

        $actor = $this->currentStaff($report->tenant_id, $report->facility_id);

        $verified = $this->radiology->verifyReport($report, $actor->getKey(), $request->validated('lockVersion'));

        $this->audit->record(
            'radiology_report.verified',
            'radiology_report',
            $verified->getKey(),
            ['studyId' => $verified->study_id, 'reportType' => $verified->report_type, 'status' => $verified->status, 'verifiedByStaffId' => $verified->verified_by_staff_id, 'verifiedAt' => $verified->verified_at?->toIso8601String()],
            $request,
        );

        return Envelope::success(data: $this->presentReport($verified->fresh()), request: $request);
    }

    /**
     * POST /radiology-reports/{report}/amend — the current final is
     * superseded (preserved as 'amended') and a NEW final draft is created
     * with a parent link; it must go through verifyReport again.
     */
    public function amendReport(AmendRadiologyReportRequest $request, RadiologyReport $report): JsonResponse
    {
        AccessCheck::scoped($report, write: true);

        $actor = $this->currentStaff($report->tenant_id, $report->facility_id);

        $amendment = $this->radiology->amendReport(
            $report,
            $request->validated('content'),
            $request->validated('impression'),
            $request->validated('criticalFindings'),
            $actor->getKey(),
        );

        $this->audit->record(
            'radiology_report.amended',
            'radiology_report',
            $amendment->getKey(),
            ['studyId' => $amendment->study_id, 'parentReportId' => $report->getKey(), 'reportedByStaffId' => $amendment->reported_by_staff_id],
            $request,
        );

        return Envelope::success(data: $this->presentReport($amendment), status: 201, request: $request);
    }

    /**
     * POST /studies/{study}/image-references — attach DICOM/PACS references
     * to a performed study (references only, never pixels).
     */
    public function addImageReferences(StoreImageReferenceRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $references = $this->radiology->addImageReferences($study, $request->validated('references'));

        $this->audit->record(
            'radiology_study.image_references',
            'study',
            $study->getKey(),
            ['facilityId' => $study->facility_id, 'referenceCount' => count($references)],
            $request,
        );

        return Envelope::success(data: collect($references)->map(fn ($reference): array => $this->presentImageReference($reference))->values(), status: 201, request: $request);
    }

    /**
     * GET /patients/{patient}/radiology-reports — released (verified)
     * reports for one patient: preliminary AND final, newest verified
     * first; the patient scope is the bound record, so another patient's
     * reports are unreachable through this surface.
     */
    public function forPatient(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $rows = RadiologyReport::query()
            ->whereIn('status', [RadiologyReport::STATUS_PRELIMINARY, RadiologyReport::STATUS_FINAL])
            ->whereHas('study.order', function ($query) use ($patient): void {
                $query->where('patient_id', $patient->getKey());
            })
            ->with('study:id,lab_order_id,modality_id,status,performed_at', 'study.modality:id,code,name')
            ->orderByDesc('verified_at')
            ->get()
            ->map(fn (RadiologyReport $report): array => $this->presentReport($report))
            ->values();

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

    /**
     * @return array<string, mixed>
     */
    private function presentModality(Modality $modality): array
    {
        return [
            'id' => $modality->getKey(),
            'facilityId' => $modality->facility_id,
            'code' => $modality->code,
            'name' => $modality->name,
            'modalityType' => $modality->modality_type,
            'dailyCapacity' => $modality->daily_capacity,
            'status' => $modality->status,
            'lockVersion' => $modality->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudy(Study $study): array
    {
        return [
            'id' => $study->getKey(),
            'facilityId' => $study->facility_id,
            'orderId' => $study->lab_order_id,
            'modalityId' => $study->modality_id,
            'status' => $study->status,
            'orderedAt' => $study->ordered_at?->toIso8601String(),
            'scheduledAt' => $study->scheduled_at?->toIso8601String(),
            'performedAt' => $study->performed_at?->toIso8601String(),
            'performedByStaffId' => $study->performed_by_staff_id,
            'preparationInstructions' => $study->preparation_instructions,
            'cancelReason' => $study->cancel_reason,
            'lockVersion' => $study->lock_version,
            'modality' => $study->relationLoaded('modality') && $study->modality !== null
                ? ['id' => $study->modality->getKey(), 'code' => $study->modality->code, 'name' => $study->modality->name]
                : null,
            'reports' => $study->relationLoaded('reports')
                ? $study->reports->map(fn (RadiologyReport $report): array => $this->presentReport($report))->values()
                : [],
            'imageReferences' => $study->relationLoaded('imageReferences')
                ? $study->imageReferences->map(fn ($reference): array => $this->presentImageReference($reference))->values()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReport(RadiologyReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'facilityId' => $report->facility_id,
            'studyId' => $report->study_id,
            'reportType' => $report->report_type,
            'status' => $report->status,
            'content' => $report->content,
            'impression' => $report->impression,
            'criticalFindings' => $report->critical_findings,
            'reportedByStaffId' => $report->reported_by_staff_id,
            'reportedAt' => $report->reported_at?->toIso8601String(),
            'verifiedByStaffId' => $report->verified_by_staff_id,
            'verifiedAt' => $report->verified_at?->toIso8601String(),
            'parentReportId' => $report->parent_report_id,
            'lockVersion' => $report->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentImageReference($reference): array
    {
        return [
            'id' => $reference->getKey(),
            'facilityId' => $reference->facility_id,
            'studyId' => $reference->study_id,
            'referenceType' => $reference->reference_type,
            'referenceValue' => $reference->reference_value,
            'description' => $reference->description,
        ];
    }
}
