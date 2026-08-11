<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenancy-and-authorization join (DATABASE.md §3.7, TENANCY.md §0).
 *
 * A user's access is ALWAYS expressed as scoped assignments — never as
 * global membership on the user row. This table is also the user's
 * membership record: a user with no active assignments has no access.
 *
 *  - tenant_id is NULL only for platform-scope assignments;
 *  - facility_id is the domain scope for facility-local roles;
 *  - branch_id is reserved for branch-scope roles (FK arrives with the
 *    branches phase);
 *  - revocation is a status transition (active -> revoked), never a DELETE —
 *    assignment history is the authorization audit (DATABASE.md §3.7);
 *  - one active assignment per (user, role, scope): enforced by a partial
 *    unique index using NULLS NOT DISTINCT so org-scoped (facility NULL)
 *    duplicates are caught too (PostgreSQL 15+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->text('scope_type');
            $table->text('status')->default('active');
            $table->uuid('granted_by')->nullable();
            $table->timestampTz('granted_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
        });

        DB::statement(
            "alter table role_assignments add constraint chk_role_assignments_scope_type check (scope_type in ('platform', 'organization', 'facility', 'branch'))"
        );
        DB::statement(
            "alter table role_assignments add constraint chk_role_assignments_status check (status in ('active', 'revoked'))"
        );
        // Platform assignments have no tenant; every other scope must name one.
        DB::statement(
            "alter table role_assignments add constraint chk_role_assignments_tenant check ((scope_type = 'platform' and tenant_id is null) or (scope_type <> 'platform' and tenant_id is not null))"
        );

        DB::statement('create index idx_role_assignments_user_status on role_assignments (user_id, status)');
        DB::statement('create index idx_role_assignments_tenant_role on role_assignments (tenant_id, role_id)');
        DB::statement(
            'create unique index uq_role_assignments_active_scope on role_assignments (user_id, role_id, tenant_id, facility_id, branch_id) nulls not distinct where status = \'active\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
