<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Dashboard;
use App\Models\DashboardKpi;
use App\Models\KpiDefinition;
use App\Models\MetricSnapshot;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Support\ErrorCodes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 21 — Analytics and Reporting (ROADMAP Phase 17, PRODUCT
 * REQUIREMENTS §6.19, DATABASE.md §3.51).
 *
 * The core guarantee is OBSERVED DATA ONLY (MASTER_RULES.md P.15): every
 * metric value and report row_count is COMPUTED at generation time from the
 * real source tables — never fabricated, never stored as a hand-entered
 * number. Metric definitions are versioned (one ACTIVE version per code,
 * DB backstopped); snapshots are idempotent (one per KPI+period+dimension);
 * reports run against the dedicated `reporting` connection so reporting
 * load never touches the transactional write path (read-replica isolation).
 *
 * Safety: the source table, date columns, filter columns, and sum columns
 * are ALL whitelisted per source. No user input ever becomes SQL — the
 * query builder binds every value. Report error messages carry facts only
 * (never PHI); audit payloads are written by the controller with ids,
 * counts, and timestamps.
 */
final class AnalyticsService
{
    /**
     * The read-replica reporting connection (config/database.php). In
     * production this points at a read replica; locally it is exercised
     * against the same database (simulated replica).
     */
    public const REPORTING_CONNECTION = 'reporting';

    /**
     * Whitelisted sources with the columns analytics may read. Filter
     * columns are status/state discriminators; date columns are the
     * period boundaries; sum columns are the only numeric columns an
     * aggregation may total. Anything else is rejected at 422 — a
     * definition/report can never probe an unlisted column.
     *
     * @var array<string, array{date_columns: list<string>, filter_columns: list<string>, sum_columns: list<string>}>
     */
    private const SOURCE_WHITELIST = [
        'patients' => ['date_columns' => ['created_at'], 'filter_columns' => ['status', 'sex'], 'sum_columns' => []],
        'appointments' => ['date_columns' => ['starts_at', 'created_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'encounters' => ['date_columns' => ['created_at', 'ended_at'], 'filter_columns' => ['status', 'disposition'], 'sum_columns' => []],
        'admissions' => ['date_columns' => ['admitted_at', 'discharged_at', 'created_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'beds' => ['date_columns' => ['created_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'charges' => ['date_columns' => ['created_at'], 'filter_columns' => ['status', 'source_type'], 'sum_columns' => ['amount_minor']],
        'invoices' => ['date_columns' => ['created_at'], 'filter_columns' => ['status'], 'sum_columns' => ['total_minor', 'paid_minor']],
        'payments' => ['date_columns' => ['created_at'], 'filter_columns' => ['status'], 'sum_columns' => ['amount_minor']],
        'lab_orders' => ['date_columns' => ['ordered_at', 'created_at'], 'filter_columns' => ['status', 'priority'], 'sum_columns' => []],
        'procedures' => ['date_columns' => ['created_at', 'started_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'prescriptions' => ['date_columns' => ['created_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'critical_value_events' => ['date_columns' => ['created_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'follow_ups' => ['date_columns' => ['created_at', 'planned_at'], 'filter_columns' => ['status'], 'sum_columns' => []],
        'inventory_items' => ['date_columns' => ['created_at'], 'filter_columns' => [], 'sum_columns' => ['quantity_on_hand', 'reorder_level']],
    ];

    /**
     * Create a versioned metric definition (version 1, ACTIVE). Every field
     * is validated against the source whitelist before a row is written.
     *
     * @param  array<string, mixed>  $filter
     */
    public function createKpiDefinition(
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $domain,
        string $sourceTable,
        ?string $dateColumn,
        array $filter,
        string $aggregation,
        ?string $sumColumn,
        ?string $unit,
        ?string $staffId,
    ): KpiDefinition {
        $this->assertDefinition($sourceTable, $dateColumn, $filter, $aggregation, $sumColumn);

        return $this->guardUnique(fn () => KpiDefinition::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'domain' => $domain,
            'source_table' => $sourceTable,
            'date_column' => $dateColumn,
            'filter' => $filter,
            'aggregation' => $aggregation,
            'sum_column' => $aggregation === KpiDefinition::AGGREGATION_SUM ? $sumColumn : null,
            'unit' => $unit,
            'version' => 1,
            'status' => KpiDefinition::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    /**
     * Supersede an ACTIVE KPI definition and create its next version
     * ("a changing KPI is not a KPI"): the old row is marked superseded
     * (never mutated), a new ACTIVE row is created with version+1. The
     * CAS on (status, lock_version) means a concurrent supersede affects
     * zero rows and gets 409 — the new version is never double-created.
     *
     * @param  array<string, mixed>  $changes
     */
    public function supersedeKpi(KpiDefinition $kpi, array $changes, ?string $staffId): KpiDefinition
    {
        $sourceTable = $changes['source_table'] ?? $kpi->source_table;
        $dateColumn = $changes['date_column'] ?? $kpi->date_column;
        $filter = $changes['filter'] ?? $kpi->filter ?? [];
        $aggregation = $changes['aggregation'] ?? $kpi->aggregation;
        $sumColumn = $changes['sum_column'] ?? $kpi->sum_column;
        $this->assertDefinition($sourceTable, $dateColumn, $filter, $aggregation, $sumColumn);

        $affected = KpiDefinition::query()
            ->whereKey($kpi->getKey())
            ->where('status', KpiDefinition::STATUS_ACTIVE)
            ->where('lock_version', $kpi->lock_version)
            ->update([
                'status' => KpiDefinition::STATUS_SUPERSEDED,
                'lock_version' => $kpi->lock_version + 1,
                'updated_by' => $staffId,
            ]);

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::LOCK_CONFLICT,
                'The KPI definition was changed concurrently; reload and retry.',
                409
            );
        }

        $new = $kpi->replicate(['id', 'version', 'status', 'lock_version', 'created_at', 'updated_at', 'created_by']);
        $new->version = $kpi->version + 1;
        $new->status = KpiDefinition::STATUS_ACTIVE;
        $new->lock_version = 0;
        $new->created_by = $staffId;
        $new->updated_by = $staffId;
        $new->fill($changes);
        $new->save();

        return $new;
    }

    /**
     * Compute a metric snapshot from the REAL source table on the reporting
     * connection (observed data only), then store it idempotently — one
     * snapshot per (KPI, period, dimension). A concurrent double-refresh
     * either updates in place or loses the race to the partial unique and
     * re-reads the winner.
     *
     * @param  array<string, mixed>  $dimension
     */
    public function refreshMetric(
        KpiDefinition $kpi,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        array $dimension = [],
        ?string $staffId = null,
    ): MetricSnapshot {
        $whitelist = self::SOURCE_WHITELIST[$kpi->source_table];

        $builder = DB::connection(self::REPORTING_CONNECTION)
            ->table($kpi->source_table)
            ->where('tenant_id', $kpi->tenant_id);

        // Facility-scoped definitions count only their facility; org-wide
        // definitions (facility_id null) count the whole tenant.
        if ($kpi->facility_id !== null) {
            $builder->where('facility_id', $kpi->facility_id);
        }

        $this->applyFilter($builder, $kpi->source_table, $kpi->filter ?? []);

        if ($kpi->date_column !== null) {
            $builder->whereBetween($kpi->date_column, [$periodStart, $periodEnd]);
        }

        if ($kpi->aggregation === KpiDefinition::AGGREGATION_SUM) {
            $column = $kpi->sum_column;
            $row = $builder->selectRaw('SUM("'.$column.'") as value, COUNT(*) as row_count')->first();
            $value = $row->value ?? 0;
        } else {
            $row = $builder->selectRaw('COUNT(*) as value, COUNT(*) as row_count')->first();
            $value = $row->value;
        }
        $rowCount = (int) $row->row_count;

        // The dimension is bound as an explicit JSON literal so the WHERE is
        // a deterministic jsonb equality. The stored row is written through
        // the model's normal array cast (jsonb object); the lookup uses a
        // raw jsonb comparison so the string form can never be confused with
        // Laravel's array-in-where handling.
        $dimensionJson = (string) json_encode($dimension, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $snapshotWhere = fn ($query) => $query
            ->where('kpi_definition_id', $kpi->getKey())
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->whereRaw('dimension = ?::jsonb', [$dimensionJson]);

        try {
            // Nested transaction = savepoint when the caller is inside one:
            // the period partial unique aborts ONLY the savepoint on a
            // concurrent double-refresh, then we re-read the winner — the
            // surrounding transaction stays healthy.
            $snapshot = DB::transaction(function () use ($kpi, $periodStart, $periodEnd, $dimension, $value, $rowCount, $staffId, $snapshotWhere): MetricSnapshot {
                $existing = MetricSnapshot::query()
                    ->where(fn ($query) => $snapshotWhere($query))
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'value' => (float) $value,
                        'row_count' => $rowCount,
                        'generated_at' => now(),
                        'generated_by_staff_id' => $staffId,
                        'lock_version' => $existing->lock_version + 1,
                    ])->save();

                    return $existing;
                }

                return MetricSnapshot::query()->create([
                    'tenant_id' => $kpi->tenant_id,
                    'facility_id' => $kpi->facility_id,
                    'kpi_definition_id' => $kpi->getKey(),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'dimension' => $dimension, // array — the model's normal cast path
                    'value' => (float) $value,
                    'row_count' => $rowCount,
                    'generated_at' => now(),
                    'generated_by_staff_id' => $staffId,
                    'lock_version' => 0,
                ]);
            });
        } catch (QueryException) {
            // A concurrent writer created the same period snapshot first —
            // re-read the winner (idempotent refresh semantics).
            $snapshot = MetricSnapshot::query()
                ->where(fn ($query) => $snapshotWhere($query))
                ->firstOrFail();
        }

        return $snapshot;
    }

    /**
     * @param  list<string>  $roleGate
     */
    public function createDashboard(
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        array $roleGate,
        ?string $staffId,
    ): Dashboard {
        return $this->guardUnique(fn () => Dashboard::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'role_gate' => $roleGate,
            'is_active' => true,
            'lock_version' => 0,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    public function addKpiToDashboard(
        Dashboard $dashboard,
        KpiDefinition $kpi,
        int $position,
        ?string $staffId,
    ): DashboardKpi {
        return $this->guardUnique(fn () => DashboardKpi::query()->create([
            'tenant_id' => $dashboard->tenant_id,
            'facility_id' => $dashboard->facility_id,
            'dashboard_id' => $dashboard->getKey(),
            'kpi_definition_id' => $kpi->getKey(),
            'position' => $position,
            'is_active' => true,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $parameterSchema
     */
    public function createReportTemplate(
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $category,
        string $scope,
        array $query,
        array $parameterSchema,
        ?string $staffId,
    ): ReportTemplate {
        $this->assertTemplateQuery($query);

        return $this->guardUnique(fn () => ReportTemplate::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'scope' => $scope,
            'parameter_schema' => $parameterSchema,
            'query' => $query,
            'is_active' => true,
            'lock_version' => 0,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    public function scheduleReport(
        ReportTemplate $template,
        string $cronExpression,
        ?string $staffId,
    ): ReportSchedule {
        // Validate the cron now — an invalid expression fails at creation,
        // not silently at 3am.
        $this->nextOccurrence($cronExpression, CarbonImmutable::now());

        return $this->guardUnique(fn () => ReportSchedule::query()->create([
            'tenant_id' => $template->tenant_id,
            'facility_id' => $template->facility_id,
            'template_id' => $template->getKey(),
            'cron_expression' => $cronExpression,
            'enabled' => true,
            'last_run_at' => null,
            'next_run_at' => null,
            'created_by_staff_id' => $staffId,
            'lock_version' => 0,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    /**
     * Execute a report template against the REPORTING (read-replica)
     * connection and record the audited run. The result rows are returned
     * on the run (response payload only — never persisted); the run row
     * stores row_count and, for exports, a checksum of the canonical
     * serialization (an immutable fingerprint without storing PHI).
     *
     * @param  array<string, mixed>  $parameters
     */
    public function runReport(
        ReportTemplate $template,
        array $parameters = [],
        ?string $staffId = null,
        bool $export = false,
        ?string $exportFormat = null,
        ?ReportSchedule $schedule = null,
    ): ReportRun {
        $run = new ReportRun;
        $run->forceFill([
            'tenant_id' => $template->tenant_id,
            'facility_id' => $template->facility_id,
            'template_id' => $template->getKey(),
            'schedule_id' => $schedule?->getKey(),
            'requested_by_staff_id' => $staffId,
            'status' => ReportRun::STATUS_RUNNING,
            'run_at' => now(),
            'is_export' => $export,
            'export_format' => $exportFormat,
            'lock_version' => 0,
        ]);
        $run->save();

        try {
            $rows = $this->executeTemplateQuery($template, $parameters);
            $run->forceFill([
                'status' => ReportRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'row_count' => count($rows),
                'output_checksum' => $export ? hash('sha256', (string) json_encode($rows)) : null,
            ]);
            $run->save();
            $run->setAttribute('rows', $rows);

            return $run;
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => ReportRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => 'Report query failed for template '.$template->code.'.',
            ]);
            $run->save();
            throw $e;
        }
    }

    /**
     * Run every enabled schedule that is due. The CAS on (enabled,
     * next_run_at, lock_version) guarantees a concurrent worker cannot
     * double-run the same schedule — exactly one winner per due schedule.
     */
    public function runDueSchedules(?CarbonInterface $now = null): int
    {
        $now ??= now();

        $due = ReportSchedule::query()
            ->where('enabled', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        $ran = 0;
        foreach ($due as $schedule) {
            $next = $this->nextOccurrence($schedule->cron_expression, $now);
            $affected = ReportSchedule::query()
                ->whereKey($schedule->getKey())
                ->where('enabled', true)
                ->where('lock_version', $schedule->lock_version)
                ->where(function ($q) use ($now): void {
                    $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
                })
                ->update([
                    'last_run_at' => $now,
                    'next_run_at' => $next,
                    'lock_version' => $schedule->lock_version + 1,
                ]);

            if ($affected === 1) {
                $this->runReport($schedule->template, [], null, false, null, $schedule);
                $ran++;
            }
        }

        return $ran;
    }

    /**
     * Translate a unique-violation (SQLSTATE 23505) into the documented 409
     * contract — a duplicate active code/position is a conflict, not a 500.
     * Other constraint failures (FK, CHECK) re-throw untouched.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function guardUnique(callable $create)
    {
        try {
            // The nested transaction is a SAVEPOINT when the caller is inside
            // one (the established pattern — AuthSubjectBindingTest): the
            // unique violation aborts only the savepoint, never the request's
            // surrounding transaction, and the 409 is a clean response.
            return DB::transaction($create);
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'A conflicting active record with this code already exists.',
                    409
                );
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executeTemplateQuery(ReportTemplate $template, array $parameters): array
    {
        $query = $template->query;
        $source = $query['source_table'] ?? null;
        $this->assertSource($source);
        $whitelist = self::SOURCE_WHITELIST[$source];

        $aggregation = $query['aggregation'] ?? KpiDefinition::AGGREGATION_COUNT;
        $sumColumn = $query['sum_column'] ?? null;
        if ($aggregation === KpiDefinition::AGGREGATION_SUM && ! in_array($sumColumn, $whitelist['sum_columns'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'sum_column is not whitelisted for this source.', 422);
        }

        $builder = DB::connection(self::REPORTING_CONNECTION)
            ->table($source)
            ->where('tenant_id', $template->tenant_id)
            ->where('facility_id', $template->facility_id);

        $this->applyFilter($builder, $source, $query['filter'] ?? []);

        $dateColumn = $query['date_column'] ?? null;
        if ($dateColumn !== null) {
            if (! in_array($dateColumn, $whitelist['date_columns'], true)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'date_column is not whitelisted for this source.', 422);
            }
            [$start, $end] = $this->periodWindow($query['period'] ?? 'last_7_days', $parameters);
            $builder->whereBetween($dateColumn, [$start, $end]);
        }

        $groupBy = $query['group_by'] ?? null;
        if ($groupBy !== null) {
            if (! in_array($groupBy, $whitelist['filter_columns'], true)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'group_by is not whitelisted for this source.', 422);
            }
            $rows = $builder
                ->groupBy($groupBy)
                ->orderBy($groupBy)
                ->get([$groupBy, DB::raw('COUNT(*) as row_count')])
                ->map(fn ($row): array => [
                    $groupBy => $row->{$groupBy},
                    'rowCount' => (int) $row->row_count,
                ])
                ->all();

            return $rows;
        }

        $row = $builder->selectRaw(
            $aggregation === KpiDefinition::AGGREGATION_SUM
                ? 'SUM("'.$sumColumn.'") as value, COUNT(*) as row_count'
                : 'COUNT(*) as value, COUNT(*) as row_count'
        )->first();

        return [[
            'value' => $row->value ?? 0,
            'rowCount' => (int) $row->row_count,
        ]];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function applyFilter(Builder $builder, string $source, array $filter): void
    {
        $whitelist = self::SOURCE_WHITELIST[$source];
        foreach ($filter as $column => $values) {
            if (! in_array($column, $whitelist['filter_columns'], true)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Filter column "'.$column.'" is not whitelisted for this source.', 422);
            }
            if (is_array($values)) {
                $builder->whereIn($column, $values);
            } else {
                $builder->where($column, $values);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function assertDefinition(
        string $sourceTable,
        ?string $dateColumn,
        array $filter,
        string $aggregation,
        ?string $sumColumn,
    ): void {
        $this->assertSource($sourceTable);
        $whitelist = self::SOURCE_WHITELIST[$sourceTable];

        if ($dateColumn !== null && ! in_array($dateColumn, $whitelist['date_columns'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'date_column is not whitelisted for this source.', 422);
        }
        if ($aggregation !== KpiDefinition::AGGREGATION_COUNT && $aggregation !== KpiDefinition::AGGREGATION_SUM) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'aggregation must be count or sum.', 422);
        }
        if ($aggregation === KpiDefinition::AGGREGATION_SUM && ! in_array($sumColumn, $whitelist['sum_columns'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'sum_column is not whitelisted for this source.', 422);
        }
        foreach (array_keys($filter) as $column) {
            if (! in_array($column, $whitelist['filter_columns'], true)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Filter column "'.$column.'" is not whitelisted for this source.', 422);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function assertTemplateQuery(array $query): void
    {
        $source = $query['source_table'] ?? null;
        $this->assertSource($source);
        $whitelist = self::SOURCE_WHITELIST[$source];

        $dateColumn = $query['date_column'] ?? null;
        if ($dateColumn !== null && ! in_array($dateColumn, $whitelist['date_columns'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'date_column is not whitelisted for this source.', 422);
        }
        $groupBy = $query['group_by'] ?? null;
        if ($groupBy !== null && ! in_array($groupBy, $whitelist['filter_columns'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'group_by is not whitelisted for this source.', 422);
        }
    }

    private function assertSource(mixed $source): void
    {
        if (! is_string($source) || ! isset(self::SOURCE_WHITELIST[$source])) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'source_table is not supported.', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function periodWindow(string $period, array $parameters): array
    {
        $now = CarbonImmutable::now();
        $start = $end = null;
        switch ($period) {
            case 'today':
                $start = $now->startOfDay();
                $end = $now->endOfDay();
                break;
            case 'yesterday':
                $start = $now->subDay()->startOfDay();
                $end = $now->subDay()->endOfDay();
                break;
            case 'last_7_days':
                $start = $now->subDays(6)->startOfDay();
                $end = $now->endOfDay();
                break;
            case 'last_30_days':
                $start = $now->subDays(29)->startOfDay();
                $end = $now->endOfDay();
                break;
            case 'this_month':
                $start = $now->startOfMonth();
                $end = $now->endOfMonth();
                break;
            case 'custom':
                $start = CarbonImmutable::parse($parameters['start']);
                $end = CarbonImmutable::parse($parameters['end']);
                break;
            default:
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'period is not supported.', 422);
        }

        return [$start, $end];
    }

    /**
     * Compute the next occurrence of a 5-field cron expression after $from
     * (fields: minute hour day-of-month month day-of-week; supports star,
     * star-slash step notation, comma lists, and single integers). A
     * genuinely correct scan — bounded so an impossible expression fails
     * loudly instead of hanging.
     */
    private function nextOccurrence(string $cron, CarbonInterface $from): CarbonInterface
    {
        $parts = preg_split('/\s+/', trim($cron));
        if ($parts === false || count($parts) !== 5) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'cron_expression must have exactly 5 fields.', 422);
        }
        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        $cursor = CarbonImmutable::instance($from)->addMinute()->startOfMinute();
        for ($i = 0; $i < 366 * 24 * 60; $i++) {
            if (
                $this->cronFieldMatches($minute, $cursor->minute)
                && $this->cronFieldMatches($hour, $cursor->hour)
                && $this->cronFieldMatches($dayOfMonth, $cursor->day)
                && $this->cronFieldMatches($month, $cursor->month)
                // cron day-of-week: 0 = Sunday; ISO day 7 (Sunday) % 7 = 0.
                && $this->cronFieldMatches($dayOfWeek, $cursor->dayOfWeekIso % 7)
            ) {
                return $cursor;
            }
            $cursor = $cursor->addMinute();
        }

        throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'cron_expression never matches; review it.', 422);
    }

    private function cronFieldMatches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }
        if (str_contains($field, '/')) {
            [$base, $step] = explode('/', $field, 2);
            $base = $base === '*' ? 0 : (int) $base;
            $step = (int) $step;
            if ($step <= 0) {
                return false;
            }

            return $value >= $base && ($value - $base) % $step === 0;
        }
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $part) {
                if ((int) $part === $value) {
                    return true;
                }
            }

            return false;
        }

        return (int) $field === $value;
    }
}
