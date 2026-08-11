<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Services: the facility's catalog of clinical/billable offerings (OPD
 * consultation, procedure, investigation…), referenced by doctor schedules
 * (DATABASE.md §3.16 service_id) and appointment booking (PRODUCT_REQUIREMENTS
 * §6.1), and by billing in later phases.
 *
 * This table extends the DATABASE.md logical model: §3.16 references
 * `service_id` but no services entity was defined there — this is the
 * definition, added in Phase 4 and recorded in DEVELOPMENT_LOG.md.
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities (DATABASE.md
 * §0.9). Rates are integer minor units, never floats (DATABASE.md §0.4).
 * Soft-deletable with an active-scope partial unique on code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id')->nullable();
            $table->string('name');
            $table->string('code', 50);
            $table->text('service_type')->default('other');
            $table->text('status')->default('active');
            $table->integer('default_duration_minutes')->nullable();
            $table->bigInteger('default_charge_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table services add constraint chk_services_type check (service_type in ('opd_consultation', 'procedure', 'investigation', 'follow_up', 'other'))"
        );
        DB::statement(
            "alter table services add constraint chk_services_status check (status in ('active', 'inactive'))"
        );
        DB::statement(
            'alter table services add constraint chk_services_currency check (currency is null or char_length(currency) = 3)'
        );

        DB::statement(
            'create unique index uq_services_tenant_facility_code on services (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_services_tenant_facility on services (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
