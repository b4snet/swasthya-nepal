<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medications / formulary (DATABASE.md §3.22): the tenant's medicine
 * catalog — the reference for prescribing and dispensing. Prices are
 * integer minor units, never floats (DATABASE.md §0.4).
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities. Soft-
 * deletable with an active-scope partial unique on code — retired medicines
 * stay referenced by prescription history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->text('generic_name');
            $table->text('brand_name')->nullable();
            $table->text('strength');
            $table->text('form')->default('tablet'); // tablet, syrup, injection…
            $table->text('unit');
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('NPR');
            $table->boolean('is_controlled')->default(false);
            $table->text('status')->default('active'); // active, inactive
            $table->bigInteger('lock_version')->default(0);
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
            "alter table medications add constraint chk_medications_status check (status in ('active', 'inactive'))"
        );
        DB::statement(
            'alter table medications add constraint chk_medications_price check (price_minor >= 0)'
        );
        DB::statement(
            'alter table medications add constraint chk_medications_currency check (char_length(currency) = 3)'
        );

        DB::statement(
            'create unique index uq_medications_tenant_facility_code on medications (tenant_id, facility_id, code) where deleted_at is null'
        );
        // Composite-FK support: prescription_lines reference medications
        // via (tenant_id, id).
        DB::statement('create unique index uq_medications_tenant_id on medications (tenant_id, id)');
        DB::statement('create index idx_medications_tenant_facility on medications (tenant_id, facility_id)');
        DB::statement('create index idx_medications_tenant_name on medications (tenant_id, generic_name)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
