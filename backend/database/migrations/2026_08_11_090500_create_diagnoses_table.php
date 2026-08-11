<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnoses (DATABASE.md §3.18): coded where available (ICD readiness),
 * typed (provisional, differential, final). A diagnosis is a clinical fact
 * — never soft-deleted, status is the lifecycle.
 *
 * Tenant-scoped; the encounter is the facility anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('encounter_id');
            $table->string('code', 20)->nullable();
            $table->text('coding_system')->nullable(); // icd10, snomed, custom
            $table->text('description');
            $table->text('diagnosis_type')->default('provisional'); // provisional, differential, final
            $table->boolean('is_primary')->default(false);
            $table->date('onset_date')->nullable();
            $table->text('status')->default('active'); // active, resolved, ruled_out
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table diagnoses add constraint chk_diagnoses_system check (coding_system is null or coding_system in ('icd10', 'snomed', 'custom'))"
        );
        DB::statement(
            "alter table diagnoses add constraint chk_diagnoses_type check (diagnosis_type in ('provisional', 'differential', 'final'))"
        );
        DB::statement(
            "alter table diagnoses add constraint chk_diagnoses_status check (status in ('active', 'resolved', 'ruled_out'))"
        );

        DB::statement('create index idx_diagnoses_tenant_encounter on diagnoses (tenant_id, encounter_id)');
        DB::statement('create index idx_diagnoses_tenant_code on diagnoses (tenant_id, code)');
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
