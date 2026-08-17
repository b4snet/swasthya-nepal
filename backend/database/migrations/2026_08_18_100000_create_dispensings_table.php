<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — STANDALONE dispensing records (PRODUCT_REQUIREMENTS §6.7
 * `dispensing` entity + stock-out mode; DATABASE.md §3.30/§3.33; the
 * documented remaining-scope item "standalone `dispensings` table").
 *
 * Prescription-linked dispensing stays on the existing line-stamp model
 * (ordered → dispensed with batch stamps) — that path is untouched. This
 * migration adds the documented standalone surface: a pharmacist dispenses
 * a medication DIRECTLY to a patient (walk-in/OTC-style) with no
 * prescription, using the SAME stock truth (batch CAS + shelf CAS + ledger)
 * and the SAME financial truth (posted charge):
 *
 *   dispensings                one row per standalone dispense: patient,
 *                              medication, exact batch (id + snapshot),
 *                              quantity, dispenser stamp. status
 *                              dispensed → reversed (reversal is a later
 *                              surface; the row is the immutable record).
 *
 *   charges.dispensing_id      typed FK — a standalone dispense posts a
 *                              charge with source_type = 'dispensing' (the
 *                              documented source; the CHECK is extended).
 *
 *   inventory_movements.dispensing_id — the dispense ledger movement
 *                              references the standalone record (the
 *                              prescription_line_id slot stays NULL).
 *
 * No second stock truth: deducting from the batch and shelf uses the SAME
 * CAS mechanisms as prescription dispensing, and the ledger remains the
 * single append-only movement record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('medication_id');
            $table->uuid('inventory_item_id');
            $table->uuid('stock_batch_id');
            $table->string('batch_number', 100);
            $table->date('batch_expires_at');
            $table->bigInteger('quantity_minor');
            $table->text('status')->default('dispensed'); // dispensed, reversed
            $table->uuid('dispensed_by_staff_id');
            $table->timestampTz('dispensed_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'dispensed_by_staff_id'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table dispensings add constraint chk_dispensings_status check (status in ('dispensed', 'reversed'))"
        );
        DB::statement(
            'alter table dispensings add constraint chk_dispensings_quantity check (quantity_minor > 0)'
        );

        // Composite-FK backers + query indexes.
        DB::statement('create unique index uq_dispensings_tenant_id on dispensings (tenant_id, id)');
        DB::statement('create index idx_dispensings_tenant_patient on dispensings (tenant_id, patient_id, dispensed_at)');
        DB::statement('create index idx_dispensings_tenant_batch on dispensings (tenant_id, stock_batch_id)');

        // The financial linkage: a standalone dispense posts a charge with
        // source_type = 'dispensing' (the documented source in §3.33), and
        // the ledger movement references the standalone record.
        Schema::table('charges', function (Blueprint $table): void {
            $table->uuid('dispensing_id')->nullable();
        });
        DB::statement(
            'alter table charges add constraint chk_charges_source_new check '
            ."(source_type in ('encounter', 'prescription', 'manual', 'dispensing'))"
        );
        DB::statement('alter table charges drop constraint chk_charges_source');
        DB::statement('alter table charges rename constraint chk_charges_source_new to chk_charges_source');

        DB::statement(
            'alter table charges add constraint fk_charges_tenant_dispensing '
            .'foreign key (tenant_id, dispensing_id) references dispensings (tenant_id, id)'
        );
        DB::statement('create index idx_charges_tenant_dispensing on charges (tenant_id, dispensing_id)');

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->uuid('dispensing_id')->nullable();
        });
        DB::statement(
            'alter table inventory_movements add constraint fk_movements_tenant_dispensing '
            .'foreign key (tenant_id, dispensing_id) references dispensings (tenant_id, id)'
        );
        DB::statement('create index idx_movements_tenant_dispensing on inventory_movements (tenant_id, dispensing_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists idx_movements_tenant_dispensing');
        DB::statement('alter table inventory_movements drop constraint if exists fk_movements_tenant_dispensing');
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn('dispensing_id');
        });

        DB::statement('drop index if exists idx_charges_tenant_dispensing');
        DB::statement('alter table charges drop constraint if exists fk_charges_tenant_dispensing');
        Schema::table('charges', function (Blueprint $table): void {
            $table->dropColumn('dispensing_id');
        });
        DB::statement('alter table charges drop constraint if exists chk_charges_source');
        DB::statement(
            'alter table charges add constraint chk_charges_source check '
           ."(source_type in ('encounter', 'prescription', 'manual'))"
        );

        Schema::dropIfExists('dispensings');
    }
};
