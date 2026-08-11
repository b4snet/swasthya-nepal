<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root: organizations (DATABASE.md §3.1, TENANCY.md §0).
 *
 * The organization IS the tenant — it carries no tenant_id and is RLS-exempt
 * (it is the boundary, not a row inside it). Soft deletion does not apply:
 * a tenant is never deleted; status moves to 'offboarded' and data is purged
 * per policy (DATABASE.md §0.11, TENANCY.md §14).
 *
 * Money/currency and timezone live here as tenant defaults (DATABASE.md §0.4);
 * tax_config is basis points per tax type (never floats).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 50);
            $table->text('status')->default('active');
            $table->char('currency', 3)->default('NPR');
            $table->string('timezone')->default('Asia/Kathmandu');
            $table->string('locale', 20)->default('en');
            $table->jsonb('tax_config')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            "alter table organizations add constraint chk_organizations_status check (status in ('active', 'suspended', 'closed', 'offboarded'))"
        );

        // The org code (slug) is unique and immutable in practice — the
        // human-facing tenant identifier, never a foreign key (TENANCY.md §1).
        DB::statement('alter table organizations add constraint uq_organizations_code unique (code)');
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
