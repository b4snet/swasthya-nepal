<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6 Part 2: Vendor Quotations, Quality Inspection, Reservations
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Vendor Quotations ─────────────────────────────────────────────
        Schema::create('vendor_quotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('quotation_number');
            $table->uuid('vendor_id');
            $table->text('status');
            $table->uuid('requested_by')->nullable();
            $table->uuid('evaluated_by')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('evaluated_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('currency')->default('NPR');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'vendor_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('vendors')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'requested_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'evaluated_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'vendor_id', 'status'], 'idx_vendor_quotations_vendor_status');
        });

        DB::statement('create unique index uq_vendor_quotations_tenant_number on vendor_quotations (tenant_id, quotation_number) where deleted_at is null');
        DB::statement('create unique index uq_vendor_quotations_tenant_id on vendor_quotations (tenant_id, id)');

        Schema::create('vendor_quotation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('quotation_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->text('currency')->default('NPR');
            $table->bigInteger('estimated_delivery_days')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'quotation_id'])
                ->references(['tenant_id', 'id'])
                ->on('vendor_quotations')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'quotation_id'], 'idx_vendor_quotation_lines_quotation');
        });

        // ── Quality Inspection on Receipt ─────────────────────────────────
        Schema::create('goods_receipt_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('goods_receipt_id');
            $table->text('status');
            $table->uuid('inspected_by')->nullable();
            $table->timestampTz('inspected_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'goods_receipt_id'])
                ->references(['tenant_id', 'id'])
                ->on('goods_receipts')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'inspected_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'goods_receipt_id'], 'idx_goods_receipt_inspections_gr');
        });

        DB::statement('create unique index uq_goods_receipt_inspections_tenant_id on goods_receipt_inspections (tenant_id, id)');

        Schema::create('goods_receipt_inspection_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inspection_id');
            $table->uuid('goods_receipt_line_id');
            $table->text('check_type');
            $table->text('result');
            $table->text('expected_value')->nullable();
            $table->text('actual_value')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('inspected_by')->nullable();
            $table->timestampTz('inspected_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inspection_id'])
                ->references(['tenant_id', 'id'])
                ->on('goods_receipt_inspections')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'goods_receipt_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('goods_receipt_lines')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'inspection_id'], 'idx_goods_receipt_inspection_lines_inspection');
        });

        // ── Inventory Reservations / Allocations ──────────────────────────
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inventory_item_id');
            $table->uuid('stock_batch_id')->nullable();
            $table->bigInteger('quantity')->default(0);
            $table->text('reservation_type');
            $table->text('reference_type')->nullable();
            $table->text('reference_id')->nullable();
            $table->text('status');
            $table->uuid('reserved_by');
            $table->timestampTz('reserved_at');
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('fulfilled_by')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reserved_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'status', 'expires_at'], 'idx_inventory_reservations_active');
            $table->index(['tenant_id', 'inventory_item_id', 'status'], 'idx_inventory_reservations_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('goods_receipt_inspection_lines');
        Schema::dropIfExists('goods_receipt_inspections');
        Schema::dropIfExists('vendor_quotation_lines');
        Schema::dropIfExists('vendor_quotations');
    }
};