<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\DischargeAdmissionRequest;
use App\Http\Requests\Admission\StoreAdmissionRequest;
use App\Http\Requests\Admission\TransferAdmissionRequest;
use App\Models\Admission;
use App\Models\Encounter;
use App\Models\Staff;
use App\Models\TransferEvent;
use App\Services\AdmissionService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 6 — IPD admission/discharge (PRODUCT_REQUIREMENTS §6.5,
 * DATABASE.md §3.23): a patient is admitted from an open encounter onto a
 * live available bed, and discharged with a structured summary that releases
 * the bed. Bed claims and the discharge transition are CAS-guarded; the
 * discharge summary is a signed clinical note, never an audit payload.
 */
final class AdmissionController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST encounters/{encounter}/admissions — admit from an open encounter.
     */
    public function store(StoreAdmissionRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        $context = TenantContext::current();
        $provider = $this->currentProvider($encounter, $context);

        $admission = $this->admissions->admit(
            $encounter,
            (string) $request->validated('bedId'),
            (string) $request->validated('admissionType'),
            (string) $request->validated('admittingDiagnosis'),
            $provider,
        );

        $this->audit->record(
            'admission.admitted',
            'admission',
            $admission->getKey(),
            [
                'patientId' => $admission->patient_id,
                'encounterId' => $admission->encounter_id,
                'bedId' => $request->validated('bedId'),
                'admissionType' => $admission->admission_type,
                'admissionNumber' => $admission->admission_number,
            ],
            $request,
        );

        return Envelope::success(data: self::present($admission), status: 201, request: $request);
    }

    public function show(Request $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: false);

        $this->audit->record('admission.viewed', 'admission', $admission->getKey(), [
            'patientId' => $admission->patient_id,
            'encounterId' => $admission->encounter_id,
        ], $request);

        return Envelope::success(data: self::present($admission), request: $request);
    }

    /**
     * POST admissions/{admission}/transfer — bed-to-bed/ward-to-ward move
     * with a captured reason (ROADMAP Phase 8, PRODUCT_REQUIREMENTS §6.5).
     * The transfer is doctor-approved (admission:transfer is a clinical
     * authority); the reason stays in the immutable transfer_event and never
     * reaches audit payloads.
     */
    public function transfer(TransferAdmissionRequest $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: true);

        $context = TenantContext::current();
        $actor = $this->currentTransferAuthority($admission, $context);

        [$transferred, $event] = $this->admissions->transfer(
            $admission,
            (string) $request->validated('toBedId'),
            (string) $request->validated('reason'),
            $actor,
        );

        $this->audit->record(
            'admission.transferred',
            'admission',
            $transferred->getKey(),
            [
                'patientId' => $transferred->patient_id,
                'fromBedId' => $event->from_bed_id,
                'toBedId' => $event->to_bed_id,
                'transferredAt' => $event->transferred_at?->toIso8601String(),
            ],
            $request,
        );

        return Envelope::success(data: self::present($transferred), request: $request);
    }

    /**
     * GET admissions/{admission}/transfers — the historical bed timeline
     * (every move, oldest first).
     */
    public function transfers(Request $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: false);

        $events = TransferEvent::query()
            ->where('tenant_id', $admission->tenant_id)
            ->where('admission_id', $admission->getKey())
            ->orderBy('transferred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TransferEvent $event): array => self::presentEvent($event));

        return Envelope::success(data: $events, request: $request);
    }

    /**
     * POST admissions/{admission}/discharge — clinical close + bed release.
     */
    public function discharge(DischargeAdmissionRequest $request, Admission $admission): JsonResponse
    {
        AccessCheck::scoped($admission, write: true);

        $context = TenantContext::current();
        $provider = $this->currentProvider($admission->encounter, $context);

        $discharged = $this->admissions->discharge(
            $admission,
            (string) $request->validated('dischargeType'),
            (array) $request->validated('summary'),
            $provider,
        );

        $this->audit->record(
            'admission.discharged',
            'admission',
            $discharged->getKey(),
            [
                'patientId' => $discharged->patient_id,
                'encounterId' => $discharged->encounter_id,
                'dischargeType' => $discharged->discharge_type,
                'bedReleased' => true,
            ],
            $request,
        );

        return Envelope::success(data: self::present($discharged), request: $request);
    }

    /**
     * The transfer authority: any active doctor/admission:transfer holder in
     * the admission's tenant and facility (ward transfers are a care-team
     * act — the encounter provider need not be the one authorizing a
     * night-time ward move).
     */
    private function currentTransferAuthority(Admission $admission, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $admission->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->facility_id !== $admission->facility_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'You are not authorized to perform this action.', 403);
        }

        return $staff;
    }

    /**
     * The encounter-scoped clinical-actor guard (the established pattern:
     * only the encounter provider documents/admits/discharges this visit).
     */
    private function currentProvider(Encounter $encounter, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->getKey() !== $encounter->provider_staff_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'Only the encounter provider can manage this admission.', 403);
        }

        return $staff;
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Admission $admission): array
    {
        return [
            'id' => $admission->getKey(),
            'facilityId' => $admission->facility_id,
            'patientId' => $admission->patient_id,
            'encounterId' => $admission->encounter_id,
            'admissionNumber' => $admission->admission_number,
            'admissionType' => $admission->admission_type,
            'status' => $admission->status,
            'admittedAt' => $admission->admitted_at?->toIso8601String(),
            'dischargedAt' => $admission->discharged_at?->toIso8601String(),
            'dischargeType' => $admission->discharge_type,
            'dischargeSummaryId' => $admission->discharge_summary_id,
            'lockVersion' => $admission->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentEvent(TransferEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'admissionId' => $event->admission_id,
            'fromBedId' => $event->from_bed_id,
            'toBedId' => $event->to_bed_id,
            'transferredBy' => $event->transferred_by,
            'transferredAt' => $event->transferred_at?->toIso8601String(),
        ];
    }
}
