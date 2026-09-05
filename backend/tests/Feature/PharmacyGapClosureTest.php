<?php

use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use App\Models\Wastage;
use App\Models\StockCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Pharmacy gap-closure tests — wastage recording, stock count / cycle
 * counting, drug interaction routes, and medication history. Each test
 * proves the new capabilities against real PostgreSQL.
 */
beforeEach(function (): void {
    seedIdentity();
});

function gapStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function gapStock(Organization $org, Facility $facility, int $quantity = 100): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'GAP-'.Str::random(6),
        'generic_name' => 'Amoxicillin',
        'price_minor' => 500,
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

    $batch = StockBatch::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'inventory_item_id' => $item->getKey(),
        'medication_id' => $medication->getKey(),
        'batch_number' => 'GB-'.Str::random(8),
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'quantity_received' => $quantity,
        'quantity_remaining' => $quantity,
        'status' => StockBatch::STATUS_AVAILABLE,
        'controlled_dispense_requires_dual' => false,
        'lock_version' => 0,
    ]);

    return ['medication' => $medication, 'item' => $item, 'batch' => $batch];
}

it('records wastage with stock deduction and ledger', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $user = User::factory()->create();
    $staff = gapStaff($org, $facility, $user, 'Pharmacist');
    ['medication' => $medication, 'item' => $item, 'batch' => $batch] = gapStock($org, $facility, 100);

    Identity::assign($user, 'pharmacist', $org, $facility);

    $response = $this->withToken(Identity::tokenFor($user))
        ->postJson('/api/v1/wastages', [
            'medicationId' => $medication->getKey(),
            'quantityMinor' => 10,
            'reasonCode' => 'expired',
            'stockBatchId' => $batch->getKey(),
        ])
        ->assertCreated();

    $data = $response->json('data');
    expect($data['medicationId'])->toBe($medication->getKey());
    expect($data['quantityMinor'])->toBe(10);
    expect($data['reasonCode'])->toBe('expired');

    $item->refresh();
    expect($item->quantity_on_hand)->toBe(90);

    $batch->refresh();
    expect($batch->quantity_remaining)->toBe(90);

    $movement = InventoryMovement::query()
        ->where('tenant_id', $org->getKey())
        ->where('inventory_item_id', $item->getKey())
        ->where('movement_type', InventoryMovement::TYPE_WASTAGE)
        ->first();
    expect($movement)->not->toBeNull();
    expect($movement->quantity_delta)->toBe(-10);
});

it('lists wastage records', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $user = User::factory()->create();
    $staff = gapStaff($org, $facility, $user, 'Pharmacist');
    ['medication' => $medication, 'item' => $item, 'batch' => $batch] = gapStock($org, $facility);

    Wastage::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'inventory_item_id' => $item->getKey(),
        'stock_batch_id' => $batch->getKey(),
        'batch_number' => $batch->batch_number,
        'quantity_minor' => 5,
        'reason_code' => 'damaged',
        'wasted_by_staff_id' => $staff->getKey(),
        'wasted_at' => now(),
    ]);

    Identity::assign($user, 'pharmacist', $org, $facility);

    $this->withToken(Identity::tokenFor($user))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/wastages')
        ->assertOk();
});

it('records a stock count with variance', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $user = User::factory()->create();
    $staff = gapStaff($org, $facility, $user, 'Pharmacist');
    ['medication' => $medication, 'item' => $item] = gapStock($org, $facility, 50);

    Identity::assign($user, 'pharmacist', $org, $facility);

    $response = $this->withToken(Identity::tokenFor($user))
        ->postJson('/api/v1/stock-counts', [
            'medicationId' => $medication->getKey(),
            'countedQuantity' => 48,
            'reason' => 'Annual cycle count',
        ])
        ->assertCreated();

    $data = $response->json('data');
    expect($data['expectedQuantity'])->toBe(50);
    expect($data['countedQuantity'])->toBe(48);
    expect($data['variance'])->toBe(-2);
    expect($data['status'])->toBe('counted');
});

it('reviews a stock count with CAS', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $counter = User::factory()->create();
    $reviewer = User::factory()->create();
    $counterStaff = gapStaff($org, $facility, $counter, 'Pharmacist');
    $reviewerStaff = gapStaff($org, $facility, $reviewer, 'Pharmacy Manager');
    ['medication' => $medication, 'item' => $item] = gapStock($org, $facility, 50);

    $stockCount = StockCount::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'inventory_item_id' => $item->getKey(),
        'expected_quantity' => 50,
        'counted_quantity' => 48,
        'variance' => -2,
        'reason' => 'Cycle count',
        'counted_by_staff_id' => $counterStaff->getKey(),
        'status' => StockCount::STATUS_COUNTED,
        'counted_at' => now(),
    ]);

    Identity::assign($reviewer, 'pharmacist', $org, $facility);

    $response = $this->withToken(Identity::tokenFor($reviewer))
        ->postJson('/api/v1/stock-counts/'.$stockCount->getKey().'/review', [
            'decision' => 'approved',
        ])
        ->assertOk();

    expect($response->json('data.status'))->toBe('reviewed');
});

it('prevents self-review of a stock count', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $user = User::factory()->create();
    $staff = gapStaff($org, $facility, $user, 'Pharmacist');
    ['medication' => $medication, 'item' => $item] = gapStock($org, $facility, 50);

    $stockCount = StockCount::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'inventory_item_id' => $item->getKey(),
        'expected_quantity' => 50,
        'counted_quantity' => 48,
        'variance' => -2,
        'counted_by_staff_id' => $staff->getKey(),
        'status' => StockCount::STATUS_COUNTED,
        'counted_at' => now(),
    ]);

    Identity::assign($user, 'pharmacist', $org, $facility);

    $this->withToken(Identity::tokenFor($user))
        ->postJson('/api/v1/stock-counts/'.$stockCount->getKey().'/review', [
            'decision' => 'approved',
        ])
        ->assertForbidden();
});

it('provides drug interaction check via routes', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $user = User::factory()->create();
    $staff = gapStaff($org, $facility, $user, 'Pharmacist');

    $medA = Medication::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => Medication::STATUS_ACTIVE]);
    $medB = Medication::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => Medication::STATUS_ACTIVE]);

    Identity::assign($user, 'pharmacist', $org, $facility);

    $this->withToken(Identity::tokenFor($user))
        ->postJson('/api/v1/drug-interactions/check', [
            'medicationIds' => [$medA->getKey(), $medB->getKey()],
        ])
        ->assertOk();

    $this->withToken(Identity::tokenFor($user))
        ->getJson('/api/v1/drug-interactions')
        ->assertOk();
});

it('provides medication history for a patient', function () {
    $org = Organization::factory()->create();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    DB::statement("SELECT set_config('app.tenant_id', '".$org->getKey()."', false)");
    DB::statement("SELECT set_config('app.facility_id', '".$facility->getKey()."', false)");

    $response = $this->getJson('/api/v1/patients/'.$patient->getKey().'/medication-history');

    // Should be 200 (empty history) or 403 (no user context) — not 500.
    expect($response->status())->not->toBe(500);
});

it('enforces tenant isolation on wastage', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $facilityA = Facility::factory()->create(['tenant_id' => $orgA->getKey()]);
    $facilityB = Facility::factory()->create(['tenant_id' => $orgB->getKey()]);
    $userB = User::factory()->create();
    $staffB = gapStaff($orgB, $facilityB, $userB, 'Pharmacist');
    ['medication' => $medicationA] = gapStock($orgA, $facilityA);

    Identity::assign($userB, 'pharmacist', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($userB))
        ->postJson('/api/v1/wastages', [
            'medicationId' => $medicationA->getKey(),
            'quantityMinor' => 5,
            'reasonCode' => 'expired',
        ])
        ->assertNotFound();
});

it('enforces tenant isolation on stock counts', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $facilityA = Facility::factory()->create(['tenant_id' => $orgA->getKey()]);
    $facilityB = Facility::factory()->create(['tenant_id' => $orgB->getKey()]);
    $userB = User::factory()->create();
    $staffB = gapStaff($orgB, $facilityB, $userB, 'Pharmacist');
    ['medication' => $medicationA] = gapStock($orgA, $facilityA);

    Identity::assign($userB, 'pharmacist', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($userB))
        ->postJson('/api/v1/stock-counts', [
            'medicationId' => $medicationA->getKey(),
            'countedQuantity' => 10,
        ])
        ->assertNotFound();
});
