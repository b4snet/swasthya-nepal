<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — Governed assistive AI (ROADMAP Phase 21, AI_RULES.md §1–§19,
 * MASTER_RULES.md §33, §38).
 *
 *   - ai_features — the AI REGISTRY: one row per AI function per facility.
 *     A function is not an AI feature until its registry entry is complete
 *     (tier, owner, model + version, purpose/non-goals, min inputs, output
 *     schema, confidence threshold, fallback mode, review cadence, audit
 *     class, evaluation evidence ref). `enabled` is the per-feature
 *     KILL SWITCH; `model_approved` + `evaluation_ref` gate whether the
 *     inference boundary may ever transmit (AI_RULES.md §12, §14, §17).
 *   - ai_drafts   — assistive outputs (Tier 2): documentation drafts and
 *     summaries are grounded in record refs (`source_refs`), pinned to the
 *     model id/version that produced them, and enter a record ONLY after a
 *     clinician SIGNS them (`status: signed` + signer). Drafts never mutate
 *     a clinical record by themselves — no autonomous-action path.
 *
 * Both tables are TENANT_FACILITY tier, RLS enabled + FORCED by the
 * companion migration (2026_08_17_330200).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('function', 50); // documentation_draft | summarization | forecast | ...
            $table->string('name');
            $table->integer('tier'); // 1..4 (5 is prohibited — never registered)
            $table->uuid('owner_staff_id')->nullable(); // registered owner
            $table->string('model_id', 100);
            $table->string('model_version', 100);
            $table->text('purpose');
            $table->text('non_goals')->nullable();
            $table->jsonb('min_inputs')->default('[]'); // allowed input field keys (privilege boundary)
            $table->jsonb('output_schema')->default('{}');
            $table->decimal('confidence_threshold', 6, 3)->nullable(); // calibrated, Tier 3+
            $table->text('fallback_mode')->default('manual'); // documented degraded behavior
            $table->boolean('enabled')->default(false); // KILL SWITCH — false by default
            $table->boolean('model_approved')->default(false); // evaluation precedes transmission
            $table->text('evaluation_ref')->nullable(); // evidence — required before activation
            $table->text('review_cadence')->default('quarterly');
            $table->string('audit_class', 60)->nullable();
            $table->text('status')->default('registered'); // registered | active | retired
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement('alter table ai_features add constraint chk_ai_features_tier check (tier between 1 and 4)');
        DB::statement("alter table ai_features add constraint chk_ai_features_status check (status in ('registered', 'active', 'retired'))");
        // One registry entry per function per facility.
        DB::statement('create unique index uq_ai_features_tenant_facility_function on ai_features (tenant_id, facility_id, function)');
        DB::statement('create index idx_ai_features_tenant_facility on ai_features (tenant_id, facility_id, status, enabled)');

        Schema::create('ai_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id')->nullable();
            $table->uuid('encounter_id')->nullable();
            $table->string('function', 50);
            $table->integer('tier');
            $table->string('model_id', 100);
            $table->string('model_version', 100);
            $table->jsonb('source_refs')->default('[]'); // record refs the output is grounded in (provenance)
            $table->text('output');
            $table->decimal('confidence', 6, 3)->nullable();
            $table->text('status')->default('draft'); // draft | signed | withdrawn
            $table->uuid('signer_staff_id')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
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
        });

        DB::statement('alter table ai_drafts add constraint chk_ai_drafts_tier check (tier between 1 and 4)');
        DB::statement("alter table ai_drafts add constraint chk_ai_drafts_status check (status in ('draft', 'signed', 'withdrawn'))");
        DB::statement('create index idx_ai_drafts_tenant_facility on ai_drafts (tenant_id, facility_id, status)');
        DB::statement('create index idx_ai_drafts_tenant_patient on ai_drafts (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
        Schema::dropIfExists('ai_features');
    }
};
