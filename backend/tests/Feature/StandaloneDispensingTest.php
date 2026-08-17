<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Dispensing;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 — STANDALONE dispensing records (PRODUCT_REQUIREMENTS §6.7
 * `dispensing` entity + the stock-out mode without a prescription;
 * DATABASE.md §3.30; the documented remaining-scope "standalone
 * `dispensings` table"). A pharmacist dispenses a medication directly to a
 * patient with NO prescription: the exact batch is drawn with the SAME CAS
 * machinery as prescription dispensing (FEFO/explicit selection, batch +
 * shelf deduction), the ledger movement references the standalone record
 * (no prescription_line_id), a posted charge (source_type 'dispensing',
 * price × quantity) is linked via dispensing_id, and the whole operation is
 * one transaction. Dual-required controlled batches are refused (the dual
 * verification surface is prescription-only). RLS-isolated, pharmacist-only,
 * PHI-safe audit, idempotent against the stock CAS.
 */
beforeEach(function (): void {
    seedIdentity();
});

function standaloneStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function standalonePatient(Organization $org, Facility $facility): Patient
{
    return Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
}

/**
 * @param  list<array{expiresInDays: int, quantity: int, controlled?: bool}>  $batches
 * @return array{medication: Medication, item: InventoryItem, batches: list<StockBatch>}
 */
function standaloneStock(Organization $org, Facility $facility, string $code, array $batches): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'generic_name' => 'OTC '.$code,
        'strength' => '500mg',
        'form' => 'tablet',
        'unit' => 'tab',
        'price_minor' => 500,
        'currency' => 'NPR',
        'is_controlled' => collect($batches)->contains(fn (array $b): bool => $b['controlled'] ?? false),
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
            'controlled_dispense_requires_dual' => $batch['controlled'] ?? false,
            'lock_version' => 0,
        ]);
    }

    return ['medication' => $medication, 'item' => $item, 'batches' => $created];
}

it('dispenses a medication to a patient with no prescription: record, stock, ledger, charge, audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'HAPPY', [['expiresInDays' => 180, 'quantity' => 100]]);

    $response = $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 3,
        ])
        ->assertStatus(201);

    $dispensing = Dispensing::query()->findOrFail($response->json('data.id'));
    expect($dispensing->patient_id)->toBe($patient->getKey())
        ->and($dispensing->medication_id)->toBe($stock['medication']->getKey())
        ->and($dispensing->stock_batch_id)->toBe($stock['batches'][0]->getKey())
        ->and($dispensing->batch_number)->toBe($stock['batches'][0]->batch_number)
        ->and($dispensing->quantity_minor)->toBe(3)
        ->and($dispensing->status)->toBe(Dispensing::STATUS_DISPENSED)
        ->and($dispensing->dispensed_at)->not->toBeNull();

    // Stock: batch 100 − 3 = 97; shelf 100 − 3 = 97.
    expect(StockBatch::query()->findOrFail($stock['batches'][0]->getKey())->quantity_remaining)->toBe(97)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(97);

    // Ledger: the dispense movement references the standalone record, not a
    // prescription line.
    $movement = InventoryMovement::query()->where('dispensing_id', $dispensing->getKey())->firstOrFail();
    expect($movement->movement_type)->toBe(InventoryMovement::TYPE_DISPENSE)
        ->and($movement->quantity_delta)->toBe(-3)
        ->and($movement->stock_batch_id)->toBe($stock['batches'][0]->getKey())
        ->and($movement->prescription_line_id)->toBeNull();

    // Financial: the posted dispensing charge, price × quantity.
    $charge = Charge::query()->where('dispensing_id', $dispensing->getKey())->firstOrFail();
    expect($charge->source_type)->toBe(Charge::SOURCE_DISPENSING)
        ->and($charge->amount_minor)->toBe(1500)
        ->and($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->patient_id)->toBe($patient->getKey());

    // Audit: facts only.
    $audit = AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->firstOrFail();
    expect($audit->resource_type)->toBe('dispensing')
        ->and($audit->payload['quantityMinor'])->toBe(3)
        ->and($audit->payload['totalAmountMinor'])->toBe(1500);
});

it('picks FEFO and honours an explicit batch selection; refuses wrong-medication and expired batches', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'SELD', [
        ['expiresInDays' => 300, 'quantity' => 50],
        ['expiresInDays' => 60, 'quantity' => 50],
    ]);
    $near = $stock['batches'][1];
    $far = $stock['batches'][0];

    // FEFO picks the near-expiry batch.
    $dispensed = $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 5,
        ])
        ->assertStatus(201);
    expect($dispensed->json('data.batchId'))->toBe($near->getKey())
        ->and(StockBatch::query()->findOrFail($near->getKey())->quantity_remaining)->toBe(45)
        ->and(StockBatch::query()->findOrFail($far->getKey())->quantity_remaining)->toBe(50);

    // Explicit selection of the other (still valid) batch.
    $second = $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 5,
            'batchId' => $far->getKey(),
        ])
        ->assertStatus(201);
    expect($second->json('data.batchId'))->toBe($far->getKey())
        ->and(StockBatch::query()->findOrFail($far->getKey())->quantity_remaining)->toBe(45);

    // A wrong-medication batch → 422 (existence of the batch is hidden).
    $other = standaloneStock($org, $facility, 'SELO', [['expiresInDays' => 180, 'quantity' => 50]]);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
            'batchId' => $other['batches'][0]->getKey(),
        ])
        ->assertStatus(422);

    // An expired batch is never issuable.
    $expired = StockBatch::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'inventory_item_id' => $stock['item']->getKey(),
        'medication_id' => $stock['medication']->getKey(),
        'batch_number' => 'B-EXP-'.substr((string) Str::uuid(), 0, 6),
        'expiry_date' => now()->subDays(2)->toDateString(),
        'quantity_received' => 10,
        'quantity_remaining' => 10,
        'status' => StockBatch::STATUS_AVAILABLE,
        'controlled_dispense_requires_dual' => false,
        'lock_version' => 0,
    ]);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
            'batchId' => $expired->getKey(),
        ])
        ->assertStatus(409);
});

it('refuses a dual-required controlled substance (prescription-only dual verification)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'CTRL', [['expiresInDays' => 180, 'quantity' => 50, 'controlled' => true]]);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(409);

    expect(Dispensing::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(StockBatch::query()->findOrFail($stock['batches'][0]->getKey())->quantity_remaining)->toBe(50)
        ->and(AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->count())->toBe(0);
});

it('rejects invalid and unlinked requests with zero side effects', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'INVL', [['expiresInDays' => 180, 'quantity' => 50]]);

    // Unknown patient → 404.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => (string) Str::uuid(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(404);

    // Unknown medication → 422.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => (string) Str::uuid(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(422);

    // Zero / negative / non-numeric quantity → 422.
    foreach ([0, -1, 'two'] as $qty) {
        $this->withToken(Identity::tokenFor($pharmacist))
            ->postJson('/api/v1/dispensings', [
                'patientId' => $patient->getKey(),
                'medicationId' => $stock['medication']->getKey(),
                'quantityMinor' => $qty,
            ])
            ->assertStatus(422);
    }

    // Missing patientId / medicationId → 422 (an unlinked dispensing is
    // never valid).
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', ['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1])
        ->assertStatus(422);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', ['patientId' => $patient->getKey(), 'quantityMinor' => 1])
        ->assertStatus(422);

    // No stock configured for the medication → 409.
    $noStock = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'NOSTK',
        'generic_name' => 'OTC NOSTK',
        'price_minor' => 500,
        'currency' => 'NPR',
        'status' => Medication::STATUS_ACTIVE,
    ]);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $noStock->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(409);

    // Nothing was created or audited.
    expect(Dispensing::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->count())->toBe(0);
});

it('rolls back the whole transaction when the stock CAS loses (insufficient stock, full rollback)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'SHORT', [['expiresInDays' => 180, 'quantity' => 2]]);

    // First dispense takes all 2.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 2,
        ])
        ->assertStatus(201);

    // Second dispense: only 0 remain → 409 and NOTHING is written.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(409);

    expect(Dispensing::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->count())->toBe(1)
        ->and(StockBatch::query()->findOrFail($stock['batches'][0]->getKey())->quantity_remaining)->toBe(0)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(0);
});

it('enforces RBAC: pharmacy:dispense only, unauthenticated 401', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'RBAC', [['expiresInDays' => 180, 'quantity' => 50]]);

    $payload = [
        'patientId' => $patient->getKey(),
        'medicationId' => $stock['medication']->getKey(),
        'quantityMinor' => 1,
    ];

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->postJson('/api/v1/dispensings', $payload)->assertStatus(401);

    // A doctor (pharmacy:view but NOT pharmacy:dispense) → 403.
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/dispensings', $payload)
        ->assertStatus(403);

    // A pharmacist → 201.
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', $payload)
        ->assertStatus(201);
});

it('enforces cross-tenant and cross-facility isolation with data untouched', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $patientA = standalonePatient($orgA, $facilityA);
    $stockA = standaloneStock($orgA, $facilityA, 'ISOA', [['expiresInDays' => 180, 'quantity' => 50]]);

    // Tenant-B pharmacist attacking tenant A's patient → 404 (existence hidden).
    $pharmacistB = Identity::user();
    standaloneStaff($orgB, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $orgB, $facilityB);
    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patientA->getKey(),
            'medicationId' => $stockA['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(404);

    // Tenant-B pharmacist selecting tenant A's batch on tenant B's own
    // patient → 422 (the batch is outside the medication's facility).
    $patientB = standalonePatient($orgB, $facilityB);
    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patientB->getKey(),
            'medicationId' => $stockA['medication']->getKey(),
            'quantityMinor' => 1,
            'batchId' => $stockA['batches'][0]->getKey(),
        ])
        ->assertStatus(422);

    // Cross-facility within the same tenant: a facility-A2 pharmacist cannot
    // dispense facility-A's patient or stock → 404 / 422.
    $facilityA2 = Identity::facility($orgA);
    $pharmacistA2 = Identity::user();
    standaloneStaff($orgA, $facilityA2, $pharmacistA2, 'Pharmacist');
    Identity::assign($pharmacistA2, 'pharmacist', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($pharmacistA2))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patientA->getKey(),
            'medicationId' => $stockA['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(404);

    // Tenant A's data is untouched.
    expect(Dispensing::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(StockBatch::query()->findOrFail($stockA['batches'][0]->getKey())->quantity_remaining)->toBe(50)
        ->and(AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->count())->toBe(0);
});

it('keeps patient identifiers and medication names out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    standaloneStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $patient = standalonePatient($org, $facility);
    $stock = standaloneStock($org, $facility, 'PHIA', [['expiresInDays' => 180, 'quantity' => 50]]);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/dispensings', [
            'patientId' => $patient->getKey(),
            'medicationId' => $stock['medication']->getKey(),
            'quantityMinor' => 1,
        ])
        ->assertStatus(201);

    $audit = AuditEvent::query()->where('action', 'pharmacy.standalone_dispensed')->firstOrFail();
    $encoded = json_encode($audit->payload);

    expect($encoded)->not->toContain($patient->full_name)
        ->not->toContain('OTC PHIA')
        ->not->toContain($stock['medication']->generic_name)
        ->and($audit->payload['patientId'])->toBe($patient->getKey())
        ->and($audit->payload['medicationId'])->toBe($stock['medication']->getKey());
});
