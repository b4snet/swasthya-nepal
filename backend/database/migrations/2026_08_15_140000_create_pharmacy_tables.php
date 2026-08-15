<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 3 — pharmacy dispensing & inventory (DATABASE.md §3.23,
 * PRODUCT_REQUIREMENTS §6.9).
 *
 * inventory_items: one stock row per (tenant, facility, medication). The
 * formulary is facility-owned, so stock is facility-owned too — a facility
 * dispenses from its own shelf. Quantities are integer units, never floats.
 *
 * inventory_movements: the append-only stock ledger. Every change to on-hand
 * quantity (receipt, adjustment, dispense) is a ledger row — stock is never
 * silently overwritten, mirroring the charges philosophy (reversing entries,
 * never UPDATEs; DATABASE.md §3.33). A dispense movement links to the
 * dispensed prescription line.
 *
 * prescription_lines gains the dispensing/verification actor stamps
 * (dispensed_by_staff_id, dispensed_at) — the line is the unit of
 * dispensing — while the prescription header carries the pharmacist
 * verification stamps (verified_by_staff_id, verified_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('medication_id');
            $table->bigInteger('quantity_on_hand')->default(0);
            $table->bigInteger('reorder_level')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table inventory_items add constraint chk_inventory_items_quantity check (quantity_on_hand >= 0)'
        );
        DB::statement(
            'alter table inventory_items add constraint chk_inventory_items_reorder check (reorder_level is null or reorder_level >= 0)'
        );

        // One stock row per medication per facility.
        DB::statement(
            'create unique index uq_inventory_items_tenant_facility_med on inventory_items (tenant_id, facility_id, medication_id)'
        );
        // Composite-FK support: inventory_movements reference inventory_items
        // via (tenant_id, id).
        DB::statement('create unique index uq_inventory_items_tenant_id on inventory_items (tenant_id, id)');
        DB::statement('create index idx_inventory_items_tenant_facility on inventory_items (tenant_id, facility_id)');
        DB::statement('create index idx_inventory_items_tenant_med on inventory_items (tenant_id, medication_id)');

        // Composite-FK support for the movements → prescription_lines link
        // (lines currently only carry (tenant_id, prescription_id, line_no)).
        // Must exist BEFORE the movements table declares its FK.
        DB::statement('create unique index uq_prescription_lines_tenant_id on prescription_lines (tenant_id, id)');

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inventory_item_id');
            $table->text('movement_type'); // receipt, adjustment, dispense
            $table->bigInteger('quantity_delta');
            $table->text('reason')->nullable();
            $table->uuid('prescription_line_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescription_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescription_lines')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table inventory_movements add constraint chk_inventory_movements_type check (movement_type in ('receipt', 'adjustment', 'dispense'))"
        );
        DB::statement(
            'alter table inventory_movements add constraint chk_inventory_movements_delta check (quantity_delta <> 0)'
        );

        DB::statement('create unique index uq_inventory_movements_tenant_id on inventory_movements (tenant_id, id)');
        DB::statement('create index idx_inventory_movements_tenant_item on inventory_movements (tenant_id, inventory_item_id, occurred_at)');

        // Dispensing/verification actor stamps on the lines and header.
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->uuid('dispensed_by_staff_id')->nullable();
            $table->timestampTz('dispensed_at')->nullable();
        });
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropColumn(['verified_by_staff_id', 'verified_at']);
        });
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->dropColumn(['dispensed_by_staff_id', 'dispensed_at']);
        });

        DB::statement('drop index if exists uq_prescription_lines_tenant_id');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
