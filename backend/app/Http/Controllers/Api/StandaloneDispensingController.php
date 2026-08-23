<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreDispensingRequest;
use App\Models\Charge;
use App\Models\Dispensing;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\PharmacyService;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * STANDALONE dispensing (PRODUCT_REQUIREMENTS §6.7 `dispensing` entity and
 * the stock-out mode "dispensing" WITHOUT a prescription; DATABASE.md
 * §3.30; the documented remaining-scope "standalone `dispensings` table").
 *
 * A pharmacist dispenses a medication directly to a patient (walk-in/OTC-
 * style) with NO prescription, reusing the SAME machinery as prescription
 * dispensing: FEFO/explicit batch selection, the batch + shelf CAS stock
 * deductions (no second stock truth — the ledger is the single movement
 * record, now referencing the standalone row), and a posted charge with
 * source_type = 'dispensing'. The whole operation is ONE transaction: any
 * failure rolls back the dispensing row, the deductions, the movement, and
 * the charge.
 *
 * Safety boundaries (documented, not invented):
 *  - A dual-required controlled batch is REFUSED here (409) — the dual-
 *    verification surface exists only on prescription lines; standalone
 *    dispensing must never issue an unverified controlled substance.
 *  - The patient must be in the pharmacist's own tenant + facility
 *    (AccessCheck::scoped → 404 outside scope, existence hidden).
 *  - Rejected operations write nothing and audit nothing.
 */
final class StandaloneDispensingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PharmacyService $pharmacy,
    ) {}

    /**
     * POST /dispensings — dispense a medication directly to a patient
     * (no prescription). Returns the dispensing record and its posted
     * charge (the pharmacist's financial trace for billing).
     */
    public function store(StoreDispensingRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $pharmacist = $this->currentPharmacyStaff($context);

        $tenantId = (string) $pharmacist->tenant_id;
        $facilityId = (string) $pharmacist->facility_id;

        // The patient must be in this tenant AND facility — the scoped
        // query makes a wrong-tenant or wrong-facility patient a 404
        // (existence is never leaked), matching the read-scoped checks.
        $patient = Patient::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $request->validated('patientId'))
            ->first();

        if ($patient === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        // The medication must be an active formulary item in this facility.
        $medication = Medication::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $request->validated('medicationId'))
            ->where('status', Medication::STATUS_ACTIVE)
            ->first();

        if ($medication === null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The medication must be an active formulary item in this facility.', 422);
        }

        // The stock shelf at this facility.
        $item = InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('medication_id', $medication->getKey())
            ->first();

        if ($item === null) {
            throw new ApiException(ErrorCodes::CONFLICT, 'No stock is configured for '.$medication->generic_name.' at this facility.', 409);
        }

        $quantity = (int) $request->validated('quantityMinor');
        $batchId = $request->validated('batchId');

        $dispensing = DB::transaction(function () use ($tenantId, $facilityId, $patient, $medication, $item, $quantity, $batchId, $pharmacist, $context): Dispensing {
            $userId = $context->user?->getKey();

            // Resolve the batch: explicit selection (validated against the
            // medication + expiry/availability) or FEFO — same rule as
            // prescription dispensing.
            $batch = $batchId !== null
                ? $this->pharmacy->resolveSelectedBatch($tenantId, $facilityId, $medication->getKey(), (string) $batchId)
                : $this->pharmacy->fefoBatch($tenantId, $facilityId, $medication->getKey());

            if ($batch === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'No available, unexpired batch has stock for '.$medication->generic_name.' at this facility.', 409);
            }

            // Dual-required controlled batches are prescription-only: the
            // dual-verification surface lives on prescription lines, so a
            // standalone dispense of one would issue an unverified
            // controlled substance — refused.
            if ((bool) $medication->is_controlled && $batch->controlled_dispense_requires_dual) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This controlled substance requires dual verification and can only be dispensed against a prescription.', 409);
            }

            // The immutable standalone record (created first so the ledger
            // movement and the charge can reference it).
            $dispensing = Dispensing::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patient->getKey(),
                'medication_id' => $medication->getKey(),
                'inventory_item_id' => $item->getKey(),
                'stock_batch_id' => $batch->getKey(),
                'batch_number' => $batch->batch_number,
                'batch_expires_at' => $batch->expiry_date->toDateString(),
                'quantity_minor' => $quantity,
                'status' => Dispensing::STATUS_DISPENSED,
                'dispensed_by_staff_id' => $pharmacist->getKey(),
                'dispensed_at' => now(),
                'created_by' => $userId,
            ]);

            // The SAME stock CAS machinery as prescription dispensing.
            $this->pharmacy->deductFromBatch($batch, $quantity, $userId);
            $this->pharmacy->deductShelf($item, $quantity, $userId);

            InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'inventory_item_id' => $item->getKey(),
                'movement_type' => InventoryMovement::TYPE_DISPENSE,
                'quantity_delta' => -$quantity,
                'reason' => $medication->generic_name.' standalone dispense (batch '.$batch->batch_number.')',
                // No prescription_line_id — the standalone record is the source.
                'stock_batch_id' => $batch->getKey(),
                'dispensing_id' => $dispensing->getKey(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            // The posted charge — immutable, source_type 'dispensing',
            // price × quantity in integer minor units (same money math).
            Charge::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patient->getKey(),
                'source_type' => Charge::SOURCE_DISPENSING,
                'dispensing_id' => $dispensing->getKey(),
                'description' => $medication->generic_name.' ('.$medication->strength.') × '.$quantity,
                'amount_minor' => $medication->price_minor * $quantity,
                'currency' => $medication->currency,
                ...Charge::resolveTaxFields($facilityId, 'pharmacy'),
                'status' => Charge::STATUS_POSTED,
                'charged_at' => now(),
                'created_by' => $userId,
            ]);

            return $dispensing;
        });

        $charge = Charge::query()
            ->where('tenant_id', $tenantId)
            ->where('dispensing_id', $dispensing->getKey())
            ->first();

        $this->audit->record(
            'pharmacy.standalone_dispensed',
            'dispensing',
            $dispensing->getKey(),
            [
                'patientId' => $dispensing->patient_id,
                'medicationId' => $dispensing->medication_id,
                'quantityMinor' => $dispensing->quantity_minor,
                'totalAmountMinor' => $charge?->amount_minor ?? 0,
                'batchId' => $dispensing->stock_batch_id,
                'dispensedByStaffId' => $dispensing->dispensed_by_staff_id,
            ],
            $request,
        );

        return Envelope::success(data: $this->present($dispensing, $charge), status: 201, request: $request);
    }

    /**
     * The authenticated user's active staff profile. Standalone dispensing
     * happens at the pharmacist's own facility (the pharmacist role is
     * facility-scoped).
     */
    private function currentPharmacyStaff(TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->facility_id === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active facility-scoped staff profile for this user.', 403);
        }

        return $staff;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Dispensing $dispensing, ?Charge $charge): array
    {
        return [
            'id' => $dispensing->getKey(),
            'patientId' => $dispensing->patient_id,
            'medicationId' => $dispensing->medication_id,
            'batchId' => $dispensing->stock_batch_id,
            'batchNumber' => $dispensing->batch_number,
            'batchExpiresAt' => $dispensing->batch_expires_at?->toDateString(),
            'quantityMinor' => $dispensing->quantity_minor,
            'status' => $dispensing->status,
            'dispensedByStaffId' => $dispensing->dispensed_by_staff_id,
            'dispensedAt' => $dispensing->dispensed_at?->toIso8601String(),
            'chargeId' => $charge?->getKey(),
            'totalAmountMinor' => $charge?->amount_minor ?? 0,
        ];
    }
}
