<?php

use App\Models\AuditEvent;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorContract;
use Tests\Support\Identity;

/**
 * Phase 14 — procurement (PRODUCT_REQUIREMENTS §6.16, DATABASE.md §3.32):
 * vendor master + contracts, purchase request → approval (requester never
 * approves their own) → PO (contract prices enforced) → goods receipt
 * (stock-in) → three-way match → PO close. Mismatches block the close — the
 * payment gate.
 */
beforeEach(function (): void {
    seedIdentity();
});

function procurementSetup(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $requester = Identity::user();
    $approver = Identity::user();
    Identity::assign($requester, 'hospital_admin', $org, $facility);
    Identity::assign($approver, 'org_finance', $org, $facility);

    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'code' => 'PARA', 'generic_name' => 'Paracetamol', 'strength' => '500mg',
        'form' => 'tablet', 'unit' => 'tab', 'price_minor' => 500, 'currency' => 'NPR', 'status' => 'active',
    ]);

    $vendor = Vendor::query()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'code' => 'VND-001', 'name' => 'Med Supply Co', 'status' => 'active',
    ]);

    return [$org, $facility, $requester, $approver, $medication, $vendor];
}

it('creates a vendor with encrypted credentials and blacklists it', function () {
    [$org, $facility, $requester] = procurementSetup();

    $created = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/vendors', [
            'facilityId' => $facility->getKey(),
            'code' => 'VND-002',
            'name' => 'Pharma Wholesale',
            'taxId' => 'TAX-998877',
            'bankDetails' => '{"iban":"NPR-0001"}',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.hasTaxId', true)
        ->assertJsonPath('data.hasBankDetails', true)
        ->json('data.id');

    // Credentials are encrypted at rest and never echoed as plaintext.
    $row = Vendor::query()->findOrFail($created);
    expect($row->tax_id_encrypted)->toBe('TAX-998877') // decrypted via cast
        ->and($row->getRawOriginal('tax_id_encrypted'))->not->toBe('TAX-998877');

    // The vendor appears in the listing but credentials are never exposed.
    $listed = collect($this->withToken(Identity::tokenFor($requester))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/procurement/vendors')
        ->json('data'));
    $listedVendor = $listed->firstWhere('id', $created);
    expect($listedVendor)->not->toBeNull()
        ->and(json_encode($listedVendor))->not->toContain('TAX-998877')
        ->and(json_encode($listedVendor))->not->toContain('iban')
        ->and(AuditEvent::query()->where('action', 'vendor.created')->count())->toBe(1);

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/vendors/'.$created.'/blacklist')
        ->assertOk()
        ->assertJsonPath('data.status', 'blacklisted');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/vendors/'.$created.'/blacklist')
        ->assertStatus(409);
});

it('runs the full procurement chain: request → approval → PO → GRN → match → close', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 20, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->json('data.id');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $orderId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(),
            'requestId' => $requestId,
            'vendorId' => $vendor->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.lines.0.quantityOrdered', 20)
        ->json('data.id');

    $poLineId = PurchaseOrderLine::query()->where('po_id', $orderId)->firstOrFail()->getKey();

    // GRN: receive 20 units at the PO price → stock-in + PO line advanced.
    $grnId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 20, 'unitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'received')
        ->json('data.id');

    expect(InventoryItem::query()->where('medication_id', $medication->getKey())->first()->quantity_on_hand)->toBe(20)
        ->and(InventoryMovement::query()->where('movement_type', 'receipt')->count())->toBe(1);

    // Three-way match passes (qty + price equal) → matched.
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/goods-receipts/'.$grnId.'/match')
        ->assertOk()
        ->assertJsonPath('data.status', 'matched')
        ->assertJsonPath('data.matchStatus', 'matched');

    // PO close: all lines received + all GRNs matched.
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/close')
        ->assertOk()
        ->assertJsonPath('data.status', 'received');

    expect(PurchaseRequest::query()->findOrFail($requestId)->status)->toBe('ordered')
        ->and(GoodsReceipt::query()->findOrFail($grnId)->status)->toBe('matched')
        ->and(AuditEvent::query()->where('action', 'purchase_request.approved')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'goods_receipt.matched')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'purchase_order.closed')->count())->toBe(1);
});

it('a three-way match mismatch (price or quantity deviation) blocks the PO close', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 10, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $orderId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertCreated()->json('data.id');

    $poLineId = PurchaseOrderLine::query()->where('po_id', $orderId)->firstOrFail()->getKey();

    // Receive at a DIFFERENT price than the PO → mismatch.
    $grnId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 10, 'unitPriceMinor' => 500]],
        ])
        ->assertCreated()->json('data.id');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/goods-receipts/'.$grnId.'/match')
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.matchStatus', 'mismatch');

    // The mismatch blocks the close — payment is blocked.
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/close')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(PurchaseOrder::query()->findOrFail($orderId)->status)->toBe('received');
});

it('enforces contract prices at PO issue — a deviation is refused', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    VendorContract::query()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'vendor_id' => $vendor->getKey(), 'medication_id' => $medication->getKey(),
        'unit_price_minor' => 400, 'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => now()->addYear()->toDateString(), 'status' => 'active',
    ]);

    // Request at 450 (≠ contract 400).
    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 10, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('refuses over-receipt, a blacklisted vendor, and ordering an unapproved request', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 10, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');

    // Cannot order a draft request.
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $orderId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertCreated()->json('data.id');

    $poLineId = PurchaseOrderLine::query()->where('po_id', $orderId)->firstOrFail()->getKey();

    // Over-receipt refused.
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 11, 'unitPriceMinor' => 450]],
        ])
        ->assertStatus(422);

    // Blacklisted vendor cannot receive a PO.
    $blacklisted = Vendor::query()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'code' => 'VND-BAD', 'name' => 'Bad Co', 'status' => 'blacklisted',
    ]);
    $requestId2 = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 5, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId2.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId2.'/approve')->assertOk();
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId2, 'vendorId' => $blacklisted->getKey(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('handles partial receipts: multiple GRNs advance the PO line without over-receipt', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 10, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $orderId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertCreated()->json('data.id');

    $poLineId = PurchaseOrderLine::query()->where('po_id', $orderId)->firstOrFail()->getKey();

    // First receipt: 4 of 10 → partially_received.
    $grn1 = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 4, 'unitPriceMinor' => 450]],
        ])
        ->assertCreated()->json('data.id');

    // Over-receipt rejected (7 > remaining 6).
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 7, 'unitPriceMinor' => 450]],
        ])
        ->assertStatus(422);

    $grn2 = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 6, 'unitPriceMinor' => 450]],
        ])
        ->assertCreated()->json('data.id');

    $line = PurchaseOrderLine::query()->findOrFail($poLineId);
    expect($line->received_quantity)->toBe(10)
        ->and(PurchaseOrder::query()->findOrFail($orderId)->status)->toBe('received')
        ->and(GoodsReceipt::query()->count())->toBe(2)
        ->and(InventoryItem::query()->where('medication_id', $medication->getKey())->first()->quantity_on_hand)->toBe(10);

    // Both GRNs must be matched before the PO closes.
    foreach ([$grn1, $grn2] as $grnId) {
        $this->withToken(Identity::tokenFor($requester))
            ->postJson('/api/v1/goods-receipts/'.$grnId.'/match')
            ->assertOk()
            ->assertJsonPath('data.matchStatus', 'matched');
    }

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/close')
        ->assertOk();
});

it('segregates duties and enforces RBAC across the procurement chain', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    // The requester cannot approve their own request.
    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 5, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // org_finance has procurement:approve but NOT procurement:order.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A billing clerk cannot request or view procurement documents.
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests')
        ->assertStatus(403);

    // Unauthenticated.
    $this->getJson('/api/v1/organizations/'.$org->getKey().'/procurement/vendors')->assertStatus(403);
});

it('is tenant and facility isolated — cross-tenant procurement is denied with data untouched', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $otherOrg = Identity::organization();
    $otherFacility = Identity::facility($otherOrg);
    $otherVendor = Vendor::query()->create([
        'tenant_id' => $otherOrg->getKey(), 'facility_id' => $otherFacility->getKey(),
        'code' => 'VND-OTHER', 'name' => 'Other Tenant Co', 'status' => 'active',
    ]);

    // Org A's requester cannot even resolve org B's vendor (write → 403).
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/vendors/'.$otherVendor->getKey().'/blacklist')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(Vendor::query()->findOrFail($otherVendor->getKey())->status)->toBe('active')
        ->and(Vendor::query()->count())->toBe(2);
});

it('audits procurement events with facts only — never free-text or credentials', function () {
    [$org, $facility, $requester, $approver, $medication, $vendor] = procurementSetup();

    $requestId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/requests', [
            'facilityId' => $facility->getKey(),
            'lines' => [['medicationId' => $medication->getKey(), 'quantity' => 5, 'estimatedUnitPriceMinor' => 450]],
        ])
        ->assertCreated()
        ->json('data.id');
    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/submit')->assertOk();
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/purchase-requests/'.$requestId.'/approve')->assertOk();

    $orderId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/procurement/orders', [
            'facilityId' => $facility->getKey(), 'requestId' => $requestId, 'vendorId' => $vendor->getKey(),
        ])
        ->assertCreated()->json('data.id');

    $poLineId = PurchaseOrderLine::query()->where('po_id', $orderId)->firstOrFail()->getKey();

    $grnId = $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/purchase-orders/'.$orderId.'/goods-receipts', [
            'lines' => [['poLineId' => $poLineId, 'quantity' => 5, 'unitPriceMinor' => 450]],
        ])
        ->assertCreated()->json('data.id');

    $this->withToken(Identity::tokenFor($requester))
        ->postJson('/api/v1/goods-receipts/'.$grnId.'/match')->assertOk();

    foreach (['purchase_request.created', 'purchase_request.submitted', 'purchase_request.approved', 'purchase_order.issued', 'goods_receipt.received', 'goods_receipt.matched'] as $action) {
        $event = AuditEvent::query()->where('action', $action)->firstOrFail();
        $payload = json_encode($event->payload, JSON_THROW_ON_ERROR);
        expect($payload)->not->toContain('Med Supply Co')
            ->and($payload)->not->toContain('Paracetamol')
            ->and($payload)->not->toContain('TAX-')
            ->and($payload)->not->toContain('bank');
    }
});
