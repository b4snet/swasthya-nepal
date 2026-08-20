<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 — National Analytics Reporting & Compliance.
 *
 * Tables:
 *  - compliance_reports: compliance assessment records per domain
 *  - compliance_report_items: individual findings within a compliance report
 *  - report_subscriptions: user subscriptions to scheduled reports
 *  - report_acknowledgments: read/acknowledge tracking for compliance reports
 *  - report_lineage_entries: data lineage — which source rows contributed to a metric
 *  - report_template_versions: immutable version history of report templates
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── compliance_reports ──
        Schema::create('compliance_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->text('report_code');
            $table->text('title');
            $table->text('category'); // privacy, security, clinical_quality, financial_controls, operational_governance
            $table->text('scope'); // facility, organization, national
            $table->text('status')->default('draft'); // draft, published, acknowledged, archived
            $table->jsonb('summary')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->uuid('generated_by_staff_id')->nullable();
            $table->timestampTz('generated_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->jsonb('acknowledgments_required')->default('[]'); // list of role codes
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_compliance_reports ON compliance_reports (tenant_id, facility_id, report_code, version)');
        DB::statement('CREATE INDEX idx_compliance_reports_category ON compliance_reports (tenant_id, category, status)');

        // ── compliance_report_items ──
        Schema::create('compliance_report_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('compliance_report_id');
            $table->text('rule_code');
            $table->text('rule_name');
            $table->text('severity'); // critical, high, medium, low, info
            $table->text('status'); // pass, fail, warning, na
            $table->text('description');
            $table->jsonb('evidence')->default('{}');
            $table->jsonb('recommendations')->default('[]');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('compliance_report_id')->references('id')->on('compliance_reports')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX idx_compliance_items_report ON compliance_report_items (compliance_report_id)');

        // ── report_subscriptions ──
        Schema::create('report_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('staff_id');
            $table->uuid('report_template_id')->nullable();
            $table->uuid('compliance_report_code')->nullable();
            $table->text('frequency'); // daily, weekly, monthly, on_publish
            $table->text('delivery_method')->default('in_app'); // in_app, email
            $table->jsonb('preferences')->default('{}');
            $table->text('status')->default('active'); // active, paused, cancelled
            $table->timestampTz('last_delivered_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->restrictOnDelete();
            $table->foreign('report_template_id')->references('id')->on('report_templates')->nullOnDelete();
        });

        DB::statement('CREATE INDEX idx_report_subscriptions_staff ON report_subscriptions (staff_id, status)');
        DB::statement('CREATE INDEX idx_report_subscriptions_template ON report_subscriptions (report_template_id) WHERE report_template_id IS NOT NULL');

        // ── report_acknowledgments ──
        Schema::create('report_acknowledgments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('compliance_report_id');
            $table->uuid('staff_id');
            $table->text('action'); // acknowledged, exception_noted
            $table->text('notes')->nullable();
            $table->timestampTz('acknowledged_at');
            $table->jsonb('metadata')->default('{}');

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('compliance_report_id')->references('id')->on('compliance_reports')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_report_ack ON report_acknowledgments (compliance_report_id, staff_id)');

        // ── report_lineage_entries ──
        Schema::create('report_lineage_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('report_run_id');
            $table->text('source_table');
            $table->uuid('source_id');
            $table->text('metric_code')->nullable();
            $table->jsonb('snapshot_context')->default('{}'); // filter, dimension, period
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('report_run_id')->references('id')->on('report_runs')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX idx_lineage_run ON report_lineage_entries (report_run_id)');
        DB::statement('CREATE INDEX idx_lineage_source ON report_lineage_entries (source_table, source_id)');

        // ── report_template_versions ──
        Schema::create('report_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('template_id');
            $table->integer('version');
            $table->jsonb('snapshot'); // full template state at this version
            $table->text('change_reason')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('template_id')->references('id')->on('report_templates')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_template_version ON report_template_versions (template_id, version)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_versions');
        Schema::dropIfExists('report_lineage_entries');
        Schema::dropIfExists('report_acknowledgments');
        Schema::dropIfExists('report_subscriptions');
        Schema::dropIfExists('compliance_report_items');
        Schema::dropIfExists('compliance_reports');
    }
};
