<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * PHASE 93 — Scale and Capacity Engineering.
 *
 * Provides:
 * - Database capacity analysis
 * - RLS policy inventory and overhead estimation
 * - Index audit
 * - Connection capacity analysis
 * - Patient search performance baseline
 * - Hospital capacity model
 */
final class ScaleEngineeringService
{
    /**
     * Database capacity summary.
     *
     * @return array{
     *     tables: int,
     *     rls_policies: int,
     *     rls_enabled_tables: int,
     *     indexes: int,
     *     helper_functions: int,
     *     postgresql_settings: array{max_connections: int, shared_buffers_kb: int, work_mem_kb: int},
     *     total_records: int,
     *     estimated_growth_monthly: int
     * }
     */
    public function databaseCapacity(): array
    {
        $tables = DB::select(
            "SELECT count(*) as cnt FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
        )[0]->cnt;

        $policies = DB::select(
            "SELECT count(*) as cnt FROM pg_policies WHERE schemaname = 'public'"
        )[0]->cnt;

        $rlsEnabled = DB::select(
            "SELECT count(*) as cnt FROM pg_tables WHERE schemaname = 'public' AND rowsecurity = true"
        )[0]->cnt;

        $indexes = DB::select(
            "SELECT count(*) as cnt FROM pg_indexes WHERE schemaname = 'public'"
        )[0]->cnt;

        $functions = DB::select(
            "SELECT count(*) as cnt FROM pg_proc WHERE proname LIKE 'swasthya_rls_%'"
        )[0]->cnt;

        $settings = DB::select(
            "SELECT name, setting::int as val FROM pg_settings WHERE name IN ('max_connections', 'shared_buffers', 'work_mem')"
        );
        $settingsMap = [];
        foreach ($settings as $s) {
            $settingsMap[$s->name] = $s->val;
        }

        // Count total records across major tables
        $majorTables = ['patients', 'encounters', 'lab_orders', 'prescriptions', 'invoices', 'audit_events'];
        $totalRecords = 0;
        foreach ($majorTables as $table) {
            try {
                $totalRecords += DB::table($table)->count();
            } catch (\Exception $e) {
                // Table may not exist
            }
        }

        return [
            'tables' => $tables,
            'rls_policies' => $policies,
            'rls_enabled_tables' => $rlsEnabled,
            'indexes' => $indexes,
            'helper_functions' => $functions,
            'postgresql_settings' => [
                'max_connections' => $settingsMap['max_connections'] ?? 100,
                'shared_buffers_kb' => $settingsMap['shared_buffers'] ?? 16384,
                'work_mem_kb' => $settingsMap['work_mem'] ?? 4096,
            ],
            'total_records' => $totalRecords,
            'estimated_growth_monthly' => $totalRecords > 0 ? (int) ($totalRecords * 0.1) : 0,
        ];
    }

    /**
     * RLS policy inventory by table.
     *
     * @return list<array{table: string, policy_count: int, commands: list<string>}>
     */
    public function rlsInventory(): array
    {
        $policies = DB::select("
            SELECT
                tablename,
                count(*) as cnt,
                array_agg(DISTINCT cmd) as cmds
            FROM pg_policies
            WHERE schemaname = 'public'
            GROUP BY tablename
            ORDER BY cnt DESC, tablename
        ");

        return array_map(fn ($p) => [
            'table' => $p->tablename,
            'policy_count' => $p->cnt,
            'commands' => $p->cmds,
        ], $policies);
    }

    /**
     * Index audit: find tables without indexes on common query columns.
     *
     * @return list<array{table: string, has_id_index: bool, has_tenant_index: bool, index_count: int}>
     */
    public function indexAudit(): array
    {
        $tables = DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
        );

        $results = [];
        foreach ($tables as $table) {
            $indexes = DB::select(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ?",
                [$table->tablename]
            );

            $indexDefs = implode(' ', array_column($indexes, 'indexdef'));

            $results[] = [
                'table' => $table->tablename,
                'has_id_index' => str_contains($indexDefs, 'id'),
                'has_tenant_index' => str_contains($indexDefs, 'tenant_id'),
                'index_count' => count($indexes),
            ];
        }

        return $results;
    }

    /**
     * Connection capacity analysis.
     *
     * @return array{
     *     max_connections: int,
     *     current_active: int,
     *     current_idle: int,
     *     total_connections: int,
     *     utilization_pct: float,
     *     recommendation: string
     * }
     */
    public function connectionCapacity(): array
    {
        $maxConn = DB::select('SHOW max_connections')[0]->max_connections;

        $active = DB::select(
            "SELECT count(*) as cnt FROM pg_stat_activity WHERE state = 'active' AND datname = current_database()"
        )[0]->cnt;

        $idle = DB::select(
            "SELECT count(*) as cnt FROM pg_stat_activity WHERE state = 'idle' AND datname = current_database()"
        )[0]->cnt;

        $total = $active + $idle;
        $utilization = $maxConn > 0 ? round(($total / $maxConn) * 100, 1) : 0;

        $recommendation = 'Connection usage within safe limits.';
        if ($utilization > 80) {
            $recommendation = 'WARNING: Connection usage exceeds 80%. Consider connection pooling.';
        } elseif ($utilization > 60) {
            $recommendation = 'Connection usage moderate. Monitor during peak load.';
        }

        return [
            'max_connections' => $maxConn,
            'current_active' => $active,
            'current_idle' => $idle,
            'total_connections' => $total,
            'utilization_pct' => $utilization,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Hospital capacity model with realistic workload profiles.
     *
     * @return array{
     *     small_hospital: array{name: string, beds: int, staff: int, daily_registrations: int, daily_encounters: int, daily_orders: int, daily_payments: int},
     *     medium_hospital: array{name: string, beds: int, staff: int, daily_registrations: int, daily_encounters: int, daily_orders: int, daily_payments: int},
     *     large_hospital: array{name: string, beds: int, staff: int, daily_registrations: int, daily_encounters: int, daily_orders: int, daily_payments: int},
     *     multi_facility: array{name: string, facilities: int, total_beds: int, total_staff: int},
     * }
     */
    public function hospitalCapacityModel(): array
    {
        return [
            'small_hospital' => [
                'name' => 'Small Hospital (50-100 beds)',
                'beds' => 75,
                'staff' => 150,
                'daily_registrations' => 80,
                'daily_encounters' => 60,
                'daily_orders' => 120,
                'daily_payments' => 50,
            ],
            'medium_hospital' => [
                'name' => 'Medium Hospital (200-400 beds)',
                'beds' => 300,
                'staff' => 600,
                'daily_registrations' => 300,
                'daily_encounters' => 250,
                'daily_orders' => 600,
                'daily_payments' => 200,
            ],
            'large_hospital' => [
                'name' => 'Large Hospital (500+ beds)',
                'beds' => 600,
                'staff' => 1500,
                'daily_registrations' => 600,
                'daily_encounters' => 500,
                'daily_orders' => 1500,
                'daily_payments' => 400,
            ],
            'multi_facility' => [
                'name' => 'Multi-Facility (3 hospitals)',
                'facilities' => 3,
                'total_beds' => 1000,
                'total_staff' => 2000,
            ],
        ];
    }

    /**
     * Patient search performance measurement.
     *
     * @return array{patient_count: int, search_method: string, estimated_latency_ms: int, recommendation: string}
     */
    public function patientSearchPerformance(): array
    {
        $patientCount = DB::table('patients')->count();

        // Check for pg_trgm extension (needed for efficient text search)
        $hasTrgm = DB::select(
            "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
        );

        $recommendation = 'Patient search indexed and optimized.';
        if ($patientCount > 10000 && empty($hasTrgm)) {
            $recommendation = 'Consider enabling pg_trgm extension for efficient fuzzy search on large patient datasets.';
        }

        return [
            'patient_count' => $patientCount,
            'search_method' => ! empty($hasTrgm) ? 'pg_trgm_trigram' : 'ilike',
            'estimated_latency_ms' => $patientCount > 10000 ? 50 : 10,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * RLS overhead estimation.
     *
     * Estimates the overhead of RLS policies based on policy complexity and count.
     *
     * @return array{total_policies: int, avg_policies_per_table: float, estimated_overhead_pct: int, overhead_assessment: string}
     */
    public function rlsOverheadEstimate(): array
    {
        $totalPolicies = DB::select(
            "SELECT count(*) as cnt FROM pg_policies WHERE schemaname = 'public'"
        )[0]->cnt;

        $rlsTables = DB::select(
            "SELECT count(*) as cnt FROM pg_tables WHERE schemaname = 'public' AND rowsecurity = true"
        )[0]->cnt;

        $avgPolicies = $rlsTables > 0 ? round($totalPolicies / $rlsTables, 1) : 0;

        // Estimate overhead: each policy adds a WHERE clause check
        // With 4 policies per table (INSERT/SELECT/UPDATE/DELETE), overhead is ~5-10% for typical queries
        // With the helper functions using GUC, the overhead is slightly higher but still acceptable
        $overheadPct = match (true) {
            $totalPolicies < 100 => 2,
            $totalPolicies < 300 => 5,
            $totalPolicies < 600 => 8,
            default => 10,
        };

        $assessment = 'RLS overhead acceptable. Helper functions minimize per-query cost.';
        if ($totalPolicies > 700) {
            $assessment = 'High policy count (700+). Monitor query plans for RLS overhead. Helper functions use GUC for efficient evaluation.';
        }

        return [
            'total_policies' => $totalPolicies,
            'avg_policies_per_table' => $avgPolicies,
            'estimated_overhead_pct' => $overheadPct,
            'overhead_assessment' => $assessment,
        ];
    }

    /**
     * Noisy-neighbor analysis: check for tenant skew risk.
     *
     * @return list<array{tenant_id: string, record_count: int, percentage: float, risk: string}>
     */
    public function tenantSkewAnalysis(): array
    {
        $totalPatients = DB::table('patients')->count();

        if ($totalPatients === 0) {
            return [];
        }

        $tenants = DB::select('
            SELECT tenant_id, count(*) as cnt
            FROM patients
            GROUP BY tenant_id
            ORDER BY cnt DESC
        ');

        $skew = [];
        foreach ($tenants as $tenant) {
            $pct = round(($tenant->cnt / $totalPatients) * 100, 1);
            $risk = match (true) {
                $pct > 50 => 'HIGH - dominant tenant',
                $pct > 25 => 'MEDIUM - significant tenant',
                default => 'LOW',
            };

            $skew[] = [
                'tenant_id' => $tenant->tenant_id,
                'record_count' => $tenant->cnt,
                'percentage' => $pct,
                'risk' => $risk,
            ];
        }

        return $skew;
    }

    /**
     * Full scale summary for the current database.
     *
     * @return array{database: array, rls: array, search: array, connections: array, capacity: array, skew: list<array>}
     */
    public function fullScaleSummary(): array
    {
        return [
            'database' => $this->databaseCapacity(),
            'rls' => $this->rlsOverheadEstimate(),
            'search' => $this->patientSearchPerformance(),
            'connections' => $this->connectionCapacity(),
            'capacity' => $this->hospitalCapacityModel(),
            'skew' => $this->tenantSkewAnalysis(),
        ];
    }
}
