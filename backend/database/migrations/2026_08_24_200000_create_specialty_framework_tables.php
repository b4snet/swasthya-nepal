<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── specialty_profiles ──
        Schema::create('specialty_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('department_id');
            $table->uuid('encounter_id')->nullable();
            $table->string('primary_diagnosis', 255)->nullable();
            $table->string('diagnosis_code', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->text('clinical_summary')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamp('diagnosed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX uq_specialty_profiles_patient_dept ON specialty_profiles (tenant_id, patient_id, department_id)');

        // ── specialty_assessments ──
        Schema::create('specialty_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('specialty_profile_id');
            $table->uuid('form_template_id')->nullable();
            $table->string('assessment_type', 100);
            $table->string('status', 30)->default('draft');
            $table->json('responses')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('assessed_by_staff_id')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX idx_specialty_assessments_profile ON specialty_assessments (specialty_profile_id)');

        // ── enhance care_plans ──
        Schema::table('care_plans', function (Blueprint $table): void {
            if (!Schema::hasColumn('care_plans', 'specialty_profile_id')) {
                $table->uuid('specialty_profile_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('care_plans', 'department_id')) {
                $table->uuid('department_id')->nullable()->after('specialty_profile_id');
            }
            if (!Schema::hasColumn('care_plans', 'status')) {
                $table->string('status', 30)->default('draft')->after('department_id');
            }
            if (!Schema::hasColumn('care_plans', 'plan_name')) {
                $table->string('plan_name', 255)->nullable()->after('status');
            }
            if (!Schema::hasColumn('care_plans', 'goals')) {
                $table->json('goals')->nullable()->after('plan_name');
            }
            if (!Schema::hasColumn('care_plans', 'interventions')) {
                $table->json('interventions')->nullable()->after('goals');
            }
            if (!Schema::hasColumn('care_plans', 'milestones')) {
                $table->json('milestones')->nullable()->after('interventions');
            }
            if (!Schema::hasColumn('care_plans', 'responsible_staff_id')) {
                $table->uuid('responsible_staff_id')->nullable()->after('milestones');
            }
            if (!Schema::hasColumn('care_plans', 'start_date')) {
                $table->date('start_date')->nullable()->after('responsible_staff_id');
            }
            if (!Schema::hasColumn('care_plans', 'target_end_date')) {
                $table->date('target_end_date')->nullable()->after('start_date');
            }
            if (!Schema::hasColumn('care_plans', 'review_date')) {
                $table->date('review_date')->nullable()->after('target_end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialty_assessments');
        Schema::dropIfExists('specialty_profiles');
    }
};
