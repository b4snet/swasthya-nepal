<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AddDashboardKpiRequest;
use App\Http\Requests\Analytics\RefreshMetricsRequest;
use App\Http\Requests\Analytics\RunReportRequest;
use App\Http\Requests\Analytics\StoreDashboardRequest;
use App\Http\Requests\Analytics\StoreKpiDefinitionRequest;
use App\Http\Requests\Analytics\StoreReportScheduleRequest;
use App\Http\Requests\Analytics\StoreReportTemplateRequest;
use App\Http\Requests\Analytics\SupersedeKpiRequest;
use App\Models\Dashboard;
use App\Models\DashboardKpi;
use App\Models\KpiDefinition;
use App\Models\MetricSnapshot;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Services\AnalyticsService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 21 — Analytics and Reporting (ROADMAP Phase 17, PRODUCT
 * REQUIREMENTS §6.19, DATABASE.md §3.51).
 *
 * Operational dashboards, financial/clinical analytics, scheduled
 * replica-fed reports, and executive dashboards — from OBSERVED data only
 * (MASTER_RULES.md P.15). Metric definitions are versioned; snapshots are
 * computed from the real source tables at generation time; report runs and
 * exports execute on the dedicated `reporting` read-replica connection and
 * are fully audited. Audit payloads carry facts only — ids, counts,
 * timestamps, formats — never PHI (OBSERVABILITY.md §17).
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET analytics/kpi-definitions — the facility's active metric
     * definitions (each with its version).
     */
    public function indexKpiDefinitions(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $kpis = KpiDefinition::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('status', KpiDefinition::STATUS_ACTIVE)
            ->orderBy('code')
            ->get()
            ->map(fn (KpiDefinition $kpi): array => self::presentKpi($kpi))
            ->values();

        return Envelope::success(data: $kpis, request: $request);
    }

    /**
     * POST analytics/kpi-definitions — create a versioned metric definition
     * (version 1, active).
     */
    public function storeKpiDefinition(StoreKpiDefinitionRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $kpi = $this->analytics->createKpiDefinition(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('code'),
            $request->validated('name'),
            $request->validated('domain'),
            $request->validated('sourceTable'),
            $request->validated('dateColumn'),
            $request->validated('filter', []),
            $request->validated('aggregation'),
            $request->validated('sumColumn'),
            $request->validated('unit'),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.kpi_defined', 'kpi_definition', $kpi->getKey(), [
            'code' => $kpi->code,
            'version' => $kpi->version,
            'domain' => $kpi->domain,
            'sourceTable' => $kpi->source_table,
        ], $request);

        return Envelope::success(data: self::presentKpi($kpi), status: 201, request: $request);
    }

    /**
     * POST analytics/kpi-definitions/{kpi}/supersede — publish a new version
     * of an ACTIVE definition. CAS-guarded: a concurrent supersede gets 409.
     */
    public function supersedeKpi(SupersedeKpiRequest $request, KpiDefinition $kpi): JsonResponse
    {
        AccessCheck::scoped($kpi, write: true);
        $context = TenantContext::current();

        $changes = [];
        foreach ([
            'name' => 'name',
            'domain' => 'domain',
            'sourceTable' => 'source_table',
            'dateColumn' => 'date_column',
            'filter' => 'filter',
            'aggregation' => 'aggregation',
            'sumColumn' => 'sum_column',
            'unit' => 'unit',
        ] as $field => $column) {
            if ($request->has($field)) {
                $changes[$column] = $request->validated($field);
            }
        }

        $new = $this->analytics->supersedeKpi($kpi, $changes, $this->currentStaffId($context));

        $this->audit->record('analytics.kpi_superseded', 'kpi_definition', $kpi->getKey(), [
            'code' => $kpi->code,
            'fromVersion' => $kpi->version,
            'toVersion' => $new->version,
        ], $request);

        return Envelope::success(data: self::presentKpi($new), request: $request);
    }

    /**
     * GET analytics/metrics/{kpi} — the observed snapshots of a KPI
     * (drill-down: every number is backed by a snapshot computed from real
     * source rows at generation time).
     */
    public function showMetrics(Request $request, KpiDefinition $kpi): JsonResponse
    {
        AccessCheck::scoped($kpi, write: false);
        $context = TenantContext::current();

        $snapshots = MetricSnapshot::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('kpi_definition_id', $kpi->getKey())
            ->orderByDesc('period_end')
            ->get()
            ->map(fn (MetricSnapshot $snapshot): array => [
                'id' => $snapshot->getKey(),
                'periodStart' => $snapshot->period_start->toIso8601String(),
                'periodEnd' => $snapshot->period_end->toIso8601String(),
                'value' => $snapshot->value,
                'dimension' => $snapshot->dimension,
                'rowCount' => $snapshot->row_count,
                'generatedAt' => $snapshot->generated_at->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $snapshots, request: $request);
    }

    /**
     * POST analytics/snapshots/refresh — recompute a KPI from the REAL
     * source table for the period (observed data only; idempotent — one
     * snapshot per KPI + period + dimension).
     */
    public function refreshMetrics(RefreshMetricsRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $kpi = KpiDefinition::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->findOrFail($request->validated('kpiDefinitionId'));

        $snapshot = $this->analytics->refreshMetric(
            $kpi,
            CarbonImmutable::parse($request->validated('periodStart')),
            CarbonImmutable::parse($request->validated('periodEnd')),
            $request->validated('dimension', []),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.metric_refreshed', 'metric_snapshot', $snapshot->getKey(), [
            'kpiCode' => $kpi->code,
            'version' => $kpi->version,
            'periodStart' => $snapshot->period_start->toIso8601String(),
            'periodEnd' => $snapshot->period_end->toIso8601String(),
            'value' => $snapshot->value,
            'rowCount' => $snapshot->row_count,
        ], $request);

        return Envelope::success(data: [
            'id' => $snapshot->getKey(),
            'kpiDefinitionId' => $snapshot->kpi_definition_id,
            'periodStart' => $snapshot->period_start->toIso8601String(),
            'periodEnd' => $snapshot->period_end->toIso8601String(),
            'value' => $snapshot->value,
            'dimension' => $snapshot->dimension,
            'rowCount' => $snapshot->row_count,
            'generatedAt' => $snapshot->generated_at->toIso8601String(),
        ], request: $request);
    }

    /**
     * POST analytics/dashboards — create a curated dashboard with a role
     * gate.
     */
    public function storeDashboard(StoreDashboardRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $dashboard = $this->analytics->createDashboard(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('code'),
            $request->validated('name'),
            $request->validated('roleGate', []),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.dashboard_created', 'dashboard', $dashboard->getKey(), [
            'code' => $dashboard->code,
            'roleGate' => $dashboard->role_gate,
        ], $request);

        return Envelope::success(data: self::presentDashboard($dashboard), status: 201, request: $request);
    }

    /**
     * GET analytics/dashboards/{dashboard} — the dashboard with its KPIs and
     * each KPI's LATEST observed snapshot: the drill-down path from a
     * dashboard number to the data that produced it.
     */
    public function showDashboard(Request $request, Dashboard $dashboard): JsonResponse
    {
        AccessCheck::scoped($dashboard, write: false);
        $context = TenantContext::current();

        $kpis = DashboardKpi::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('dashboard_id', $dashboard->getKey())
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->map(function (DashboardKpi $item): array {
                $kpi = $item->kpi;
                $latest = MetricSnapshot::query()
                    ->where('kpi_definition_id', $kpi->getKey())
                    ->orderByDesc('period_end')
                    ->first();

                return [
                    'position' => $item->position,
                    'kpi' => self::presentKpi($kpi),
                    'latestSnapshot' => $latest === null ? null : [
                        'id' => $latest->getKey(),
                        'periodStart' => $latest->period_start->toIso8601String(),
                        'periodEnd' => $latest->period_end->toIso8601String(),
                        'value' => $latest->value,
                        'dimension' => $latest->dimension,
                        'rowCount' => $latest->row_count,
                        'generatedAt' => $latest->generated_at->toIso8601String(),
                    ],
                ];
            })
            ->values();

        return Envelope::success(data: [
            'id' => $dashboard->getKey(),
            'code' => $dashboard->code,
            'name' => $dashboard->name,
            'roleGate' => $dashboard->role_gate,
            'kpis' => $kpis,
        ], request: $request);
    }

    /**
     * POST analytics/dashboards/{dashboard}/kpis — place a KPI on the
     * dashboard (one active slot per position; a KPI appears once).
     */
    public function addDashboardKpi(AddDashboardKpiRequest $request, Dashboard $dashboard): JsonResponse
    {
        AccessCheck::scoped($dashboard, write: true);
        $context = TenantContext::current();

        $kpi = KpiDefinition::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('status', KpiDefinition::STATUS_ACTIVE)
            ->findOrFail($request->validated('kpiDefinitionId'));

        $item = $this->analytics->addKpiToDashboard(
            $dashboard,
            $kpi,
            (int) $request->validated('position'),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.dashboard_kpi_added', 'dashboard_kpi', $item->getKey(), [
            'dashboardCode' => $dashboard->code,
            'kpiCode' => $kpi->code,
            'position' => $item->position,
        ], $request);

        return Envelope::success(data: [
            'id' => $item->getKey(),
            'dashboardId' => $item->dashboard_id,
            'kpiDefinitionId' => $item->kpi_definition_id,
            'position' => $item->position,
        ], status: 201, request: $request);
    }

    /**
     * GET analytics/report-templates — the facility's report catalog.
     */
    public function indexReportTemplates(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $templates = ReportTemplate::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (ReportTemplate $template): array => self::presentTemplate($template))
            ->values();

        return Envelope::success(data: $templates, request: $request);
    }

    /**
     * POST analytics/report-templates — define a whitelisted report query.
     */
    public function storeReportTemplate(StoreReportTemplateRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $query = $request->validated('query');
        $query['source_table'] = $query['sourceTable'];
        $query['date_column'] = $query['dateColumn'] ?? null;
        $query['group_by'] = $query['groupBy'] ?? null;
        $query['sum_column'] = $query['sumColumn'] ?? null;
        unset($query['sourceTable'], $query['dateColumn'], $query['groupBy'], $query['sumColumn']);

        $template = $this->analytics->createReportTemplate(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('code'),
            $request->validated('name'),
            $request->validated('category'),
            $request->validated('scope'),
            $query,
            $request->validated('parameterSchema', []),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.report_template_created', 'report_template', $template->getKey(), [
            'code' => $template->code,
            'category' => $template->category,
            'scope' => $template->scope,
            'sourceTable' => $template->query['source_table'],
        ], $request);

        return Envelope::success(data: self::presentTemplate($template), status: 201, request: $request);
    }

    /**
     * GET analytics/report-schedules — the facility's scheduled reports.
     */
    public function indexReportSchedules(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $schedules = ReportSchedule::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ReportSchedule $schedule): array => [
                'id' => $schedule->getKey(),
                'templateId' => $schedule->template_id,
                'templateCode' => $schedule->template?->code,
                'cronExpression' => $schedule->cron_expression,
                'enabled' => $schedule->enabled,
                'lastRunAt' => $schedule->last_run_at?->toIso8601String(),
                'nextRunAt' => $schedule->next_run_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $schedules, request: $request);
    }

    /**
     * POST analytics/report-schedules — schedule a template (cron validated
     * at creation).
     */
    public function storeReportSchedule(StoreReportScheduleRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $template = ReportTemplate::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('is_active', true)
            ->findOrFail($request->validated('templateId'));

        $schedule = $this->analytics->scheduleReport(
            $template,
            $request->validated('cronExpression'),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.report_scheduled', 'report_schedule', $schedule->getKey(), [
            'templateCode' => $template->code,
            'cronExpression' => $schedule->cron_expression,
        ], $request);

        return Envelope::success(data: [
            'id' => $schedule->getKey(),
            'templateId' => $schedule->template_id,
            'cronExpression' => $schedule->cron_expression,
            'enabled' => $schedule->enabled,
        ], status: 201, request: $request);
    }

    /**
     * GET analytics/report-runs — the audited run history.
     */
    public function indexReportRuns(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $runs = ReportRun::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('run_at')
            ->limit(100)
            ->get()
            ->map(fn (ReportRun $run): array => self::presentRun($run))
            ->values();

        return Envelope::success(data: $runs, request: $request);
    }

    /**
     * POST analytics/reports/run — execute a template now on the reporting
     * connection (no export; reports:export is a separate gate).
     */
    public function runReport(RunReportRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $template = ReportTemplate::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('is_active', true)
            ->findOrFail($request->validated('templateId'));

        $run = $this->analytics->runReport(
            $template,
            $request->validated('parameters', []),
            $this->currentStaffId($context),
        );

        $this->audit->record('analytics.report_run', 'report_run', $run->getKey(), [
            'templateCode' => $template->code,
            'status' => $run->status,
            'rowCount' => $run->row_count,
            'isExport' => false,
        ], $request);

        return Envelope::success(data: self::presentRunWithRows($run), request: $request);
    }

    /**
     * POST analytics/reports/export — run a template as an audited EXPORT
     * (reports:export gate). Every export is a run record with an output
     * checksum fingerprint — who exported what scope when (MASTER_RULES
     * §19.3).
     */
    public function exportReport(RunReportRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $template = ReportTemplate::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('is_active', true)
            ->findOrFail($request->validated('templateId'));

        $format = $request->validated('exportFormat', ReportRun::EXPORT_CSV);

        $run = $this->analytics->runReport(
            $template,
            $request->validated('parameters', []),
            $this->currentStaffId($context),
            export: true,
            exportFormat: $format,
        );

        $this->audit->record('analytics.report_export', 'report_run', $run->getKey(), [
            'templateCode' => $template->code,
            'format' => $format,
            'rowCount' => $run->row_count,
            'outputChecksum' => $run->output_checksum,
        ], $request);

        return Envelope::success(data: self::presentRun($run), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentKpi(KpiDefinition $kpi): array
    {
        return [
            'id' => $kpi->getKey(),
            'code' => $kpi->code,
            'name' => $kpi->name,
            'domain' => $kpi->domain,
            'sourceTable' => $kpi->source_table,
            'dateColumn' => $kpi->date_column,
            'filter' => $kpi->filter,
            'aggregation' => $kpi->aggregation,
            'sumColumn' => $kpi->sum_column,
            'unit' => $kpi->unit,
            'version' => $kpi->version,
            'status' => $kpi->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDashboard(Dashboard $dashboard): array
    {
        return [
            'id' => $dashboard->getKey(),
            'code' => $dashboard->code,
            'name' => $dashboard->name,
            'roleGate' => $dashboard->role_gate,
            'isActive' => $dashboard->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentTemplate(ReportTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'code' => $template->code,
            'name' => $template->name,
            'category' => $template->category,
            'scope' => $template->scope,
            'parameterSchema' => $template->parameter_schema,
            'query' => $template->query,
            'isActive' => $template->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRun(ReportRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'templateId' => $run->template_id,
            'templateCode' => $run->template?->code,
            'scheduleId' => $run->schedule_id,
            'status' => $run->status,
            'runAt' => $run->run_at->toIso8601String(),
            'completedAt' => $run->completed_at?->toIso8601String(),
            'rowCount' => $run->row_count,
            'errorMessage' => $run->error_message,
            'isExport' => $run->is_export,
            'exportFormat' => $run->export_format,
            'outputChecksum' => $run->output_checksum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRunWithRows(ReportRun $run): array
    {
        return array_merge(self::presentRun($run), [
            'rows' => $run->getAttribute('rows') ?? [],
        ]);
    }

    private function currentStaffId(TenantContext $context): ?string
    {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }
}
