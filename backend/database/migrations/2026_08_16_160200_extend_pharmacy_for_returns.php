<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 8 — extend the existing pharmacy surface for returns
 * (DATABASE.md §3.23/§3.30/§3.33) WITHOUT creating duplicate concepts:
 *
 *  1. inventory_movements.movement_type gains 'return' — the append-only
 *     ledger records the stock restoration as a positive 'return' movement
 *     (the dispense was the negative 'dispense'; the return is its mirror).
 *  2. prescription_lines.status gains 'reversed' — a returned line is
 *     explicitly reversed (dispensed → reversed, terminal for that line; a
 *     re-issue is a NEW prescription). No boolean flags: the status is the
 *     state.
 *  3. charges gains a nullable prescription_line_id — dispense charges are
 *     created per line, and the return must trace back to the exact posted
 *     charge to open the refund path. Existing rows keep NULL (the column
 *     is populated by dispensing from this slice forward).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ledger: allow the 'return' movement type.
        DB::statement(
            'alter table inventory_movements drop constraint chk_inventory_movements_type'
        );
        DB::statement(
            "alter table inventory_movements add constraint chk_inventory_movements_type check (movement_type in ('receipt', 'adjustment', 'dispense', 'return'))"
        );

        // 2. Line status: allow 'reversed'.
        DB::statement(
            'alter table prescription_lines drop constraint chk_prescription_lines_status'
        );
        DB::statement(
            "alter table prescription_lines add constraint chk_prescription_lines_status check (status in ('ordered', 'dispensed', 'cancelled', 'reversed'))"
        );

        // 3. Charge → dispensed line linkage (the return's financial trace).
        Schema::table('charges', function (Blueprint $table): void {
            $table->uuid('prescription_line_id')->nullable();

            $table->foreign(['tenant_id', 'prescription_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescription_lines')
                ->restrictOnDelete();
        });
        DB::statement('create index idx_charges_tenant_rx_line on charges (tenant_id, prescription_line_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists idx_charges_tenant_rx_line');

        Schema::table('charges', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'prescription_line_id']);
            $table->dropColumn('prescription_line_id');
        });

        DB::statement(
            'alter table prescription_lines drop constraint chk_prescription_lines_status'
        );
        DB::statement(
            "alter table prescription_lines add constraint chk_prescription_lines_status check (status in ('ordered', 'dispensed', 'cancelled'))"
        );

        DB::statement(
            'alter table inventory_movements drop constraint chk_inventory_movements_type'
        );
        DB::statement(
            "alter table inventory_movements add constraint chk_inventory_movements_type check (movement_type in ('receipt', 'adjustment', 'dispense'))"
        );
    }
};
