<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * PHASE 92 — Disaster Recovery and Business Continuity.
 *
 * Provides:
 * - Health verification (liveness, readiness, dependency checks)
 * - Database recovery validation
 * - RLS integrity verification after restore
 * - Data reconciliation checks
 * - Recovery state tracking
 *
 * All chaos testing uses disposable environments only.
 */
final class ResilienceService
{
    /**
     * Perform a comprehensive health check of the application stack.
     *
     * @return array{
     *     status: string,
     *     checks: list<array{name: string, status: string, latency_ms: float, details: string}>,
     *     timestamp: string
     * }
     */
    public function healthCheck(): array
    {
        $checks = [];
        $overallStatus = 'healthy';

        // Database check
        $dbCheck = $this->checkDatabase();
        $checks[] = $dbCheck;
        if ($dbCheck['status'] !== 'healthy') {
            $overallStatus = 'degraded';
        }

        // RLS check
        $rlsCheck = $this->checkRLS();
        $checks[] = $rlsCheck;
        if ($rlsCheck['status'] !== 'healthy') {
            $overallStatus = 'degraded';
        }

        // Redis check (optional)
        $redisCheck = $this->checkRedis();
        $checks[] = $redisCheck;
        if ($redisCheck['status'] === 'unavailable') {
            // Redis is optional - degrade but don't fail
            if ($overallStatus === 'healthy') {
                $overallStatus = 'degraded';
            }
        }

        // Queue check
        $queueCheck = $this->checkQueue();
        $checks[] = $queueCheck;

        return [
            'status' => $overallStatus,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Verify database connectivity and basic operations.
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'name' => 'database',
                'status' => 'healthy',
                'latency_ms' => $latency,
                'details' => 'Connection OK, query latency: '.$latency.'ms',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'database',
                'status' => 'unhealthy',
                'latency_ms' => -1,
                'details' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Verify RLS is still active and functional.
     */
    private function checkRLS(): array
    {
        try {
            // Check that RLS policies exist
            $policyCount = DB::select(
                "SELECT count(*) as cnt FROM pg_policies WHERE schemaname = 'public'"
            )[0]->cnt;

            if ($policyCount < 100) {
                return [
                    'name' => 'rls',
                    'status' => 'degraded',
                    'latency_ms' => 0,
                    'details' => "Only {$policyCount} RLS policies found (expected 700+)",
                ];
            }

            // Check helper functions exist
            $functions = DB::select(
                "SELECT proname FROM pg_proc WHERE proname LIKE 'swasthya_rls_%' ORDER BY proname"
            );
            $fnNames = array_column($functions, 'proname');

            $required = ['swasthya_rls_claim', 'swasthya_rls_tenant_id', 'swasthya_rls_facility_id'];
            $missing = array_diff($required, $fnNames);

            if (! empty($missing)) {
                return [
                    'name' => 'rls',
                    'status' => 'unhealthy',
                    'latency_ms' => 0,
                    'details' => 'Missing RLS functions: '.implode(', ', $missing),
                ];
            }

            // Check swasthya_app role has NOBYPASSRLS
            $role = DB::select(
                "SELECT rolbypassrls FROM pg_roles WHERE rolname = 'swasthya_app'"
            );

            if (! empty($role) && $role[0]->rolbypassrls) {
                return [
                    'name' => 'rls',
                    'status' => 'unhealthy',
                    'latency_ms' => 0,
                    'details' => 'swasthya_app has BYPASSRLS — RLS is bypassed!',
                ];
            }

            return [
                'name' => 'rls',
                'status' => 'healthy',
                'latency_ms' => 0,
                'details' => "{$policyCount} policies, ".count($fnNames).' helper functions, NOBYPASSRLS confirmed',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'rls',
                'status' => 'unhealthy',
                'latency_ms' => 0,
                'details' => 'RLS check failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connectivity (optional dependency).
     */
    private function checkRedis(): array
    {
        try {
            // Skip Redis check if not configured or extension not loaded
            if (config('cache.store') !== 'redis' && config('cache.default') !== 'redis') {
                return [
                    'name' => 'redis',
                    'status' => 'not_configured',
                    'latency_ms' => 0,
                    'details' => 'Redis not configured as cache/driver — optional dependency',
                ];
            }

            if (! extension_loaded('redis')) {
                return [
                    'name' => 'redis',
                    'status' => 'not_configured',
                    'latency_ms' => 0,
                    'details' => 'Redis PHP extension not installed',
                ];
            }

            $start = microtime(true);
            Redis::ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'name' => 'redis',
                'status' => 'healthy',
                'latency_ms' => $latency,
                'details' => 'Connected, ping: '.$latency.'ms',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'redis',
                'status' => 'unavailable',
                'latency_ms' => -1,
                'details' => 'Redis not available: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check queue health.
     */
    private function checkQueue(): array
    {
        try {
            $driver = config('queue.default');
            $pending = 0;

            if ($driver === 'database') {
                $pending = DB::table('jobs')->count();
            }

            return [
                'name' => 'queue',
                'status' => $pending > 1000 ? 'degraded' : 'healthy',
                'latency_ms' => 0,
                'details' => "Driver: {$driver}, pending jobs: {$pending}",
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'queue',
                'status' => 'unavailable',
                'latency_ms' => 0,
                'details' => 'Queue check failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Verify data integrity across critical tables.
     *
     * @return array{status: string, tables: list<array{name: string, count: int, status: string}>, integrity: string}
     */
    public function dataIntegrityCheck(): array
    {
        $tables = [
            'patients' => 'patient_identity',
            'encounters' => 'clinical_record',
            'lab_orders' => 'diagnostics',
            'prescriptions' => 'medication',
            'invoices' => 'finance',
            'payments' => 'finance',
            'audit_events' => 'audit',
        ];

        $results = [];
        $status = 'healthy';
        $totalRecords = 0;

        foreach ($tables as $table => $classification) {
            try {
                $count = DB::table($table)->count();
                $totalRecords += $count;
                $results[] = [
                    'name' => $table,
                    'count' => $count,
                    'status' => 'accessible',
                ];
            } catch (\Exception $e) {
                $status = 'degraded';
                $results[] = [
                    'name' => $table,
                    'count' => 0,
                    'status' => 'error: '.$e->getMessage(),
                ];
            }
        }

        return [
            'status' => $status,
            'tables' => $results,
            'total_records' => $totalRecords,
            'integrity' => $status === 'healthy' ? 'all_critical_tables_accessible' : 'some_tables_inaccessible',
        ];
    }

    /**
     * Verify RLS integrity after a database restore.
     *
     * This is MANDATORY after any restore operation.
     *
     * @return array{
     *     status: string,
     *     checks: list<array{name: string, status: string, details: string}>,
     *     recommendation: string
     * }
     */
    public function postRestoreRLSVerification(): array
    {
        $checks = [];

        // 1. RLS enabled on tables
        $rlsEnabled = DB::select(
            "SELECT count(*) as cnt FROM pg_tables WHERE schemaname='public' AND rowsecurity=true"
        )[0]->cnt;
        $checks[] = [
            'name' => 'rls_enabled_tables',
            'status' => $rlsEnabled > 100 ? 'pass' : 'fail',
            'details' => "{$rlsEnabled} tables have RLS enabled",
        ];

        // 2. Policies exist
        $policyCount = DB::select(
            "SELECT count(*) as cnt FROM pg_policies WHERE schemaname='public'"
        )[0]->cnt;
        $checks[] = [
            'name' => 'rls_policies',
            'status' => $policyCount > 500 ? 'pass' : 'fail',
            'details' => "{$policyCount} RLS policies exist",
        ];

        // 3. Helper functions
        $fnCount = DB::select(
            "SELECT count(*) as cnt FROM pg_proc WHERE proname LIKE 'swasthya_rls_%'"
        )[0]->cnt;
        $checks[] = [
            'name' => 'helper_functions',
            'status' => $fnCount === 6 ? 'pass' : 'fail',
            'details' => "{$fnCount}/6 helper functions present",
        ];

        // 4. NOBYPASSRLS
        $role = DB::select(
            "SELECT rolbypassrls FROM pg_roles WHERE rolname = 'swasthya_app'"
        );
        $bypassOk = empty($role) || ! $role[0]->rolbypassrls;
        $checks[] = [
            'name' => 'nobypassrls',
            'status' => $bypassOk ? 'pass' : 'fail',
            'details' => $bypassOk ? 'swasthya_app has NOBYPASSRLS' : 'swasthya_app has BYPASSRLS!',
        ];

        $allPass = ! in_array('fail', array_column($checks, 'status'));

        return [
            'status' => $allPass ? 'pass' : 'fail',
            'checks' => $checks,
            'recommendation' => $allPass
                ? 'RLS integrity confirmed after restore. Safe to resume operations.'
                : 'CRITICAL: RLS integrity compromised. Do NOT resume operations until fixed. Run roles.sql and grants.sql.',
        ];
    }

    /**
     * Create a recovery verification record.
     *
     * @param  string  $scenario  The failure scenario tested
     * @param  string  $result  pass|fail
     * @param  array{detection_time: string|null, recovery_time: string|null, data_loss: string, notes: string}  $details
     * @return string The verification record ID
     */
    public function recordRecoveryVerification(string $scenario, string $result, array $details): string
    {
        $id = Str::uuid7()->toString();

        DB::table('audit_events')->insert([
            'id' => $id,
            'event_type' => 'resilience.recovery_verified',
            'aggregate_type' => 'resilience_test',
            'aggregate_id' => $id,
            'payload' => json_encode([
                'scenario' => $scenario,
                'result' => $result,
                'detection_time' => $details['detection_time'],
                'recovery_time' => $details['recovery_time'],
                'data_loss' => $details['data_loss'],
                'notes' => $details['notes'],
                'verified_by' => auth()->id(),
            ]),
            'causer_type' => 'App\\Models\\User',
            'causer_id' => auth()->id() ?? 'system',
            'idempotency_key' => 'recovery-'.$id,
            'status' => 'completed',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }
}
