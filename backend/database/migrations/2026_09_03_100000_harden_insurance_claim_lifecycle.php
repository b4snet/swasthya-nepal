<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insurance / Coverage hardening (INSURANCE - COVERAGE §25, §20, §24, §62):
 *
 * 1. Introduce a distinct `rejected` claim status, separate from `denied`.
 *    Per §25 a claim that was NOT ACCEPTED for processing (rejected) is a
 *    different business outcome from one that WAS processed but not payable
 *    (denied). `claims.rejection_reason` already exists (2026_08_23_100000 —
 *    Nepal financial extension) but is never written; this migration adds the
 *    lifecycle STATUS so the engine can record it. `denied` keeps its existing
 *    CAS-guarded reopen; `rejected` shares the same reopen path (correct and
 *    resubmit) so no duplicate engine appears.
 *
 * 2. Capture an immutable SUBMISSION SNAPSHOT per submit attempt in a new
 *    append-only `claim_submissions` table (§20 — "what exactly was
 *    submitted?"; §62 — the historical submission is never mutated).
 *    Claim lines already freeze `billed_minor` from invoice truth at build;
 *    the snapshot additionally freezes the exact billed representation, the
 *    claim/payer/policy context, and the actor at the moment of submission.
 *    A claim that is reopened (denied|rejected → draft → submitted) appends a
 *    NEW submission row (submission_number+1): the prior submission remains
 *    immutable (§24 resubmission linkage, §62 immutable historical record).
 *
 * Every row in claim_submissions is TENANT-tier and RLS FORCED, exactly as
 * claims/claim_lines are (2026_08_16_250100, DATABASE.md §3.35): a payer
 * submission belonging to Tenant A must never be visible to Tenant B.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the `rejected` status to the claim lifecycle CHECK.
        //    (PostgreSQL cannot ALTER a CHECK; drop and recreate.)
        DB::statement('alter table claims drop constraint chk_claims_status');
        DB::statement(
            'alter table claims add constraint chk_claims_status check '
            ."(status in ('draft', 'submitted', 'pending', 'partial', 'paid', 'denied', 'rejected'))"
        );

        // 2. Immutable submission snapshots.
        Schema::create('claim_submissions', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('claim_id');
            // Per-claim ascending submission sequence — resubmission linkage:
            // submission 1 = first submit, 2 = resubmission after reopen, etc.
            $table->integer('submission_number');
            // Frozen representation of exactly what was submitted (§20): the
            // claim lines as billed from invoice truth, the billed total, and
            // the claim context at submission time.
            $table->jsonb('submitted_snapshot');
            $table->timestampTz('submitted_at');
            // The submitting USER id (same convention as claims.created_by:
            // a plain UUID, not a staff FK — the actor is a user, and staff
            // records are facility-scoped while a claim/timing is tenant-tier).
            $table->uuid('submitted_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'claim_id'])
                ->references(['tenant_id', 'id'])
                ->on('claims')
                ->restrictOnDelete();
        });

        // Append-only, one submission per attempt — a retry can never append
        // the same attempt twice.
        DB::statement(
            'create unique index uq_claim_submissions_tenant_claim_number '
            .'on claim_submissions (tenant_id, claim_id, submission_number)'
        );
        DB::statement('create unique index uq_claim_submissions_tenant_id on claim_submissions (tenant_id, id)');
        DB::statement('create index idx_claim_submissions_tenant_claim on claim_submissions (tenant_id, claim_id, submission_number)');

        // TENANT-tier RLS — same boundary as claims/claim_lines (§3.35).
        $using = 'tenant_id = public.swasthya_rls_tenant_id()';
        DB::statement("create policy p_rls_claim_submissions_select on claim_submissions for select using ({$using})");
        DB::statement('create policy p_rls_claim_submissions_insert on claim_submissions for insert with check (true)');
        DB::statement("create policy p_rls_claim_submissions_update on claim_submissions for update using ({$using}) with check ({$using})");
        DB::statement("create policy p_rls_claim_submissions_delete on claim_submissions for delete using ({$using})");
        DB::statement('alter table claim_submissions enable row level security');
        DB::statement('alter table claim_submissions force row level security');
    }

    public function down(): void
    {
        DB::statement('alter table claim_submissions no force row level security');
        DB::statement('alter table claim_submissions disable row level security');
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("drop policy if exists p_rls_claim_submissions_{$op} on claim_submissions");
        }

        Schema::dropIfExists('claim_submissions');

        DB::statement('alter table claims drop constraint chk_claims_status');
        DB::statement(
            'alter table claims add constraint chk_claims_status check '
            ."(status in ('draft', 'submitted', 'pending', 'partial', 'paid', 'denied'))"
        );
    }
};
