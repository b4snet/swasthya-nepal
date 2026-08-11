<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payers master (DATABASE.md §3.14 references the `payers` master but never
 * defines it — this is that definition, added in Phase 5 and recorded in
 * DEVELOPMENT_LOG.md as §3.45): the tenant's catalog of insurers, TPAs, and
 * government schemes that patient insurance policies reference.
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities is not needed
 * here — payers are tenant-wide, not facility-scoped (a policy covers a
 * patient at any facility of the tenant). A unique (tenant_id, id) index
 * backs the insurance_policies composite FK (DATABASE.md §0.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('payer_type')->default('private');
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table payers add constraint chk_payers_type check (payer_type in ('government', 'private', 'tpa', 'other'))"
        );
        DB::statement(
            "alter table payers add constraint chk_payers_status check (status in ('active', 'inactive'))"
        );

        DB::statement('create unique index uq_payers_tenant_code on payers (tenant_id, code)');
        // Backs the insurance_policies composite FK (tenant_id, payer_id).
        DB::statement('create unique index uq_payers_tenant_id_id on payers (tenant_id, id)');
        DB::statement('create index idx_payers_tenant on payers (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payers');
    }
};
