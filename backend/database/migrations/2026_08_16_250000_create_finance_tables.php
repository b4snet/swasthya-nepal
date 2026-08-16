<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 18 — remaining Billing and Finance (ROADMAP Phase 13,
 * PRODUCT_REQUIREMENTS §6.13–6.14, DATABASE.md §3.33–3.35):
 *
 *   deposits              advance payments held on a patient account;
 *                         allocated against invoices later (exact, CAS).
 *   deposit_allocations   the append-only record of a deposit's money
 *                         being applied to an invoice (exact allocation,
 *                         one allocation per deposit+invoice pair).
 *   settlements           daily cashier reconciliation: expected (the
 *                         day's captured payments) vs actual, variance,
 *                         status open → reconciled | disputed.
 *   claims                insurance claims built from invoice truth
 *                         (TENANT tier — no facility_id, per §3.35).
 *   claim_lines           one line per invoice line (billed vs approved).
 *
 * All amounts are integer minor units. All financial rows are immutable
 * once posted — corrections are reversing entries, never edits. The
 * (tenant_id, id) / (tenant_id, facility_id, id) unique backers are
 * declared BEFORE the child FKs (PostgreSQL validates at declaration time).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite-FK backers that must exist before the child FKs below:
        // insurance_policies (tenant_id, id) and invoice_lines (tenant_id, id).
        DB::statement('create unique index uq_insurance_policies_tenant_id on insurance_policies (tenant_id, id)');
        DB::statement('create unique index uq_invoice_lines_tenant_id on invoice_lines (tenant_id, id)');

        Schema::create('deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->bigInteger('amount_minor');
            $table->bigInteger('remaining_minor');
            $table->text('status')->default('active'); // active, exhausted, refunded
            $table->string('idempotency_key', 100);
            $table->uuid('collected_by')->nullable();
            $table->timestampTz('collected_at');
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'collected_by'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('alter table deposits add constraint chk_deposits_status check (status in (\'active\', \'exhausted\', \'refunded\'))');
        DB::statement('alter table deposits add constraint chk_deposits_amount check (amount_minor > 0)');
        DB::statement('alter table deposits add constraint chk_deposits_remaining check (remaining_minor >= 0)');
        DB::statement('alter table deposits add constraint chk_deposits_remaining_le_amount check (remaining_minor <= amount_minor)');
        DB::statement('create unique index uq_deposits_tenant_idempotency on deposits (tenant_id, idempotency_key)');
        // Composite-FK backer for deposit_allocations.
        DB::statement('create unique index uq_deposits_tenant_id on deposits (tenant_id, id)');
        DB::statement('create index idx_deposits_tenant_patient on deposits (tenant_id, patient_id)');

        Schema::create('deposit_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('deposit_id');
            $table->uuid('invoice_id');
            $table->bigInteger('amount_minor');
            $table->uuid('allocated_by')->nullable();
            $table->timestampTz('allocated_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'deposit_id'])
                ->references(['tenant_id', 'id'])
                ->on('deposits')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoices')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'allocated_by'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('alter table deposit_allocations add constraint chk_deposit_allocations_amount check (amount_minor > 0)');
        DB::statement('create unique index uq_deposit_allocations_tenant_deposit_invoice on deposit_allocations (tenant_id, deposit_id, invoice_id)');
        DB::statement('create unique index uq_deposit_allocations_tenant_id on deposit_allocations (tenant_id, id)');
        DB::statement('create index idx_deposit_allocations_tenant_invoice on deposit_allocations (tenant_id, invoice_id)');

        Schema::create('settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('cashier_id');
            $table->date('settlement_date');
            $table->bigInteger('expected_minor')->default(0);
            $table->bigInteger('actual_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->text('status')->default('open'); // open, reconciled, disputed
            $table->uuid('reconciled_by')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'cashier_id'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'reconciled_by'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('alter table settlements add constraint chk_settlements_status check (status in (\'open\', \'reconciled\', \'disputed\'))');
        DB::statement('alter table settlements add constraint chk_settlements_expected check (expected_minor >= 0)');
        DB::statement('alter table settlements add constraint chk_settlements_actual check (actual_minor is null or actual_minor >= 0)');
        DB::statement('create unique index uq_settlements_tenant_cashier_date on settlements (tenant_id, facility_id, cashier_id, settlement_date)');
        DB::statement('create unique index uq_settlements_tenant_id on settlements (tenant_id, id)');
        DB::statement('create index idx_settlements_tenant_status on settlements (tenant_id, status)');

        Schema::create('claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('claim_number', 50);
            $table->uuid('policy_id');
            $table->uuid('invoice_id');
            $table->uuid('payer_id');
            $table->text('status')->default('draft'); // draft, submitted, pending, partial, paid, denied
            $table->timestampTz('submitted_at')->nullable();
            $table->text('denial_reason')->nullable();
            $table->bigInteger('settlement_minor')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'policy_id'])
                ->references(['tenant_id', 'id'])
                ->on('insurance_policies')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoices')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'payer_id'])
                ->references(['tenant_id', 'id'])
                ->on('payers')
                ->restrictOnDelete();
        });

        DB::statement('alter table claims add constraint chk_claims_status check (status in (\'draft\', \'submitted\', \'pending\', \'partial\', \'paid\', \'denied\'))');
        DB::statement('alter table claims add constraint chk_claims_settlement check (settlement_minor is null or settlement_minor >= 0)');
        DB::statement('create unique index uq_claims_tenant_number on claims (tenant_id, claim_number)');
        // One claim per (invoice, policy), period. Resubmission after a
        // denial REOPENS the same claim (denied → draft → submitted) so
        // the claim lines stay unique per invoice line and the denial is
        // preserved in the audit trail — no fabricated duplicate lines.
        DB::statement('create unique index uq_claims_tenant_invoice_policy on claims (tenant_id, invoice_id, policy_id)');
        DB::statement('create unique index uq_claims_tenant_id on claims (tenant_id, id)');
        DB::statement('create index idx_claims_tenant_status on claims (tenant_id, status)');

        Schema::create('claim_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('claim_id');
            $table->uuid('invoice_line_id');
            $table->bigInteger('billed_minor');
            $table->bigInteger('approved_minor')->nullable();
            $table->text('status')->default('pending'); // pending, approved, denied
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'claim_id'])
                ->references(['tenant_id', 'id'])
                ->on('claims')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'invoice_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoice_lines')
                ->restrictOnDelete();
        });

        DB::statement('alter table claim_lines add constraint chk_claim_lines_status check (status in (\'pending\', \'approved\', \'denied\'))');
        DB::statement('alter table claim_lines add constraint chk_claim_lines_billed check (billed_minor > 0)');
        DB::statement('alter table claim_lines add constraint chk_claim_lines_approved check (approved_minor is null or approved_minor >= 0)');
        DB::statement('create unique index uq_claim_lines_tenant_invoice_line on claim_lines (tenant_id, invoice_line_id)');
        DB::statement('create unique index uq_claim_lines_tenant_id on claim_lines (tenant_id, id)');
        DB::statement('create index idx_claim_lines_tenant_claim on claim_lines (tenant_id, claim_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_lines');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('deposit_allocations');
        Schema::dropIfExists('deposits');
        DB::statement('drop index if exists uq_invoice_lines_tenant_id');
        DB::statement('drop index if exists uq_insurance_policies_tenant_id');
    }
};
