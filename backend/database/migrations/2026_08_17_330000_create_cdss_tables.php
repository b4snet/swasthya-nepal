<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — CDSS (ROADMAP Phase 21, PRODUCT_REQUIREMENTS §6.23,
 * CLINICAL_SAFETY.md §6, §9, AI_RULES.md §6–7).
 *
 * Knowledge-base-driven decision support:
 *   - cdss_rules         — the VERSIONED, clinically reviewed knowledge base
 *                          (rule_type: interaction | allergen | dose |
 *                          pathway). Rules are pinned by version; a rule is
 *                          never edited in place — supersession creates a new
 *                          version (mirrors kpi_definitions versioning).
 *                          The per-type payload lives in `spec` (JSONB) and
 *                          is shape-validated by CdssService; medication
 *                          references inside specs must exist in the tenant
 *                          (service-validated, like the analytics whitelist).
 *   - patient_allergies  — the patient's documented allergies (allergen
 *                          class e.g. "penicillin"), the input to the
 *                          allergy check.
 *   - cdss_check_results — the persisted outcome of a check: one row per
 *                          raised alert (type, rule code + version, severity,
 *                          message, triggering facts). Overrides are recorded
 *                          ON the result (reason captured, audited — never a
 *                          silent dismiss). CAS protects the open→overridden
 *                          transition.
 *
 * All tables are TENANT_FACILITY tier, RLS enabled + FORCED by the companion
 * migration (2026_08_17_330200).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdss_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('rule_type', 20); // interaction | allergen | dose | pathway
            $table->string('code', 60);
            $table->string('name');
            $table->string('severity', 20)->nullable(); // contraindicated | major | moderate | minor (pathway: null)
            $table->jsonb('spec')->default('{}'); // per-type payload, service-validated
            $table->integer('version')->default(1);
            $table->text('status')->default('draft'); // draft | active | superseded
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table cdss_rules add constraint chk_cdss_rules_type check (rule_type in ('interaction', 'allergen', 'dose', 'pathway'))");
        DB::statement("alter table cdss_rules add constraint chk_cdss_rules_severity check (severity is null or severity in ('contraindicated', 'major', 'moderate', 'minor'))");
        DB::statement("alter table cdss_rules add constraint chk_cdss_rules_status check (status in ('draft', 'active', 'superseded'))");
        DB::statement('alter table cdss_rules add constraint chk_cdss_rules_version check (version > 0)');
        // One version number per rule code per type per facility.
        DB::statement('create unique index uq_cdss_rules_tenant_facility_type_code_version on cdss_rules (tenant_id, facility_id, rule_type, code, version)');
        // Exactly one ACTIVE version per rule code — the DB backstop preventing
        // two live rules with the same code.
        DB::statement("create unique index uq_cdss_rules_tenant_facility_active on cdss_rules (tenant_id, facility_id, rule_type, code) where status = 'active'");
        DB::statement('create index idx_cdss_rules_tenant_facility_type on cdss_rules (tenant_id, facility_id, rule_type, status)');

        Schema::create('patient_allergies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('allergen');
            $table->text('allergen_class')->nullable(); // e.g. "penicillin" — the class the KB keys on
            $table->string('severity', 20)->default('moderate'); // mild | moderate | severe
            $table->text('reaction')->nullable();
            $table->text('status')->default('active'); // active | resolved
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('recorded_by')->nullable();
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

        DB::statement("alter table patient_allergies add constraint chk_patient_allergies_severity check (severity in ('mild', 'moderate', 'severe'))");
        DB::statement("alter table patient_allergies add constraint chk_patient_allergies_status check (status in ('active', 'resolved'))");
        // One active allergy per allergen class per patient (resolved allergies
        // may be re-documented as new rows).
        DB::statement('create unique index uq_patient_allergies_tenant_patient_class_active on patient_allergies (tenant_id, patient_id, allergen_class) where status = \'active\'');
        DB::statement('create index idx_patient_allergies_tenant_patient on patient_allergies (tenant_id, patient_id)');

        Schema::create('cdss_check_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->string('alert_type', 20); // interaction | allergy | dose | pathway
            $table->string('rule_code', 60);
            $table->integer('rule_version');
            $table->string('severity', 20);
            $table->text('message');
            $table->jsonb('triggering_facts')->default('[]'); // medication ids / allergen class — no free-text PHI
            $table->text('status')->default('open'); // open | overridden
            $table->text('override_reason')->nullable();
            $table->uuid('overridden_by')->nullable();
            $table->timestampTz('overridden_at')->nullable();
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

        DB::statement("alter table cdss_check_results add constraint chk_cdss_check_results_type check (alert_type in ('interaction', 'allergy', 'dose', 'pathway'))");
        DB::statement("alter table cdss_check_results add constraint chk_cdss_check_results_severity check (severity in ('contraindicated', 'major', 'moderate', 'minor'))");
        DB::statement("alter table cdss_check_results add constraint chk_cdss_check_results_status check (status in ('open', 'overridden'))");
        DB::statement('alter table cdss_check_results add constraint chk_cdss_check_results_version check (rule_version > 0)');
        DB::statement('create index idx_cdss_check_results_tenant_patient on cdss_check_results (tenant_id, patient_id, status)');
        DB::statement('create index idx_cdss_check_results_tenant_type on cdss_check_results (tenant_id, alert_type, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cdss_check_results');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('cdss_rules');
    }
};
