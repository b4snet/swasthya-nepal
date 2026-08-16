<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 8 — pharmacy returns & reversals (PRODUCT_REQUIREMENTS §6.7,
 * DATABASE.md §3.30/§3.23): a dispensed prescription line is reversed by a
 * pharmacist — reason captured, stock restored to the shelf, and the money
 * path opened through the existing billing reversal mechanism.
 *
 *  - The LINE is the unit of dispensing (DATABASE.md §3.30), so it is the
 *    unit of return. A return is a FULL reversal of the dispensed line:
 *    quantity_minor is the dispensed quantity (never partial — the charge is
 *    one price × quantity per line, so a partial return cannot be expressed
 *    without splitting the charge).
 *  - The row IS the immutable reversal record (like an approved refund
 *    request): the line status flips dispensed → reversed, stock is restored
 *    via the append-only ledger ('return' movement), and the linked posted
 *    charge stays immutable — the refund path opens through a refund_requests
 *    row (requested → approved by billing), never by mutating the charge.
 *  - One return per line (unique tenant_id + prescription_line_id): a line
 *    can never be returned twice — double restoration is impossible at the
 *    database level.
 *  - reason_code is a structured code; reason_note is free text that MAY
 *    contain PHI and therefore NEVER appears in audit payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('prescription_line_id');
            $table->uuid('prescription_id');
            $table->uuid('charge_id');
            $table->bigInteger('quantity_minor');
            $table->text('reason_code'); // patient_return, wrong_medication, adverse_reaction, dispensing_error, duplicate_dispense, other
            $table->text('reason_note')->nullable();
            $table->uuid('returned_by')->nullable();
            $table->timestampTz('returned_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescription_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescription_lines')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescription_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescriptions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'charge_id'])
                ->references(['tenant_id', 'id'])
                ->on('charges')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table pharmacy_returns add constraint chk_pharmacy_returns_quantity check (quantity_minor > 0)'
        );
        DB::statement(
            "alter table pharmacy_returns add constraint chk_pharmacy_returns_reason check (reason_code in ('patient_return', 'wrong_medication', 'adverse_reaction', 'dispensing_error', 'duplicate_dispense', 'other'))"
        );

        // One reversal per line — a line can never be returned twice.
        DB::statement(
            'create unique index uq_pharmacy_returns_tenant_line on pharmacy_returns (tenant_id, prescription_line_id)'
        );
        // Composite-FK support for future children referencing via (tenant_id, id).
        DB::statement('create unique index uq_pharmacy_returns_tenant_id on pharmacy_returns (tenant_id, id)');
        DB::statement('create index idx_pharmacy_returns_tenant_charge on pharmacy_returns (tenant_id, charge_id)');
        DB::statement('create index idx_pharmacy_returns_tenant_rx on pharmacy_returns (tenant_id, prescription_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_returns');
    }
};
