<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the tenant-safe composite FK pattern (DATABASE.md §0.9): child
 * tables of facilities reference `FOREIGN KEY (tenant_id, facility_id)
 * REFERENCES facilities (tenant_id, id)`, which PostgreSQL requires to be
 * backed by a unique index on the parent's referenced columns.
 *
 * The index is redundant with the primary key but small; it buys structural
 * impossibility of cross-tenant references — a row in tenant A can never
 * point at a facility in tenant B even if application code is buggy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index uq_facilities_tenant_id_id on facilities (tenant_id, id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_facilities_tenant_id_id');
    }
};
