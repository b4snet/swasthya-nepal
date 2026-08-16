<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 11 — refund completed/disbursement state (PRODUCT_REQUIREMENTS
 * §6.13, DATABASE.md §3.33): an APPROVED refund request transitions to
 * 'completed' when the money is actually disbursed back to the patient.
 *
 * The 2026_08_15_160000 migration documented this exact addition:
 *   "A designed 'completed' state (actual disbursement of money back to the
 *    patient) is a later-phase addition when a payment/disbursement surface
 *    exists."
 *
 * No payment provider exists or is invented here: completion is recorded by
 * the finance officer who hands the money over (completed_by / completed_at),
 * the same CAS-guarded pattern as approval. The approved request remains the
 * immutable reversing entry — the charge is never mutated, and completion
 * only marks the disbursement, never the financial reversal itself.
 *
 * Lifecycle: requested → approved | rejected (terminal) → completed (terminal).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table refund_requests drop constraint chk_refund_requests_status');
        DB::statement(
            "alter table refund_requests add constraint chk_refund_requests_status check (status in ('requested', 'approved', 'rejected', 'completed'))"
        );

        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->uuid('completed_by')->nullable()->after('rejected_at');
            $table->timestampTz('completed_at')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->dropColumn(['completed_by', 'completed_at']);
        });

        DB::statement('alter table refund_requests drop constraint chk_refund_requests_status');
        DB::statement(
            "alter table refund_requests add constraint chk_refund_requests_status check (status in ('requested', 'approved', 'rejected'))"
        );
    }
};
