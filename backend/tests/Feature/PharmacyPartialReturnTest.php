<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PharmacyReturn;
use App\Models\PrescriptionLine;
use App\Models\RefundRequest;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 — pharmacy PARTIAL-quantity returns (PRODUCT_REQUIREMENTS §6.7,
 * DATABASE.md §3.30/§3.33): a dispensed line can be returned in whole OR in
 * part over multiple return events, until the full dispensed quantity has
 * been restored. Every return restores stock for exactly its quantity and
 * opens a refund request for exactly its money value (unit price × quantity);
 * the posted charge is never mutated; the line stays 'dispensed' until the
 * full quantity is back (then it flips to 'reversed'); over-return is
 * impossible (CHECK constraint + row-lock CAS); concurrent returners
 * serialize on the line row.
 */
beforeEach(function (): void {
    seedIdentity();
});

function partialReturnStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => $designation,
        'status' => 'active',
    ]);
}

function partialReturnEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => Encounter::STATUS_OPEN,
    ]);
}

/**
 * @return array{medication: Medication, item: InventoryItem}
 */
function partialReturnStock(Organization $org, Facility $facility, string $code, int $priceMinor, int $quantity = 100): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'generic_name' => 'Paracetamol',
        'price_minor' => $priceMinor,
        'currency' => 'NPR',
        'status' => Medication::STATUS_ACTIVE,
    ]);

    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'quantity_on_hand' => $quantity,
        'reorder_level' => 10,
        'lock_version' => 0,
    ]);

    StockBatch::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'inventory_item_id' => $item->getKey(),
        'medication_id' => $medication->getKey(),
        'batch_number' => 'B-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'quantity_received' => $quantity,
        'quantity_remaining' => $quantity,
        'status' => StockBatch::STATUS_AVAILABLE,
        'controlled_dispense_requires_dual' => false,
        'lock_version' => 0,
    ]);

    return ['medication' => $medication, 'item' => $item];
}

/**
 * Drive prescription creation through the real API (doctor writes the
 * prescription on an open encounter).
 *
 * @return array{prescriptionId: string, lineIds: list<string>, encounterId: string, doctorStaffId: string, patientId: string}
 */
function partialReturnPrescription(TestCase $test, Organization $org, Facility $facility, User $doctorUser, array $lines): array
{
    $doctor = partialReturnStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = partialReturnEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/prescriptions', [
            'lines' => collect($lines)->map(fn (array $line, int $i): array => [
                'medicationId' => $line['medicationId'],
                'dose' => '1 '.($i + 1),
                'route' => 'oral',
                'frequency' => 'tid',
                'quantityMinor' => $line['quantityMinor'],
            ])->values()->all(),
        ])
        ->assertCreated();

    return [
        'prescriptionId' => $response->json('data.id'),
        'lineIds' => collect($response->json('data.lines'))->pluck('id')->all(),
        'encounterId' => $encounter->getKey(),
        'doctorStaffId' => $doctor->getKey(),
        'patientId' => $encounter->patient_id,
    ];
}

/**
 * Verify + dispense the prescription as the pharmacist, returning the
 * pharmacist user.
 */
function partialReturnDispense(TestCase $test, Organization $org, Facility $facility, array $rx): User
{
    $pharmacist = Identity::user();
    partialReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    return $pharmacist;
}

it('returns a partial quantity: line stays dispensed, stock + ledger + refund match the returned amount', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    // Dispense 5 units at 500 minor each → charge 2500.
    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Return 2 of the 5 dispensed.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 2,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.return.quantityMinor', 2);

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED) // NOT reversed yet
        ->and($line->returned_quantity_minor)->toBe(2);

    // Stock restored by exactly 2 (100 − 5 dispensed + 2 returned = 97).
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(97);

    // Ledger: the positive 'return' movement mirrors exactly 2.
    $movement = InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->firstOrFail();
    expect($movement->quantity_delta)->toBe(2)
        ->and($movement->prescription_line_id)->toBe($line->getKey());

    // Reversal record carries the returned quantity.
    $pharmacyReturn = PharmacyReturn::query()->where('prescription_line_id', $line->getKey())->firstOrFail();
    expect($pharmacyReturn->quantity_minor)->toBe(2);

    // Refund request = unit price (500) × 2 = 1000 — requested, not approved.
    $refund = RefundRequest::query()->where('charge_id', $pharmacyReturn->charge_id)->firstOrFail();
    expect($refund->status)->toBe(RefundRequest::STATUS_REQUESTED)
        ->and($refund->amount_minor)->toBe(1000);

    // The posted charge is immutable.
    $charge = Charge::query()->findOrFail($pharmacyReturn->charge_id);
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount_minor)->toBe(2500);

    // Audit: one pharmacy.returned with facts.
    $audit = AuditEvent::query()->where('action', 'pharmacy.returned')->firstOrFail();
    expect($audit->payload['quantityMinor'])->toBe(2);
});

it('accumulates multiple partial returns and flips the line to reversed only when fully returned', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Return 2, then 2 → line still dispensed with returned_quantity_minor 4.
    foreach ([2, 2] as $qty) {
        $this->withToken(Identity::tokenFor($pharmacist))
            ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
                'reasonCode' => 'patient_return',
                'quantityMinor' => $qty,
            ])
            ->assertStatus(201);
    }

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and($line->returned_quantity_minor)->toBe(4);

    // The final 1 unit fully returns the line → reversed.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 1,
        ])
        ->assertStatus(201);

    $line->refresh();
    expect($line->status)->toBe(PrescriptionLine::STATUS_REVERSED)
        ->and($line->returned_quantity_minor)->toBe(5);

    // Stock fully restored (100 − 5 + 5 = 100).
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100);

    // Three return events, three refund requests summing to the charge (2500).
    $returns = PharmacyReturn::query()->where('prescription_line_id', $line->getKey())->get();
    $refunds = RefundRequest::query()->whereIn('charge_id', $returns->pluck('charge_id'))->get();
    expect($returns)->toHaveCount(3)
        ->and($returns->sum('quantity_minor'))->toBe(5)
        ->and($refunds->sum('amount_minor'))->toBe(2500);

    // Ledger return movements sum to exactly 5.
    $movements = InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->get();
    expect($movements->sum('quantity_delta'))->toBe(5);
});

it('rejects an over-return and a return beyond the remaining quantity with zero side effects', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Over the dispensed quantity (6 > 5) → 422.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 6,
        ])
        ->assertStatus(422);

    // A valid partial (2), then an over-return of the remaining (4 > 3) → 422.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 2,
        ])
        ->assertStatus(201);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 4,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Nothing else mutated: exactly one return, one refund, stock = 97
    // (100 − 5 + 2), line still dispensed with returned 2.
    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->returned_quantity_minor)->toBe(2)
        ->and($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->where('prescription_line_id', $line->getKey())->count())->toBe(1)
        ->and(RefundRequest::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(97)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(1);
});

it('rejects zero and negative quantities at validation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    foreach ([0, -1] as $qty) {
        $this->withToken(Identity::tokenFor($pharmacist))
            ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
                'reasonCode' => 'patient_return',
                'quantityMinor' => $qty,
            ])
            ->assertStatus(422);
    }

    // Non-numeric quantity → 422.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 'two',
        ])
        ->assertStatus(422);

    // Nothing happened.
    expect(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->returned_quantity_minor)->toBe(0)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(0);
});

it('lets the whole-line default (no quantityMinor) return everything remaining', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // No quantityMinor → the full remaining (5) is returned, line reversed —
    // slice-8 backward compatibility.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->status)->toBe(PrescriptionLine::STATUS_REVERSED)
        ->and($line->returned_quantity_minor)->toBe(5);

    // A second whole-line default after full return → 409 (nothing to return).
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409);

    expect(PharmacyReturn::query()->where('prescription_line_id', $line->getKey())->count())->toBe(1)
        ->and(RefundRequest::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100);
});

it('wins the concurrent partial-return race via CAS: a stale returner affects zero rows', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);

    // The winning partial advances returned_quantity_minor atomically — the
    // exact CAS the service runs: WHERE status = dispensed AND
    // returned_quantity_minor = <snapshot>, then add the quantity.
    $winner = DB::table('prescription_lines')
        ->where('id', $line->getKey())
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->where('returned_quantity_minor', 0)
        ->update(['returned_quantity_minor' => 2]);

    expect($winner)->toBe(1);

    // A second returner holding the SAME stale snapshot (returned = 0) can
    // never advance the line again: the CAS affects zero rows — the
    // database backstop against over-return from a lost race.
    $loser = DB::table('prescription_lines')
        ->where('id', $line->getKey())
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->where('returned_quantity_minor', 0)
        ->update(['returned_quantity_minor' => 2]);

    expect($loser)->toBe(0);

    // The API layer always re-reads under the row lock, so a concurrent
    // request after the race sees the ADVANCED state (returned = 2,
    // remaining = 3): returning exactly what remains succeeds and the
    // boundary is enforced exactly — returning MORE than the remaining is
    // refused, so two racing returners can never over-return the line.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$line->getKey().'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 3,
        ])
        ->assertStatus(201);

    $line->refresh();
    expect($line->returned_quantity_minor)->toBe(5)
        ->and($line->status)->toBe(PrescriptionLine::STATUS_REVERSED);

    // The line is now fully returned — nothing further can be returned.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$line->getKey().'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 1,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('keeps the refundable accounting intact after partial returns (financial integrity)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 4]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Return 1 then 3 (full) → refunds 500 + 1500 = 2000 = the charge.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 1])
        ->assertStatus(201);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 3])
        ->assertStatus(201);

    $chargeId = Charge::query()->where('prescription_line_id', $rx['lineIds'][0])->firstOrFail()->getKey();
    $refunds = RefundRequest::query()->where('charge_id', $chargeId)->get();

    expect($refunds->sum('amount_minor'))->toBe(2000)
        ->and($refunds->every(fn (RefundRequest $r): bool => $r->status === RefundRequest::STATUS_REQUESTED))->toBeTrue();

    // Approving both keeps the sum within the charge (billing layer backstop).
    $approver = Identity::user();
    Identity::assign($approver, 'org_admin', $org, $facility);
    foreach ($refunds as $refund) {
        $this->withToken(Identity::tokenFor($approver))
            ->postJson('/api/v1/refund-requests/'.$refund->getKey().'/approve')
            ->assertOk();
    }

    expect((int) RefundRequest::query()->where('charge_id', $chargeId)->where('status', RefundRequest::STATUS_APPROVED)->sum('amount_minor'))->toBe(2000);
});

it('keeps patient identifiers and reason text out of partial-return audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    $patientName = Patient::query()->findOrFail($rx['patientId'])->full_name;

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'reasonNote' => 'Sensitive reason about the patient prognosis',
            'quantityMinor' => 2,
        ])
        ->assertStatus(201);

    $audit = AuditEvent::query()->where('action', 'pharmacy.returned')->firstOrFail();
    $encoded = json_encode($audit->payload);

    expect($encoded)->not->toContain($patientName)
        ->and($encoded)->not->toContain('Sensitive reason')
        ->and($encoded)->not->toContain('prognosis')
        ->and($audit->payload['quantityMinor'])->toBe(2)
        ->and($audit->payload['returnedSoFarMinor'])->toBe(2);
});

it('enforces authorization: pharmacist only, unauthenticated 401', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(401);

    // A doctor (no pharmacy:return) → 403.
    $otherDoctor = Identity::user();
    Identity::assign($otherDoctor, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($otherDoctor))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(403);

    // The pharmacist succeeds.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(201);
});

it('enforces cross-tenant and cross-facility isolation for partial returns', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = Identity::user();
    $stockA = partialReturnStock($orgA, $facilityA, 'PARA', 500, quantity: 100);
    $rxA = partialReturnPrescription($this, $orgA, $facilityA, $doctorA, [['medicationId' => $stockA['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacistA = partialReturnDispense($this, $orgA, $facilityA, $rxA);

    // Tenant-B pharmacist attacks tenant A's line → 403, data untouched.
    $pharmacistB = Identity::user();
    partialReturnStaff($orgB, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $orgB, $facilityB);
    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescription-lines/'.$rxA['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(403);

    // Cross-facility within the same tenant (tenant A, facility A2).
    $facilityA2 = Identity::facility($orgA);
    $pharmacistA2 = Identity::user();
    partialReturnStaff($orgA, $facilityA2, $pharmacistA2, 'Pharmacist');
    Identity::assign($pharmacistA2, 'pharmacist', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($pharmacistA2))
        ->postJson('/api/v1/prescription-lines/'.$rxA['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(403);

    // Tenant A's data is untouched.
    $line = PrescriptionLine::query()->findOrFail($rxA['lineIds'][0]);
    expect($line->returned_quantity_minor)->toBe(0)
        ->and($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});

it('rolls back the whole transaction when a partial return fails mid-way', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = partialReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = partialReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = partialReturnDispense($this, $org, $facility, $rx);

    // Void the linked charge — the service fails AFTER the line lock but
    // BEFORE any mutation, so nothing partial is written.
    $charge = Charge::query()->where('prescription_line_id', $rx['lineIds'][0])->firstOrFail();
    $charge->update(['status' => Charge::STATUS_VOIDED, 'void_reason' => 'Posted in error']);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', ['reasonCode' => 'patient_return', 'quantityMinor' => 2])
        ->assertStatus(409);

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->returned_quantity_minor)->toBe(0)
        ->and($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(0)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(95)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});
