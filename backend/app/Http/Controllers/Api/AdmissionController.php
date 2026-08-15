<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\DischargeAdmissionRequest;
use App\Http\Requests\Admission\StoreAdmissionRequest;
use App\Models\Admission;
use App\Models\Encounter;
use App\Models\Staff;
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
}
