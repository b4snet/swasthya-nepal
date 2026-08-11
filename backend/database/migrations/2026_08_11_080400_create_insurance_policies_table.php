<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient insurance policies (DATABASE.md §3.14): a patient's coverage
 * under a payer's product — validity, benefits, and the approval linkage
 * used at charge time.
 *
 * The payer is a tenant-safe composite FK to the payers master (§3.45).
 * Policy number is unique per payer among active policies; one active policy
 * per (patient, payer). `lock_version` guards concurrent changes. Status is
 * a lifecycle (active → expired/cancelled), never a deletion — claims will
 * reference coverage at claim time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->uuid('payer_id');
            $table->string('policy_number', 100);
            $table->text('coverage_type')->default('general');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->jsonb('benefits')->default('{}');
            $table->text('status')->default('active');
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'payer_id'])
                ->references(['tenant_id', 'id'])
                ->on('payers')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table insurance_policies add constraint chk_policies_status check (status in ('active', 'expired', 'cancelled'))"
        );

        DB::statement(
            'create unique index uq_policies_tenant_payer_number on insurance_policies (tenant_id, payer_id, policy_number) where status = \'active\''
        );
        DB::statement(
            'create unique index uq_policies_tenant_patient_payer on insurance_policies (tenant_id, patient_id, payer_id) where status = \'active\''
        );
        DB::statement('create index idx_policies_tenant_patient on insurance_policies (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
    }
};
