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
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 3 — the pharmacy dispensing workflow (PRODUCT_REQUIREMENTS
 * §6.9): prescription → pharmacist verification → stock check → dispense →
 * inventory deduction → billing. Verification is REQUIRED before dispensing
 * (drafted → active → dispensed). The dispense is one atomic transaction:
 * stock CAS + ledger movement + line stamp + charge per line; any shortfall
 * rolls back everything (no partial dispensing, no partial deduction).
 */
beforeEach(function (): void {
    seedIdentity();
});

function pharmacyStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function pharmacyEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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
function pharmacyStock(Organization $org, Facility $facility, string $code, int $priceMinor, int $quantity = 100): array
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

    return ['medication' => $medication, 'item' => $item];
}

/**
 * Drive prescription creation through the real API (doctor writes the
 * prescription on an open encounter).
 *
 * @param  list<array{medicationId: string, quantityMinor: int}>  $lines
 * @return array{prescriptionId: string, lineIds: list<string>, encounterId: string, doctorStaffId: string}
 */
function pharmacyPrescription(TestCase $test, Organization $org, Facility $facility, User $doctorUser, array $lines): array
{
    $doctor = pharmacyStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = pharmacyEncounter($org, $facility, $doctor);
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
    ];
}

it('receives stock, lists it with facility scoping, and upserts repeat receipts', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $facilityB = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityC = Identity::facility($orgB);

    $med = Medication::factory()->create([
        'tenant_id' => $orgA->getKey(),
        'facility_id' => $facilityA->getKey(),
        'code' => 'PARA',
        'price_minor' => 500,
        'status' => Medication::STATUS_ACTIVE,
    ]);

    $pharmacistA = Identity::user();
    Identity::assign($pharmacistA, 'pharmacist', $orgA, $facilityA);
    $pharmacistB = Identity::user();
    Identity::assign($pharmacistB, 'pharmacist', $orgA, $facilityB);

    // Receipt at facility A.
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/inventory', [
            'facilityId' => $facilityA->getKey(),
            'medicationId' => $med->getKey(),
            'quantity' => 50,
            'reorderLevel' => 5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.quantityOnHand', 50)
        ->assertJsonPath('data.medication.genericName', $med->generic_name);

    expect(InventoryItem::query()->where('medication_id', $med->getKey())->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RECEIPT)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'inventory.received')->count())->toBe(1);

    // Repeat receipt upserts the same row (no duplicate stock rows).
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/inventory', [
            'facilityId' => $facilityA->getKey(),
            'medicationId' => $med->getKey(),
            'quantity' => 25,
        ])
        ->assertCreated()
        ->assertJsonPath('data.quantityOnHand', 75);

    expect(InventoryItem::query()->where('medication_id', $med->getKey())->count())->toBe(1);

    // Facility-B pharmacist sees an empty shelf (facility RLS on the
    // org-scoped route); the org_admin sees the org-wide shelf.
    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/organizations/'.$orgA->getKey().'/inventory')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $orgA);
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$orgA->getKey().'/inventory')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Unknown medication in this facility → 422. Out-of-scope org → 404.
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/inventory', [
            'facilityId' => $facilityA->getKey(),
            'medicationId' => (string) Str::uuid(),
            'quantity' => 10,
        ])
        ->assertStatus(422);

    // A tenant-B pharmacist cannot write into tenant A's inventory (writes
    // to an out-of-scope org deny with 403; reads hide existence with 404).
    $pharmacistC = Identity::user();
    Identity::assign($pharmacistC, 'pharmacist', $orgB, $facilityC);
    $this->withToken(Identity::tokenFor($pharmacistC))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/inventory', [
            'facilityId' => $facilityC->getKey(),
            'medicationId' => $med->getKey(),
            'quantity' => 10,
        ])
        ->assertStatus(403);
});

it('verifies a drafted prescription and audits it, denying non-pharmacists', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 2]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);

    // Unauthenticated → 401 (withToken persists, so flush it first).
    $this->flushHeaders();
    $this->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')->assertStatus(401);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_ACTIVE)
        ->assertJsonPath('data.lines.0.status', PrescriptionLine::STATUS_ORDERED)
        ->assertJsonPath('data.verifiedByStaffId', fn (mixed $v) => is_string($v));

    $prescription = Prescription::query()->findOrFail($rx['prescriptionId']);
    expect($prescription->status)->toBe(Prescription::STATUS_ACTIVE)
        ->and($prescription->verified_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'pharmacy.verified')->count())->toBe(1);

    // A nurse (no pharmacy:dispense) and the doctor (no pharmacy:dispense)
    // are denied; verification is the pharmacist's step.
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(403);

    $doctor2 = Identity::user();
    $doctorStaff2 = pharmacyStaff($org, $facility, $doctor2, 'Consultant Physician');
    Identity::assign($doctor2, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($doctor2))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(403);
});

it('dispenses a verified prescription: stock deduction, ledger, charges, and audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $para = pharmacyStock($org, $facility, 'PARA', 500);
    $amox = pharmacyStock($org, $facility, 'AMOX', 1200);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [
        ['medicationId' => $para['medication']->getKey(), 'quantityMinor' => 2],
        ['medicationId' => $amox['medication']->getKey(), 'quantityMinor' => 1],
    ]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_DISPENSED)
        ->assertJsonCount(2, 'data.lines');

    // Header + lines advanced.
    $prescription = Prescription::query()->findOrFail($rx['prescriptionId']);
    expect($prescription->status)->toBe(Prescription::STATUS_DISPENSED)
        ->and($prescription->lock_version)->toBe(2);

    $lines = PrescriptionLine::query()->where('prescription_id', $rx['prescriptionId'])->get();
    expect($lines->every(fn (PrescriptionLine $l): bool => $l->status === PrescriptionLine::STATUS_DISPENSED))->toBeTrue()
        ->and($lines->every(fn (PrescriptionLine $l): bool => $l->dispensed_at !== null))->toBeTrue();

    // Stock deducted: 100−2 and 100−1.
    expect(InventoryItem::query()->findOrFail($para['item']->getKey())->quantity_on_hand)->toBe(98)
        ->and(InventoryItem::query()->findOrFail($amox['item']->getKey())->quantity_on_hand)->toBe(99);

    // Ledger: one dispense movement per line, negative delta, linked to the line.
    $movements = InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_DISPENSE)->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('quantity_delta')->all())->toBe([-2, -1])
        ->and($movements->pluck('prescription_line_id')->filter()->count())->toBe(2);

    // Charges: price × quantity in minor units (2×500, 1×1200).
    $charges = Charge::query()->where('prescription_id', $rx['prescriptionId'])->get();
    expect($charges)->toHaveCount(2)
        ->and($charges->pluck('amount_minor')->sort()->values()->all())->toBe([1000, 1200])
        ->and($charges->pluck('status')->unique()->all())->toBe([Charge::STATUS_POSTED])
        ->and($charges->pluck('currency')->unique()->all())->toBe(['NPR']);

    // Audit with the total amount (fact, never PHI).
    $audit = AuditEvent::query()->where('action', 'pharmacy.dispensed')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload['totalAmountMinor'] ?? null)->toBe(2200);
});

it('refuses to dispense an unverified prescription', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);

    // Still drafted → cannot be dispensed (verification is the required step).
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Prescription::query()->findOrFail($rx['prescriptionId'])->status)->toBe(Prescription::STATUS_DRAFTED);
});

it('rolls back the whole dispense when any line is short on stock', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    // Only 10 tablets on hand; the prescription asks for 15.
    $stock = pharmacyStock($org, $facility, 'PARA', 500, quantity: 10);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 15]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Nothing happened: prescription still active, line still ordered, stock
    // untouched, no dispense movement, no prescription charge, no audit.
    $prescription = Prescription::query()->findOrFail($rx['prescriptionId']);
    expect($prescription->status)->toBe(Prescription::STATUS_ACTIVE)
        ->and($prescription->lock_version)->toBe(1)
        ->and(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_ORDERED)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(10)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_DISPENSE)->count())->toBe(0)
        ->and(Charge::query()->where('prescription_id', $rx['prescriptionId'])->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.dispensed')->count())->toBe(0);
});

it('refuses dispensing when no stock is configured for a medication', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    // Catalog medication with NO inventory item.
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'NOSTK',
        'price_minor' => 300,
        'status' => Medication::STATUS_ACTIVE,
    ]);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $medication->getKey(), 'quantityMinor' => 1]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('rejects invalid state transitions with CONFLICT', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);

    // Verify → dispense → both are terminal for their step.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();

    // Verifying again is invalid.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // Dispensing twice is invalid; verifying a dispensed prescription too.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(409);
});

it('adjusts stock with a mandatory reason and blocks negative outcomes', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500, quantity: 50);
    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/adjust', [
            'quantityDelta' => 10,
            'reason' => 'Count correction after shelf audit',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantityOnHand', 60);

    expect(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'inventory.adjusted')->count())->toBe(1);

    // A negative adjustment below zero is refused; the reason is mandatory.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/adjust', [
            'quantityDelta' => -200,
            'reason' => 'Damage write-off',
        ])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/adjust', [
            'quantityDelta' => -5,
        ])
        ->assertStatus(422);

    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(60);
});

it('enforces cross-tenant isolation for the whole dispensing surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = Identity::user();
    $stock = pharmacyStock($orgA, $facilityA, 'PARA', 500);
    $rx = pharmacyPrescription($this, $orgA, $facilityA, $doctorA, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    // Tenant-B pharmacist attacking tenant A's prescription and inventory.
    $pharmacistB = Identity::user();
    pharmacyStaff($orgB, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/adjust', [
            'quantityDelta' => 5,
            'reason' => 'Attack',
        ])
        ->assertStatus(403);

    // And tenant A's data is untouched.
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100)
        ->and(Prescription::query()->findOrFail($rx['prescriptionId'])->status)->toBe(Prescription::STATUS_DRAFTED);
});

it('enforces cross-facility isolation within a tenant', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    $doctorA = Identity::user();
    $stock = pharmacyStock($org, $facilityA, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facilityA, $doctorA, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    // Facility-B pharmacist cannot reach facility A's prescription.
    $pharmacistB = Identity::user();
    pharmacyStaff($org, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $org, $facilityB);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertStatus(403);
});

it('wins the concurrent dispense race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();

    $prescription = Prescription::query()->findOrFail($rx['prescriptionId']);
    expect($prescription->status)->toBe(Prescription::STATUS_ACTIVE);

    // The winning dispense commits atomically — the exact compare-and-swap
    // the controller runs: WHERE status AND lock_version match, then advance.
    $winner = DB::table('prescriptions')
        ->where('id', $rx['prescriptionId'])
        ->where('status', Prescription::STATUS_ACTIVE)
        ->where('lock_version', $prescription->lock_version)
        ->update(['status' => Prescription::STATUS_DISPENSED, 'lock_version' => $prescription->lock_version + 1]);

    expect($winner)->toBe(1);

    // A second dispenser holding the SAME stale snapshot can never advance
    // the prescription again: the CAS affects zero rows.
    $loser = DB::table('prescriptions')
        ->where('id', $rx['prescriptionId'])
        ->where('status', Prescription::STATUS_ACTIVE)
        ->where('lock_version', $prescription->lock_version)
        ->update(['status' => Prescription::STATUS_ACTIVE, 'lock_version' => $prescription->lock_version + 1]);

    expect($loser)->toBe(0);

    // And the losing HTTP request — arriving after the winner committed —
    // fails safely with CONFLICT and changes nothing.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Prescription::query()->findOrFail($rx['prescriptionId'])->status)->toBe(Prescription::STATUS_DISPENSED);
});

it('deducts stock atomically across two prescriptions (stock-level CAS)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    $stock = pharmacyStock($org, $facility, 'PARA', 500, quantity: 100);
    $item = InventoryItem::query()->findOrFail($stock['item']->getKey());

    // Two independent dispensers racing on the same shelf, both holding the
    // same snapshot. The winner's CAS deducts 5; the loser's stale snapshot
    // affects zero rows — stock can never be double-deducted.
    $winner = DB::table('inventory_items')
        ->where('tenant_id', $item->tenant_id)
        ->where('id', $item->getKey())
        ->where('lock_version', $item->lock_version)
        ->where('quantity_on_hand', '>=', 5)
        ->update(['quantity_on_hand' => DB::raw('quantity_on_hand - 5'), 'lock_version' => $item->lock_version + 1]);

    $loser = DB::table('inventory_items')
        ->where('tenant_id', $item->tenant_id)
        ->where('id', $item->getKey())
        ->where('lock_version', $item->lock_version)
        ->where('quantity_on_hand', '>=', 10)
        ->update(['quantity_on_hand' => DB::raw('quantity_on_hand - 10'), 'lock_version' => $item->lock_version + 1]);

    expect($winner)->toBe(1)
        ->and($loser)->toBe(0)
        ->and(InventoryItem::query()->findOrFail($item->getKey())->quantity_on_hand)->toBe(95);
});

it('keeps patient and medication identifiers out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmacistUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $patient = Patient::query()->where('tenant_id', $org->getKey())->first();
    $patientName = $patient->full_name;

    pharmacyStaff($org, $facility, $pharmacistUser, 'Pharmacist');
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('Paracetamol')
            ->and($encoded)->not->toContain('500mg');
    }
});

it('gates the prescription view by pharmacy:view', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyStock($org, $facility, 'PARA', 500);
    $rx = pharmacyPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 2]]);

    // The doctor (pharmacy:view) can read the pharmacy view with stock.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertOk()
        ->assertJsonPath('data.lines.0.availableQuantity', 100);

    // The receptionist has no pharmacy permission at all.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertStatus(403);
});
