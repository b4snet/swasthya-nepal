<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 5 — billing refunds & adjustments (PRODUCT_REQUIREMENTS
 * §6.13, DATABASE.md §3.33): the request/approval surface that turns a
 * posted charge into an immutable reversing entry.
 *
 *  - A refund/adjustment REQUEST is created against a posted charge (a
 *    voided or non-posted charge is not refundable).
 *  - Approval is the financial gate: the approved request IS the reversing
 *    entry — the original charge is never mutated (posted rows are
 *    immutable; corrections are reversing entries, MASTER_RULES §37.3).
 *  - The refundable amount is `amount_minor − Σ(approved)` for the charge;
 *    both creation and approval re-check it, so over-refund is impossible
 *    even under concurrency (the charge row is locked in the approval
 *    transaction).
 *  - CAS (status + lock_version) makes duplicate approval/rejection atomic:
 *    a stale approver affects zero rows and gets a 409.
 *  - Money is integer minor units (DATABASE.md §0.4); reason_code is a
 *    structured code, reason_note is free text that MAY contain PHI and
 *    therefore NEVER appears in audit payloads.
 *
 * Status lifecycle: requested → approved | rejected (terminal). A designed
 * 'completed' state (actual disbursement of money back to the patient) is a
 * later-phase addition when a payment/disbursement surface exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('charge_id');
            $table->bigInteger('amount_minor');
            $table->text('reason_code'); // overcharge, duplicate_charge, service_not_rendered, patient_request, adjustment, other
            $table->text('reason_note')->nullable();
            $table->text('status')->default('requested'); // requested, approved, rejected
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
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

            $table->foreign(['tenant_id', 'charge_id'])
                ->references(['tenant_id', 'id'])
                ->on('charges')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table refund_requests add constraint chk_refund_requests_amount check (amount_minor > 0)'
        );
        DB::statement(
            "alter table refund_requests add constraint chk_refund_requests_reason check (reason_code in ('overcharge', 'duplicate_charge', 'service_not_rendered', 'patient_request', 'adjustment', 'other'))"
        );
        DB::statement(
            "alter table refund_requests add constraint chk_refund_requests_status check (status in ('requested', 'approved', 'rejected'))"
        );

        // Composite-FK support: refund_requests is referenced via
        // (tenant_id, id) by future children.
        DB::statement('create unique index uq_refund_requests_tenant_id on refund_requests (tenant_id, id)');
        DB::statement('create index idx_refund_requests_tenant_charge on refund_requests (tenant_id, charge_id)');
        DB::statement('create index idx_refund_requests_tenant_status on refund_requests (tenant_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
