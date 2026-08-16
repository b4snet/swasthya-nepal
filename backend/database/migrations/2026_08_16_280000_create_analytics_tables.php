<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 21 — Analytics and Reporting (ROADMAP Phase 17, PRODUCT
 * REQUIREMENTS §6.19, DATABASE.md §3.51).
 *
 * Operational dashboards, financial/clinical analytics, scheduled
 * replica-fed reports, and executive dashboards — from OBSERVED data only
 * (MASTER_RULES.md P.15: no fabricated metrics). Metric definitions are
 * VERSIONED ("a changing KPI is not a KPI"); aggregates are stored as
 * metric_snapshots computed by AnalyticsService from the real source tables
 * at generation time; report runs execute against the dedicated `reporting`
 * read-replica connection and are fully audited (who, what scope, when —
 * MASTER_RULES.md §19.3). No PHI in any analytics row or audit payload:
 * snapshots carry aggregate counts/values, never records.
 *
 * All 7 tables are TENANT_FACILITY tier (dashboards and KPIs are curated
 * per facility; org-wide claims see every facility of the tenant through
 * the established `OR facility_id IS NULL` claim semantics). RLS is enabled
 * + FORCED by the companion migration (2026_08_16_280100).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────── Versioned metric definitions ───────────────────

        Schema::create('kpi_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('domain'); // operational | financial | clinical | executive
            $table->string('source_table', 100);
            $table->string('date_column', 50)->nullable();
            $table->jsonb('filter')->default('{}'); // whitelisted criteria, e.g. {"status": ["admitted"]}
            $table->text('aggregation')->default('count'); // count | sum
            $table->string('sum_column', 50)->nullable();
            $table->string('unit', 30)->nullable();
            $table->integer('version')->default(1);
            $table->text('status')->default('active'); // draft | active | superseded
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table kpi_definitions add constraint chk_kpi_domain check (domain in ('operational', 'financial', 'clinical', 'executive'))");
        DB::statement("alter table kpi_definitions add constraint chk_kpi_aggregation check (aggregation in ('count', 'sum'))");
        DB::statement("alter table kpi_definitions add constraint chk_kpi_status check (status in ('draft', 'active', 'superseded'))");
        DB::statement('alter table kpi_definitions add constraint chk_kpi_version check (version > 0)');
        // One version number per metric code per facility.
        DB::statement('create unique index uq_kpi_definitions_tenant_facility_code_version on kpi_definitions (tenant_id, facility_id, code, version)');
        // Exactly one ACTIVE version per metric code — the DB backstop that
        // prevents two live definitions of the same KPI (versioning rule).
        DB::statement('create unique index uq_kpi_definitions_tenant_facility_active on kpi_definitions (tenant_id, facility_id, code) where status = \'active\'');
        DB::statement('create index idx_kpi_definitions_tenant_facility on kpi_definitions (tenant_id, facility_id, status)');
        // Backer for the metric_snapshots / dashboard_kpis composite FKs.
        DB::statement('create unique index uq_kpi_definitions_tenant_facility_id on kpi_definitions (tenant_id, facility_id, id)');

        // ───────────────────────── Observed aggregates ─────────────────────────

        Schema::create('metric_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('kpi_definition_id');
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->decimal('value', 20, 4);
            $table->jsonb('dimension')->default('{}'); // e.g. {"department_id": "…"}
            $table->integer('row_count')->default(0); // rows observed in the source table
            $table->timestampTz('generated_at');
            $table->uuid('generated_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'kpi_definition_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('kpi_definitions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'generated_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('alter table metric_snapshots add constraint chk_metric_snapshots_period check (period_end >= period_start)');
        DB::statement('alter table metric_snapshots add constraint chk_metric_snapshots_value check (value >= 0)');
        DB::statement('alter table metric_snapshots add constraint chk_metric_snapshots_rows check (row_count >= 0)');
        // One snapshot per (KPI, period, dimension) — the idempotency
        // backstop: a concurrent double-refresh cannot create two snapshots
        // of the same period; the second writer either updates in place or
        // loses the race (409) and re-reads.
        DB::statement('create unique index uq_metric_snapshots_period on metric_snapshots (kpi_definition_id, period_start, period_end, dimension)');
        DB::statement('create index idx_metric_snapshots_tenant_kpi_period on metric_snapshots (tenant_id, kpi_definition_id, period_start desc)');

        // ─────────────────────────── Dashboards ───────────────────────────

        Schema::create('dashboards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->jsonb('role_gate')->default('[]'); // role codes allowed to view
            $table->boolean('is_active')->default(true);
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement('create unique index uq_dashboards_tenant_facility_code on dashboards (tenant_id, facility_id, code) where is_active = true');
        DB::statement('create index idx_dashboards_tenant_facility on dashboards (tenant_id, facility_id)');
        // Backer for the dashboard_kpis composite FK.
        DB::statement('create unique index uq_dashboards_tenant_facility_id on dashboards (tenant_id, facility_id, id)');

        Schema::create('dashboard_kpis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('dashboard_id');
            $table->uuid('kpi_definition_id');
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'dashboard_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('dashboards')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'kpi_definition_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('kpi_definitions')
                ->restrictOnDelete();
        });

        DB::statement('alter table dashboard_kpis add constraint chk_dashboard_kpis_position check (position > 0)');
        // A KPI appears once per dashboard.
        DB::statement('create unique index uq_dashboard_kpis_tenant_dashboard_kpi on dashboard_kpis (dashboard_id, kpi_definition_id)');
        // One active slot per position.
        DB::statement('create unique index uq_dashboard_kpis_tenant_position on dashboard_kpis (dashboard_id, position) where is_active = true');
        DB::statement('create index idx_dashboard_kpis_tenant_dashboard on dashboard_kpis (dashboard_id, position)');

        // ────────────────────────── Report surface ──────────────────────────

        Schema::create('report_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('category'); // operational | financial | clinical | executive
            $table->text('scope')->default('facility'); // tenant | facility | branch
            $table->jsonb('parameter_schema')->default('{}');
            // The report QUERY is a whitelisted structure — source_table,
            // filter criteria, selected aggregate columns, date column, and
            // a named period. NEVER raw SQL (MASTER_RULES.md §25.4).
            $table->jsonb('query');
            $table->boolean('is_active')->default(true);
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table report_templates add constraint chk_report_templates_category check (category in ('operational', 'financial', 'clinical', 'executive'))");
        DB::statement("alter table report_templates add constraint chk_report_templates_scope check (scope in ('tenant', 'facility', 'branch'))");
        DB::statement('create unique index uq_report_templates_tenant_facility_code on report_templates (tenant_id, facility_id, code) where is_active = true');
        DB::statement('create index idx_report_templates_tenant_facility on report_templates (tenant_id, facility_id)');
        // Backer for the report_schedules / report_runs composite FKs.
        DB::statement('create unique index uq_report_templates_tenant_facility_id on report_templates (tenant_id, facility_id, id)');

        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('template_id');
            $table->string('cron_expression', 100);
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'template_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('report_templates')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'created_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        // One schedule per template expression.
        DB::statement('create unique index uq_report_schedules_tenant_template_cron on report_schedules (template_id, cron_expression)');
        DB::statement('create index idx_report_schedules_tenant_enabled on report_schedules (tenant_id, enabled, next_run_at)');
        // Backer for the report_runs composite FK.
        DB::statement('create unique index uq_report_schedules_tenant_facility_id on report_schedules (tenant_id, facility_id, id)');

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('template_id');
            $table->uuid('schedule_id')->nullable();
            $table->uuid('requested_by_staff_id')->nullable();
            $table->text('status')->default('queued'); // queued | running | completed | failed
            $table->timestampTz('run_at');
            $table->timestampTz('completed_at')->nullable();
            $table->integer('row_count')->default(0);
            $table->text('error_message')->nullable(); // facts only — never PHI
            $table->boolean('is_export')->default(false);
            $table->text('export_format')->nullable(); // csv | pdf
            $table->text('output_checksum')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'template_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('report_templates')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'schedule_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('report_schedules')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'requested_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table report_runs add constraint chk_report_runs_status check (status in ('queued', 'running', 'completed', 'failed'))");
        DB::statement("alter table report_runs add constraint chk_report_runs_export_format check (export_format is null or export_format in ('csv', 'pdf'))");
        DB::statement('alter table report_runs add constraint chk_report_runs_rows check (row_count >= 0)');
        DB::statement('create index idx_report_runs_tenant_template_time on report_runs (tenant_id, template_id, run_at desc)');
        DB::statement('create index idx_report_runs_tenant_schedule on report_runs (tenant_id, schedule_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_templates');
        Schema::dropIfExists('dashboard_kpis');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('kpi_definitions');
    }
};
