<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite-FK support indexes (DATABASE.md §0.9) for parents that already
 * exist when this migration runs (staff, services).
 *
 * Child tables reference parents via tenant-scoped composite FKs
 * ((tenant_id, id) or (tenant_id, facility_id, id)); PostgreSQL requires a
 * unique constraint on the exact referenced column set. Indexes for the
 * Phase 6/7 parents are created inside their own table migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        // staff: (tenant_id, id) — referenced by clinical_notes (author),
        // prescriptions (prescriber). staff itself is Phase 4.
        DB::statement('create unique index uq_staff_tenant_id on staff (tenant_id, id)');

        // services: (tenant_id, facility_id, id) — schedule_templates,
        // appointments. services itself is Phase 4.
        DB::statement('create unique index uq_services_tenant_facility_id on services (tenant_id, facility_id, id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_staff_tenant_id');
        DB::statement('drop index if exists uq_services_tenant_facility_id');
    }
};
