<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wards (DATABASE.md §3.24): a clinical ward within a facility (general,
 * surgery, pediatric, ICU…), grouping rooms and beds.
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities (DATABASE.md
 * §0.9). Soft-deletable, but RESTRICT while rooms exist — a ward with
 * clinical capacity is never removed (DATABASE.md §3.24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('ward_type')->default('general');
            $table->text('status')->default('active');
            $table->jsonb('settings')->default('{}');
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
            "alter table wards add constraint chk_wards_type check (ward_type in ('general', 'surgery', 'pediatric', 'icu', 'maternity', 'other'))"
        );
        DB::statement(
            "alter table wards add constraint chk_wards_status check (status in ('active', 'inactive'))"
        );

        // Unique (tenant_id, facility_id, id) backs the rooms composite FK.
        DB::statement('create unique index uq_wards_tenant_facility_id on wards (tenant_id, facility_id, id)');

        DB::statement(
            'create unique index uq_wards_tenant_facility_code on wards (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_wards_tenant_facility on wards (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wards');
    }
};
