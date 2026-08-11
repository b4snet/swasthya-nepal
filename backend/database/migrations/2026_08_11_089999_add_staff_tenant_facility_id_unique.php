<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite-FK support index on staff (DATABASE.md §0.9).
 *
 * Child tables (schedule_templates, schedule_exceptions, appointments,
 * encounters) reference staff via (tenant_id, facility_id, id) — PostgreSQL
 * requires a unique constraint on the referenced columns. Phase 4's staff
 * migration omitted this redundant-but-required index (departments and rooms
 * have theirs); this adds it without touching the existing migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index uq_staff_tenant_facility_id on staff (tenant_id, facility_id, id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_staff_tenant_facility_id');
    }
};
