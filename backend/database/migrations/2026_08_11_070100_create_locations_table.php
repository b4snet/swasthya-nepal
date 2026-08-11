<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locations (DATABASE.md §3.9): generic physical places that are not clinical
 * bed spaces — waiting areas, stores, nursing stations, procedure areas.
 * Used by inventory placement and asset tracking in later phases.
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities (DATABASE.md
 * §0.9). branch_id is reserved for the branches entity (a later phase) and
 * carries no FK yet.
 *
 * Soft-deletable with an active-scope partial unique on code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('code', 50);
            $table->text('type')->default('other');
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table locations add constraint chk_locations_type check (type in ('store', 'waiting_area', 'nursing_station', 'procedure_area', 'other'))"
        );
        DB::statement(
            "alter table locations add constraint chk_locations_status check (status in ('active', 'inactive'))"
        );

        DB::statement(
            'create unique index uq_locations_tenant_facility_code on locations (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_locations_tenant_facility on locations (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
