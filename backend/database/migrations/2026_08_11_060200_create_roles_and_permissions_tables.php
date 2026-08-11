<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-global RBAC catalogs (DATABASE.md §3.5–3.6, MASTER_RULES.md §9).
 *
 * roles and permissions carry NO tenant_id: they are platform-owned,
 * read-only to tenants, seeded (never user-creatable per tenant), and never
 * deleted — roles are retired as a status change, permissions are additive.
 *
 * role_permissions is the N–N grant matrix, also platform-owned; a grant is
 * audited (who granted what). Permission codes are namespaced 'domain:action'
 * and are part of the versioned API contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100);
            $table->string('name');
            $table->text('scope_type');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        DB::statement('alter table roles add constraint uq_roles_code unique (code)');
        DB::statement(
            "alter table roles add constraint chk_roles_scope_type check (scope_type in ('platform', 'organization', 'facility', 'branch'))"
        );

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100);
            $table->string('domain');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        DB::statement('alter table permissions add constraint uq_permissions_code unique (code)');
        DB::statement('create index idx_permissions_domain on permissions (domain)');

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestampsTz();

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
