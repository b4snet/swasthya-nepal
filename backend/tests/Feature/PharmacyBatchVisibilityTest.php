<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 — pharmacy batch/lot and expiry VISIBILITY (ROADMAP Phase 12
 * acceptance: "expiring/expired batches visible and never issuable";
 * PRODUCT_REQUIREMENTS §6.7: "expiring-stock handling must be visible to
 * staff"). GET /inventory-items/{item}/batches exposes every batch of an
 * item — available, depleted, quarantined, expired — ordered soonest expiry
 * first, with a date-derived expiry status (`valid` / `expiring_soon` /
 * `expired`), days to expiry (negative when expired), per-batch stock, and
 * the controlled-substance flag. The endpoint is read-only (no mutation, no
 * audit), facility-scoped, pharmacy:view-gated, and RLS-isolated; the
 * dispensing CAS remains the hard expiry gate (never issuable is proven
 * separately in PharmacyScopeTest).
 */
beforeEach(function (): void {
    seedIdentity();
});

function batchVisibilityStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

/**
 * Create a medication + shelf + batches with explicit expiry offsets (days
 * from today; negative = already expired).
 *
 * @param  list<array{expiresInDays: int, quantity: int, status?: string, controlled?: bool}>  $batches
 * @return array{medication: Medication, item: InventoryItem, batches: list<StockBatch>}
 */
function batchVisibilityStock(Organization $org, Facility $facility, string $code, array $batches): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'generic_name' => 'BatchMed '.$code,
        'price_minor' => 500,
        'currency' => 'NPR',
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
            'status' => $batch['status'] ?? StockBatch::STATUS_AVAILABLE,
            'controlled_dispense_requires_dual' => $batch['controlled'] ?? false,
            'lock_version' => 0,
        ]);
    }

    return ['medication' => $medication, 'item' => $item, 'batches' => $created];
}

it('lists every batch of an item with expiry fields, soonest expiry first', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $stock = batchVisibilityStock($org, $facility, 'VISB', [
        ['expiresInDays' => 300, 'quantity' => 50],
        ['expiresInDays' => 60, 'quantity' => 40],
        ['expiresInDays' => -5, 'quantity' => 10],
        ['expiresInDays' => 120, 'quantity' => 30, 'status' => StockBatch::STATUS_QUARANTINED],
        ['expiresInDays' => 90, 'quantity' => 0, 'status' => StockBatch::STATUS_DEPLETED],
    ]);

    $response = $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk();

    $data = $response->json('data');

    // All five batches visible, ordered by expiry (soonest first: the
    // expired one, then 60, 90, 120, 300).
    expect($data)->toHaveCount(5)
        ->and(collect($data)->pluck('id')->all())
        ->toBe([
            $stock['batches'][2]->getKey(), // -5 → expired
            $stock['batches'][1]->getKey(), // 60
            $stock['batches'][4]->getKey(), // 90 → depleted
            $stock['batches'][3]->getKey(), // 120 → quarantined
            $stock['batches'][0]->getKey(), // 300
        ]);

    // Per-batch facts.
    $expired = collect($data)->firstWhere('id', $stock['batches'][2]->getKey());
    expect($expired['expiryStatus'])->toBe(StockBatch::EXPIRY_STATUS_EXPIRED)
        ->and($expired['daysToExpiry'])->toBeLessThan(0)
        ->and($expired['quantityRemaining'])->toBe(10)
        ->and($expired['status'])->toBe(StockBatch::STATUS_AVAILABLE);

    $quarantined = collect($data)->firstWhere('id', $stock['batches'][3]->getKey());
    expect($quarantined['status'])->toBe(StockBatch::STATUS_QUARANTINED)
        ->and($quarantined['expiryStatus'])->toBe(StockBatch::EXPIRY_STATUS_VALID)
        ->and($quarantined['quantityReceived'])->toBe(30);

    $depleted = collect($data)->firstWhere('id', $stock['batches'][4]->getKey());
    expect($depleted['status'])->toBe(StockBatch::STATUS_DEPLETED)
        ->and($depleted['expiryStatus'])->toBe(StockBatch::EXPIRY_STATUS_EXPIRING_SOON);
});

it('flags expiring-soon at the documented 90-day boundary (90 expiring, 91 valid)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $stock = batchVisibilityStock($org, $facility, 'BNDY', [
        ['expiresInDays' => 90, 'quantity' => 10],
        ['expiresInDays' => 91, 'quantity' => 10],
    ]);

    $data = $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk()
        ->json('data');

    $at90 = collect($data)->firstWhere('id', $stock['batches'][0]->getKey());
    $at91 = collect($data)->firstWhere('id', $stock['batches'][1]->getKey());

    expect($at90['expiryStatus'])->toBe(StockBatch::EXPIRY_STATUS_EXPIRING_SOON)
        ->and($at90['daysToExpiry'])->toBe(90)
        ->and($at91['expiryStatus'])->toBe(StockBatch::EXPIRY_STATUS_VALID)
        ->and($at91['daysToExpiry'])->toBe(91);
});

it('exposes the controlled-substance flag per batch', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $stock = batchVisibilityStock($org, $facility, 'CTLB', [
        ['expiresInDays' => 180, 'quantity' => 10, 'controlled' => true],
        ['expiresInDays' => 180, 'quantity' => 10, 'controlled' => false],
    ]);

    $data = $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk()
        ->json('data');

    $controlled = collect($data)->firstWhere('id', $stock['batches'][0]->getKey());
    $plain = collect($data)->firstWhere('id', $stock['batches'][1]->getKey());

    expect($controlled['controlledDispenseRequiresDual'])->toBeTrue()
        ->and($plain['controlledDispenseRequiresDual'])->toBeFalse();
});

it('is read-only: mutates nothing and records no audit event', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $stock = batchVisibilityStock($org, $facility, 'ROWB', [['expiresInDays' => 180, 'quantity' => 25]]);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk();

    expect(StockBatch::query()->findOrFail($stock['batches'][0]->getKey())->quantity_remaining)->toBe(25)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('enforces RBAC: pharmacy:view required, unauthenticated 401', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $stock = batchVisibilityStock($org, $facility, 'RBAB', [['expiresInDays' => 180, 'quantity' => 25]]);

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertStatus(401);

    // A receptionist (no pharmacy:view — the doctor role DOES hold
    // pharmacy:view, line 676 of RolePermissionSeeder) → 403.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertStatus(403);

    // A pharmacist → 200.
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk();
});

it('enforces cross-tenant and cross-facility isolation (404 reads, data untouched)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $stockA = batchVisibilityStock($orgA, $facilityA, 'ISOA', [['expiresInDays' => 180, 'quantity' => 25]]);

    // Tenant-B pharmacist cannot see tenant A's item → 404 (existence hidden).
    $pharmacistB = Identity::user();
    batchVisibilityStaff($orgB, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $orgB, $facilityB);
    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/inventory-items/'.$stockA['item']->getKey().'/batches')
        ->assertStatus(404);

    // Cross-facility within the same tenant → 404 for a facility-scoped principal.
    $facilityA2 = Identity::facility($orgA);
    $pharmacistA2 = Identity::user();
    batchVisibilityStaff($orgA, $facilityA2, $pharmacistA2, 'Pharmacist');
    Identity::assign($pharmacistA2, 'pharmacist', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($pharmacistA2))
        ->getJson('/api/v1/inventory-items/'.$stockA['item']->getKey().'/batches')
        ->assertStatus(404);

    // Tenant A's batch is untouched.
    expect(StockBatch::query()->findOrFail($stockA['batches'][0]->getKey())->quantity_remaining)->toBe(25);
});

it('keeps patient data and audit out of the batch visibility response', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $pharmacist = Identity::user();
    batchVisibilityStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    $stock = batchVisibilityStock($org, $facility, 'PHIB', [['expiresInDays' => 180, 'quantity' => 25]]);

    $response = $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/inventory-items/'.$stock['item']->getKey().'/batches')
        ->assertOk();

    $encoded = json_encode($response->json('data'));

    // No patient identifiers anywhere; only operational batch facts.
    expect($encoded)->not->toContain('patient')
        ->not->toContain('Patient')
        ->not->toContain('mrn')
        ->not->toContain('full_name');

    // Batch number (operational, not PHI) is present — same exposure the
    // dispensing response already carries.
    expect($encoded)->toContain($stock['batches'][0]->batch_number);
});
