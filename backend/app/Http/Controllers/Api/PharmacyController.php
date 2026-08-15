<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacy dispensing (DATABASE.md §3.23, PRODUCT_REQUIREMENTS §6.9):
 *
 *   pharmacist verifies the prescription (drafted → active) →
 *   stock is checked → dispense (active → dispensed) —
 *   each ordered line's stock is deducted atomically, the line is marked
 *   dispensed, a charge is posted, and the whole operation is one
 *   transaction: a single line that cannot be filled rolls back everything
 *   (no partial dispensing, no partial deduction).
 *
 * The stock deduction is a compare-and-swap on (quantity_on_hand,
 * lock_version) — two concurrent dispenses of the same shelf cannot both
 * succeed or drive stock negative. Verification is a required step before
 * dispensing and is recorded on the header.
 */
final class PharmacyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /prescriptions/{prescription} — the pharmacy's view: header, lines
     * with their medication, and the current on-hand quantity at the
     * facility (the stock-check step of the workflow).
     */
    public function show(Request $request, Prescription $prescription): JsonResponse
    {
        AccessCheck::prescription($prescription, write: false);

        $prescription->load('lines.medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency', 'encounter');

        return Envelope::success(data: $this->present($prescription), request: $request);
    }

    /**
     * POST /prescriptions/{prescription}/verify — drafted → active. The
     * pharmacist confirms the order is complete and clinically dispensible;
     * the stamp is recorded on the header (verified_by, verified_at).
     */
    public function verify(Request $request, Prescription $prescription): JsonResponse
    {
        AccessCheck::prescription($prescription, write: true);
        $this->guardStatus($prescription, Prescription::STATUS_DRAFTED, 'verified');

        $context = TenantContext::current();
        $pharmacist = $this->currentPharmacyStaff($prescription, $context);

        if ($prescription->lines()->where('status', PrescriptionLine::STATUS_ORDERED)->count() === 0) {
            throw new ApiException(ErrorCodes::CONFLICT, 'A prescription must have at least one ordered line before it can be verified.', 409);
        }

        $this->applyTransition($prescription, Prescription::STATUS_DRAFTED, [
            'status' => Prescription::STATUS_ACTIVE,
            'verified_by_staff_id' => $pharmacist->getKey(),
            'verified_at' => now(),
        ], $context);

        $this->audit->record(
            'pharmacy.verified',
            'prescription',
            $prescription->getKey(),
            ['patientId' => $prescription->patient_id, 'encounterId' => $prescription->encounter_id, 'lineCount' => $prescription->lines()->count(), 'verifiedByStaffId' => $pharmacist->getKey()],
            $request,
        );

        return Envelope::success(data: $this->present($prescription->fresh(['lines.medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency', 'encounter'])), request: $request);
    }

    /**
     * POST /prescriptions/{prescription}/dispense — active → dispensed.
     * One atomic transaction: for every ordered line, check stock, deduct it
     * (CAS), record the ledger movement, mark the line dispensed, and post
     * the charge (price × quantity, minor units — the same money math as
     * billing). Any shortfall rolls the whole dispense back.
     */
    public function dispense(Request $request, Prescription $prescription): JsonResponse
    {
        AccessCheck::prescription($prescription, write: true);
        $this->guardStatus($prescription, Prescription::STATUS_ACTIVE, 'dispensed');

        $context = TenantContext::current();
        $pharmacist = $this->currentPharmacyStaff($prescription, $context);

        $totalMinor = DB::transaction(function () use ($prescription, $context, $pharmacist): int {
            $encounter = $prescription->encounter;

            if ($encounter === null) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This prescription has no encounter; it cannot be dispensed.', 409);
            }

            $lines = $prescription->lines()
                ->where('status', PrescriptionLine::STATUS_ORDERED)
                ->with('medication:id,generic_name,brand_name,strength,unit,price_minor,currency')
                ->get();

            if ($lines->isEmpty()) {
                throw new ApiException(ErrorCodes::CONFLICT, 'There are no ordered lines to dispense.', 409);
            }

            $totalMinor = 0;

            foreach ($lines as $line) {
                $medication = $line->medication;

                if ($medication === null) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'A line references a medication that no longer exists; the prescription cannot be dispensed.', 409);
                }

                $quantity = max(1, (int) ($line->quantity_minor ?? 1));

                $item = InventoryItem::query()
                    ->where('tenant_id', $prescription->tenant_id)
                    ->where('facility_id', $encounter->facility_id)
                    ->where('medication_id', $line->medication_id)
                    ->first();

                if ($item === null) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'No stock is configured for '.$medication->generic_name.' at this facility.', 409);
                }

                $this->deductStock($item, $quantity, $medication->generic_name, $context);

                InventoryMovement::query()->create([
                    'tenant_id' => $prescription->tenant_id,
                    'facility_id' => $encounter->facility_id,
                    'inventory_item_id' => $item->getKey(),
                    'movement_type' => InventoryMovement::TYPE_DISPENSE,
                    'quantity_delta' => -$quantity,
                    'reason' => $medication->generic_name.' dispense',
                    'prescription_line_id' => $line->getKey(),
                    'occurred_at' => now(),
                    'created_by' => $context->user?->getKey(),
                ]);

                $line->update([
                    'status' => PrescriptionLine::STATUS_DISPENSED,
                    'dispensed_by_staff_id' => $pharmacist->getKey(),
                    'dispensed_at' => now(),
                ]);

                Charge::query()->create([
                    'tenant_id' => $prescription->tenant_id,
                    'facility_id' => $encounter->facility_id,
                    'patient_id' => $prescription->patient_id,
                    'source_type' => Charge::SOURCE_PRESCRIPTION,
                    'encounter_id' => $encounter->getKey(),
                    'prescription_id' => $prescription->getKey(),
                    'description' => $medication->generic_name.' ('.$medication->strength.') × '.$quantity,
                    'amount_minor' => $medication->price_minor * $quantity,
                    'currency' => $medication->currency,
                    'tax_rate_bps' => 0,
                    'status' => Charge::STATUS_POSTED,
                    'charged_at' => now(),
                    'created_by' => $context->user?->getKey(),
                ]);

                $totalMinor += $medication->price_minor * $quantity;
            }

            $this->applyTransition($prescription, Prescription::STATUS_ACTIVE, [
                'status' => Prescription::STATUS_DISPENSED,
            ], $context);

            return $totalMinor;
        });

        $this->audit->record(
            'pharmacy.dispensed',
            'prescription',
            $prescription->getKey(),
            ['patientId' => $prescription->patient_id, 'encounterId' => $prescription->encounter_id, 'lineCount' => $prescription->lines()->where('status', PrescriptionLine::STATUS_DISPENSED)->count(), 'totalAmountMinor' => $totalMinor, 'dispensedByStaffId' => $pharmacist->getKey()],
            $request,
        );

        return Envelope::success(data: $this->present($prescription->fresh(['lines.medication:id,generic_name,brand_name,strength,form,unit,is_controlled,price_minor,currency', 'encounter'])), request: $request);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The atomic stock deduction: compare-and-swap on (quantity_on_hand,
     * lock_version). A concurrent dispense wins → 0 rows → CONFLICT, and the
     * enclosing transaction rolls back the whole dispense.
     */
    private function deductStock(InventoryItem $item, int $quantity, string $genericName, TenantContext $context): void
    {
        $updated = DB::table('inventory_items')
            ->where('tenant_id', $item->tenant_id)
            ->where('id', $item->getKey())
            ->where('lock_version', $item->lock_version)
            ->where('quantity_on_hand', '>=', $quantity)
            ->update([
                'quantity_on_hand' => DB::raw('quantity_on_hand - '.$quantity),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Insufficient stock for '.$genericName.' or the stock was concurrently modified; refresh and retry.', 409);
        }
    }

    private function guardStatus(Prescription $prescription, string $expected, string $gerund): void
    {
        if ($prescription->status !== $expected) {
            $hint = $expected === Prescription::STATUS_DRAFTED
                ? 'only a drafted prescription can be verified'
                : 'a prescription must be verified before it can be dispensed';

            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This prescription cannot be '.$gerund.' from its current state ('.$prescription->status.'); '.$hint.'.',
                409,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function applyTransition(Prescription $prescription, ?string $expectedStatus, array $fields, TenantContext $context): void
    {
        $query = DB::table('prescriptions')
            ->where('id', $prescription->getKey())
            ->where('lock_version', $prescription->lock_version);

        if ($expectedStatus !== null) {
            $query->where('status', $expectedStatus);
        }

        $updated = $query->update(array_merge($fields, [
            'lock_version' => $prescription->lock_version + 1,
            'updated_by' => $context->user?->getKey(),
        ]));

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This prescription was concurrently modified; refresh and retry.', 409);
        }
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
    private function present(Prescription $prescription): array
    {
        $facilityId = $prescription->encounter?->facility_id;

        return [
            'id' => $prescription->getKey(),
            'patientId' => $prescription->patient_id,
            'encounterId' => $prescription->encounter_id,
            'prescriberStaffId' => $prescription->prescriber_staff_id,
            'status' => $prescription->status,
            'notes' => $prescription->notes,
            'verifiedByStaffId' => $prescription->verified_by_staff_id,
            'verifiedAt' => $prescription->verified_at?->toIso8601String(),
            'lockVersion' => $prescription->lock_version,
            'lines' => $prescription->lines->map(function (PrescriptionLine $line) use ($facilityId, $prescription): array {
                $stock = $facilityId !== null
                    ? InventoryItem::query()
                        ->where('tenant_id', $prescription->tenant_id)
                        ->where('facility_id', $facilityId)
                        ->where('medication_id', $line->medication_id)
                        ->value('quantity_on_hand')
                    : null;

                return [
                    'id' => $line->getKey(),
                    'medication' => $line->medication ? [
                        'id' => $line->medication->getKey(),
                        'genericName' => $line->medication->generic_name,
                        'brandName' => $line->medication->brand_name,
                        'strength' => $line->medication->strength,
                        'form' => $line->medication->form,
                        'unit' => $line->medication->unit,
                        'isControlled' => $line->medication->is_controlled,
                        'priceMinor' => $line->medication->price_minor,
                        'currency' => $line->medication->currency,
                    ] : null,
                    'dose' => $line->dose,
                    'route' => $line->route,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'quantityMinor' => $line->quantity_minor,
                    'instructions' => $line->instructions,
                    'status' => $line->status,
                    'dispensedByStaffId' => $line->dispensed_by_staff_id,
                    'dispensedAt' => $line->dispensed_at?->toIso8601String(),
                    'availableQuantity' => $stock,
                ];
            })->values(),
        ];
    }
}
