<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — Oncology Information System.
 *
 * Tables:
 *   - oncology_profiles: patient oncology summary (primary diagnosis, staging)
 *   - oncology_diagnoses: cancer diagnoses with TNM staging
 *   - treatment_plans: chemotherapy/radiation treatment plans
 *   - treatment_cycles: individual cycles within a plan
 *   - treatment_medications: medications per cycle
 *   - toxicity_records: adverse event tracking per cycle
 *   - oncology_encounters: oncology-specific encounter documentation
 *   - multidisciplinary_reviews: MDT review meetings
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── oncology_profiles ──
        Schema::create('oncology_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('primary_diagnosis')->nullable();
            $table->text('cancer_site')->nullable(); // e.g. 'breast', 'lung', 'colorectal'
            $table->text('histology')->nullable();
            $table->text('grade')->nullable();
            $table->text('tnm_staging')->nullable(); // e.g. 'T2N1M0'
            $table->text('overall_stage')->nullable(); // e.g. 'IIIA'
            $table->text('performance_status')->nullable(); // e.g. 'ECOG 1'
            $table->text('status')->default('active'); // active, in_remission, deceased
            $table->timestampTz('diagnosed_at')->nullable();
            $table->uuid('treating_physician_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
        });

        DB::statement('create unique index uq_oncology_profiles_patient on oncology_profiles (tenant_id, patient_id)');

        // ── oncology_diagnoses ──
        Schema::create('oncology_diagnoses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('oncology_profile_id');
            $table->text('diagnosis_code')->nullable(); // ICD-10
            $table->text('description');
            $table->text('cancer_site');
            $table->text('histology')->nullable();
            $table->text('grade')->nullable();
            $table->text('tnm_t')->nullable();
            $table->text('tnm_n')->nullable();
            $table->text('tnm_m')->nullable();
            $table->text('overall_stage');
            $table->text('diagnosis_type')->default('primary'); // primary, recurrent, metastatic
            $table->timestampTz('diagnosed_at')->nullable();
            $table->uuid('diagnosed_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('oncology_profile_id')->references('id')->on('oncology_profiles')->restrictOnDelete();
        });

        DB::statement('create index idx_oncology_diagnoses_profile on oncology_diagnoses (oncology_profile_id)');

        // ── treatment_plans ──
        Schema::create('treatment_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('oncology_profile_id');
            $table->uuid('encounter_id')->nullable();
            $table->text('plan_type'); // chemotherapy, radiotherapy, combined, surgery, palliative
            $table->text('protocol_code')->nullable(); // e.g. 'FOLFOX', 'AC-T'
            $table->text('protocol_name')->nullable();
            $table->text('intent')->default('curative'); // curative, adjuvant, neoadjuvant, palliative
            $table->text('status')->default('draft'); // draft, active, completed, discontinued, suspended
            $table->text('line_of_therapy')->default('first'); // first, second, third, subsequent
            $table->integer('planned_cycles')->nullable();
            $table->integer('completed_cycles')->default(0);
            $table->text('discontinuation_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->uuid('approved_by_staff_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('oncology_profile_id')->references('id')->on('oncology_profiles')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
        });

        DB::statement('create index idx_treatment_plans_profile on treatment_plans (oncology_profile_id)');
        DB::statement('create index idx_treatment_plans_status on treatment_plans (tenant_id, status)');

        // ── treatment_cycles ──
        Schema::create('treatment_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('treatment_plan_id');
            $table->integer('cycle_number');
            $table->text('status')->default('scheduled'); // scheduled, in_progress, completed, delayed, skipped
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('delay_reason')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->restrictOnDelete();
        });

        DB::statement('create unique index uq_treatment_cycles_plan_number on treatment_cycles (treatment_plan_id, cycle_number)');

        // ── treatment_medications ──
        Schema::create('treatment_medications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('treatment_plan_id');
            $table->uuid('medication_id')->nullable();
            $table->text('medication_name');
            $table->decimal('dose', 12, 4);
            $table->text('dose_unit');
            $table->text('route'); // IV, oral, subcutaneous, intramuscular
            $table->text('frequency');
            $table->integer('days_per_cycle')->nullable();
            $table->text('cycle_schedule')->nullable(); // e.g. 'day 1, day 8'
            $table->boolean('premedication')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->restrictOnDelete();
            $table->foreign('medication_id')->references('id')->on('medications')->nullOnDelete();
        });

        DB::statement('create index idx_treatment_medications_plan on treatment_medications (treatment_plan_id)');

        // ── toxicity_records ──
        Schema::create('toxicity_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('treatment_cycle_id');
            $table->uuid('patient_id');
            $table->text('toxicity_type'); // e.g. 'neutropenia', 'nausea', 'neuropathy'
            $table->text('ctcae_grade'); // Grade 1-5 per CTCAE
            $table->text('description')->nullable();
            $table->text('management_action')->nullable();
            $table->text('outcome')->nullable(); // resolved, resolving, ongoing, fatal
            $table->boolean('dose_modified')->default(false);
            $table->text('dose_modification')->nullable();
            $table->timestampTz('onset_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('reported_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('treatment_cycle_id')->references('id')->on('treatment_cycles')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
        });

        DB::statement('create index idx_toxicity_records_cycle on toxicity_records (treatment_cycle_id)');
        DB::statement('create index idx_toxicity_records_patient on toxicity_records (patient_id, ctcae_grade)');

        // ── oncology_encounters ──
        Schema::create('oncology_encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('encounter_id');
            $table->uuid('oncology_profile_id');
            $table->text('encounter_type'); // consultation, treatment, follow_up, emergency, mdt_review
            $table->text('performance_status')->nullable();
            $table->text('clinical_summary')->nullable();
            $table->text('treatment_response')->nullable(); // complete_response, partial_response, stable, progressive
            $table->text('plan_notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('oncology_profile_id')->references('id')->on('oncology_profiles')->restrictOnDelete();
        });

        DB::statement('create unique index uq_oncology_encounters_encounter on oncology_encounters (encounter_id)');

        // ── multidisciplinary_reviews ──
        Schema::create('multidisciplinary_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('oncology_profile_id');
            $table->timestampTz('review_date');
            $table->text('decision')->nullable();
            $table->text('recommendations')->nullable();
            $table->jsonb('attendees')->default('[]'); // staff IDs
            $table->uuid('reviewed_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('oncology_profile_id')->references('id')->on('oncology_profiles')->restrictOnDelete();
        });

        DB::statement('create index idx_mdt_reviews_profile on multidisciplinary_reviews (oncology_profile_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('multidisciplinary_reviews');
        Schema::dropIfExists('oncology_encounters');
        Schema::dropIfExists('toxicity_records');
        Schema::dropIfExists('treatment_medications');
        Schema::dropIfExists('treatment_cycles');
        Schema::dropIfExists('treatment_plans');
        Schema::dropIfExists('oncology_diagnoses');
        Schema::dropIfExists('oncology_profiles');
    }
};
