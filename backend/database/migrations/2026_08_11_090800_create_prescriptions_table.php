<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescriptions (DATABASE.md §3.21): the header — what was prescribed, by
 * whom, for whom. Lines live in prescription_lines.
 *
 * Tenant-scoped; the encounter is the facility anchor. Never soft-deleted:
 * discontinuation is a status — a prescription is clinical history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('prescriber_staff_id');
            $table->text('status')->default('drafted'); // drafted, active, dispensed, discontinued, expired
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescriber_staff_id'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table prescriptions add constraint chk_prescriptions_status check (status in ('drafted', 'active', 'dispensed', 'discontinued', 'expired'))"
        );

        // Composite-FK support: prescription_lines and charges reference
        // prescriptions via (tenant_id, id).
        DB::statement('create unique index uq_prescriptions_tenant_id on prescriptions (tenant_id, id)');
        DB::statement('create index idx_prescriptions_tenant_patient on prescriptions (tenant_id, patient_id, created_at)');
        DB::statement('create index idx_prescriptions_tenant_prescriber on prescriptions (tenant_id, prescriber_staff_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
