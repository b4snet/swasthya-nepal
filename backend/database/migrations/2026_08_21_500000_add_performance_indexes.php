<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add performance indexes for national-scale benchmark optimization.
 *
 * These indexes target the specific query patterns identified in
 * performance benchmarks with 50K+ patients and 200K+ encounters.
 */
return new class extends Migration
{
    public function up(): void
    {
        // encounters: the main bottleneck — orderBy('created_at', 'desc') with tenant+facility filter
        DB::statement('CREATE INDEX IF NOT EXISTS idx_encounters_perf_list ON encounters (tenant_id, facility_id, created_at DESC)');

        // patients: text search optimization — the LIKE '%name%' query
        DB::statement('CREATE INDEX IF NOT EXISTS idx_encounters_perf_patient ON encounters (patient_id, tenant_id, created_at DESC)');

        // invoices: the dashboard counts and outstanding queries
        DB::statement('CREATE INDEX IF NOT EXISTS idx_invoices_perf_count ON invoices (tenant_id, facility_id, status, created_at DESC)');

        // dashboard_summary: total counts per tenant+facility
        DB::statement('CREATE INDEX IF NOT EXISTS idx_lab_orders_perf_status ON lab_orders (tenant_id, facility_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_encounters_perf_list');
        DB::statement('DROP INDEX IF EXISTS idx_encounters_perf_patient');
        DB::statement('DROP INDEX IF EXISTS idx_invoices_perf_count');
        DB::statement('DROP INDEX IF EXISTS idx_lab_orders_perf_status');
    }
};
