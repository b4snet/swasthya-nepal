<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\IssueOrderRequest;
use App\Http\Requests\Procurement\ReceiveGoodsRequest;
use App\Http\Requests\Procurement\RejectPurchaseRequest;
use App\Http\Requests\Procurement\StoreContractRequest;
use App\Http\Requests\Procurement\StorePurchaseRequest;
use App\Http\Requests\Procurement\StoreVendorRequest;
use App\Models\GoodsReceipt;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\ProcurementService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 — the procurement surface (PRODUCT_REQUIREMENTS §6.16, DATABASE.md
 * §3.32): vendor master + contracts, purchase request → approval (requester
 * never approves their own) → PO (contract prices enforced) → goods receipt
 * (stock-in) → three-way match → PO close. Payment blocks on match failure
 * are enforced, not advisory.
 */
final class ProcurementController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProcurementService $procurement,
    ) {}

    /* ---------------- Vendors + contracts ---------------- */

    public function indexVendors(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Vendor::query()->where('tenant_id', $organization->getKey());
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $vendors = $query->orderBy('name')->get()->map(fn (Vendor $v): array => self::presentVendor($v))->values();

        return Envelope::success(data: $vendors, request: $request);
    }

    public function storeVendor(StoreVendorRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $vendor = $this->procurement->createVendor(
            (string) $context->tenantId(),
            (string) $facility->getKey(),
            (string) $request->validated('code'),
            (string) $request->validated('name'),
            $request->validated('taxId'),
            $request->validated('bankDetails'),
            $context->user?->getKey(),
        );

        $this->audit->record('vendor.created', 'vendor', $vendor->getKey(), [
            'facilityId' => $vendor->facility_id,
            'code' => $vendor->code,
        ], $request);

        return Envelope::success(data: self::presentVendor($vendor), status: 201, request: $request);
    }

    public function blacklistVendor(Request $request, Vendor $vendor): JsonResponse
    {
        AccessCheck::scoped($vendor, write: true);

        $context = TenantContext::current();
        $blacklisted = $this->procurement->blacklistVendor(
            (string) $context->tenantId(),
            (string) $vendor->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('vendor.blacklisted', 'vendor', $blacklisted->getKey(), [
            'facilityId' => $blacklisted->facility_id,
            'code' => $blacklisted->code,
        ], $request);

        return Envelope::success(data: self::presentVendor($blacklisted), request: $request);
    }

    public function indexContracts(Request $request, Vendor $vendor): JsonResponse
    {
        AccessCheck::scoped($vendor, write: false);

        $contracts = $vendor->contracts()->orderBy('valid_from')->get()
            ->map(fn ($contract): array => [
                'id' => $contract->getKey(),
                'vendorId' => $contract->vendor_id,
                'medicationId' => $contract->medication_id,
                'unitPriceMinor' => $contract->unit_price_minor,
                'validFrom' => $contract->valid_from?->toDateString(),
                'validTo' => $contract->valid_to?->toDateString(),
                'terms' => $contract->terms,
                'status' => $contract->status,
            ])
            ->values();

        return Envelope::success(data: $contracts, request: $request);
    }

    public function storeContract(StoreContractRequest $request, Vendor $vendor): JsonResponse
    {
        AccessCheck::scoped($vendor, write: true);

        $context = TenantContext::current();
        $medication = Medication::query()
            ->where('tenant_id', $vendor->tenant_id)
            ->where('facility_id', $vendor->facility_id)
            ->where('id', $request->validated('medicationId'))
            ->first();

        if ($medication === null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The medication must be an active formulary item in this facility.', 422);
        }

        $contract = $this->procurement->createContract(
            (string) $vendor->tenant_id,
            (string) $vendor->facility_id,
            (string) $vendor->getKey(),
            (string) $request->validated('medicationId'),
            (int) $request->validated('unitPriceMinor'),
            (string) $request->validated('validFrom'),
            (string) $request->validated('validTo'),
            $request->validated('terms'),
            $context->user?->getKey(),
        );

        $this->audit->record('contract.created', 'vendor_contract', $contract->getKey(), [
            'vendorId' => $vendor->getKey(),
            'medicationId' => $contract->medication_id,
            'unitPriceMinor' => $contract->unit_price_minor,
        ], $request);

        return Envelope::success(data: [
            'id' => $contract->getKey(),
            'vendorId' => $contract->vendor_id,
            'medicationId' => $contract->medication_id,
            'unitPriceMinor' => $contract->unit_price_minor,
            'validFrom' => $contract->valid_from?->toDateString(),
            'validTo' => $contract->valid_to?->toDateString(),
            'status' => $contract->status,
        ], status: 201, request: $request);
    }

    /* ---------------- Purchase requests ---------------- */

    public function indexRequests(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = PurchaseRequest::query()->where('tenant_id', $organization->getKey());
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $requests = $query->orderByDesc('created_at')->get()
            ->map(fn (PurchaseRequest $r): array => $this->presentRequest($r))->values();

        return Envelope::success(data: $requests, request: $request);
    }

    public function storeRequest(StorePurchaseRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $purchaseRequest = $this->procurement->createRequest(
            (string) $context->tenantId(),
            (string) $facility->getKey(),
            $request->validated('lines'),
            $request->validated('departmentId'),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_request.created', 'purchase_request', $purchaseRequest->getKey(), [
            'facilityId' => $purchaseRequest->facility_id,
            'estimatedTotalMinor' => $purchaseRequest->estimatedTotalMinor(),
        ], $request);

        return Envelope::success(data: $this->presentRequest($purchaseRequest->load('lines')), status: 201, request: $request);
    }

    public function showRequest(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        AccessCheck::scoped($purchaseRequest, write: false);

        return Envelope::success(data: $this->presentRequest($purchaseRequest->load('lines', 'approval')), request: $request);
    }

    public function submitRequest(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        AccessCheck::scoped($purchaseRequest, write: true);

        $context = TenantContext::current();
        $submitted = $this->procurement->submitRequest(
            (string) $context->tenantId(),
            (string) $purchaseRequest->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_request.submitted', 'purchase_request', $submitted->getKey(), [
            'facilityId' => $submitted->facility_id,
            'estimatedTotalMinor' => $submitted->estimatedTotalMinor(),
        ], $request);

        return Envelope::success(data: $this->presentRequest($submitted->load('lines')), request: $request);
    }

    public function approveRequest(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        AccessCheck::scoped($purchaseRequest, write: true);

        $context = TenantContext::current();
        $approved = $this->procurement->approveRequest(
            (string) $context->tenantId(),
            (string) $purchaseRequest->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_request.approved', 'purchase_request', $approved->getKey(), [
            'facilityId' => $approved->facility_id,
            'estimatedTotalMinor' => $approved->estimatedTotalMinor(),
        ], $request);

        return Envelope::success(data: $this->presentRequest($approved->load('lines', 'approval')), request: $request);
    }

    public function rejectRequest(RejectPurchaseRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        AccessCheck::scoped($purchaseRequest, write: true);

        $context = TenantContext::current();
        $rejected = $this->procurement->rejectRequest(
            (string) $context->tenantId(),
            (string) $purchaseRequest->getKey(),
            (string) $request->validated('rejectionReason'),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_request.rejected', 'purchase_request', $rejected->getKey(), [
            'facilityId' => $rejected->facility_id,
        ], $request);

        return Envelope::success(data: $this->presentRequest($rejected->load('lines', 'approval')), request: $request);
    }

    /* ---------------- Purchase orders + receipts ---------------- */

    public function indexOrders(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = PurchaseOrder::query()->where('tenant_id', $organization->getKey());
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $orders = $query->orderByDesc('created_at')->get()
            ->map(fn (PurchaseOrder $o): array => $this->presentOrder($o))->values();

        return Envelope::success(data: $orders, request: $request);
    }

    public function storeOrder(IssueOrderRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $order = $this->procurement->issueOrder(
            (string) $context->tenantId(),
            (string) $facility->getKey(),
            (string) $request->validated('requestId'),
            (string) $request->validated('vendorId'),
            $request->validated('expectedDelivery'),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_order.issued', 'purchase_order', $order->getKey(), [
            'facilityId' => $order->facility_id,
            'vendorId' => $order->vendor_id,
            'totalMinor' => $order->lines()->get()->sum(fn ($l): int => $l->quantity_ordered * $l->unit_price_minor),
        ], $request);

        return Envelope::success(data: $this->presentOrder($order->load('lines')), status: 201, request: $request);
    }

    public function confirmOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        AccessCheck::scoped($order, write: true);

        $context = TenantContext::current();
        $confirmed = $this->procurement->confirmOrder(
            (string) $context->tenantId(),
            (string) $order->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_order.confirmed', 'purchase_order', $confirmed->getKey(), [
            'facilityId' => $confirmed->facility_id,
        ], $request);

        return Envelope::success(data: $this->presentOrder($confirmed->load('lines')), request: $request);
    }

    public function closeOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        AccessCheck::scoped($order, write: true);

        $context = TenantContext::current();
        $closed = $this->procurement->closeOrder(
            (string) $context->tenantId(),
            (string) $order->getKey(),
            $context->user?->getKey(),
        );

        $this->audit->record('purchase_order.closed', 'purchase_order', $closed->getKey(), [
            'facilityId' => $closed->facility_id,
        ], $request);

        return Envelope::success(data: $this->presentOrder($closed->load('lines')), request: $request);
    }

    public function receiveGoods(ReceiveGoodsRequest $request, PurchaseOrder $order): JsonResponse
    {
        AccessCheck::scoped($order, write: true);

        $context = TenantContext::current();
        $result = $this->procurement->receiveGoods(
            (string) $context->tenantId(),
            (string) $order->facility_id,
            (string) $order->getKey(),
            $request->validated('lines'),
            $context->user?->getKey(),
        );

        $grn = $result['grn'];

        $this->audit->record('goods_receipt.received', 'goods_receipt', $grn->getKey(), [
            'poId' => $order->getKey(),
            'lineCount' => $grn->lines()->count(),
        ], $request);

        return Envelope::success(data: [
            'id' => $grn->getKey(),
            'grnNumber' => $grn->grn_number,
            'poId' => $grn->po_id,
            'status' => $grn->status,
            'receivedAt' => $grn->received_at?->toIso8601String(),
            'itemMovements' => $result['itemMovements'],
        ], status: 201, request: $request);
    }

    public function matchReceipt(Request $request, GoodsReceipt $grn): JsonResponse
    {
        AccessCheck::scoped($grn, write: true);

        $context = TenantContext::current();
        $matched = $this->procurement->matchReceipt(
            (string) $context->tenantId(),
            (string) $grn->getKey(),
        );

        $this->audit->record('goods_receipt.matched', 'goods_receipt', $matched->getKey(), [
            'poId' => $matched->po_id,
            'matchStatus' => $matched->match_status,
        ], $request);

        return Envelope::success(data: [
            'id' => $matched->getKey(),
            'grnNumber' => $matched->grn_number,
            'poId' => $matched->po_id,
            'status' => $matched->status,
            'matchStatus' => $matched->match_status,
        ], request: $request);
    }

    public function indexReceipts(Request $request, PurchaseOrder $order): JsonResponse
    {
        AccessCheck::scoped($order, write: false);

        $receipts = $order->receipts()->orderBy('created_at')->get()->map(fn (GoodsReceipt $grn): array => [
            'id' => $grn->getKey(),
            'grnNumber' => $grn->grn_number,
            'status' => $grn->status,
            'matchStatus' => $grn->match_status,
            'receivedAt' => $grn->received_at?->toIso8601String(),
        ])->values();

        return Envelope::success(data: $receipts, request: $request);
    }

    /* ---------------- Presentation ---------------- */

    /**
     * @return array<string, mixed>
     */
    private static function presentVendor(Vendor $vendor): array
    {
        return [
            'id' => $vendor->getKey(),
            'facilityId' => $vendor->facility_id,
            'code' => $vendor->code,
            'name' => $vendor->name,
            'status' => $vendor->status,
            // Credentials are encrypted at rest and never echoed.
            'hasTaxId' => $vendor->tax_id_encrypted !== null,
            'hasBankDetails' => $vendor->bank_details_encrypted !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRequest(PurchaseRequest $request): array
    {
        return [
            'id' => $request->getKey(),
            'facilityId' => $request->facility_id,
            'requestNumber' => $request->request_number,
            'status' => $request->status,
            'requestedAt' => $request->requested_at?->toIso8601String(),
            'estimatedTotalMinor' => $request->estimatedTotalMinor(),
            'approval' => $request->approval ? [
                'approverId' => $request->approval->approver_id,
                'decision' => $request->approval->decision,
                'decidedAt' => $request->approval->decided_at?->toIso8601String(),
            ] : null,
            'lines' => $request->lines->map(fn ($line): array => [
                'id' => $line->getKey(),
                'medicationId' => $line->medication_id,
                'quantity' => $line->quantity,
                'estimatedUnitPriceMinor' => $line->estimated_unit_price_minor,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(PurchaseOrder $order): array
    {
        return [
            'id' => $order->getKey(),
            'facilityId' => $order->facility_id,
            'poNumber' => $order->po_number,
            'vendorId' => $order->vendor_id,
            'status' => $order->status,
            'expectedDelivery' => $order->expected_delivery?->toDateString(),
            'lockVersion' => $order->lock_version,
            'lines' => $order->lines->map(fn ($line): array => [
                'id' => $line->getKey(),
                'medicationId' => $line->medication_id,
                'quantityOrdered' => $line->quantity_ordered,
                'unitPriceMinor' => $line->unit_price_minor,
                'receivedQuantity' => $line->received_quantity,
            ])->values(),
        ];
    }
}
