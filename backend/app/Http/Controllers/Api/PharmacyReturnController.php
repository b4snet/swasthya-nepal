<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\ReturnPrescriptionLineRequest;
use App\Models\PharmacyReturn;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Services\PharmacyReturnService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 8 — pharmacy returns & reversals (PRODUCT_REQUIREMENTS §6.7,
 * DATABASE.md §3.30): a pharmacist reverses a dispensed line — reason
 * captured, stock restored, reversal recorded, and the refund path opened
 * against the linked posted charge through the existing billing mechanism.
 *
 * The controller is the HTTP boundary; the whole reversal runs atomically in
 * PharmacyReturnService. Access scope follows the prescription's encounter
 * facility (AccessCheck::prescription — the same anchor dispensing uses).
 */
final class PharmacyReturnController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PharmacyReturnService $returns,
    ) {}

    /**
     * POST /prescription-lines/{prescriptionLine}/return — reverse a
     * dispensed line. Returns the reversal record and the opened refund
     * request (requested — billing approval is a separate step).
     */
    public function store(ReturnPrescriptionLineRequest $request, PrescriptionLine $prescriptionLine): JsonResponse
    {
        $prescriptionLine->load('prescription.encounter');
        AccessCheck::prescription($prescriptionLine->prescription, write: true);

        $context = TenantContext::current();
        $pharmacist = $this->currentPharmacyStaff($prescriptionLine->prescription, $context);

        $result = $this->returns->reverseLine(
            (string) $prescriptionLine->prescription->tenant_id,
            (string) $prescriptionLine->getKey(),
            (string) $request->validated('reasonCode'),
            $request->validated('reasonNote'),
            $pharmacist->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record(
            'pharmacy.returned',
            'pharmacy_return',
            $result['return']->getKey(),
            [
                'prescriptionId' => $result['return']->prescription_id,
                'prescriptionLineId' => $result['return']->prescription_line_id,
                'chargeId' => $result['return']->charge_id,
                'refundRequestId' => $result['refundRequest']->getKey(),
                'quantityMinor' => $result['return']->quantity_minor,
                'reasonCode' => $result['return']->reason_code,
                'returnedByStaffId' => $result['return']->returned_by,
            ],
            $request,
        );

        return Envelope::success(
            data: [
                'return' => $this->present($result['return']),
                'refundRequestId' => $result['refundRequest']->getKey(),
            ],
            status: 201,
            request: $request,
        );
    }

    /**
     * The authenticated user's staff profile in the prescription's tenant.
     */
    private function currentPharmacyStaff(Prescription $prescription, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $prescription->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active staff profile for this user in the prescription\'s tenant.', 403);
        }

        return $staff;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PharmacyReturn $return): array
    {
        return [
            'id' => $return->getKey(),
            'prescriptionId' => $return->prescription_id,
            'prescriptionLineId' => $return->prescription_line_id,
            'chargeId' => $return->charge_id,
            'quantityMinor' => $return->quantity_minor,
            'reasonCode' => $return->reason_code,
            'reasonNote' => $return->reason_note,
            'returnedBy' => $return->returned_by,
            'returnedAt' => $return->returned_at?->toIso8601String(),
        ];
    }
}
