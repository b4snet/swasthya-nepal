<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — pharmacy PARTIAL-quantity returns (PRODUCT_REQUIREMENTS §6.7,
 * DATABASE.md §3.30/§3.33): a dispensed line can be returned in whole OR in
 * part, over multiple return events, until the full dispensed quantity has
 * been restored.
 *
 * Slice 8 deliberately implemented full-line reversal only: "the charge is
 * one price × quantity per line, so a partial return cannot be expressed
 * without splitting the charge". This migration removes that constraint by
 * tracking the already-returned quantity on the line itself:
 *
 *   - prescription_lines.returned_quantity_minor (default 0, CHECK ≤ the
 *     dispensed quantity_minor): the line's own remaining-returnable
 *     accounting. A line is fully returned when returned == dispensed.
 *   - The line status stays 'dispensed' while partially returned and flips
 *     to 'reversed' only when the FULL dispensed quantity has been returned
 *     (a partial return is not a full reversal of the line's history).
 *   - The one-return-per-line unique index (uq_pharmacy_returns_tenant_line)
 *     is dropped — a line may now have MANY return events, each restoring
 *     part of the dispensed quantity. Over-return is backstopped by the
 *     CHECK constraint on returned_quantity_minor AND the row-lock + CAS in
 *     the service (the line row is locked FOR UPDATE, so concurrent returns
 *     serialize; the CAS on returned_quantity_minor makes a stale actor
 *     affect zero rows).
 *   - The linked posted charge is STILL never mutated: each partial return
 *     opens its OWN refund request for exactly `unit price × returned
 *     quantity` (unit price = amount_minor / dispensed quantity — exact
 *     integer minor units, since the charge is price × quantity). The
 *     existing refund layer's refundable check (amount − Σ approved) and the
 *     approval-time charge-row lock already prevent over-refund across
 *     multiple partial requests.
 *
 * Financial correctness is preserved end to end: Σ(returned) ≤ dispensed
 * (line CHECK), Σ(refund requests) ≤ charge amount (refund layer), stock
 * restoration exactly equals each returned quantity (ledger mirror), and
 * the original dispensed history stays immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Track how much of the dispensed quantity has been returned.
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->bigInteger('returned_quantity_minor')->default(0)->after('quantity_minor');
        });
        DB::statement(
            'alter table prescription_lines add constraint chk_prescription_lines_returned check (returned_quantity_minor >= 0 and returned_quantity_minor <= quantity_minor)'
        );

        // 2. A line may now be returned multiple times (partial quantities).
        DB::statement('drop index if exists uq_pharmacy_returns_tenant_line');
        DB::statement('create index idx_pharmacy_returns_tenant_line on pharmacy_returns (tenant_id, prescription_line_id)');

        // 3. Backfill: every existing fully-reversed line is fully returned
        //    (slice 8 semantics — one whole-line return per reversed line).
        DB::statement(
            "update prescription_lines set returned_quantity_minor = quantity_minor where status = 'reversed' and returned_quantity_minor = 0"
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists idx_pharmacy_returns_tenant_line');
        DB::statement(
            'create unique index uq_pharmacy_returns_tenant_line on pharmacy_returns (tenant_id, prescription_line_id)'
        );

        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->dropColumn('returned_quantity_minor');
        });
    }
};
