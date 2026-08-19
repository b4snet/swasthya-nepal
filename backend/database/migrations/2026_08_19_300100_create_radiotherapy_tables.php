<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — Radiotherapy Treatment Planning.
 *
 * Tables:
 *   - rt_treatment_courses: overall treatment course
 *   - rt_treatment_plans: individual RT plans (VMAT, IMRT, 3D-CRT, etc.)
 *   - rt_fractions: individual fractions within a plan
 *   - rt_fraction_sessions: actual treatment sessions
 *   - rt_treatment_machines: linear accelerators and equipment
 *   - rt_plan_approvals: approval workflow for plans
 *   - rt_structures: structure sets (organs at risk, targets)
 *   - rt_dose_constraints: dose-volume constraints per structure
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── rt_treatment_machines ──
        Schema::create('rt_treatment_machines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('code')->unique();
            $table->text('name');
            $table->text('machine_type'); // linac, cobalt, proton
            $table->text('manufacturer')->nullable();
            $table->text('model')->nullable();
            $table->text('energy_range')->nullable(); // e.g. '6-18 MV'
            $table->text('status')->default('active'); // active, maintenance, decommissioned
            $table->integer('daily_capacity')->default(30);
            $table->jsonb('capabilities')->default('{}'); // VMAT, IMRT, SRS, SBRT, electrons
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
        });

        // ── rt_treatment_courses ──
        Schema::create('rt_treatment_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('oncology_profile_id');
            $table->uuid('treatment_plan_id')->nullable(); // link to chemotherapy plan if combined
            $table->text('intent')->default('curative'); // curative, adjuvant, neoadjuvant, palliative, re_irradiation
            $table->text('status')->default('planned'); // planned, in_progress, completed, discontinued
            $table->integer('total_fractions')->default(0);
            $table->integer('completed_fractions')->default(0);
            $table->decimal('total_dose_cgy', 12, 2)->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('discontinuation_reason')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('oncology_profile_id')->references('id')->on('oncology_profiles')->restrictOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->nullOnDelete();
        });

        DB::statement('create index idx_rt_courses_profile on rt_treatment_courses (oncology_profile_id)');

        // ── rt_treatment_plans ──
        Schema::create('rt_treatment_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_course_id');
            $table->text('plan_name');
            $table->text('technique'); // VMAT, IMRT, 3DCRT, electron, proton, SBRT, SRS
            $table->text('energy')->nullable(); // e.g. '6 MV', '10 MV'
            $table->integer('fraction_dose_cgy');
            $table->integer('num_fractions');
            $table->decimal('total_dose_cgy', 12, 2);
            $table->text('status')->default('draft'); // draft, in_review, approved, in_treatment, completed
            $table->uuid('planned_by_staff_id')->nullable();
            $table->uuid('approved_by_physicist_id')->nullable();
            $table->timestampTz('physicist_approved_at')->nullable();
            $table->uuid('approved_by_ro_id')->nullable(); // radiation oncologist
            $table->timestampTz('ro_approved_at')->nullable();
            $table->text('plan_note')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_course_id')->references('id')->on('rt_treatment_courses')->restrictOnDelete();
        });

        DB::statement('create index idx_rt_plans_course on rt_treatment_plans (rt_course_id)');

        // ── rt_fractions ──
        Schema::create('rt_fractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_plan_id');
            $table->integer('fraction_number');
            $table->decimal('dose_cgy', 12, 2);
            $table->text('status')->default('planned'); // planned, delivered, missed, cancelled
            $table->timestampTz('scheduled_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_plan_id')->references('id')->on('rt_treatment_plans')->restrictOnDelete();
        });

        DB::statement('create unique index uq_rt_fractions_plan_number on rt_fractions (rt_plan_id, fraction_number)');

        // ── rt_fraction_sessions ──
        Schema::create('rt_fraction_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_fraction_id');
            $table->uuid('machine_id');
            $table->text('status')->default('scheduled'); // scheduled, in_progress, completed, interrupted
            $table->timestampTz('session_start')->nullable();
            $table->timestampTz('session_end')->nullable();
            $table->decimal('delivered_dose_cgy', 12, 2)->nullable();
            $table->text('interrupt_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('delivered_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_fraction_id')->references('id')->on('rt_fractions')->restrictOnDelete();
            $table->foreign('machine_id')->references('id')->on('rt_treatment_machines')->restrictOnDelete();
        });

        DB::statement('create index idx_rt_sessions_fraction on rt_fraction_sessions (rt_fraction_id)');

        // ── rt_structures ──
        Schema::create('rt_structures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_plan_id');
            $table->text('structure_name');
            $table->text('structure_type'); // PTV, CTV, GTV, OAR, field
            $table->decimal('volume_cc', 12, 4)->nullable();
            $table->decimal('mean_dose_cgy', 12, 2)->nullable();
            $table->decimal('max_dose_cgy', 12, 2)->nullable();
            $table->jsonb('dvh_data')->default('{}'); // dose-volume histogram points
            $table->jsonb('contour_data')->default('{}'); // DICOM RT structure set references
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_plan_id')->references('id')->on('rt_treatment_plans')->restrictOnDelete();
        });

        DB::statement('create index idx_rt_structures_plan on rt_structures (rt_plan_id)');

        // ── rt_dose_constraints ──
        Schema::create('rt_dose_constraints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_structure_id');
            $table->text('constraint_type'); // max_dose, mean_dose, v_volume_pct, d_dose_cc
            $table->decimal('constraint_value', 12, 2);
            $table->text('constraint_unit'); // cgy, Gy, %, cc
            $table->decimal('achieved_value', 12, 2)->nullable();
            $table->boolean('met')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_structure_id')->references('id')->on('rt_structures')->restrictOnDelete();
        });

        DB::statement('create index idx_rt_constraints_structure on rt_dose_constraints (rt_structure_id)');

        // ── rt_plan_approvals ──
        Schema::create('rt_plan_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('rt_plan_id');
            $table->text('approval_type'); // physicist_check, ro_approval, secondary_check
            $table->text('status'); // pending, approved, rejected
            $table->text('decision')->nullable();
            $table->text('comments')->nullable();
            $table->uuid('approved_by_staff_id');
            $table->timestampTz('approved_at')->nullable();
            $table->jsonb('checklist')->default('{}'); // verification checklist items
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('rt_plan_id')->references('id')->on('rt_treatment_plans')->restrictOnDelete();
        });

        DB::statement('create index idx_rt_approvals_plan on rt_plan_approvals (rt_plan_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rt_plan_approvals');
        Schema::dropIfExists('rt_dose_constraints');
        Schema::dropIfExists('rt_structures');
        Schema::dropIfExists('rt_fraction_sessions');
        Schema::dropIfExists('rt_fractions');
        Schema::dropIfExists('rt_treatment_plans');
        Schema::dropIfExists('rt_treatment_courses');
        Schema::dropIfExists('rt_treatment_machines');
    }
};
