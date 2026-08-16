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
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 17 — the remaining documented Pharmacy scope (ROADMAP
 * Phase 12, PRODUCT_REQUIREMENTS §6.7): batch-selected dispensing,
 * batch/expiry tracking (FEFO), policy-driven verification, expired-batch
 * prevention, and Phase-2 controlled-substance dual verification. Every
 * stock movement is transactional (stock ledger + batch + charge + line
 * stamp in one transaction); the ledger truth is
 * inventory_items.quantity_on_hand = Σ available batch quantities.
 */
beforeEach(function (): void {
    seedIdentity();
});

function scopeStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function scopeEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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
 * Create a medication + shelf + one or more batches.
 *
 * @param  list<array{expiresInDays: int, quantity: int, controlled: bool}>  $batches
 * @return array{medication: Medication, item: InventoryItem, batches: list<StockBatch>}
 */
function scopeStock(Organization $org, Facility $facility, string $code, array $batches = [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'generic_name' => 'Scope Med '.$code,
        'price_minor' => 500,
        'currency' => 'NPR',
        'is_controlled' => collect($batches)->contains(fn (array $b): bool => $b['controlled']),
        'status' => Medication::STATUS_ACTIVE,
    ]);

    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'quantity_on_hand' => collect($batches)->sum('quantity'),
        'reorder_level' => 10,
        'lock_version' => 0,
    ]);

    $created = [];
    foreach ($batches as $batch) {
        $created[] = StockBatch::factory()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'inventory_item_id' => $item->getKey(),
            'medication_id' => $medication->getKey(),
            'batch_number' => 'B-'.$code.'-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
            'expiry_date' => now()->addDays($batch['expiresInDays'])->toDateString(),
            'quantity_received' => $batch['quantity'],
            'quantity_remaining' => $batch['quantity'],
            'status' => StockBatch::STATUS_AVAILABLE,
            'controlled_dispense_requires_dual' => $batch['controlled'],
            'lock_version' => 0,
        ]);
    }

    return ['medication' => $medication, 'item' => $item, 'batches' => $created];
}

/**
 * Create + verify a prescription through the API (doctor writes it, the
 * first pharmacist verifies it).
 *
 * @return array{prescriptionId: string, lineIds: list<string>, pharmacist: Staff, doctor: Staff, encounter: Encounter}
 */
/**
 * Create the doctor + pharmacist staff records once per test (reusable
 * across multiple prescriptions — staff is unique per tenant+user).
 *
 * @return array{doctor: Staff, pharmacist: Staff}
 */
function scopePharmacyStaff(Organization $org, Facility $facility, User $doctorUser, User $pharmUser): array
{
    $doctor = scopeStaff($org, $facility, $doctorUser, 'Consultant Physician');
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    $pharmacist = scopeStaff($org, $facility, $pharmUser, 'Pharmacist');
    Identity::assign($pharmUser, 'pharmacist', $org, $facility);

    return ['doctor' => $doctor, 'pharmacist' => $pharmacist];
}

/**
 * @param  array{doctor: Staff, pharmacist: Staff}|null  $staff  pre-created staff (reused across calls)
 * @return array{prescriptionId: string, lineIds: list<string>, pharmacist: Staff, doctor: Staff, encounter: Encounter}
 */
function scopeVerifiedPrescription(TestCase $test, Organization $org, Facility $facility, User $doctorUser, User $pharmUser, string $medicationId, int $quantity = 2, ?array $staff = null): array
{
    if ($staff === null) {
        $staff = scopePharmacyStaff($org, $facility, $doctorUser, $pharmUser);
    }
    $doctor = $staff['doctor'];
    $pharmacist = $staff['pharmacist'];
    $encounter = scopeEncounter($org, $facility, $doctor);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/prescriptions', [
            'lines' => [[
                'medicationId' => $medicationId,
                'dose' => '1',
                'route' => 'oral',
                'frequency' => 'tid',
                'quantityMinor' => $quantity,
            ]],
        ])
        ->assertCreated();

    $prescriptionId = $response->json('data.id');
    $lineIds = collect($response->json('data.lines'))->pluck('id')->all();

    $test->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/verify')
        ->assertOk();

    return ['prescriptionId' => $prescriptionId, 'lineIds' => $lineIds, 'pharmacist' => $pharmacist, 'doctor' => $doctor, 'encounter' => $encounter];
}

it('receives a batch with expiry and dispenses FEFO (oldest expiry first)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    // Two batches: the far batch expires first → FEFO picks it.
    $stock = scopeStock($org, $facility, 'FEFO', [
        ['expiresInDays' => 300, 'quantity' => 50, 'controlled' => false],
        ['expiresInDays' => 60, 'quantity' => 50, 'controlled' => false],
    ]);
    $near = $stock['batches'][1];
    $far = $stock['batches'][0];

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10);

    $dispensed = $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();

    // The NEAR-expiry batch was selected (FEFO).
    expect($dispensed->json('data.lines.0.batchId'))->toBe($near->getKey())
        ->and($dispensed->json('data.lines.0.batchExpiresAt'))->toBe($near->expiry_date->toDateString());

    $near->refresh();
    $far->refresh();
    expect($near->quantity_remaining)->toBe(40)
        ->and($far->quantity_remaining)->toBe(50)
        ->and(StockBatch::query()->findOrFail($near->getKey())->status)->toBe(StockBatch::STATUS_AVAILABLE);

    // The ledger and the shelf agree.
    $item = InventoryItem::query()->findOrFail($stock['item']->getKey());
    expect($item->quantity_on_hand)->toBe(90);

    $dispenseMovement = InventoryMovement::query()
        ->where('prescription_line_id', $lineId)
        ->where('movement_type', InventoryMovement::TYPE_DISPENSE)
        ->first();
    expect($dispenseMovement->stock_batch_id)->toBe($near->getKey())
        ->and($dispenseMovement->quantity_delta)->toBe(-10);
});

it('dispenses an explicitly selected batch and refuses a wrong-medication batch', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    $stockA = scopeStock($org, $facility, 'SELA', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);
    $stockB = scopeStock($org, $facility, 'SELB', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stockA['medication']->getKey(), 10);

    // Selecting medication B's batch for medication A's line → 422.
    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense', [
            'batchSelections' => [['lineId' => $lineId, 'batchId' => $stockB['batches'][0]->getKey()]],
        ])
        ->assertStatus(422);

    // Selecting A's own batch → dispenses from it.
    $dispensed = $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense', [
            'batchSelections' => [['lineId' => $lineId, 'batchId' => $stockA['batches'][0]->getKey()]],
        ])
        ->assertOk();

    expect($dispensed->json('data.lines.0.batchId'))->toBe($stockA['batches'][0]->getKey())
        ->and(StockBatch::query()->findOrFail($stockA['batches'][0]->getKey())->quantity_remaining)->toBe(90);
});

it('never dispenses an expired batch (FEFO skips it, explicit selection is refused)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    // An expired batch with stock + a fresh batch (same shelf).
    $stock = scopeStock($org, $facility, 'EXPB', [['expiresInDays' => 180, 'quantity' => 50, 'controlled' => false]]);
    $staff = scopePharmacyStaff($org, $facility, $doctorUser, $pharmUser);
    $item = $stock['item'];
    $expired = StockBatch::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'inventory_item_id' => $item->getKey(),
        'medication_id' => $stock['medication']->getKey(),
        'batch_number' => 'B-EXP-'.substr((string) Str::uuid(), 0, 6),
        'expiry_date' => now()->subDays(2)->toDateString(),
        'quantity_received' => 100,
        'quantity_remaining' => 100,
        'status' => StockBatch::STATUS_AVAILABLE,
        'controlled_dispense_requires_dual' => false,
        'lock_version' => 0,
    ]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10, $staff);

    // FEFO skips the expired batch → dispenses from the fresh one.
    $dispensed = $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();
    expect($dispensed->json('data.lines.0.batchId'))->toBe($stock['batches'][0]->getKey())
        ->and(StockBatch::query()->findOrFail($expired->getKey())->quantity_remaining)->toBe(100);

    // Explicitly selecting the expired batch → 409 (never issuable). The
    // second prescription must use the SAME medication so the selection
    // passes the wrong-medication check and reaches the expiry refusal.
    ['prescriptionId' => $pid2, 'lineIds' => [$lid2]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10, $staff);

    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$pid2.'/dispense', [
            'batchSelections' => [['lineId' => $lid2, 'batchId' => $expired->getKey()]],
        ])
        ->assertStatus(409);
});

it('requires dual verification for controlled substances and rejects the same pharmacist', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();
    $pharm2User = Identity::user();

    $stock = scopeStock($org, $facility, 'CTRL', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => true]]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId], 'pharmacist' => $pharmacist] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10);

    $dispensed = $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();

    $line = PrescriptionLine::query()->findOrFail($lineId);
    expect($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and($line->dual_verified_by_staff_id)->toBeNull();

    // The SAME pharmacist cannot dual-verify (dispenser ≠ verifier).
    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/dual-verify')
        ->assertStatus(403);

    // A different pharmacist verifies.
    scopeStaff($org, $facility, $pharm2User, 'Pharmacist');
    Identity::assign($pharm2User, 'pharmacist', $org, $facility);

    $verified = $this->withToken(Identity::tokenFor($pharm2User))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/dual-verify')
        ->assertOk();

    expect($verified->json('data.dualVerifiedByStaffId'))->not->toBeNull()
        ->and($verified->json('data.dualVerifiedAt'))->not->toBeNull();

    // Re-verify → 409 (single stamp).
    $this->withToken(Identity::tokenFor($pharm2User))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/dual-verify')
        ->assertStatus(409);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'pharmacy.dual_verified',
        'tenant_id' => $org->getKey(),
    ]);
});

it('keeps non-controlled lines free of the dual-verification requirement', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    $stock = scopeStock($org, $facility, 'NCTRL', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10);

    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();

    $line = PrescriptionLine::query()->findOrFail($lineId);
    expect($line->dual_verified_by_staff_id)->toBeNull()
        ->and($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED);
});

it('restores a return to the SAME batch and keeps the ledger consistent', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    $stock = scopeStock($org, $facility, 'RTNB', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10);

    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();

    $batch = StockBatch::query()->findOrFail($stock['batches'][0]->getKey());
    expect($batch->quantity_remaining)->toBe(90);

    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertCreated();

    $batch->refresh();
    $item = InventoryItem::query()->findOrFail($stock['item']->getKey());
    expect($batch->quantity_remaining)->toBe(100)
        ->and($item->quantity_on_hand)->toBe(100);

    $returnMovement = InventoryMovement::query()
        ->where('prescription_line_id', $lineId)
        ->where('movement_type', InventoryMovement::TYPE_RETURN)
        ->first();
    expect($returnMovement->stock_batch_id)->toBe($stock['batches'][0]->getKey())
        ->and($returnMovement->quantity_delta)->toBe(10);

    // The line is reversed; the charge remains posted (financial linkage).
    $line = PrescriptionLine::query()->findOrFail($lineId);
    expect($line->status)->toBe(PrescriptionLine::STATUS_REVERSED);
    expect(Charge::query()->where('prescription_line_id', $lineId)->where('status', Charge::STATUS_POSTED)->exists())->toBeTrue();
});

it('prevents double-dispense from a batch (CAS — one winner, loser 409 with nothing changed)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();

    // A single-quantity batch: only one dispense can win.
    $staff = scopePharmacyStaff($org, $facility, $doctorUser, $pharmUser);
    $stock = scopeStock($org, $facility, 'CASB', [['expiresInDays' => 180, 'quantity' => 1, 'controlled' => false]]);

    ['prescriptionId' => $pid1, 'lineIds' => [$lid1]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 1, $staff);
    ['prescriptionId' => $pid2, 'lineIds' => [$lid2]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 1, $staff);

    // First dispense consumes the batch.
    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$pid1.'/dispense')
        ->assertOk();

    // Second dispense: no available, unexpired batch → 409, line untouched.
    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$pid2.'/dispense')
        ->assertStatus(409);

    expect(PrescriptionLine::query()->findOrFail($lid2)->status)->toBe(PrescriptionLine::STATUS_ORDERED)
        ->and(StockBatch::query()->findOrFail($stock['batches'][0]->getKey())->quantity_remaining)->toBe(0);
});

it('isolates batches across tenants (batch data unreachable and immutable from outside)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $stockA = scopeStock($orgA, $facilityA, 'ISOA', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);
    $stockB = scopeStock($orgB, $facilityB, 'ISOB', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);

    // Tenant B dispenses its own stock; tenant A's batch is untouched.
    $doctorBUser = Identity::user();
    $pharmBUser = Identity::user();
    ['prescriptionId' => $pidB] = scopeVerifiedPrescription($this, $orgB, $facilityB, $doctorBUser, $pharmBUser, $stockB['medication']->getKey(), 10);

    $this->withToken(Identity::tokenFor($pharmBUser))
        ->postJson('/api/v1/prescriptions/'.$pidB.'/dispense')
        ->assertOk();

    expect(StockBatch::query()->findOrFail($stockA['batches'][0]->getKey())->quantity_remaining)->toBe(100)
        ->and(StockBatch::query()->findOrFail($stockB['batches'][0]->getKey())->quantity_remaining)->toBe(90);

    // DB-layer isolation is covered by the RLS claims proof; the API layer
    // refuses a cross-tenant batch selection on a tenant-B prescription.
    $stockA2 = scopeStock($orgA, $facilityA, 'ISOC', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => false]]);
    $doctorAUser = Identity::user();
    $pharmAUser = Identity::user();
    ['prescriptionId' => $pidA, 'lineIds' => [$lidA]] = scopeVerifiedPrescription($this, $orgA, $facilityA, $doctorAUser, $pharmAUser, $stockA2['medication']->getKey(), 10);

    $this->withToken(Identity::tokenFor($pharmAUser))
        ->postJson('/api/v1/prescriptions/'.$pidA.'/dispense', [
            'batchSelections' => [['lineId' => $lidA, 'batchId' => $stockB['batches'][0]->getKey()]],
        ])
        ->assertStatus(422);
});

it('keeps batch and dual-verification audit payloads PHI-safe', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $pharmUser = Identity::user();
    $pharm2User = Identity::user();

    $stock = scopeStock($org, $facility, 'AUDC', [['expiresInDays' => 180, 'quantity' => 100, 'controlled' => true]]);

    ['prescriptionId' => $prescriptionId, 'lineIds' => [$lineId]] = scopeVerifiedPrescription($this, $org, $facility, $doctorUser, $pharmUser, $stock['medication']->getKey(), 10);

    $this->withToken(Identity::tokenFor($pharmUser))
        ->postJson('/api/v1/prescriptions/'.$prescriptionId.'/dispense')
        ->assertOk();

    scopeStaff($org, $facility, $pharm2User, 'Pharmacist');
    Identity::assign($pharm2User, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharm2User))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/dual-verify')
        ->assertOk();

    $events = AuditEvent::query()->where('tenant_id', $org->getKey())->get();
    foreach ($events as $event) {
        $payload = $event->payload;
        expect(json_encode($payload))->not->toContain('Scope Med')
            ->not->toContain('B-AUDC');
    }
});
