<?php

use App\Models\AuditEvent;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Medication;
use App\Models\Patient;
use Tests\Support\Identity;

/**
 * Phase 14 — inventory (PRODUCT_REQUIREMENTS §6.15, ROADMAP §15): inter-
 * facility transfers (atomic paired ledger movements — the ledger stays the
 * only stock truth), the approval-gated adjustment path (requester ≠
 * approver), and reorder alerts.
 */
beforeEach(function (): void {
    seedIdentity();
});

function inventorySetup(string $role = 'hospital_admin'): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user();
    Identity::assign($user, $role, $org, $facility);

    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'PARA',
        'generic_name' => 'Paracetamol',
        'strength' => '500mg',
        'form' => 'tablet',
        'unit' => 'tab',
        'price_minor' => 500,
        'currency' => 'NPR',
        'status' => 'active',
    ]);

    return [$org, $facility, $user, $medication];
}

it('transfers stock between facilities atomically with paired ledger movements', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup('org_admin');
    $facilityB = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey()]);
    $source = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 50,
    ]);
    $destination = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 10,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-transfers', [
            'inventoryItemId' => $source->getKey(),
            'destinationFacilityId' => $facilityB->getKey(),
            'quantity' => 15,
            'reason' => 'Restock the branch pharmacy',
        ])
        ->assertCreated()
        ->assertJsonPath('data.quantity', 15)
        ->assertJsonPath('data.destinationFacilityId', $facilityB->getKey());

    expect($source->refresh()->quantity_on_hand)->toBe(35)
        ->and($destination->refresh()->quantity_on_hand)->toBe(25)
        ->and(InventoryTransfer::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'transfer')->count())->toBe(2)
        ->and(InventoryMovement::query()->where('movement_type', 'transfer')->where('quantity_delta', -15)->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'transfer')->where('quantity_delta', 15)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'inventory.transferred')->count())->toBe(1)
        ->and($source->quantity_on_hand + $destination->quantity_on_hand)->toBe(60);
});

it('refuses a transfer that would drive source stock negative or an unstocked destination', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup('org_admin');
    $facilityB = Identity::facility($org);
    $source = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 5,
    ]);

    // Destination not stocked at all.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-transfers', [
            'inventoryItemId' => $source->getKey(),
            'destinationFacilityId' => $facilityB->getKey(),
            'quantity' => 2,
            'reason' => 'Transfer to unstocked',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Source insufficient (5 available, 10 requested).
    $destination = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 0,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-transfers', [
            'inventoryItemId' => $source->getKey(),
            'destinationFacilityId' => $facilityB->getKey(),
            'quantity' => 10,
            'reason' => 'Over transfer',
        ])
        ->assertStatus(409);

    expect($source->refresh()->quantity_on_hand)->toBe(5)
        ->and($destination->refresh()->quantity_on_hand)->toBe(0)
        ->and(InventoryTransfer::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', 'transfer')->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'inventory.transferred')->count())->toBe(0);
});

it('requires org-level authority for transfers — a pharmacist or clerk cannot transfer', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup('org_admin');
    $facilityB = Identity::facility($org);
    $source = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 50,
    ]);

    $pharmacist = Identity::user();
    Identity::assign($pharmacist, 'pharmacist', $org, $facilityA);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/inventory-transfers', [
            'inventoryItemId' => $source->getKey(),
            'destinationFacilityId' => $facilityB->getKey(),
            'quantity' => 1,
            'reason' => 'Pharmacist transfer',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    $this->postJson('/api/v1/inventory-transfers', [
        'inventoryItemId' => $source->getKey(),
        'destinationFacilityId' => $facilityB->getKey(),
        'quantity' => 1,
        'reason' => 'anon',
    ])->assertStatus(403);

    expect(InventoryTransfer::query()->count())->toBe(0);
});

it('approves an adjustment request — the approver applies the signed delta atomically', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup();
    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 50,
    ]);

    // A pharmacist requests the correction.
    $pharmacist = Identity::user();
    Identity::assign($pharmacist, 'pharmacist', $org, $facilityA);

    $requestId = $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/inventory-items/'.$item->getKey().'/adjustment-requests', [
            'quantityDelta' => -5,
            'reason' => 'Cycle count found five damaged units',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->json('data.id');

    // The admin (not the requester) approves → stock applies + ledger row.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($item->refresh()->quantity_on_hand)->toBe(45)
        ->and(InventoryMovement::query()->where('movement_type', 'adjustment')->where('quantity_delta', -5)->count())->toBe(1)
        ->and(InventoryAdjustmentRequest::query()->findOrFail($requestId)->approved_by)->toBe($admin->getKey())
        ->and(AuditEvent::query()->where('action', 'inventory.adjustment_approved')->count())->toBe(1);
});

it('enforces requester ≠ approver and rejects with zero side effects on decline', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup();
    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 50,
    ]);

    // The requester cannot approve their own request.
    $requestId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-items/'.$item->getKey().'/adjustment-requests', [
            'quantityDelta' => 10,
            'reason' => 'Self approval attempt',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/approve')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // A different approver rejects → terminal, no stock change.
    $otherAdmin = Identity::user();
    Identity::assign($otherAdmin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($otherAdmin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/reject', [
            'rejectionReason' => 'Count already verified',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect($item->refresh()->quantity_on_hand)->toBe(50)
        ->and(InventoryMovement::query()->where('movement_type', 'adjustment')->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'inventory.adjustment_approved')->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'inventory.adjustment_rejected')->count())->toBe(1);

    // A rejected request can never be approved.
    $this->withToken(Identity::tokenFor($otherAdmin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/approve')
        ->assertStatus(409);
});

it('a duplicate adjustment approval resolves to exactly one winner and one audit', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup();
    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 50,
    ]);

    $pharmacist = Identity::user();
    Identity::assign($pharmacist, 'pharmacist', $org, $facilityA);

    $requestId = $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/inventory-items/'.$item->getKey().'/adjustment-requests', [
            'quantityDelta' => 5,
            'reason' => 'Add found stock',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/approve')
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/inventory-adjustment-requests/'.$requestId.'/approve')
        ->assertStatus(409);

    expect($item->refresh()->quantity_on_hand)->toBe(55)
        ->and(InventoryMovement::query()->where('movement_type', 'adjustment')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'inventory.adjustment_approved')->count())->toBe(1);
});

it('lists reorder alerts for items at or below their reorder level', function () {
    [$org, $facilityA, $admin, $medication] = inventorySetup();
    $low = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => $medication->getKey(), 'quantity_on_hand' => 4, 'reorder_level' => 10,
    ]);
    $healthy = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
        'medication_id' => Medication::factory()->create([
            'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(),
            'code' => 'AMOX', 'generic_name' => 'Amoxicillin', 'strength' => '250mg',
            'form' => 'capsule', 'unit' => 'cap', 'price_minor' => 300, 'currency' => 'NPR', 'status' => 'active',
        ])->getKey(),
        'quantity_on_hand' => 40, 'reorder_level' => 10,
    ]);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/reorder-alerts')
        ->assertOk();

    // The low-stock item must appear with correct shortage.
    $alerts = collect($response->json('data'));
    $lowAlert = $alerts->firstWhere('id', $low->getKey());
    expect($lowAlert)->not->toBeNull()
        ->and($lowAlert['quantityOnHand'])->toBe(4)
        ->and($lowAlert['reorderLevel'])->toBe(10)
        ->and($lowAlert['shortageMinor'])->toBe(6);

    // Healthy item must NOT appear.
    expect($alerts->firstWhere('id', $healthy->getKey()))->toBeNull();

    expect(AuditEvent::query()->where('action', 'like', 'inventory.%')->count())->toBe(0);
});
