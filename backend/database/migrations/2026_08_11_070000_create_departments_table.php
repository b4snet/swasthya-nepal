<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments (DATABASE.md §3.8): organizational structure within a facility
 * (OPD, surgery, pharmacy…), used by staff, inventory, and reporting.
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities (DATABASE.md
 * §0.9) so a cross-tenant reference is structurally impossible. The parent
 * hierarchy is self-referencing with the same composite pattern.
 *
 * Soft-deletable with an active-scope partial unique on code (DATABASE.md
 * §0.11). A department with staff cannot be deleted — the composite FK is
 * RESTRICT and staff never delete (departure is a status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('status')->default('active');
            $table->uuid('parent_department_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Unique (tenant_id, facility_id, id) backs the composite self-FK.
        DB::statement('create unique index uq_departments_tenant_facility_id on departments (tenant_id, facility_id, id)');

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'parent_department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table departments add constraint chk_departments_status check (status in ('active', 'inactive'))"
        );

        // Unique per (tenant, facility) among live departments only.
        DB::statement(
            'create unique index uq_departments_tenant_facility_code on departments (tenant_id, facility_id, code) where deleted_at is null'
        );

        DB::statement('create index idx_departments_tenant_facility on departments (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
