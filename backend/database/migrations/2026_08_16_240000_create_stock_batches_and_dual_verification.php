<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 17 — Pharmacy batch/expiry + controlled-substance dual
 * verification (ROADMAP Phase 12, PRODUCT_REQUIREMENTS §6.7, DATABASE.md
 * §3.30/§3.31). One TENANT_FACILITY table plus additive columns on the
 * existing dispensing surface:
 *
 *   stock_batches     one row per received lot: batch_number, expiry_date,
 *                     quantity_received / quantity_remaining, status
 *                     (available / depleted / quarantined), and the
 *                     controlled-substance policy flag. A batch is the
 *                     unit of expiry-safe dispensing — an EXPIRED batch is
 *                     never selectable (the CAS expiry guard refuses it).
 *
 * Additive columns on prescription_lines:
 *   batch_id / batch_number / batch_expires_at / batch_quantity_minor —
 *   the exact batch a dispensed line came from (return restores to the
 *   SAME batch; financial linkage is preserved via the existing charge).
 *   dual_verified_by_staff_id / dual_verified_at — the SECOND pharmacist's
 *   stamp for controlled-substance dispensing (Phase 2 policy-driven dual
 *   verification; the dispenser and the second verifier must differ).
 *
 * All TENANT_FACILITY tier: RLS enabled + FORCED by the companion migration
 * (2026_08_16_240100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inventory_item_id');
            $table->uuid('medication_id');
            $table->string('batch_number', 100);
            $table->date('expiry_date');
            $table->bigInteger('quantity_received')->default(0);
            $table->bigInteger('quantity_remaining')->default(0);
            $table->text('status')->default('available'); // available, depleted, quarantined
            $table->boolean('controlled_dispense_requires_dual')->default(false);
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table stock_batches add constraint chk_stock_batches_status check (status in ('available', 'depleted', 'quarantined'))"
        );
        DB::statement(
            'alter table stock_batches add constraint chk_stock_batches_received check (quantity_received >= 0)'
        );
        DB::statement(
            'alter table stock_batches add constraint chk_stock_batches_remaining check (quantity_remaining >= 0)'
        );

        // One batch number per medication+facility; the (tenant_id, id)
        // unique backer for the prescription_lines.batch_id FK must exist
        // BEFORE the child table's FK is declared.
        DB::statement(
            'create unique index uq_stock_batches_tenant_item_batch on stock_batches (tenant_id, inventory_item_id, batch_number)'
        );
        DB::statement('create unique index uq_stock_batches_tenant_id on stock_batches (tenant_id, id)');
        DB::statement('create index idx_stock_batches_tenant_item on stock_batches (tenant_id, inventory_item_id)');
        DB::statement('create index idx_stock_batches_tenant_expiry on stock_batches (tenant_id, facility_id, expiry_date)');

        // The dispensing surface records the exact batch and the second
        // pharmacist's dual-verification stamp (controlled substances).
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('batch_expires_at')->nullable();
            $table->bigInteger('batch_quantity_minor')->nullable();
            $table->uuid('dual_verified_by_staff_id')->nullable();
            $table->timestampTz('dual_verified_at')->nullable();

            $table->foreign(['tenant_id', 'batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            // prescription_lines has no facility_id (it hangs off the
            // prescription); the dual-verifier FK is tenant-scoped only.
            $table->foreign(['tenant_id', 'dual_verified_by_staff_id'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table prescription_lines add constraint chk_prescription_lines_batch_qty check (batch_quantity_minor is null or batch_quantity_minor > 0)'
        );
        DB::statement(
            'alter table prescription_lines add constraint chk_prescription_lines_dual_verified check (dual_verified_at is null or dual_verified_by_staff_id is not null)'
        );

        // The stock ledger records which batch every movement touched
        // (dispense/return traceability at the batch level).
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->uuid('stock_batch_id')->nullable();

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();
        });
        DB::statement('create index idx_inventory_movements_tenant_batch on inventory_movements (tenant_id, stock_batch_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_stock_batches_tenant_id');
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'stock_batch_id']);
            $table->dropColumn('stock_batch_id');
        });
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'batch_id']);
            $table->dropForeign(['tenant_id', 'dual_verified_by_staff_id']);
            $table->dropColumn([
                'batch_id',
                'batch_number',
                'batch_expires_at',
                'batch_quantity_minor',
                'dual_verified_by_staff_id',
                'dual_verified_at',
            ]);
        });
        Schema::dropIfExists('stock_batches');
    }
};
