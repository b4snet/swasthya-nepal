<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Radiology\AmendRadiologyReportRequest;
use App\Http\Requests\Radiology\ArriveStudyRequest;
use App\Http\Requests\Radiology\AssignTechnicianRequest;
use App\Http\Requests\Radiology\CancelStudyRequest;
use App\Http\Requests\Radiology\CompleteAcquisitionRequest;
use App\Http\Requests\Radiology\DraftRadiologyReportRequest;
use App\Http\Requests\Radiology\MarkPendingInterpretationRequest;
use App\Http\Requests\Radiology\PerformStudyRequest;
use App\Http\Requests\Radiology\RejectStudyRequest;
use App\Http\Requests\Radiology\ReleaseToClinicianRequest;
use App\Http\Requests\Radiology\RescheduleStudyRequest;
use App\Http\Requests\Radiology\ScheduleStudyRequest;
use App\Http\Requests\Radiology\StartAcquisitionRequest;
use App\Http\Requests\Radiology\StoreImageReferenceRequest;
use App\Http\Requests\Radiology\StoreModalityRequest;
use App\Http\Requests\Radiology\StoreModalityScheduleExceptionRequest;
use App\Http\Requests\Radiology\StoreRadiologyOrderRequest;
use App\Http\Requests\Radiology\UpdateModalityRequest;
use App\Http\Requests\Radiology\UpdateModalityScheduleExceptionRequest;
use App\Http\Requests\Radiology\VerifyRadiologyReportRequest;
use App\Models\Encounter;
use App\Models\ImageReference;
use App\Models\LabOrder;
use App\Models\Modality;
use App\Models\ModalityScheduleException;
use App\Models\Patient;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\Study;
use App\Models\StudyEvent;
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
     * GET /radiology/worklist — the radiologist's interpretation worklist:
     * performed studies without a verified final report, priority-aware.
     */
    public function worklist(Request $request): JsonResponse
    {
        $rows = Study::query()
            ->join('lab_orders', function ($join): void {
                $join->on('lab_orders.id', '=', 'studies.lab_order_id')
                    ->on('lab_orders.tenant_id', '=', 'studies.tenant_id');
            })
            ->where('studies.status', Study::STATUS_PERFORMED)
            ->whereDoesntHave('reports', function ($query): void {
                $query->whereIn('status', [
                    RadiologyReport::STATUS_DRAFT,
                    RadiologyReport::STATUS_PRELIMINARY,
                    RadiologyReport::STATUS_FINAL,
                ]);
            })
            ->orderByRaw("case lab_orders.priority when 'stat' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('studies.performed_at')
            ->select('studies.*')
            ->with('modality:id,code,name', 'order:id,priority,patient_id,clinical_indication')
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

        $performed = $this->radiology->perform($study, $actor->getKey(), $request->validated('lockVersion'), $request->validated('procedureStartedAt'));

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
     * GET /patients/{patient}/imaging-history — longitudinal imaging history:
     * all studies for a patient across encounters, ordered by date descending.
     */
    public function imagingHistory(Patient $patient, Request $request): JsonResponse
    {
        AccessCheck::scoped($patient, read: true);

        $studies = Study::query()
            ->join('lab_orders', function ($join): void {
                $join->on('lab_orders.id', '=', 'studies.lab_order_id')
                    ->on('lab_orders.tenant_id', '=', 'studies.tenant_id');
            })
            ->where('lab_orders.patient_id', $patient->getKey())
            ->where('studies.tenant_id', TenantContext::tenantId())
            ->with(['modality:id,code,name', 'reports:id,study_id,status,report_type,critical_findings,reported_at,verified_at', 'imageReferences:id,study_id,reference_type,reference_value'])
            ->orderByDesc('studies.ordered_at')
            ->paginate(25);

        return Envelope::success(data: $studies->through(fn (Study $study): array => $this->presentStudy($study)), request: $request);
    }

    /**
     * GET /radiology/stats — radiology department statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = TenantContext::tenantId();
        $facilityId = TenantContext::facilityId();

        $query = Study::where('tenant_id', $tenantId);
        if ($facilityId) {
            $query->where('facility_id', $facilityId);
        }

        $pending = (clone $query)->where('status', Study::STATUS_ORDERED)->count();
        $scheduled = (clone $query)->where('status', Study::STATUS_SCHEDULED)->count();
        $performed = (clone $query)->where('status', Study::STATUS_PERFORMED)->count();
        $reported = (clone $query)->where('status', Study::STATUS_REPORTED)->count();
        $cancelled = (clone $query)->where('status', Study::STATUS_CANCELLED)->count();

        $criticalPending = RadiologyReport::where('tenant_id', $tenantId)
            ->whereNotNull('critical_findings')
            ->where('status', '!=', RadiologyReport::STATUS_FINAL)
            ->count();

        return Envelope::success(data: [
            'pending' => $pending,
            'scheduled' => $scheduled,
            'performed' => $performed,
            'reported' => $reported,
            'cancelled' => $cancelled,
            'critical_pending' => $criticalPending,
        ], request: $request);
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
            'procedureStartedAt' => $study->procedure_started_at?->toIso8601String(),
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

    // ──────────────────── Extended Study Lifecycle ───────────────────────

    /**
     * POST /studies/{study}/assign-technician — assign a radiographer.
     */
    public function assignTechnician(AssignTechnicianRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $assigned = $this->radiology->assignTechnician($study, $request->validated('technicianStaffId'), $request->validated('lockVersion'));

        $this->audit->record('study.technician_assigned', 'study', $assigned->getKey(), ['technicianStaffId' => $assigned->assigned_technician_id], $request);

        return Envelope::success(data: $this->presentStudy($assigned->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/arrive — scheduled → arrived (patient check-in).
     */
    public function arrive(ArriveStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $arrived = $this->radiology->arrive($study, $request->validated('lockVersion'));

        $this->audit->record('study.arrived', 'study', $arrived->getKey(), [], $request);

        return Envelope::success(data: $this->presentStudy($arrived->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/start-acquisition — arrived → in_progress.
     */
    public function startAcquisition(StartAcquisitionRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $started = $this->radiology->startAcquisition($study, $request->validated('lockVersion'), $request->validated('procedureStartedAt'));

        $this->audit->record('study.acquisition_started', 'study', $started->getKey(), ['procedureStartedAt' => $started->procedure_started_at?->toIso8601String()], $request);

        return Envelope::success(data: $this->presentStudy($started->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/complete-acquisition — in_progress → acquired.
     */
    public function completeAcquisition(CompleteAcquisitionRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $acquired = $this->radiology->completeAcquisition($study, $request->validated('performedByStaffId'), $request->validated('lockVersion'), $request->validated('acquisitionNotes'));

        $this->audit->record('study.acquisition_completed', 'study', $acquired->getKey(), ['performedByStaffId' => $acquired->performed_by_staff_id], $request);

        return Envelope::success(data: $this->presentStudy($acquired->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/mark-pending-interpretation — acquired → pending_interpretation.
     */
    public function markPendingInterpretation(MarkPendingInterpretationRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $pending = $this->radiology->markPendingInterpretation($study, $request->validated('lockVersion'));

        $this->audit->record('study.pending_interpretation', 'study', $pending->getKey(), [], $request);

        return Envelope::success(data: $this->presentStudy($pending->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/reject — performed/acquired → rejected (quality issue).
     */
    public function rejectStudy(RejectStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $rejected = $this->radiology->reject($study, $request->validated('reason'), $request->validated('lockVersion'));

        $this->audit->record('study.rejected', 'study', $rejected->getKey(), ['reason' => $rejected->cancel_reason], $request);

        return Envelope::success(data: $this->presentStudy($rejected->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/release-to-clinician — verified → released_to_clinician (result delivery).
     */
    public function releaseToClinician(ReleaseToClinicianRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $released = $this->radiology->releaseToClinician($study, $request->validated('lockVersion'));

        $this->audit->record('study.released_to_clinician', 'study', $released->getKey(), ['releasedAt' => $released->released_to_clinician_at?->toIso8601String()], $request);

        return Envelope::success(data: $this->presentStudy($released->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    /**
     * POST /studies/{study}/reschedule — reschedule a scheduled study.
     */
    public function reschedule(RescheduleStudyRequest $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: true);

        $rescheduled = $this->radiology->reschedule($study, $request->validated('modalityId'), $request->validated('scheduledAt'), $request->validated('rescheduleReason'), $request->validated('lockVersion'));

        $this->audit->record('study.rescheduled', 'study', $rescheduled->getKey(), ['modalityId' => $rescheduled->modality_id, 'scheduledAt' => $rescheduled->scheduled_at?->toIso8601String()], $request);

        return Envelope::success(data: $this->presentStudy($rescheduled->fresh(['modality', 'reports', 'imageReferences'])), request: $request);
    }

    // ──────────────────── Modality Schedule Exceptions ───────────────────

    /**
     * GET /radiology/modalities/{modality}/schedule-exceptions — list exceptions.
     */
    public function modalityScheduleExceptions(Request $request, Modality $modality): JsonResponse
    {
        AccessCheck::scoped($modality, write: false);

        $exceptions = ModalityScheduleException::query()
            ->where('tenant_id', $modality->tenant_id)
            ->where('facility_id', $modality->facility_id)
            ->where('modality_id', $modality->getKey())
            ->whereNull('deleted_at')
            ->orderBy('exception_date')
            ->get()
            ->map(fn (ModalityScheduleException $ex): array => $this->presentScheduleException($ex))
            ->values();

        return Envelope::success(data: $exceptions, request: $request);
    }

    /**
     * POST /radiology/modalities/{modality}/schedule-exceptions — create exception.
     */
    public function storeModalityScheduleException(StoreModalityScheduleExceptionRequest $request, Modality $modality): JsonResponse
    {
        AccessCheck::scoped($modality, write: true);

        $exception = ModalityScheduleException::query()->create([
            'tenant_id' => $modality->tenant_id,
            'facility_id' => $modality->facility_id,
            'modality_id' => $modality->getKey(),
            'exception_date' => $request->validated('exceptionDate'),
            'start_time' => $request->validated('startTime'),
            'end_time' => $request->validated('endTime'),
            'reason' => $request->validated('reason'),
            'is_blocked' => $request->validated('isBlocked', true),
            'lock_version' => 0,
            'created_by' => TenantContext::current()->user?->getKey(),
        ]);

        $this->audit->record('modality_schedule_exception.created', 'modality_schedule_exception', $exception->getKey(), ['modalityId' => $modality->getKey(), 'exceptionDate' => $exception->exception_date], $request);

        return Envelope::success(data: $this->presentScheduleException($exception), status: 201, request: $request);
    }

    /**
     * PATCH /radiology/modalities/{modality}/schedule-exceptions/{exception} — update exception.
     */
    public function updateModalityScheduleException(UpdateModalityScheduleExceptionRequest $request, Modality $modality, ModalityScheduleException $exception): JsonResponse
    {
        AccessCheck::scoped($modality, write: true);
        AccessCheck::scoped($exception, write: true);

        $affected = ModalityScheduleException::query()
            ->whereKey($exception->getKey())
            ->where('lock_version', $request->validated('lockVersion'))
            ->update([
                'exception_date' => $request->validated('exceptionDate', $exception->exception_date),
                'start_time' => $request->validated('startTime', $exception->start_time),
                'end_time' => $request->validated('endTime', $exception->end_time),
                'reason' => $request->validated('reason', $exception->reason),
                'is_blocked' => $request->validated('isBlocked', $exception->is_blocked),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by' => TenantContext::current()->user?->getKey(),
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The schedule exception changed concurrently. Refresh and retry.', 409);
        }

        $this->audit->record('modality_schedule_exception.updated', 'modality_schedule_exception', $exception->getKey(), ['modalityId' => $modality->getKey()], $request);

        return Envelope::success(data: $this->presentScheduleException($exception->fresh()), request: $request);
    }

    /**
     * DELETE /radiology/modalities/{modality}/schedule-exceptions/{exception} — delete exception.
     */
    public function deleteModalityScheduleException(Request $request, Modality $modality, ModalityScheduleException $exception): JsonResponse
    {
        AccessCheck::scoped($modality, write: true);
        AccessCheck::scoped($exception, write: true);

        $exception->delete();

        $this->audit->record('modality_schedule_exception.deleted', 'modality_schedule_exception', $exception->getKey(), ['modalityId' => $modality->getKey()], $request);

        return Envelope::success(data: ['deleted' => true], request: $request);
    }

    // ──────────────────── Study Events ──────────────────────────────────

    /**
     * GET /studies/{study}/events — study event timeline.
     */
    public function studyEvents(Request $request, Study $study): JsonResponse
    {
        AccessCheck::scoped($study, write: false);

        $events = StudyEvent::query()
            ->where('tenant_id', $study->tenant_id)
            ->where('study_id', $study->getKey())
            ->with('actor:id,full_name,designation')
            ->orderBy('created_at')
            ->get()
            ->map(fn (StudyEvent $event): array => $this->presentStudyEvent($event))
            ->values();

        return Envelope::success(data: $events, request: $request);
    }

    // ──────────────────── Presenters ────────────────────────────────────

    private function presentScheduleException(ModalityScheduleException $ex): array
    {
        return [
            'id' => $ex->getKey(),
            'modalityId' => $ex->modality_id,
            'exceptionDate' => $ex->exception_date,
            'startTime' => $ex->start_time?->format('H:i'),
            'endTime' => $ex->end_time?->format('H:i'),
            'reason' => $ex->reason,
            'isBlocked' => $ex->is_blocked,
            'lockVersion' => $ex->lock_version,
        ];
    }

    private function presentStudyEvent(StudyEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'eventType' => $event->event_type,
            'eventDescription' => $event->event_description,
            'actorStaffId' => $event->actor_staff_id,
            'actorName' => $event->actor?->full_name,
            'actorDesignation' => $event->actor?->designation,
            'metadata' => $event->metadata,
            'createdAt' => $event->created_at?->toIso8601String(),
        ];
    }
}
