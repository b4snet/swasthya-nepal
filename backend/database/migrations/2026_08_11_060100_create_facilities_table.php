<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facilities: hospitals/clinics owned by one organization (DATABASE.md §3.2).
 *
 * Tenant-scoped: tenant_id is NOT NULL and every child of a facility is
 * tenant-safe (composite (tenant_id, facility_id) FKs) so a cross-tenant
 * reference is structurally impossible (DATABASE.md §0.9).
 *
 * Soft-deletable with an active-scope partial unique index so a closed
 * facility's code can be deliberately reused (DATABASE.md §0.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('status')->default('active');
            $table->string('timezone');
            $table->jsonb('address')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table facilities add constraint chk_facilities_status check (status in ('active', 'inactive'))"
        );

        // Unique per tenant, only among live facilities — a soft-deleted
        // facility does not block code reuse (DATABASE.md §0.11).
        DB::statement(
            'create unique index uq_facilities_tenant_code on facilities (tenant_id, code) where deleted_at is null'
        );

        DB::statement('create index idx_facilities_tenant on facilities (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
