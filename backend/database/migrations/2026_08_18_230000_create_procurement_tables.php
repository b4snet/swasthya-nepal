<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — Inventory and Procurement (ROADMAP §15, PRODUCT_REQUIREMENTS
 * §6.15–6.16, DATABASE.md §3.31–3.32).
 *
 * Adds the documented storekeeping + procurement surfaces that were still
 * planned:
 *
 *   inventory_transfers          — inter-facility stock transfer (atomic,
 *                                  paired ledger movements; stock never
 *                                  goes in-transit)
 *   inventory_adjustment_requests— the APPROVAL-GATED cycle-count /
 *                                  correction path (requester ≠ approver)
 *   vendors                      — vendor master (facility-scoped)
 *   purchase_requests(+lines)    — department request → approval → PO
 *   purchase_request_approvals   — single-level approval row per request
 *   purchase_orders(+lines)      — issued from an approved request
 *   goods_receipts(+lines)       — GRN against PO lines, stock-in applied
 *   vendor_contracts             — contract pricing enforced at PO issue
 *
 * inventory_movements gains:
 *   movement_type 'transfer'     — paired source/destination ledger rows
 *   inventory_transfer_id        — traceability to the transfer record
 *   goods_receipt_line_id        — traceability of GRN stock-in movements
 *
 * The movement ledger remains the ONLY stock truth — nothing here creates a
 * second inventory truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id'); // source facility
            $table->uuid('destination_facility_id');
            $table->uuid('inventory_item_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity');
            $table->text('reason');
            $table->uuid('dispatched_by')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });

        DB::statement('alter table inventory_transfers add constraint chk_inventory_transfers_qty check (quantity > 0)');
        DB::statement('create unique index uq_inventory_transfers_tenant_id on inventory_transfers (tenant_id, id)');
        DB::statement('create index idx_inventory_transfers_tenant_facility on inventory_transfers (tenant_id, facility_id)');
        DB::statement('create index idx_inventory_transfers_tenant_dest on inventory_transfers (tenant_id, destination_facility_id)');

        Schema::create('inventory_adjustment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inventory_item_id');
            $table->bigInteger('quantity_delta');
            $table->text('reason');
            $table->text('status'); // requested, approved, rejected
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])->on('inventory_items')->restrictOnDelete();
        });

        DB::statement("alter table inventory_adjustment_requests add constraint chk_inv_adj_req_status check (status in ('requested', 'approved', 'rejected'))");
        DB::statement('alter table inventory_adjustment_requests add constraint chk_inv_adj_req_delta check (quantity_delta <> 0)');
        DB::statement('create unique index uq_inv_adj_req_tenant_id on inventory_adjustment_requests (tenant_id, id)');
        DB::statement('create index idx_inv_adj_req_tenant_item on inventory_adjustment_requests (tenant_id, inventory_item_id)');

        Schema::create('vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->text('name');
            // Encrypted at rest (EncryptedString cast); the DATABASE.md
            // `jsonb` shape describes the pre-encryption object — encrypted
            // payloads are opaque strings, so both columns are text.
            $table->text('tax_id_encrypted')->nullable();
            $table->text('bank_details_encrypted')->nullable();
            $table->text('status'); // active, blacklisted
            $table->jsonb('rating')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
        });

        DB::statement("alter table vendors add constraint chk_vendors_status check (status in ('active', 'blacklisted'))");
        DB::statement('create unique index uq_vendors_tenant_code on vendors (tenant_id, code)');
        DB::statement('create unique index uq_vendors_tenant_id on vendors (tenant_id, id)');
        DB::statement('create index idx_vendors_tenant_facility on vendors (tenant_id, facility_id)');

        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('request_number', 50);
            $table->uuid('requested_by')->nullable();
            $table->uuid('department_id')->nullable();
            $table->text('status'); // draft, submitted, approved, rejected, ordered
            $table->timestampTz('requested_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('departments')->restrictOnDelete();
        });

        DB::statement("alter table purchase_requests add constraint chk_purchase_requests_status check (status in ('draft', 'submitted', 'approved', 'rejected', 'ordered'))");
        DB::statement('create unique index uq_purchase_requests_tenant_number on purchase_requests (tenant_id, request_number)');
        DB::statement('create unique index uq_purchase_requests_tenant_id on purchase_requests (tenant_id, id)');
        DB::statement('create index idx_purchase_requests_tenant_facility on purchase_requests (tenant_id, facility_id)');

        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('purchase_request_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity');
            $table->bigInteger('estimated_unit_price_minor');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'purchase_request_id'])
                ->references(['tenant_id', 'id'])->on('purchase_requests')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });

        DB::statement('alter table purchase_request_lines add constraint chk_purchase_request_lines_qty check (quantity > 0)');
        DB::statement('alter table purchase_request_lines add constraint chk_purchase_request_lines_price check (estimated_unit_price_minor >= 0)');
        DB::statement('create unique index uq_purchase_request_lines_item on purchase_request_lines (tenant_id, purchase_request_id, medication_id)');
        DB::statement('create index idx_purchase_request_lines_tenant_pr on purchase_request_lines (tenant_id, purchase_request_id)');

        Schema::create('purchase_request_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('purchase_request_id');
            $table->uuid('approver_id')->nullable();
            $table->text('decision'); // approved, rejected
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'purchase_request_id'])
                ->references(['tenant_id', 'id'])->on('purchase_requests')->restrictOnDelete();
        });

        DB::statement("alter table purchase_request_approvals add constraint chk_purchase_request_approvals_decision check (decision in ('approved', 'rejected'))");
        DB::statement('create unique index uq_purchase_request_approvals_pr on purchase_request_approvals (tenant_id, purchase_request_id)');
        DB::statement('create index idx_purchase_request_approvals_tenant_pr on purchase_request_approvals (tenant_id, purchase_request_id)');

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('po_number', 50);
            $table->uuid('vendor_id');
            $table->text('status'); // draft, issued, confirmed, partially_received, received, cancelled
            $table->date('expected_delivery')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])->on('facilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_id'])
                ->references(['tenant_id', 'id'])->on('vendors')->restrictOnDelete();
        });

        DB::statement("alter table purchase_orders add constraint chk_purchase_orders_status check (status in ('draft', 'issued', 'confirmed', 'partially_received', 'received', 'cancelled'))");
        DB::statement('create unique index uq_purchase_orders_tenant_number on purchase_orders (tenant_id, po_number)');
        DB::statement('create unique index uq_purchase_orders_tenant_id on purchase_orders (tenant_id, id)');
        DB::statement('create index idx_purchase_orders_tenant_vendor on purchase_orders (tenant_id, vendor_id)');

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('po_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity_ordered');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('received_quantity')->default(0);
            $table->bigInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'po_id'])
                ->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });

        DB::statement('alter table purchase_order_lines add constraint chk_purchase_order_lines_qty check (quantity_ordered > 0)');
        DB::statement('alter table purchase_order_lines add constraint chk_purchase_order_lines_price check (unit_price_minor >= 0)');
        DB::statement('alter table purchase_order_lines add constraint chk_purchase_order_lines_received check (received_quantity >= 0 and received_quantity <= quantity_ordered)');
        DB::statement('create unique index uq_purchase_order_lines_item on purchase_order_lines (tenant_id, po_id, medication_id)');
        DB::statement('create unique index uq_purchase_order_lines_tenant_id on purchase_order_lines (tenant_id, id)');
        DB::statement('create index idx_purchase_order_lines_tenant_po on purchase_order_lines (tenant_id, po_id)');

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('grn_number', 50);
            $table->uuid('po_id');
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('status'); // draft, received, matched
            $table->text('match_status')->nullable(); // matched, mismatch
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'po_id'])
                ->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        DB::statement("alter table goods_receipts add constraint chk_goods_receipts_status check (status in ('draft', 'received', 'matched'))");
        DB::statement("alter table goods_receipts add constraint chk_goods_receipts_match check (match_status in ('matched', 'mismatch'))");
        DB::statement('create unique index uq_goods_receipts_tenant_number on goods_receipts (tenant_id, grn_number)');
        DB::statement('create unique index uq_goods_receipts_tenant_id on goods_receipts (tenant_id, id)');
        DB::statement('create index idx_goods_receipts_tenant_po on goods_receipts (tenant_id, po_id)');

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('grn_id');
            $table->uuid('po_line_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity_received');
            $table->bigInteger('unit_price_received');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'grn_id'])
                ->references(['tenant_id', 'id'])->on('goods_receipts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'po_line_id'])
                ->references(['tenant_id', 'id'])->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });

        DB::statement('alter table goods_receipt_lines add constraint chk_goods_receipt_lines_qty check (quantity_received > 0)');
        DB::statement('alter table goods_receipt_lines add constraint chk_goods_receipt_lines_price check (unit_price_received >= 0)');
        DB::statement('create unique index uq_goods_receipt_lines_grn_po on goods_receipt_lines (tenant_id, grn_id, po_line_id)');
        DB::statement('create unique index uq_goods_receipt_lines_tenant_id on goods_receipt_lines (tenant_id, id)');
        DB::statement('create index idx_goods_receipt_lines_tenant_grn on goods_receipt_lines (tenant_id, grn_id)');

        Schema::create('vendor_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('vendor_id');
            $table->uuid('medication_id');
            $table->bigInteger('unit_price_minor');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->text('terms')->nullable();
            $table->text('status'); // active, expired
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'vendor_id'])
                ->references(['tenant_id', 'id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });

        DB::statement('alter table vendor_contracts add constraint chk_vendor_contracts_price check (unit_price_minor >= 0)');
        DB::statement("alter table vendor_contracts add constraint chk_vendor_contracts_status check (status in ('active', 'expired'))");
        DB::statement('create unique index uq_vendor_contracts_vendor_item on vendor_contracts (tenant_id, vendor_id, medication_id, valid_from)');
        DB::statement('create index idx_vendor_contracts_tenant_vendor on vendor_contracts (tenant_id, vendor_id)');

        // --- inventory_movements: transfer type + traceability links ---
        DB::statement('alter table inventory_movements drop constraint chk_inventory_movements_type');
        DB::statement(
            "alter table inventory_movements add constraint chk_inventory_movements_type check (movement_type in ('receipt', 'adjustment', 'dispense', 'return', 'transfer'))"
        );
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->uuid('inventory_transfer_id')->nullable();
            $table->uuid('goods_receipt_line_id')->nullable();

            $table->foreign(['tenant_id', 'inventory_transfer_id'])
                ->references(['tenant_id', 'id'])->on('inventory_transfers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'goods_receipt_line_id'])
                ->references(['tenant_id', 'id'])->on('goods_receipt_lines')->restrictOnDelete();
        });
        DB::statement('create index idx_inventory_movements_tenant_transfer on inventory_movements (tenant_id, inventory_transfer_id)');
        DB::statement('create index idx_inventory_movements_tenant_grn on inventory_movements (tenant_id, goods_receipt_line_id)');
    }

    public function down(): void
    {
        DB::statement('alter table inventory_movements drop constraint chk_inventory_movements_type');
        DB::statement(
            "alter table inventory_movements add constraint chk_inventory_movements_type check (movement_type in ('receipt', 'adjustment', 'dispense', 'return'))"
        );
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'inventory_transfer_id']);
            $table->dropForeign(['tenant_id', 'goods_receipt_line_id']);
            $table->dropColumn(['inventory_transfer_id', 'goods_receipt_line_id']);
        });

        Schema::dropIfExists('vendor_contracts');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_request_approvals');
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('inventory_adjustment_requests');
        Schema::dropIfExists('inventory_transfers');
    }
};
