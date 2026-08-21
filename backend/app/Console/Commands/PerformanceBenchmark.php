<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run performance benchmarks against seeded data.
 *
 * Measures p50/p95/p99 latency, throughput, and error rate for representative queries.
 */
final class PerformanceBenchmark extends Command
{
    protected $signature = 'perf:benchmark
        {--iterations=1000 : Number of iterations per benchmark}
        {--concurrency=10 : Concurrent threads (simulated)}';

    protected $description = 'Run performance benchmarks against seeded data';

    private int $iterations;

    public function handle(): int
    {
        $this->iterations = (int) $this->option('iterations');
        $concurrency = (int) $this->option('concurrency');

        $this->info("Performance Benchmark — {$this->iterations} iterations, {$concurrency} concurrent threads");
        $this->line(str_repeat('─', 60));

        // Get tenant/facility for queries
        $tenant = DB::table('organizations')->where('code', 'like', 'perf-group-%')->first();
        if (! $tenant) {
            $this->error('No perf data found. Run: php artisan perf:seed');

            return self::FAILURE;
        }

        $facility = DB::table('facilities')->where('tenant_id', $tenant->id)->first();

        $benchmarks = [
            'patient_search' => fn () => DB::table('patients')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->where('full_name', 'like', '%Patient '.rand(1, 1000).'%')
                ->limit(20)
                ->get(),

            'patient_by_mrn' => fn () => DB::table('patients')
                ->where('tenant_id', $tenant->id)
                ->where('mrn', 'MRN-'.str_pad(rand(1, 50000), 7, '0', STR_PAD_LEFT))
                ->first(),

            'patient_count' => fn () => DB::table('patients')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->count(),

            'encounters_list' => fn () => DB::table('encounters')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get(),

            'appointments_today' => fn () => DB::table('appointments')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->where('starts_at', '>=', now()->subDays(30)->format('Y-m-d'))
                ->limit(100)
                ->get(),

            'lab_orders_pending' => fn () => DB::table('lab_orders')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->where('status', 'ordered')
                ->limit(50)
                ->get(),

            'prescriptions_active' => fn () => DB::table('prescriptions')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->limit(50)
                ->get(),

            'invoices_outstanding' => fn () => DB::table('invoices')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->where('status', 'issued')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get(),

            'audit_events_recent' => fn () => DB::table('audit_events')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->orderBy('occurred_at', 'desc')
                ->limit(50)
                ->get(),

            'stock_batches_expiry' => fn () => DB::table('stock_batches')
                ->where('tenant_id', $tenant->id)
                ->where('facility_id', $facility->id)
                ->where('expiry_date', '<=', now()->addMonths(3)->format('Y-m-d'))
                ->where('status', 'available')
                ->limit(50)
                ->get(),

            'full_patient_with_encounters' => fn () => DB::table('patients')
                ->leftJoin('encounters', 'patients.id', '=', 'encounters.patient_id')
                ->where('patients.tenant_id', $tenant->id)
                ->where('patients.facility_id', $facility->id)
                ->where('patients.id', DB::raw("(SELECT id FROM patients WHERE tenant_id = '".$tenant->id."' LIMIT 1)"))
                ->get(),

            'dashboard_summary' => fn () => [
                'patients' => DB::table('patients')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->count(),
                'encounters' => DB::table('encounters')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->count(),
                'appointments' => DB::table('appointments')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->count(),
                'invoices' => DB::table('invoices')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->count(),
                'lab_orders' => DB::table('lab_orders')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->count(),
                'revenue' => DB::table('invoices')->where('tenant_id', $tenant->id)->where('facility_id', $facility->id)->where('status', 'paid')->sum('total_minor'),
            ],
        ];

        $results = [];

        foreach ($benchmarks as $name => $query) {
            $this->line("\n  Benchmark: {$name}");
            $result = $this->runBenchmark($name, $query);
            $results[$name] = $result;
            $this->line("    p50: {$result['p50']}ms  p95: {$result['p95']}ms  p99: {$result['p99']}ms  throughput: {$result['throughput']}qps  errors: {$result['errors']}");
        }

        $this->newLine();
        $this->info('════════════════════════════════════════════════════════');
        $this->info('BENCHMARK SUMMARY');
        $this->info('════════════════════════════════════════════════════════');

        $totalTime = 0;
        $totalErrors = 0;
        foreach ($results as $name => $r) {
            $totalTime += $r['total_ms'];
            $totalErrors += $r['errors'];
            $this->line(sprintf('  %-30s  p50: %6sms  p95: %6sms  p99: %6sms  %6s qps', $name, $r['p50'], $r['p95'], $r['p99'], $r['throughput']));
        }

        $this->newLine();
        $this->line('  Total time: '.round($totalTime, 1).'ms');
        $this->line("  Total errors: {$totalErrors}");
        $this->info('════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function runBenchmark(string $name, callable $query): array
    {
        $times = [];
        $errors = 0;
        $startTotal = microtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            try {
                $query();
            } catch (\Throwable $e) {
                $errors++;
            }
            $elapsed = (microtime(true) - $start) * 1000;
            $times[] = $elapsed;
        }

        sort($times);
        $totalMs = (microtime(true) - $startTotal) * 1000;
        $count = count($times);

        return [
            'name' => $name,
            'p50' => $count > 0 ? round($times[(int) ($count * 0.5)], 1) : 0,
            'p95' => $count > 0 ? round($times[(int) ($count * 0.95)], 1) : 0,
            'p99' => $count > 0 ? round($times[(int) ($count * 0.99)], 1) : 0,
            'throughput' => $totalMs > 0 ? (int) ($this->iterations / ($totalMs / 1000)) : 0,
            'errors' => $errors,
            'total_ms' => round($totalMs, 1),
            'min' => $count > 0 ? round($times[0], 1) : 0,
            'max' => $count > 0 ? round($times[$count - 1], 1) : 0,
        ];
    }
}
