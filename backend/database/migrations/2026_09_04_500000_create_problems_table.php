<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient-level problem list — longitudinal conditions that persist
 * across encounters. Separate from encounter-scoped diagnoses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->string('code', 50)->nullable();
            $table->string('coding_system', 50)->nullable();
            $table->string('description', 255);
            $table->text('clinical_description')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('onset_date')->nullable();
            $table->date('resolved_date')->nullable();
            $table->text('resolved_reason')->nullable();
            $table->uuid('encounter_id')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->index(['patient_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['encounter_id']);
        });

        DB::statement('ALTER TABLE problems ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE problems FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY sel_problems ON problems
                FOR SELECT USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                    AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)
                )
        ');
        DB::statement('
            CREATE POLICY ins_problems ON problems
                FOR INSERT WITH CHECK (tenant_id = public.swasthya_rls_tenant_id())
        ');
        DB::statement('
            CREATE POLICY upd_problems ON problems
                FOR UPDATE USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};
